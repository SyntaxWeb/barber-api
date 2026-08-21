<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\ActivityLogger;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $query = Sale::with('items')->where('company_id', $companyId)->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('from')) {
            $query->where('closed_at', '>=', $request->date('from')->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('closed_at', '<=', $request->date('to')->endOfDay());
        }

        return response()->json($query->limit(100)->get()->map(fn (Sale $sale) => $this->serialize($sale))->values());
    }

    public function show(Request $request, Sale $sale)
    {
        $this->authorizeSale($sale, $this->companyId($request));
        return response()->json($this->serialize($sale->load('items')));
    }

    public function appointmentSale(Request $request, Appointment $appointment)
    {
        $companyId = $this->companyId($request);
        if ($appointment->company_id !== $companyId) {
            abort(403, 'Agendamento não pertence à sua empresa.');
        }

        $sale = Sale::with('items')->where('company_id', $companyId)->where('appointment_id', $appointment->id)->first();
        if (!$sale) {
            $sale = DB::transaction(fn () => $this->createOpenSaleFromAppointment($request, $appointment));
        }

        return response()->json($this->serialize($sale->load('items')));
    }


    public function closeDirectSale(Request $request)
    {
        $companyId = $this->companyId($request);
        $data = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:30',
            'services' => 'array',
            'services.*.service_id' => 'required|integer|exists:services,id',
            'services.*.quantity' => 'nullable|integer|min:1',
            'products' => 'array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'discount' => 'nullable|numeric|min:0',
            'addition' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:1000',
        ]);

        if (empty($data['services'] ?? []) && empty($data['products'] ?? [])) {
            return response()->json(['message' => 'Adicione ao menos um serviço ou produto.'], 422);
        }

        $sale = DB::transaction(function () use ($request, $companyId, $data) {
            $sale = Sale::create([
                'company_id' => $companyId,
                'user_id' => $request->user('sanctum')?->id,
                'customer_name' => $data['customer_name'] ?? 'Venda avulsa',
                'customer_phone' => $data['customer_phone'] ?? null,
                'status' => 'open',
            ]);

            $servicesTotal = 0;
            foreach ($data['services'] ?? [] as $item) {
                $service = \App\Models\Service::where('company_id', $companyId)
                    ->where('ativo', true)
                    ->where('id', $item['service_id'])
                    ->first();
                if (!$service) {
                    abort(422, 'Serviço indisponível.');
                }
                $quantity = (int) ($item['quantity'] ?? 1);
                $lineTotal = round((float) $service->preco * $quantity, 2);
                $servicesTotal += $lineTotal;
                $sale->items()->create([
                    'service_id' => $service->id,
                    'type' => 'service',
                    'description' => $service->nome,
                    'quantity' => $quantity,
                    'unit_price' => $service->preco,
                    'total' => $lineTotal,
                ]);
            }

            $productsTotal = 0;
            foreach ($data['products'] ?? [] as $item) {
                $product = Product::where('company_id', $companyId)
                    ->where('active', true)
                    ->where('id', $item['product_id'])
                    ->lockForUpdate()
                    ->first();
                if (!$product) {
                    abort(422, 'Produto indisponível.');
                }
                $quantity = (int) $item['quantity'];
                if ((int) $product->stock_quantity < $quantity) {
                    abort(422, "Estoque insuficiente para {$product->name}.");
                }
                $lineTotal = round((float) $product->sale_price * $quantity, 2);
                $productsTotal += $lineTotal;
                $sale->items()->create([
                    'product_id' => $product->id,
                    'type' => 'product',
                    'description' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $product->sale_price,
                    'total' => $lineTotal,
                ]);

                $product->decrement('stock_quantity', $quantity);
                $product->refresh();
                StockMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'user_id' => $request->user('sanctum')?->id,
                    'type' => 'sale',
                    'quantity' => -$quantity,
                    'balance_after' => (int) $product->stock_quantity,
                    'notes' => "Venda avulsa #{$sale->id}",
                ]);
            }

            $discount = (float) ($data['discount'] ?? 0);
            $addition = (float) ($data['addition'] ?? 0);
            $total = max(0, round($servicesTotal + $productsTotal + $addition - $discount, 2));
            $sale->update([
                'services_total' => $servicesTotal,
                'products_total' => $productsTotal,
                'discount' => $discount,
                'addition' => $addition,
                'total' => $total,
                'payment_method' => $data['payment_method'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'closed',
                'closed_at' => now(),
            ]);

            ActivityLogger::record($request->user('sanctum'), 'sale.direct_closed', [
                'sale_id' => $sale->id,
                'total' => $total,
            ], $request);

            return $sale->load('items');
        });

        return response()->json($this->serialize($sale), 201);
    }

    public function closeAppointmentSale(Request $request, Appointment $appointment, LoyaltyService $loyalty)
    {
        $companyId = $this->companyId($request);
        if ($appointment->company_id !== $companyId) {
            abort(403, 'Agendamento não pertence à sua empresa.');
        }

        $data = $request->validate([
            'products' => 'array',
            'products.*.product_id' => 'required|integer|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'discount' => 'nullable|numeric|min:0',
            'addition' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:1000',
        ]);

        $sale = DB::transaction(function () use ($request, $appointment, $companyId, $data, $loyalty) {
            $sale = Sale::where('company_id', $companyId)->where('appointment_id', $appointment->id)->lockForUpdate()->first();
            if (!$sale) {
                $sale = $this->createOpenSaleFromAppointment($request, $appointment);
            }
            if ($sale->status === 'closed') {
                abort(422, 'Este atendimento já foi fechado.');
            }

            $sale->items()->where('type', 'product')->delete();

            $productsTotal = 0;
            foreach ($data['products'] ?? [] as $item) {
                $product = Product::where('company_id', $companyId)->where('active', true)->where('id', $item['product_id'])->lockForUpdate()->first();
                if (!$product) {
                    abort(422, 'Produto indisponível.');
                }
                $quantity = (int) $item['quantity'];
                if ((int) $product->stock_quantity < $quantity) {
                    abort(422, "Estoque insuficiente para {$product->name}.");
                }

                $lineTotal = round((float) $product->sale_price * $quantity, 2);
                $productsTotal += $lineTotal;
                $sale->items()->create([
                    'product_id' => $product->id,
                    'type' => 'product',
                    'description' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $product->sale_price,
                    'total' => $lineTotal,
                ]);

                $product->decrement('stock_quantity', $quantity);
                $product->refresh();
                StockMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'user_id' => $request->user('sanctum')?->id,
                    'type' => 'sale',
                    'quantity' => -$quantity,
                    'balance_after' => (int) $product->stock_quantity,
                    'notes' => "Venda no atendimento #{$appointment->id}",
                ]);
            }

            $servicesTotal = (float) $sale->items()->where('type', 'service')->sum('total');
            $discount = (float) ($data['discount'] ?? 0);
            $addition = (float) ($data['addition'] ?? 0);
            $total = max(0, round($servicesTotal + $productsTotal + $addition - $discount, 2));

            $sale->update([
                'products_total' => $productsTotal,
                'services_total' => $servicesTotal,
                'discount' => $discount,
                'addition' => $addition,
                'total' => $total,
                'payment_method' => $data['payment_method'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'closed',
                'closed_at' => now(),
            ]);

            $previousStatus = $appointment->status;
            $appointment->update(['status' => 'concluido', 'preco' => $servicesTotal]);
            if ($previousStatus !== 'concluido') {
                $loyalty->awardForAppointment($appointment);
            }

            ActivityLogger::record($request->user('sanctum'), 'sale.closed', [
                'sale_id' => $sale->id,
                'appointment_id' => $appointment->id,
                'total' => $total,
            ], $request);

            return $sale->load('items');
        });

        return response()->json($this->serialize($sale));
    }

    private function createOpenSaleFromAppointment(Request $request, Appointment $appointment): Sale
    {
        $appointment->loadMissing('services', 'service');
        $sale = Sale::create([
            'company_id' => $appointment->company_id,
            'appointment_id' => $appointment->id,
            'user_id' => $request->user('sanctum')?->id,
            'customer_name' => $appointment->cliente,
            'customer_phone' => $appointment->telefone,
            'status' => 'open',
        ]);

        $services = $appointment->services->isNotEmpty() ? $appointment->services : collect([$appointment->service])->filter();
        $servicesTotal = 0;
        foreach ($services as $service) {
            $lineTotal = (float) $service->preco;
            $servicesTotal += $lineTotal;
            $sale->items()->create([
                'service_id' => $service->id,
                'type' => 'service',
                'description' => $service->nome,
                'quantity' => 1,
                'unit_price' => $lineTotal,
                'total' => $lineTotal,
            ]);
        }

        $sale->update(['services_total' => $servicesTotal, 'total' => $servicesTotal]);
        return $sale->load('items');
    }

    private function serialize(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'appointment_id' => $sale->appointment_id,
            'customer_name' => $sale->customer_name,
            'customer_phone' => $sale->customer_phone,
            'status' => $sale->status,
            'services_total' => (float) $sale->services_total,
            'products_total' => (float) $sale->products_total,
            'discount' => (float) $sale->discount,
            'addition' => (float) $sale->addition,
            'total' => (float) $sale->total,
            'payment_method' => $sale->payment_method,
            'notes' => $sale->notes,
            'closed_at' => $sale->closed_at?->toIso8601String(),
            'items' => $sale->items->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type,
                'service_id' => $item->service_id,
                'product_id' => $item->product_id,
                'description' => $item->description,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total,
            ])->values(),
        ];
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->user('sanctum')?->company_id;
        if (!$companyId) {
            abort(403, 'Usuário não associado a uma empresa.');
        }
        return $companyId;
    }

    private function authorizeSale(Sale $sale, int $companyId): void
    {
        if ($sale->company_id !== $companyId) {
            abort(403, 'Venda não pertence à sua empresa.');
        }
    }
}
