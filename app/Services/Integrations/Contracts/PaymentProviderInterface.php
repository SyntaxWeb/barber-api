<?php

namespace App\Services\Integrations\Contracts;

use App\Models\Appointment;
use App\Models\Integration;
use App\Models\Payment;

interface PaymentProviderInterface extends IntegrationProviderInterface
{
    public function createPixPayment(Integration $integration, Appointment $appointment, array $options = []): Payment;

    public function getPayment(Integration $integration, string $providerPaymentId): array;

    public function cancelPayment(Integration $integration, Payment $payment): Payment;

    public function refundPayment(Integration $integration, Payment $payment): Payment;

    public function refreshCredentialsIfNeeded(Integration $integration): Integration;
}
