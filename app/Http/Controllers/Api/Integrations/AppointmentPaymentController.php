<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Http\Request;

class AppointmentPaymentController extends Controller
{
    public function store(Request $request, Appointment $appointment, IntegrationManager $manager)
    {
        $user = $request->user('sanctum');

        if ($user?->role === 'client') {
            if ($appointment->user_id !== $user->id) {
                abort(403, 'Agendamento nao pertence ao cliente autenticado.');
            }
        } elseif ($appointment->company_id !== $user?->company_id) {
            abort(403, 'Agendamento nao pertence a sua empresa.');
        }

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'payer_email' => ['nullable', 'email', 'max:255'],
            'products' => ['array'],
            'products.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'addition' => ['nullable', 'numeric', 'min:0'],
        ]);

        $sale = DB::transaction(function () use ($request, $appointment, $validated) {
            $sale = Sale::where('company_id', $appointment->company_id)->where('appointment_id', $appointment->id)->lockForUpdate()->first();
            if (!$sale) {
                $sale = Sale::create([
                    'company_id' => $appointment->company_id,
                    'appointment_id' => $appointment->id,
                    'user_id' => $request->user('sanctum')?->id,
                    'customer_name' => $appointment->cliente,
                    'customer_phone' => $appointment->telefone,
                    'status' => 'open',
                ]);
                $appointment->loadMissing('services', 'service');
                $services = $appointment->services->isNotEmpty() ? $appointment->services : collect([$appointment->service])->filter();
                foreach ($services as $service) {
                    $sale->items()->create(['service_id' => $service->id, 'type' => 'service', 'description' => $service->nome, 'quantity' => 1, 'unit_price' => $service->preco, 'total' => $service->preco]);
                }
            }
            if ($sale->status === 'closed') abort(422, 'Este atendimento já foi fechado.');

            $sale->items()->where('type', 'product')->delete();
            $productsTotal = 0;
            foreach ($validated['products'] ?? [] as $item) {
                $product = Product::where('company_id', $appointment->company_id)->where('active', true)->where('id', $item['product_id'])->first();
                if (!$product) abort(422, 'Produto indisponível.');
                if ((int) $product->stock_quantity < (int) $item['quantity']) abort(422, "Estoque insuficiente para {$product->name}.");
                $lineTotal = round((float) $product->sale_price * (int) $item['quantity'], 2);
                $productsTotal += $lineTotal;
                $sale->items()->create(['product_id' => $product->id, 'type' => 'product', 'description' => $product->name, 'quantity' => (int) $item['quantity'], 'unit_price' => $product->sale_price, 'total' => $lineTotal]);
            }

            $servicesTotal = (float) $sale->items()->where('type', 'service')->sum('total');
            $discount = (float) ($validated['discount'] ?? 0);
            $addition = (float) ($validated['addition'] ?? 0);
            $sale->update(['services_total' => $servicesTotal, 'products_total' => $productsTotal, 'discount' => $discount, 'addition' => $addition, 'total' => max(0, round($servicesTotal + $productsTotal + $addition - $discount, 2)), 'payment_method' => 'pix']);

            return $sale->refresh();
        });

        $amount = (float) $sale->total;

        if ($amount <= 0) {
            return response()->json(['message' => 'Agendamento sem valor para pagamento.'], 422);
        }

        if ($appointment->payments()->where('status', Payment::STATUS_APPROVED)->exists()) {
            return response()->json(['message' => 'Este agendamento ja possui pagamento aprovado.'], 422);
        }

        $integration = $manager->connectedPaymentIntegration((int) $appointment->company_id);
        if (!$integration) {
            return response()->json(['message' => 'Este prestador ainda nao disponibilizou pagamento online.'], 422);
        }

        try {
            $payment = $manager->paymentProvider($integration->provider)->createPixPayment($integration, $appointment, [
            'sale_id' => $sale->id,
            'amount' => $amount,
            'description' => $validated['description'] ?? null,
            'payer_email' => $validated['payer_email'] ?? ($user?->role === 'client' ? $user?->email : null),
            ]);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'id' => $payment->id,
            'provider' => $payment->provider,
            'sale_id' => $payment->sale_id,
            'status' => $payment->status,
            'amount' => (float) $payment->amount,
            'payment_method' => $payment->payment_method,
            'external_reference' => $payment->external_reference,
            'pix' => [
                'qr_code' => $payment->payment_data['qr_code'] ?? null,
                'qr_code_base64' => $payment->payment_data['qr_code_base64'] ?? null,
                'ticket_url' => $payment->payment_data['ticket_url'] ?? null,
            ],
        ], 201);
    }
}
