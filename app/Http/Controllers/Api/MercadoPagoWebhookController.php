<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionOrder;
use App\Services\MercadoPagoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoPagoWebhookController extends Controller
{
    public function __invoke(Request $request, MercadoPagoService $mercadoPago)
    {
        $topic = $request->input('type') ?? $request->input('topic');
        $paymentId = $request->input('data.id') ?? $request->input('id');

        if ($topic !== 'payment' || !$paymentId) {
            return response()->json(['status' => 'ignored']);
        }

        try {
            $payment = $mercadoPago->findPayment($paymentId);
        } catch (\Throwable $exception) {
            Log::error('Falha ao consultar pagamento no Mercado Pago', [
                'payment_id' => $paymentId,
                'error' => $exception->getMessage(),
            ]);
            return response()->json(['status' => 'error'], 502);
        }

        $externalReference = $payment['external_reference'] ?? null;
        if (!$externalReference) {
            return response()->json(['status' => 'missing_reference'], 422);
        }

        $order = SubscriptionOrder::where('external_reference', $externalReference)->first();
        if (!$order) {
            return response()->json(['status' => 'order_not_found'], 404);
        }

        $mpStatus = $payment['status'] ?? null;
        $orderStatus = match ($mpStatus) {
            'approved' => 'pago',
            'rejected', 'cancelled' => 'falhou',
            default => 'pendente',
        };

        $order->update([
            'status' => $orderStatus,
            'mp_payment_id' => (string) $paymentId,
            'mp_status' => $mpStatus,
            'mp_payload' => $payment,
            'paid_at' => $mpStatus === 'approved' ? Carbon::parse($payment['date_approved'] ?? now()) : $order->paid_at,
        ]);

        if ($mpStatus === 'approved') {
            $this->activateSubscription($order);
        }

        return response()->json(['status' => 'processed']);
    }

    protected function activateSubscription(SubscriptionOrder $order): void
    {
        $company = $order->company;
        if (!$company) {
            return;
        }

        $plans = config('subscriptions.plans', []);
        $plan = $plans[$order->plan_key] ?? null;
        $months = $plan['months'] ?? 1;
        $renewsAt = Carbon::now()->addMonths($months);

        $company->forceFill([
            'subscription_plan' => $order->plan_key,
            'subscription_status' => 'ativo',
            'subscription_price' => $order->price,
            'subscription_renews_at' => $renewsAt,
        ])->save();
    }
}
