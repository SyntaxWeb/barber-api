<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_REFUNDED = 'REFUNDED';

    protected $fillable = [
        'company_id',
        'appointment_id',
        'sale_id',
        'integration_id',
        'provider',
        'provider_payment_id',
        'external_reference',
        'amount',
        'payment_method',
        'status',
        'provider_status',
        'payment_data',
        'paid_at',
        'expired_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_data' => 'array',
        'paid_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function integration()
    {
        return $this->belongsTo(Integration::class);
    }
}
