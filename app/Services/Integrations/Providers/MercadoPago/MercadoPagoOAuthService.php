<?php

namespace App\Services\Integrations\Providers\MercadoPago;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MercadoPagoOAuthService
{
    protected string $baseUri;

    public function __construct()
    {
        $this->baseUri = rtrim(config('services.mercadopago.base_uri', 'https://api.mercadopago.com'), '/');
    }

    public function authorizationUrl(int $companyId): array
    {
        $state = Str::random(48);
        cache()->put($this->stateCacheKey($state), $companyId, now()->addMinutes(15));

        $query = http_build_query([
            'client_id' => config('integrations.providers.mercado_pago.oauth.client_id'),
            'response_type' => 'code',
            'platform_id' => 'mp',
            'redirect_uri' => config('integrations.providers.mercado_pago.oauth.redirect_uri'),
            'state' => $state,
        ]);

        return [
            'authorization_url' => config('integrations.providers.mercado_pago.oauth.authorize_url') . '?' . $query,
            'state' => $state,
        ];
    }

    public function connectFromCallback(string $code, string $state): Integration
    {
        $companyId = cache()->pull($this->stateCacheKey($state));
        if (!$companyId) {
            abort(422, 'State OAuth invalido ou expirado.');
        }

        $payload = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => config('integrations.providers.mercado_pago.oauth.client_id'),
            'client_secret' => config('integrations.providers.mercado_pago.oauth.client_secret'),
            'code' => $code,
            'redirect_uri' => config('integrations.providers.mercado_pago.oauth.redirect_uri'),
        ]);

        return $this->persistIntegration($companyId, $payload);
    }

    public function refresh(Integration $integration): Integration
    {
        $credentials = $integration->credentials ?? [];
        $refreshToken = $credentials['refresh_token'] ?? null;
        if (!$refreshToken) {
            return $integration;
        }

        $payload = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'client_id' => config('integrations.providers.mercado_pago.oauth.client_id'),
            'client_secret' => config('integrations.providers.mercado_pago.oauth.client_secret'),
            'refresh_token' => $refreshToken,
        ]);

        return $this->persistIntegration((int) $integration->company_id, $payload, $integration);
    }

    protected function tokenRequest(array $payload): array
    {
        $response = Http::asForm()->acceptJson()->post("{$this->baseUri}/oauth/token", $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Falha ao autorizar Mercado Pago.');
        }

        return $response->json();
    }

    protected function persistIntegration(int $companyId, array $payload, ?Integration $integration = null): Integration
    {
        $expiresIn = (int) ($payload['expires_in'] ?? 0);
        $credentials = [
            'access_token' => $payload['access_token'] ?? null,
            'refresh_token' => $payload['refresh_token'] ?? null,
            'expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn)->toIso8601String() : null,
            'provider_user_id' => (string) ($payload['user_id'] ?? ''),
        ];

        $metadata = array_filter([
            'provider_user_id' => (string) ($payload['user_id'] ?? ''),
            'account_email' => $payload['public_key'] ?? null,
        ]);

        $integration ??= Integration::firstOrNew([
            'company_id' => $companyId,
            'provider' => 'mercado_pago',
            'type' => 'payment',
        ]);

        $integration->fill([
            'status' => Integration::STATUS_CONNECTED,
            'credentials' => $credentials,
            'settings' => array_merge(
                config('integrations.providers.mercado_pago.settings', []),
                $integration->settings ?? []
            ),
            'metadata' => array_merge($integration->metadata ?? [], $metadata),
            'connected_at' => $integration->connected_at ?? now(),
            'disconnected_at' => null,
        ])->save();

        return $integration->refresh();
    }

    protected function stateCacheKey(string $state): string
    {
        return "integrations:mercado_pago:oauth_state:{$state}";
    }
}
