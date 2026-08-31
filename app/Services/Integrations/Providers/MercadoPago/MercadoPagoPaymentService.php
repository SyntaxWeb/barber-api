<?php

namespace App\Services\Integrations\Providers\MercadoPago;

use App\Models\Appointment;
use App\Models\Integration;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MercadoPagoPaymentService
{
    protected string $baseUri;

    public function __construct()
    {
        $this->baseUri = rtrim(config('services.mercadopago.base_uri', 'https://api.mercadopago.com'), '/');
    }

    public function createPixPayment(Integration $integration, Appointment $appointment, array $options = []): Payment
    {
        $externalReference = 'appointment:' . $appointment->id . ':' . Str::uuid();
        $amount = (float) ($options['amount'] ?? $appointment->preco);

        $response = $this->http($integration)->post("{$this->baseUri}/v1/payments", [
            'transaction_amount' => $amount,
            'description' => $options['description'] ?? "Agendamento #{$appointment->id}",
            'payment_method_id' => 'pix',
            'external_reference' => $externalReference,
            'notification_url' => rtrim(config('app.url'), '/') . '/api/webhooks/integrations/mercado-pago',
            'payer' => [
                'email' => $options['payer_email'] ?? 'cliente+' . $appointment->id . '@example.com',
                'first_name' => $appointment->cliente,
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Falha ao criar pagamento Pix.');
        }

        $payload = $response->json();
        $status = MercadoPagoProvider::mapPaymentStatus($payload['status'] ?? null);
        $point = $payload['point_of_interaction']['transaction_data'] ?? [];

        $payment = Payment::create([
            'company_id' => $appointment->company_id,
            'appointment_id' => $appointment->id,
            'integration_id' => $integration->id,
            'provider' => 'mercado_pago',
            'provider_payment_id' => isset($payload['id']) ? (string) $payload['id'] : null,
            'external_reference' => $externalReference,
            'amount' => $amount,
            'payment_method' => 'pix',
            'status' => $status,
            'provider_status' => $payload['status'] ?? null,
            'payment_data' => [
                'qr_code' => $point['qr_code'] ?? null,
                'qr_code_base64' => $point['qr_code_base64'] ?? null,
                'ticket_url' => $point['ticket_url'] ?? null,
                'raw' => $payload,
            ],
            'expired_at' => isset($payload['date_of_expiration']) ? $payload['date_of_expiration'] : null,
        ]);

        $appointment->update(['payment_status' => $status]);

        return $payment;
    }

    public function getPayment(Integration $integration, string $providerPaymentId): array
    {
        $response = $this->http($integration)->get("{$this->baseUri}/v1/payments/{$providerPaymentId}");

        if ($response->failed()) {
            throw new \RuntimeException('Falha ao consultar pagamento Pix.');
        }

        return $response->json();
    }

    protected function http(Integration $integration)
    {
        $token = ($integration->credentials ?? [])['access_token'] ?? null;
        if (!$token) {
            throw new \RuntimeException('Integracao Mercado Pago sem credenciais validas.');
        }

        return Http::withToken($token)->acceptJson()->asJson();
    }
}
