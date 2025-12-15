<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Service;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ClientAppointmentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('sanctum');

        $query = Appointment::with('service')
            ->where('user_id', $user->id)
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

        $service = Service::where('company_id', $appointment->company_id)->findOrFail($data['service_id']);
        $data['preco'] = $service->preco;

        $isSameSlot = $appointment->data->toDateString() === $data['data'] && $appointment->horario === $data['horario'];

        if (
            !$isSameSlot &&
            !in_array($data['horario'], $availability->horariosDisponiveis($data['data'], $appointment->company_id), true)
        ) {
            return response()->json(['message' => 'Horário indisponível'], 422);
        }

        $appointment->update($data);

        return new AppointmentResource($appointment->fresh(['service']));
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        $user = $request->user('sanctum');

        if ($appointment->user_id !== $user->id) {
            abort(403, 'Agendamento não pertence ao cliente autenticado.');
        }

        if ($appointment->status !== 'confirmado') {
            return response()->json(['message' => 'Apenas agendamentos confirmados podem ser cancelados.'], 422);
        }

        $start = Carbon::parse(sprintf('%s %s', $appointment->data->toDateString(), $appointment->horario));
        if (now()->diffInMinutes($start, false) < 60) {
            return response()->json(['message' => 'Cancelamento permitido somente até 1 hora antes.'], 422);
        }

        $appointment->update(['status' => 'cancelado']);

        return new AppointmentResource($appointment->fresh(['service']));
    }
}
