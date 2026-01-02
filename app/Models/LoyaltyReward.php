<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'points_cost',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'points_cost' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
