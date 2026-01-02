<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'enabled',
        'rule_type',
        'spend_amount_cents_per_point',
        'points_per_visit',
        'expiration_enabled',
        'expiration_days',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'expiration_enabled' => 'boolean',
        'spend_amount_cents_per_point' => 'integer',
        'points_per_visit' => 'integer',
        'expiration_days' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
