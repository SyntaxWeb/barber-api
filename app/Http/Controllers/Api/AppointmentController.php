<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentStatusRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Appointment::with('service');

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

        if (!in_array($data['horario'], $availability->horariosDisponiveis($data['data']), true)) {
            return response()->json(['message' => 'Horário indisponível'], 422);
        }

        $appointment = Appointment::create($data + ['user_id' => $request->user()->id]);

        return new AppointmentResource($appointment->load('service'));
    }

    public function update(StoreAppointmentRequest $request, Appointment $appointment, AvailabilityService $availability)
    {
        $data = $request->validated();
        $isSameSlot = $appointment->data->toDateString() === $data['data'] && $appointment->horario === $data['horario'];

        if (!$isSameSlot && !in_array($data['horario'], $availability->horariosDisponiveis($data['data']), true)) {
            return response()->json(['message' => 'Horário indisponível'], 422);
        }

        $appointment->update($data);

        return new AppointmentResource($appointment->load('service'));
    }

    public function status(UpdateAppointmentStatusRequest $request, Appointment $appointment)
    {
        $appointment->update(['status' => $request->validated()['status']]);
        return new AppointmentResource($appointment->load('service'));
    }

    public function destroy(Appointment $appointment)
    {
        $appointment->delete();
        return response()->noContent();
    }
}
