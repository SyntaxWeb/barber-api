<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentStatusRequest;
use App\Http\Resources\AppointmentResource;
use App\Jobs\SendAppointmentAlertJob;
use App\Jobs\SendFeedbackInvitationJob;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\LoyaltyRedemption;
use App\Models\Service;
use App\Notifications\NewAppointmentNotification;
use App\Services\AvailabilityService;
use App\Services\ActivityLogger;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

class AppointmentController extends Controller
{
    private function resolveRequestedServiceIds(array $data): array
    {
        $serviceIds = collect($data['service_ids'] ?? ($data['service_id'] ? [$data['service_id']] : []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($serviceIds)) {
            abort(422, 'Selecione pelo menos um serviço.');
        }

        return $serviceIds;
    }

    public function index(Request $request)
    {
        $companyId = $request->user('sanctum')?->company_id;
        if (!$companyId) {
            abort(403, 'Usuário não associado a uma empresa.');
        }
        $query = Appointment::with(['service', 'services', 'company', 'feedback', 'loyaltyRedemption.reward'])->where('company_id', $companyId);

        if ($request->has('date')) {
            $query->whereDate('data', $request->date);
        }
        if ($request->has(['from', 'to'])) {
            $query->whereBetween('data', [$request->from, $request->to]);
        }

        return AppointmentResource::collection(
            $query->orderBy('data')->orderBy('horario')->get()
        );
    }

    public function store(Request $request, AvailabilityService $availability)
    {

        $validator = Validator::make($request->all(), [
            'cliente'       => 'sometimes|required|string|max:255',
            'telefone'      => 'sometimes|required|string|max:30',
            'data'          => 'required|date|after_or_equal:today',
            'horario'       => 'required|string',
            'service_id'    => 'nullable|exists:services,id',
            'service_ids'   => 'nullable|array|min:1',
            'service_ids.*' => 'integer|exists:services,id',
            'preco'         => 'nullable|numeric|min:0',
            'observacoes'   => 'nullable|string',
            'company_slug'  => 'nullable|string|exists:companies,slug',
            'loyalty_redemption_id' => 'nullable|integer|exists:loyalty_redemptions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Erro de validação.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $user = $request->user('sanctum');

        if (!in_array($user->role, ['provider', 'client'])) {
            return response()->json(['message' => 'Usuário não autorizado a criar agendamentos.'], 403);
        }

        $companyId = $user->company_id;
        if ($user->role === 'client') {
            $companyId = $this->resolveCompanyFromSlug($request);
        } elseif (!$companyId) {
            return response()->json(['message' => 'Associe-se a uma empresa antes de agendar.'], 422);
        }

        if (!$companyId) {
            return response()->json(['message' => 'Selecione uma empresa para agendar.'], 422);
        }

        $data['cliente'] = $data['cliente'] ?? $user->name;
        $data['telefone'] = $data['telefone'] ?? $user->telefone;

        if (blank($data['cliente']) || blank($data['telefone'])) {
            return response()->json(['message' => 'Complete o perfil do cliente antes de agendar.'], 422);
        }

        $serviceIds = $this->resolveRequestedServiceIds($data);
        $services = Service::where('company_id', $companyId)
            ->where('ativo', true)
            ->whereIn('id', $serviceIds)
            ->get();
        if ($services->count() !== count($serviceIds)) {
            return response()->json(['message' => 'Serviço inativo ou não encontrado.'], 422);
        }

        if (
            !in_array(
                $data['horario'],
                $availability->horariosDisponiveis($data['data'], $companyId, $serviceIds),
                true
            )
        ) {
            return response()->json(['message' => 'Horário indisponível'], 422);
        }

        $data['service_id'] = $serviceIds[0];
        $data['preco'] = (float) $services->sum('preco');

        $redemption = null;
        if (!empty($data['loyalty_redemption_id'])) {
            $redemption = LoyaltyRedemption::with('reward')
                ->where('id', $data['loyalty_redemption_id'])
                ->where('company_id', $companyId)
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->first();

            if (!$redemption) {
                return response()->json(['message' => 'Resgate de fidelidade indisponível para uso.'], 422);
            }

            if (!$redemption->reward || !$redemption->reward->grants_free_appointment) {
                return response()->json(['message' => 'Esta recompensa não libera agendamento gratuito.'], 422);
            }

            $data['preco'] = 0;
        }

        unset($data['company_slug'], $data['loyalty_redemption_id']);

        $existingAppointment = Appointment::where('company_id', $companyId)
            ->whereDate('data', $data['data'])
            ->where('horario', $data['horario'])
            ->first();

        if ($existingAppointment) {
            if ($existingAppointment->status !== 'cancelado') {
                return response()->json(['message' => 'Horário já ocupado.'], 422);
            }

            $existingAppointment->update($data + [
                'status' => 'confirmado',
                'user_id' => $user->id,
            ]);

            if ($redemption) {
                $redemption->update([
                    'appointment_id' => $existingAppointment->id,
                    'status' => 'applied',
                ]);
            }

            $existingAppointment->load('service', 'company', 'company.users');
            $this->notifyCompanyUsers($existingAppointment);
            ActivityLogger::record($user, 'appointment.reactivated', [
                'appointment_id' => $existingAppointment->id,
                'data' => $existingAppointment->data?->toDateString(),
                'horario' => $existingAppointment->horario,
                'service_id' => $existingAppointment->service_id,
            ], $request);
            $existingAppointment->services()->sync($serviceIds);
            return new AppointmentResource($existingAppointment->load('service', 'services', 'company', 'feedback', 'loyaltyRedemption.reward'));
        }

        $appointment = Appointment::create($data + [
            'user_id' => $user->id,
            'company_id' => $companyId,
        ]);
        $appointment->services()->sync($serviceIds);

        if ($redemption) {
            $redemption->update([
                'appointment_id' => $appointment->id,
                'status' => 'applied',
            ]);
        }

        $appointment->load('service', 'company', 'company.users');
        $this->notifyCompanyUsers($appointment);
        ActivityLogger::record($user, 'appointment.created', [
            'appointment_id' => $appointment->id,
            'data' => $appointment->data?->toDateString(),
            'horario' => $appointment->horario,
            'service_id' => $appointment->service_id,
        ], $request);

        return new AppointmentResource($appointment->load('service', 'services', 'company', 'feedback', 'loyaltyRedemption.reward'));
    }

    public function update(StoreAppointmentRequest $request, Appointment $appointment, AvailabilityService $availability)
    {
        if ($appointment->company_id !== $request->user('sanctum')?->company_id) {
            abort(403, 'Agendamento não pertence à sua empresa.');
        }

        $data = $request->validated();
        $appointment->loadMissing('loyaltyRedemption');
        $serviceIds = $this->resolveRequestedServiceIds($data);
        $services = Service::where('company_id', $request->user('sanctum')?->company_id)
            ->whereIn('id', $serviceIds)
            ->get();
        if ($services->count() !== count($serviceIds) || $services->contains(fn ($service) => !$service->ativo)) {
            return response()->json(['message' => 'Serviço inativo ou não encontrado.'], 422);
        }

        if (!array_key_exists('preco', $data)) {
            if ($appointment->loyaltyRedemption && $appointment->loyaltyRedemption->status === 'applied') {
                $data['preco'] = 0;
            } elseif ($services->contains(fn ($service) => !$service->ativo)) {
                return response()->json(['message' => 'Serviço inativo ou não encontrado.'], 422);
            } else {
                $data['preco'] = (float) $services->sum('preco');
            }
        }
        $data['service_id'] = $serviceIds[0];
        unset($data['company_slug']);

        $isSameSlot = $appointment->data->toDateString() === $data['data']
            && $appointment->horario === $data['horario']
            && collect($appointment->services()->pluck('services.id')->all())->map(fn ($id) => (int) $id)->values()->all() === $serviceIds;

        if (
            !$isSameSlot &&
            !in_array(
                $data['horario'],
                $availability->horariosDisponiveis(
                    $data['data'],
                    $appointment->company_id,
                    $serviceIds,
                    $appointment->id
                ),
                true
            )
        ) {
            return response()->json(['message' => 'Horário indisponível'], 422);
        }

        $appointment->update($data);
        $appointment->services()->sync($serviceIds);
        ActivityLogger::record($request->user('sanctum'), 'appointment.updated', [
            'appointment_id' => $appointment->id,
            'data' => $data['data'],
            'horario' => $data['horario'],
            'service_id' => $data['service_id'],
        ], $request);

        return new AppointmentResource($appointment->load('service', 'services', 'company', 'feedback', 'loyaltyRedemption.reward'));
    }

    public function status(UpdateAppointmentStatusRequest $request, Appointment $appointment, LoyaltyService $loyalty)
    {
        if ($appointment->company_id !== $request->user('sanctum')?->company_id) {
            abort(403, 'Agendamento não pertence à sua empresa.');
        }

        $previousStatus = $appointment->status;
        $newStatus = $request->validated()['status'];
        $appointment->update(['status' => $newStatus]);
        ActivityLogger::record($request->user('sanctum'), 'appointment.status_updated', [
            'appointment_id' => $appointment->id,
            'status' => $newStatus,
        ], $request);

        if ($previousStatus === 'concluido' && $newStatus !== 'concluido') {
            $loyalty->revokeForAppointment($appointment);
        }

        if ($newStatus === 'cancelado' && $previousStatus !== 'cancelado') {
            $loyalty->restorePendingRedemptionFromAppointment($appointment);
        }

        if ($newStatus === 'concluido' && $previousStatus !== 'concluido') {
            SendFeedbackInvitationJob::dispatch($appointment->id);
            $loyalty->awardForAppointment($appointment);
        }

        return new AppointmentResource($appointment->load('service', 'services', 'company', 'feedback', 'loyaltyRedemption.reward'));
    }

    public function destroy(Request $request, Appointment $appointment)
    {
        if ($appointment->company_id !== $request->user('sanctum')?->company_id) {
            abort(403, 'Agendamento não pertence à sua empresa.');
        }

        app(LoyaltyService::class)->restorePendingRedemptionFromAppointment($appointment);
        $appointment->delete();
        ActivityLogger::record($request->user('sanctum'), 'appointment.deleted', [
            'appointment_id' => $appointment->id,
        ], $request);
        return response()->noContent();
    }

    private function resolveCompanyFromSlug(Request $request): ?int
    {
        if ($slug = $request->input('company_slug') ?: $request->query('company')) {
            $company = Company::where('slug', $slug)->first();
            if (!$company) {
                abort(404, 'Empresa não encontrada.');
            }
            return $company->id;
        }

        return null;
    }

    private function notifyCompanyUsers(Appointment $appointment): void
    {
        $company = $appointment->company;

        if (!$company) {
            return;
        }
        $recipients = $company->users()->where('role', 'provider')->get();

        $appointment->loadMissing('user');
        if ($appointment->user && $appointment->user->role === 'client') {
            $recipients->push($appointment->user);
        }

        $recipients = $recipients->unique('id');

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new NewAppointmentNotification($appointment));
        }

        SendAppointmentAlertJob::dispatch($appointment->id);
    }
}
