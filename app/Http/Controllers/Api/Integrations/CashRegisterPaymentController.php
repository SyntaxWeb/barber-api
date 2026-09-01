<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashRegisterPaymentController extends Controller
{
    public function show(Request $request, Payment $payment)
    {
        $companyId = $request->user('sanctum')?->company_id;

        if (!$companyId || (int) $payment->company_id !== (int) $companyId) {
            abort(403, 'Pagamento nao pertence a sua empresa.');
        }

        return response()->json($this->serializePayment($payment));
    }

    public function store(Request $request, IntegrationManager $manager)
    {
        $user = $request->user('sanctum');
        $companyId = $user?->company_id;

        if (!$companyId) {
            return response()->json(['message' => 'Empresa nao encontrada.'], 404);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:255'],
            'payer_email' => ['nullable', 'email', 'max:255'],
            'payer_name' => ['nullable', 'string', 'max:120'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'services' => ['array'],
            'services.*.service_id' => ['required', 'integer', 'exists:services,id'],
            'services.*.quantity' => ['nullable', 'integer', 'min:1'],
            'products' => ['array'],
            'products.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'addition' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (empty($validated['services'] ?? []) && empty($validated['products'] ?? [])) {
            return response()->json(['message' => 'Adicione ao menos um serviço ou produto.'], 422);
        }

        $integration = $manager->connectedPaymentIntegration((int) $companyId);
        if (!$integration) {
            return response()->json(['message' => 'Este prestador ainda nao disponibilizou pagamento online.'], 422);
        }

        $sale = DB::transaction(function () use ($request, $companyId, $validated) {
            $sale = Sale::create([
                'company_id' => $companyId,
                'user_id' => $request->user('sanctum')?->id,
                'customer_name' => $validated['customer_name'] ?? 'Venda avulsa',
                'customer_phone' => $validated['customer_phone'] ?? null,
                'payment_method' => 'pix',
                'status' => 'open',
            ]);

            $servicesTotal = 0;
            foreach ($validated['services'] ?? [] as $item) {
                $service = \App\Models\Service::where('company_id', $companyId)->where('ativo', true)->where('id', $item['service_id'])->first();
                if (!$service) abort(422, 'Serviço indisponível.');
                $quantity = (int) ($item['quantity'] ?? 1);
                $lineTotal = round((float) $service->preco * $quantity, 2);
                $servicesTotal += $lineTotal;
                $sale->items()->create(['service_id' => $service->id, 'type' => 'service', 'description' => $service->nome, 'quantity' => $quantity, 'unit_price' => $service->preco, 'total' => $lineTotal]);
            }

            $productsTotal = 0;
            foreach ($validated['products'] ?? [] as $item) {
                $product = Product::where('company_id', $companyId)->where('active', true)->where('id', $item['product_id'])->first();
                if (!$product) abort(422, 'Produto indisponível.');
                if ((int) $product->stock_quantity < (int) $item['quantity']) abort(422, "Estoque insuficiente para {$product->name}.");
                $lineTotal = round((float) $product->sale_price * (int) $item['quantity'], 2);
                $productsTotal += $lineTotal;
                $sale->items()->create(['product_id' => $product->id, 'type' => 'product', 'description' => $product->name, 'quantity' => (int) $item['quantity'], 'unit_price' => $product->sale_price, 'total' => $lineTotal]);
            }

            $discount = (float) ($validated['discount'] ?? 0);
            $addition = (float) ($validated['addition'] ?? 0);
            $sale->update(['services_total' => $servicesTotal, 'products_total' => $productsTotal, 'discount' => $discount, 'addition' => $addition, 'total' => max(0, round($servicesTotal + $productsTotal + $addition - $discount, 2))]);

            return $sale->refresh();
        });

        try {
            $payment = $manager->paymentProvider($integration->provider)->createStandalonePixPayment($integration, (int) $companyId, [
            'sale_id' => $sale->id,
            'amount' => (float) $validated['amount'],
            'description' => $validated['description'] ?? "Venda livre #{$sale->id}",
            'payer_email' => $validated['payer_email'] ?? null,
            'payer_name' => $validated['payer_name'] ?? $validated['customer_name'] ?? null,
            ]);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->serializePayment($payment), 201);
    }

    private function serializePayment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'provider' => $payment->provider,
            'sale_id' => $payment->sale_id,
            'status' => $payment->status,
            'amount' => (float) $payment->amount,
            'payment_method' => $payment->payment_method,
            'external_reference' => $payment->external_reference,
            'provider_status' => $payment->provider_status,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'pix' => [
                'qr_code' => $payment->payment_data['qr_code'] ?? null,
                'qr_code_base64' => $payment->payment_data['qr_code_base64'] ?? null,
                'ticket_url' => $payment->payment_data['ticket_url'] ?? null,
            ],
        ];
    }
}
