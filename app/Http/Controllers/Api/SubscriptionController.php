<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionOrder;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        $latestOrder = $company->subscriptionOrders()->latest()->first();

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
            'latest_order' => $latestOrder ? [
                'status' => $latestOrder->status,
                'checkout_url' => $latestOrder->checkout_url,
                'created_at' => $latestOrder->created_at,
            ] : null,
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

        $order = SubscriptionOrder::create([
            'company_id' => $company->id,
            'external_reference' => (string) Str::uuid(),
            'plan_key' => $planKey,
            'plan_name' => $planData['name'] ?? ucfirst($planKey),
            'price' => $planData['price'],
            'status' => 'pendente',
        ]);

        $preference = $mercadoPago->createPaymentLink([
            'title' => $planData['name'] ?? 'Assinatura',
            'unit_price' => $planData['price'],
            'external_reference' => $order->external_reference,
            'payer' => [
                'email' => $user->email,
                'name' => $user->name,
            ],
        ]);

        $checkoutUrl = $preference['init_point'] ?? $preference['sandbox_init_point'] ?? null;

        $order->update([
            'mp_preference_id' => $preference['id'] ?? null,
            'checkout_url' => $checkoutUrl,
        ]);

        return response()->json([
            'checkout_url' => $checkoutUrl,
            'order_id' => $order->id,
        ]);
    }
}
