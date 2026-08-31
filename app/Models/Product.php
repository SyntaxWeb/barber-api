<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'sku',
        'description',
        'image_path',
        'sale_price',
        'cost_price',
        'stock_quantity',
        'minimum_stock',
        'active',
    ];

    protected $appends = [
        'image_url',
    ];

    protected $casts = [
        'sale_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'minimum_stock' => 'integer',
        'active' => 'boolean',
    ];

    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image_path) {
            return null;
        }

        return url('/api/products/images/' . ltrim($this->image_path, '/'));
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }
}
