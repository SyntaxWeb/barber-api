<?php

namespace App\Services\Integrations\Providers\MercadoPago;

use App\Models\Payment;
use App\Models\StockMovement;
use App\Services\LoyaltyService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MercadoPagoWebhookService
{
    public function handle(Request $request, MercadoPagoProvider $provider): array
    {
        $providerPaymentId = $request->input('data.id') ?? $request->input('id');
        if (!$providerPaymentId) {
            return ['status' => 'ignored'];
        }

        $payment = Payment::query()
            ->with(['integration', 'appointment', 'sale.items.product'])
            ->where('provider', 'mercado_pago')
            ->where('provider_payment_id', (string) $providerPaymentId)
            ->first();

        if (!$payment || !$payment->integration) {
            return ['status' => 'payment_not_found'];
        }

        $payload = $provider->getPayment($payment->integration, (string) $providerPaymentId);
        if (($payload['external_reference'] ?? null) !== $payment->external_reference) {
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
}
