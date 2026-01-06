<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;

class MercadoPagoController extends Controller
{
    public function index(Request $request, MercadoPagoService $mercadoPago)
    {
        try {
            $filters = array_filter([
                'status' => $request->query('status'),
                'payer_email' => $request->query('email'),
                'external_reference' => $request->query('reference'),
            ], fn ($value) => $value !== null && $value !== '');

            $subscriptions = $mercadoPago->listSubscriptions($filters);

            return response()->json([
                'data' => $subscriptions,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json([
                'message' => 'Nao foi possivel consultar as assinaturas no Mercado Pago.',
            ], 500);
        }
    }

    public function syncPlans(MercadoPagoService $mercadoPago)
    {
        try {
            $plans = config('subscriptions.plans', []);
            $synced = $mercadoPago->syncConfiguredPlans($plans);

            return response()->json([
                'data' => $synced,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Nao foi possivel sincronizar os planos no Mercado Pago.',
            ], 500);
        }
    }
}
