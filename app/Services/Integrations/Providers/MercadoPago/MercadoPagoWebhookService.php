<?php

namespace App\Services\Integrations\Providers\MercadoPago;

use App\Models\Payment;
use App\Models\StockMovement;
use App\Services\LoyaltyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MercadoPagoWebhookService
{
    public function handle(Request $request, MercadoPagoProvider $provider): array
    {
        $providerPaymentId = $request->input('data.id') ?? $request->input('id');
        if (!$this->signatureIsValid($request, $providerPaymentId)) {
            Log::warning('Mercado Pago integration webhook rejected by signature validation.', [
                'provider_payment_id' => $providerPaymentId,
                'has_signature' => $request->headers->has('x-signature'),
                'has_request_id' => $request->headers->has('x-request-id'),
            ]);

            abort(401, 'Webhook signature invalid.');
        }

        Log::info('Mercado Pago integration webhook received.', [
            'provider_payment_id' => $providerPaymentId,
            'type' => $request->input('type'),
            'action' => $request->input('action'),
            'topic' => $request->input('topic'),
        ]);

        if (!$providerPaymentId) {
            return ['status' => 'ignored'];
        }

        $payment = Payment::query()
            ->with(['integration', 'appointment', 'sale.items.product'])
            ->where('provider', 'mercado_pago')
            ->where('provider_payment_id', (string) $providerPaymentId)
            ->first();

        if (!$payment || !$payment->integration) {
            Log::warning('Mercado Pago integration webhook payment not found.', [
                'provider_payment_id' => $providerPaymentId,
            ]);

            return ['status' => 'payment_not_found'];
        }

        $payload = $provider->getPayment($payment->integration, (string) $providerPaymentId);
        if (($payload['external_reference'] ?? null) !== $payment->external_reference) {
            Log::warning('Mercado Pago integration webhook reference mismatch.', [
                'payment_id' => $payment->id,
                'provider_payment_id' => $providerPaymentId,
                'expected_reference' => $payment->external_reference,
                'received_reference' => $payload['external_reference'] ?? null,
            ]);

            return ['status' => 'reference_mismatch'];
        }

        $status = MercadoPagoProvider::mapPaymentStatus($payload['status'] ?? null);
        $payment->update([
            'status' => $status,
            'provider_status' => $payload['status'] ?? null,
            'payment_data' => array_merge($payment->payment_data ?? [], ['raw' => $payload]),
            'paid_at' => $status === Payment::STATUS_APPROVED
                ? Carbon::parse($payload['date_approved'] ?? now())
                : $payment->paid_at,
        ]);

        if ($payment->appointment) {
            $payment->appointment->update(['payment_status' => $status]);
        }

        Log::info('Mercado Pago integration webhook payment synchronized.', [
            'payment_id' => $payment->id,
            'sale_id' => $payment->sale_id,
            'appointment_id' => $payment->appointment_id,
            'status' => $status,
            'provider_status' => $payload['status'] ?? null,
        ]);

        if ($status === Payment::STATUS_APPROVED && $payment->sale && $payment->sale->status !== 'closed') {
            DB::transaction(function () use ($payment) {
                $sale = $payment->sale()->with('items.product', 'appointment')->lockForUpdate()->first();
                if (!$sale || $sale->status === 'closed') {
                    return;
                }

                foreach ($sale->items->where('type', 'product') as $item) {
                    $product = $item->product;
                    if (!$product) {
                        continue;
                    }
                    $product->decrement('stock_quantity', (int) $item->quantity);
                    $product->refresh();
                    StockMovement::create([
                        'company_id' => $sale->company_id,
                        'product_id' => $product->id,
                        'user_id' => $sale->user_id,
                        'type' => 'sale',
                        'quantity' => -((int) $item->quantity),
                        'balance_after' => (int) $product->stock_quantity,
                        'notes' => "Venda Pix #{$sale->id}",
                    ]);
                }

                $sale->update([
                    'status' => 'closed',
                    'payment_method' => 'pix',
                    'closed_at' => now(),
                ]);

                Log::info('Mercado Pago integration webhook sale closed.', [
                    'payment_id' => $payment->id,
                    'sale_id' => $sale->id,
                ]);

                if ($sale->appointment) {
                    $previousStatus = $sale->appointment->status;
                    $sale->appointment->update(['status' => 'concluido']);
                    if ($previousStatus !== 'concluido') {
                        app(LoyaltyService::class)->awardForAppointment($sale->appointment);
                    }
                }
            });
        }

        return ['status' => 'processed'];
    }

    private function signatureIsValid(Request $request, ?string $providerPaymentId): bool
    {
        $secret = config('integrations.providers.mercado_pago.webhook_secret');
        if (!$secret) {
            return true;
        }

        $signature = (string) $request->header('x-signature', '');
        $requestId = (string) $request->header('x-request-id', '');
        $dataId = (string) ($request->query('data.id') ?? $request->query('data_id') ?? $providerPaymentId ?? '');

        if (!$signature || !$requestId || !$dataId) {
            return false;
        }

        $parts = collect(explode(',', $signature))
            ->mapWithKeys(function (string $part) {
                [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
                return $key && $value ? [trim($key) => trim($value)] : [];
            });

        $ts = $parts->get('ts');
        $v1 = $parts->get('v1');
        if (!$ts || !$v1) {
            return false;
        }

        if (ctype_alnum($dataId)) {
            $dataId = strtolower($dataId);
        }

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $v1);
    }
}
