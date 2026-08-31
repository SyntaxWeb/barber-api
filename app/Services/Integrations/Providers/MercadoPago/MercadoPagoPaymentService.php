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
        $payment = $this->createPixPaymentRecord($integration, (int) $appointment->company_id, [
            ...$options,
            'appointment_id' => $appointment->id,
            'amount' => (float) ($options['amount'] ?? $appointment->preco),
            'description' => $options['description'] ?? "Agendamento #{$appointment->id}",
            'payer_email' => $options['payer_email'] ?? 'cliente+' . $appointment->id . '@example.com',
            'payer_name' => $appointment->cliente,
            'reference_prefix' => 'appointment:' . $appointment->id,
        ]);

        $appointment->update(['payment_status' => $payment->status]);

        return $payment;
    }

    public function createStandalonePixPayment(Integration $integration, int $companyId, array $options = []): Payment
    {
        return $this->createPixPaymentRecord($integration, $companyId, [
            ...$options,
            'reference_prefix' => $options['reference_prefix'] ?? 'cash-register',
        ]);
    }

    protected function createPixPaymentRecord(Integration $integration, int $companyId, array $options): Payment
    {
        $amount = (float) ($options['amount'] ?? 0);
        $externalReference = ($options['reference_prefix'] ?? 'payment') . ':' . Str::uuid();

        $response = $this->http($integration)->post("{$this->baseUri}/v1/payments", [
            'transaction_amount' => $amount,
            'description' => $options['description'] ?? 'Pagamento Pix',
            'payment_method_id' => 'pix',
            'external_reference' => $externalReference,
            'notification_url' => rtrim(config('app.url'), '/') . '/api/webhooks/integrations/mercado-pago',
            'payer' => [
                'email' => $options['payer_email'] ?? 'cliente-' . Str::uuid() . '@syntaxatendimento.com.br',
                'first_name' => $options['payer_name'] ?? 'Cliente',
            ],
        ]);

        if ($response->failed()) {
            $message = $response->json('message')
                ?? $response->json('error')
                ?? $response->body()
                ?: 'Falha ao criar pagamento Pix.';

            throw new \RuntimeException('Mercado Pago: ' . $message);
        }

        $payload = $response->json();
        $status = MercadoPagoProvider::mapPaymentStatus($payload['status'] ?? null);
        $point = $payload['point_of_interaction']['transaction_data'] ?? [];

        return Payment::create([
            'company_id' => $companyId,
            'appointment_id' => $options['appointment_id'] ?? null,
            'sale_id' => $options['sale_id'] ?? null,
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
