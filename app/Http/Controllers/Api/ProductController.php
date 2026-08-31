<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $query = Product::where('company_id', $companyId);

        if (!$request->boolean('include_inactive')) {
            $query->where('active', true);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('name')->get()->map(fn (Product $product) => $this->serialize($product))->values());
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId($request);
        $validator = Validator::make($request->all(), $this->rules($companyId));

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Erro de validação.', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        if ($request->hasFile('image')) {
            $payload['image_path'] = $request->file('image')->store('products', 'public');
        }
        unset($payload['image'], $payload['remove_image']);

        $product = Product::create($payload + ['company_id' => $companyId, 'active' => true]);
        $this->recordMovement($request, $product, 'initial', (int) $product->stock_quantity, 'Estoque inicial');
        ActivityLogger::record($request->user('sanctum'), 'product.created', ['product_id' => $product->id, 'name' => $product->name], $request);

        return response()->json($this->serialize($product), 201);
    }

    public function update(Request $request, Product $product)
    {
        $companyId = $this->companyId($request);
        $this->authorizeProduct($product, $companyId);
        $validator = Validator::make($request->all(), $this->rules($companyId, $product->id));

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Erro de validação.', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        if ($request->boolean('remove_image') && $product->image_path) {
            Storage::disk('public')->delete($product->image_path);
            $payload['image_path'] = null;
        }

        if ($request->hasFile('image')) {
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
            $payload['image_path'] = $request->file('image')->store('products', 'public');
        }
        unset($payload['image'], $payload['remove_image']);

        $oldStock = (int) $product->stock_quantity;
        $product->update($payload);
        $diff = (int) $product->stock_quantity - $oldStock;
        if ($diff !== 0) {
            $this->recordMovement($request, $product, 'adjustment', $diff, 'Ajuste manual no cadastro');
        }
        ActivityLogger::record($request->user('sanctum'), 'product.updated', ['product_id' => $product->id, 'name' => $product->name], $request);

        return response()->json($this->serialize($product->fresh()));
    }

    public function image(string $path)
    {
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }

    public function destroy(Request $request, Product $product)
    {
        $companyId = $this->companyId($request);
        $this->authorizeProduct($product, $companyId);
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }
        $product->update(['active' => false, 'image_path' => null]);
        ActivityLogger::record($request->user('sanctum'), 'product.inactivated', ['product_id' => $product->id], $request);
        return response()->noContent();
    }

    public function adjustStock(Request $request, Product $product)
    {
        $companyId = $this->companyId($request);
        $this->authorizeProduct($product, $companyId);
        $data = $request->validate([
            'quantity' => 'required|integer|not_in:0',
            'notes' => 'nullable|string|max:500',
        ]);

        if ((int) $product->stock_quantity + (int) $data['quantity'] < 0) {
            return response()->json(['message' => 'Estoque insuficiente para este ajuste.'], 422);
        }

        DB::transaction(function () use ($request, $product, $data) {
            $product->increment('stock_quantity', (int) $data['quantity']);
            $product->refresh();
            $this->recordMovement($request, $product, 'adjustment', (int) $data['quantity'], $data['notes'] ?? 'Ajuste manual');
        });

        return response()->json($this->serialize($product->fresh()));
    }

    public function movements(Request $request, Product $product)
    {
        $companyId = $this->companyId($request);
        $this->authorizeProduct($product, $companyId);

        return response()->json(
            StockMovement::where('company_id', $companyId)
                ->where('product_id', $product->id)
                ->latest()
                ->limit(50)
                ->get()
        );
    }

    private function rules(int $companyId, ?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('products', 'name')->where(fn ($query) => $query->where('company_id', $companyId)->where('active', true))->ignore($ignoreId)],
            'sku' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
            'image' => 'sometimes|nullable|image|max:4096',
            'remove_image' => 'sometimes|boolean',
            'sale_price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'minimum_stock' => 'required|integer|min:0',
            'active' => 'sometimes|boolean',
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

    private function authorizeProduct(Product $product, int $companyId): void
    {
        if ($product->company_id !== $companyId) {
            abort(403, 'Produto não pertence à sua empresa.');
        }
    }

    private function recordMovement(Request $request, Product $product, string $type, int $quantity, ?string $notes = null): void
    {
        if ($quantity === 0) {
            return;
        }

        StockMovement::create([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'user_id' => $request->user('sanctum')?->id,
            'type' => $type,
            'quantity' => $quantity,
            'balance_after' => (int) $product->stock_quantity,
            'notes' => $notes,
        ]);
    }

    private function serialize(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'description' => $product->description,
            'image_url' => $product->image_url,
            'sale_price' => (float) $product->sale_price,
            'cost_price' => $product->cost_price !== null ? (float) $product->cost_price : null,
            'stock_quantity' => (int) $product->stock_quantity,
            'minimum_stock' => (int) $product->minimum_stock,
            'active' => (bool) $product->active,
            'low_stock' => (int) $product->stock_quantity <= (int) $product->minimum_stock,
        ];
    }
}
