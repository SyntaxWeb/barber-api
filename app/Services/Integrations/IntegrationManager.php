<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Services\Integrations\Contracts\IntegrationProviderInterface;
use App\Services\Integrations\Contracts\PaymentProviderInterface;
use App\Services\Integrations\Providers\MercadoPago\MercadoPagoProvider;
use InvalidArgumentException;

class IntegrationManager
{
    /** @var array<string, class-string<IntegrationProviderInterface>> */
    protected array $providers = [
        'mercado_pago' => MercadoPagoProvider::class,
    ];

    public function provider(string $provider): IntegrationProviderInterface
    {
        $normalized = str_replace('-', '_', $provider);
        $class = $this->providers[$normalized] ?? null;

        if (!$class) {
            throw new InvalidArgumentException("Provider {$provider} nao suportado.");
        }

        return app($class);
    }

    public function paymentProvider(string $provider): PaymentProviderInterface
    {
        $resolved = $this->provider($provider);

        if (!$resolved instanceof PaymentProviderInterface) {
            throw new InvalidArgumentException("Provider {$provider} nao suporta pagamentos.");
        }

        return $resolved;
    }

    public function connectedPaymentIntegration(int $companyId, ?string $provider = null): ?Integration
    {
        $query = Integration::query()
            ->where('company_id', $companyId)
            ->where('type', 'payment')
            ->where('status', Integration::STATUS_CONNECTED);

        if ($provider) {
            $query->where('provider', str_replace('-', '_', $provider));
        }

        return $query->oldest('id')->first();
    }
}
