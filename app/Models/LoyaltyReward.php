<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class LoyaltyReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'image_path',
        'points_cost',
        'active',
        'grants_free_appointment',
    ];

    protected $casts = [
        'active' => 'boolean',
        'points_cost' => 'integer',
        'grants_free_appointment' => 'boolean',
    ];

    protected $appends = [
        'image_url',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function redemptions()
    {
        return $this->hasMany(LoyaltyRedemption::class, 'reward_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->image_path);
    }
}
