<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\Providers\MercadoPago\MercadoPagoOAuthService;
use Illuminate\Http\Request;

class MercadoPagoOAuthCallbackController extends Controller
{
    public function __invoke(Request $request, MercadoPagoOAuthService $oauth)
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $oauth->connectFromCallback($validated['code'], $validated['state']);

        $front = rtrim(config('app.frontend_url', config('app.url')), '/');
        return redirect()->away("{$front}/configuracoes?tab=integracoes&connected=mercado_pago");
    }
}
