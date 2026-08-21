<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'appointment_id',
        'user_id',
        'customer_name',
        'customer_phone',
        'status',
        'services_total',
        'products_total',
        'discount',
        'addition',
        'total',
        'payment_method',
        'notes',
        'closed_at',
    ];

    protected $casts = [
        'services_total' => 'decimal:2',
        'products_total' => 'decimal:2',
        'discount' => 'decimal:2',
        'addition' => 'decimal:2',
        'total' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }
}
