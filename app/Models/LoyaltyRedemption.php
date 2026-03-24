<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'reward_id',
        'appointment_id',
        'status',
    ];

    public function reward()
    {
        return $this->belongsTo(LoyaltyReward::class, 'reward_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }
}
