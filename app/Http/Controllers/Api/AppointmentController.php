<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentStatusRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\Service;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->user()->company_id;
        if (!$companyId) {
            abort(403, 'Usuário não associado a uma empresa.');
        }
        $query = Appointment::with('service')->where('company_id', $companyId);

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

    public function store(StoreAppointmentRequest $request, AvailabilityService $availability)
    {
        $data = $request->validated();
        $user = $request->user();

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

        if (!in_array($data['horario'], $availability->horariosDisponiveis($data['data'], $companyId), true)) {
            return response()->json(['message' => 'Horário indisponível'], 422);
        }

        $service = Service::where('company_id', $companyId)->findOrFail($data['service_id']);
        $data['preco'] = $service->preco;

        unset($data['company_slug']);

        $appointment = Appointment::create($data + [
            'user_id' => $user->id,
            'company_id' => $companyId,
        ]);

        return new AppointmentResource($appointment->load('service'));
    }

    public function update(StoreAppointmentRequest $request, Appointment $appointment, AvailabilityService $availability)
    {
        if ($appointment->company_id !== $request->user()->company_id) {
            abort(403, 'Agendamento não pertence à sua empresa.');
        }

        $data = $request->validated();
        if (!array_key_exists('preco', $data)) {
            $service = Service::where('company_id', $request->user()->company_id)->find($data['service_id']);
            if ($service) {
                $data['preco'] = $service->preco;
            }
        }
        unset($data['company_slug']);

        $isSameSlot = $appointment->data->toDateString() === $data['data'] && $appointment->horario === $data['horario'];

        if (!$isSameSlot && !in_array($data['horario'], $availability->horariosDisponiveis($data['data'], $appointment->company_id), true)) {
            return response()->json(['message' => 'Horário indisponível'], 422);
        }

        $appointment->update($data);

        return new AppointmentResource($appointment->load('service'));
    }

    public function status(UpdateAppointmentStatusRequest $request, Appointment $appointment)
    {
        if ($appointment->company_id !== $request->user()->company_id) {
            abort(403, 'Agendamento não pertence à sua empresa.');
        }

        $appointment->update(['status' => $request->validated()['status']]);
        return new AppointmentResource($appointment->load('service'));
    }

    public function destroy(Request $request, Appointment $appointment)
    {
        if ($appointment->company_id !== $request->user()->company_id) {
            abort(403, 'Agendamento não pertence à sua empresa.');
        }

        $appointment->delete();
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

        return Company::first()?->id;
    }
}
