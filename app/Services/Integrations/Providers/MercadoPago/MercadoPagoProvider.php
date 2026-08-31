<?php

namespace App\Services\Integrations\Providers\MercadoPago;

use App\Models\Appointment;
use App\Models\Integration;
use App\Models\Payment;
use App\Services\Integrations\Contracts\PaymentProviderInterface;

class MercadoPagoProvider implements PaymentProviderInterface
{
    public function __construct(
        protected MercadoPagoOAuthService $oauth,
        protected MercadoPagoPaymentService $payments,
    ) {
    }

    public function provider(): string
    {
        return 'mercado_pago';
    }

    public function type(): string
    {
        return 'payment';
    }

    public function isConnected(Integration $integration): bool
    {
        return $integration->status === Integration::STATUS_CONNECTED
            && !empty(($integration->credentials ?? [])['access_token']);
    }

    public function createPixPayment(Integration $integration, Appointment $appointment, array $options = []): Payment
    {
        $integration = $this->refreshCredentialsIfNeeded($integration);
        return $this->payments->createPixPayment($integration, $appointment, $options);
    }

    public function createStandalonePixPayment(Integration $integration, int $companyId, array $options = []): Payment
    {
        $integration = $this->refreshCredentialsIfNeeded($integration);
        return $this->payments->createStandalonePixPayment($integration, $companyId, $options);
    }

    public function getPayment(Integration $integration, string $providerPaymentId): array
    {
        $integration = $this->refreshCredentialsIfNeeded($integration);
        return $this->payments->getPayment($integration, $providerPaymentId);
    }

    public function cancelPayment(Integration $integration, Payment $payment): Payment
    {
        $payment->update(['status' => Payment::STATUS_CANCELLED]);
        return $payment;
    }

    public function refundPayment(Integration $integration, Payment $payment): Payment
    {
        $payment->update(['status' => Payment::STATUS_REFUNDED]);
        return $payment;
    }

    public function refreshCredentialsIfNeeded(Integration $integration): Integration
    {
        $expiresAt = ($integration->credentials ?? [])['expires_at'] ?? null;
        if ($expiresAt && now()->addMinutes(5)->greaterThanOrEqualTo($expiresAt)) {
            return $this->oauth->refresh($integration);
        }

        return $integration;
    }

    public function disconnect(Integration $integration): void
    {
        $integration->update([
            'status' => Integration::STATUS_DISCONNECTED,
            'disconnected_at' => now(),
        ]);
    }

    public static function mapPaymentStatus(?string $providerStatus): string
    {
        return match ($providerStatus) {
            'approved', 'authorized' => Payment::STATUS_APPROVED,
            'rejected' => Payment::STATUS_REJECTED,
            'cancelled' => Payment::STATUS_CANCELLED,
            'refunded', 'charged_back' => Payment::STATUS_REFUNDED,
            'expired' => Payment::STATUS_EXPIRED,
            default => Payment::STATUS_PENDING,
        };
    }
}
