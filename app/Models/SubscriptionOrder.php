<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'external_reference',
        'plan_key',
        'plan_name',
        'price',
        'status',
        'mp_preference_id',
        'mp_payment_id',
        'mp_status',
        'checkout_url',
        'mp_payload',
        'paid_at',
    ];

    protected $casts = [
        'mp_payload' => 'array',
        'paid_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
