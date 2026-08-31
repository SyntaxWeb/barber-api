<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\Providers\MercadoPago\MercadoPagoOAuthService;
use Illuminate\Http\Request;

class IntegrationSettingsController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->user('sanctum')?->company_id;
        $integrations = $companyId
            ? Integration::where('company_id', $companyId)->get()->keyBy('provider')
            : collect();

        return response()->json([
            'data' => collect(config('integrations.providers', []))->map(function (array $definition, string $provider) use ($integrations) {
                return $this->serialize($definition, $integrations->get($provider));
            })->values(),
        ]);
    }

    public function show(Request $request, string $provider)
    {
        $definition = $this->definition($provider);
        $integration = Integration::where('company_id', $request->user('sanctum')?->company_id)
            ->where('provider', $definition['provider'])
            ->first();

        return response()->json($this->serialize($definition, $integration));
    }

    public function connect(Request $request, string $provider, MercadoPagoOAuthService $mercadoPagoOAuth)
    {
        $definition = $this->definition($provider);
        $companyId = $request->user('sanctum')?->company_id;

        if (!$companyId) {
            return response()->json(['message' => 'Empresa nao encontrada.'], 404);
        }

        if ($definition['provider'] !== 'mercado_pago') {
            return response()->json(['message' => 'Provider ainda nao possui conexao automatica.'], 422);
        }

        $oauth = $definition['oauth'] ?? [];
        if (blank($oauth['client_id'] ?? null) || blank($oauth['client_secret'] ?? null)) {
            return response()->json([
                'message' => 'Configure as credenciais OAuth do Mercado Pago antes de conectar a integracao.',
            ], 422);
        }

        return response()->json($mercadoPagoOAuth->authorizationUrl((int) $companyId));
    }

    public function destroy(Request $request, string $provider, IntegrationManager $manager)
    {
        $definition = $this->definition($provider);
        $integration = Integration::where('company_id', $request->user('sanctum')?->company_id)
            ->where('provider', $definition['provider'])
            ->firstOrFail();

        $manager->provider($definition['provider'])->disconnect($integration);

        return response()->noContent();
    }

    protected function definition(string $provider): array
    {
        $normalized = str_replace('-', '_', $provider);
        $definition = config("integrations.providers.{$normalized}");

        if (!$definition || !($definition['enabled'] ?? false)) {
            abort(404, 'Integracao nao encontrada.');
        }

        return $definition;
    }

    protected function serialize(array $definition, ?Integration $integration): array
    {
        return [
            'provider' => $definition['provider'],
            'slug' => $definition['slug'] ?? str_replace('_', '-', $definition['provider']),
            'name' => $definition['name'],
            'type' => $definition['type'],
            'enabled' => (bool) ($definition['enabled'] ?? false),
            'description' => $definition['description'] ?? null,
            'status' => $integration?->status ?? Integration::STATUS_DISCONNECTED,
            'settings' => array_merge($definition['settings'] ?? [], $integration?->settings ?? []),
            'metadata' => $integration?->metadata ?? [],
            'configuration_status' => $this->configurationStatus($definition),
            'connected_at' => $integration?->connected_at?->toIso8601String(),
            'disconnected_at' => $integration?->disconnected_at?->toIso8601String(),
        ];
    }

    protected function configurationStatus(array $definition): string
    {
        if (($definition['provider'] ?? null) !== 'mercado_pago') {
            return 'ready';
        }

        $oauth = $definition['oauth'] ?? [];

        return blank($oauth['client_id'] ?? null) || blank($oauth['client_secret'] ?? null)
            ? 'missing_credentials'
            : 'ready';
    }
}
