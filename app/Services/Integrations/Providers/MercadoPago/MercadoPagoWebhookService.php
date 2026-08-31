<?php

namespace App\Services\Integrations\Providers\MercadoPago;

use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MercadoPagoWebhookService
{
    public function handle(Request $request, MercadoPagoProvider $provider): array
    {
        $providerPaymentId = $request->input('data.id') ?? $request->input('id');
        if (!$providerPaymentId) {
            return ['status' => 'ignored'];
        }

        $payment = Payment::query()
            ->with(['integration', 'appointment'])
            ->where('provider', 'mercado_pago')
            ->where('provider_payment_id', (string) $providerPaymentId)
            ->first();

        if (!$payment || !$payment->integration || !$payment->appointment) {
            return ['status' => 'payment_not_found'];
        }

        $payload = $provider->getPayment($payment->integration, (string) $providerPaymentId);
        if (($payload['external_reference'] ?? null) !== $payment->external_reference) {
            return ['status' => 'reference_mismatch'];
        }

        $status = MercadoPagoProvider::mapPaymentStatus($payload['status'] ?? null);
        $payment->update([
            'status' => $status,
            'provider_status' => $payload['status'] ?? null,
            'payment_data' => array_merge($payment->payment_data ?? [], ['raw' => $payload]),
            'paid_at' => $status === Payment::STATUS_APPROVED
                ? Carbon::parse($payload['date_approved'] ?? now())
                : $payment->paid_at,
        ]);

        $payment->appointment->update(['payment_status' => $status]);

        return ['status' => 'processed'];
    }
}
