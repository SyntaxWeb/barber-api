<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function show(Request $request)
    {
        $company = $request->user('sanctum')?->company;
        if (!$company) {
            return response()->json(['message' => 'Empresa nao encontrada.'], 404);
        }

        $plans = config('subscriptions.plans', []);
        $planKey = $company->subscription_plan;
        $plan = $plans[$planKey] ?? null;

        return response()->json([
            'company' => $company->only([
                'id',
                'nome',
                'subscription_plan',
                'subscription_status',
                'subscription_price',
                'subscription_renews_at',
            ]),
            'plan' => $plan ? array_merge($plan, ['key' => $planKey]) : null,
            'available_plans' => collect($plans)
                ->map(fn ($data, $key) => array_merge($data, ['key' => $key]))
                ->values(),
        ]);
    }

    public function checkout(Request $request, MercadoPagoService $mercadoPago)
    {
        $user = $request->user('sanctum');
        $company = $user?->company;

        if (!$company) {
            return response()->json(['message' => 'Empresa nao encontrada.'], 404);
        }

        $validated = $request->validate([
            'plan' => ['required', 'string'],
        ]);

        $plans = config('subscriptions.plans', []);
        $planKey = $validated['plan'];

        if (!isset($plans[$planKey])) {
            return response()->json(['message' => 'Plano invalido.'], 422);
        }

        $planData = $plans[$planKey];
        $checkout = $mercadoPago->createSubscriptionCheckout($planKey, $planData, [
            'company_id' => $company->id,
            'payer_email' => $user->email,
            'payer_name' => $user->name,
        ]);

        return response()->json([
            'checkout_url' => $checkout['init_point'] ?? $checkout['sandbox_init_point'] ?? null,
            'subscription' => $checkout,
        ]);
    }
}
