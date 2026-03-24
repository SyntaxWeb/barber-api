<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentFeedbackRequest;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Jobs\SendAppointmentAlertJob;
use App\Models\Appointment;
use App\Models\Service;
use App\Notifications\AppointmentChangedNotification;
use App\Services\AvailabilityService;
use App\Services\ActivityLogger;
use App\Services\LoyaltyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class ClientAppointmentController extends Controller
{
    private const FEEDBACK_EXPIRATION_DAYS = 30;

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
        $user = $request->user('sanctum');

        if (!$user->company_id) {
            return response()->json(['message' => 'Cliente não vinculado a uma empresa.'], 422);
        }

        $query = Appointment::with(['service', 'services', 'company', 'feedback', 'loyaltyRedemption.reward'])
            ->where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->orderBy('data')
            ->orderBy('horario');

        if ($companySlug = $request->query('company')) {
            $query->whereHas('company', function ($builder) use ($companySlug) {
                $builder->where('slug', $companySlug);
            });
        }

        return AppointmentResource::collection($query->get());
    }

    public function update(StoreAppointmentRequest $request, Appointment $appointment, AvailabilityService $availability)
    {
        $user = $request->user('sanctum');

        if ($appointment->user_id !== $user->id) {
            abort(403, 'Agendamento não pertence ao cliente autenticado.');
        }

        if ($appointment->company_id !== $user->company_id) {
            abort(403, 'Agendamento não pertence à empresa vinculada ao cliente.');
        }

        if ($appointment->status !== 'confirmado') {
            return response()->json(['message' => 'Somente agendamentos confirmados podem ser alterados.'], 422);
        }

        $appointment->loadMissing('company');
        $companySlug = $request->input('company_slug');
        if ($companySlug && optional($appointment->company)->slug !== $companySlug) {
            abort(403, 'Agendamento não pertence a esta empresa.');
        }

        $data = $request->validated();
        unset($data['company_slug']);

        $appointment->loadMissing('loyaltyRedemption');

        $serviceIds = $this->resolveRequestedServiceIds($data);
        $services = Service::where('company_id', $appointment->company_id)
            ->whereIn('id', $serviceIds)
            ->get();
        if ($services->count() !== count($serviceIds) || $services->contains(fn ($service) => !$service->ativo)) {
            return response()->json(['message' => 'Serviço inativo ou não encontrado.'], 422);
        }
        if ($appointment->loyaltyRedemption && $appointment->loyaltyRedemption->status === 'applied') {
            $data['preco'] = 0;
        } else {
            $data['preco'] = (float) $services->sum('preco');
        }
        $data['service_id'] = $serviceIds[0];

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
        ActivityLogger::record($user, 'client.appointment.updated', [
            'appointment_id' => $appointment->id,
            'data' => $data['data'],
            'horario' => $data['horario'],
            'service_id' => $data['service_id'],
        ], $request);
        $this->notifyParticipants($appointment, 'updated');
        SendAppointmentAlertJob::dispatch($appointment->id, 'updated');

        return new AppointmentResource($appointment->fresh(['service', 'services', 'company', 'feedback', 'loyaltyRedemption.reward']));
    }

    public function cancel(Request $request, Appointment $appointment, LoyaltyService $loyalty)
    {
        $user = $request->user('sanctum');

        if ($appointment->user_id !== $user->id) {
            abort(403, 'Agendamento não pertence ao cliente autenticado.');
        }

        if ($appointment->company_id !== $user->company_id) {
            abort(403, 'Agendamento não pertence à empresa vinculada ao cliente.');
        }

        if ($appointment->status !== 'confirmado') {
            return response()->json(['message' => 'Apenas agendamentos confirmados podem ser cancelados.'], 422);
        }

        $start = Carbon::parse(sprintf('%s %s', $appointment->data->toDateString(), $appointment->horario));
        if (now()->diffInMinutes($start, false) < 60) {
            return response()->json(['message' => 'Cancelamento permitido somente até 1 hora antes.'], 422);
        }

        $loyalty->restorePendingRedemptionFromAppointment($appointment);
        $appointment->update(['status' => 'cancelado']);
        ActivityLogger::record($user, 'client.appointment.cancelled', [
            'appointment_id' => $appointment->id,
        ], $request);
        $this->notifyParticipants($appointment, 'cancelled');
        SendAppointmentAlertJob::dispatch($appointment->id, 'cancelled');

        return new AppointmentResource($appointment->fresh(['service', 'services', 'company', 'feedback', 'loyaltyRedemption.reward']));
    }

    public function submitFeedback(
        StoreAppointmentFeedbackRequest $request,
        Appointment $appointment
    ) {
        $user = $request->user('sanctum');

        if ($appointment->user_id !== $user->id) {
            abort(403, 'Agendamento não pertence ao cliente autenticado.');
        }

        if ($appointment->company_id !== $user->company_id) {
            abort(403, 'Agendamento não pertence à empresa vinculada ao cliente.');
        }

        if ($appointment->status !== 'concluido') {
            return response()->json(['message' => 'Feedback disponível somente após a conclusão do atendimento.'], 422);
        }

        $appointment->loadMissing('feedback');
        if ($appointment->feedback) {
            return response()->json(['message' => 'Feedback já enviado para este atendimento.'], 422);
        }

        $appointmentDateTime = Carbon::parse(
            sprintf('%s %s', $appointment->data?->toDateString(), $appointment->horario ?? '00:00')
        );

        if ($appointmentDateTime->lt(now()->subDays(self::FEEDBACK_EXPIRATION_DAYS))) {
            return response()->json([
                'message' => 'Link expirado. Feedback disponível por tempo limitado.',
            ], 422);
        }

        $payload = $request->validated() + ['submitted_at' => now()];

        $appointment->feedback()->create($payload);

        $appointment->forceFill([
            'feedback_token' => null,
            'feedback_token_expires_at' => null,
        ])->save();

        ActivityLogger::record($user, 'client.appointment.feedback_submitted', [
            'appointment_id' => $appointment->id,
            'service_rating' => $payload['service_rating'],
            'professional_rating' => $payload['professional_rating'],
            'scheduling_rating' => $payload['scheduling_rating'],
        ], $request);

        return new AppointmentResource($appointment->fresh(['service', 'services', 'company', 'feedback']));
    }

    private function notifyParticipants(Appointment $appointment, string $action): void
    {
        $appointment->loadMissing('service', 'company.users', 'user');

        $recipients = collect();

        if ($appointment->company && $appointment->company->relationLoaded('users')) {
            $recipients = $recipients->merge($appointment->company->users->where('role', 'provider'));
        } elseif ($appointment->company) {
            $recipients = $recipients->merge($appointment->company->users()->where('role', 'provider')->get());
        }

        if ($appointment->user) {
            $recipients->push($appointment->user);
        }

        $recipients = $recipients->filter()->unique('id');

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new AppointmentChangedNotification($appointment, $action));
    }
}
