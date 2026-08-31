<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\Providers\MercadoPago\MercadoPagoProvider;
use App\Services\Integrations\Providers\MercadoPago\MercadoPagoWebhookService;
use Illuminate\Http\Request;

class IntegrationWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, IntegrationManager $manager, MercadoPagoWebhookService $mercadoPagoWebhook)
    {
        $resolved = $manager->provider($provider);

        if ($resolved instanceof MercadoPagoProvider) {
            return response()->json($mercadoPagoWebhook->handle($request, $resolved));
        }

        return response()->json(['status' => 'ignored']);
    }
}
