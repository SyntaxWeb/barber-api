<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentFeedbackRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use Illuminate\Http\Request;

class PublicFeedbackController extends Controller
{
    public function show(string $token)
    {
        $appointment = $this->resolveAppointmentByToken($token);

        return new AppointmentResource($appointment->load(['company', 'service', 'feedback']));
    }

    public function submit(StoreAppointmentFeedbackRequest $request, string $token)
    {
        $appointment = $this->resolveAppointmentByToken($token);

        if ($appointment->feedback) {
            return response()->json(['message' => 'Feedback já enviado.'], 422);
        }

        if ($appointment->status !== 'concluido') {
            return response()->json(['message' => 'Feedback disponível somente após a conclusão do atendimento.'], 422);
        }

        $payload = $request->validated() + ['submitted_at' => now()];
        $appointment->feedback()->create($payload);
        $appointment->forceFill([
            'feedback_token' => null,
            'feedback_token_expires_at' => null,
        ])->save();

        return (new AppointmentResource($appointment->load(['company', 'service', 'feedback'])))->response()->setStatusCode(201);
    }

    private function resolveAppointmentByToken(string $token): Appointment
    {
        $appointment = Appointment::where('feedback_token', $token)->with(['company', 'service', 'feedback'])->first();

        if (!$appointment) {
            abort(404, 'Link inválido ou expirado.');
        }

        if ($appointment->feedback_token_expires_at && $appointment->feedback_token_expires_at->isPast()) {
            abort(410, 'Este link expirou.');
        }

        return $appointment;
    }
}
