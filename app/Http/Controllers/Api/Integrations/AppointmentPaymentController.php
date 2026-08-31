<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Payment;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Http\Request;

class AppointmentPaymentController extends Controller
{
    public function store(Request $request, Appointment $appointment, IntegrationManager $manager)
    {
        $user = $request->user('sanctum');

        if ($user?->role === 'client') {
            if ($appointment->user_id !== $user->id) {
                abort(403, 'Agendamento nao pertence ao cliente autenticado.');
            }
        } elseif ($appointment->company_id !== $user?->company_id) {
            abort(403, 'Agendamento nao pertence a sua empresa.');
        }

        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
            'payer_email' => ['nullable', 'email', 'max:255'],
        ]);

        $amount = (float) ($validated['amount'] ?? $appointment->preco);

        if ($amount <= 0) {
            return response()->json(['message' => 'Agendamento sem valor para pagamento.'], 422);
        }

        if ($appointment->payments()->where('status', Payment::STATUS_APPROVED)->exists()) {
            return response()->json(['message' => 'Este agendamento ja possui pagamento aprovado.'], 422);
        }

        $integration = $manager->connectedPaymentIntegration((int) $appointment->company_id);
        if (!$integration) {
            return response()->json(['message' => 'Este prestador ainda nao disponibilizou pagamento online.'], 422);
        }

        $payment = $manager->paymentProvider($integration->provider)->createPixPayment($integration, $appointment, [
            'amount' => $amount,
            'description' => $validated['description'] ?? null,
            'payer_email' => $validated['payer_email'] ?? $user?->email,
        ]);

        return response()->json([
            'id' => $payment->id,
            'provider' => $payment->provider,
            'status' => $payment->status,
            'amount' => (float) $payment->amount,
            'payment_method' => $payment->payment_method,
            'external_reference' => $payment->external_reference,
            'pix' => [
                'qr_code' => $payment->payment_data['qr_code'] ?? null,
                'qr_code_base64' => $payment->payment_data['qr_code_base64'] ?? null,
                'ticket_url' => $payment->payment_data['ticket_url'] ?? null,
            ],
        ], 201);
    }
}
