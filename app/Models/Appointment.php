<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'cliente',
        'telefone',
        'data',
        'horario',
        'service_id',
        'preco',
        'status',
        'observacoes',
        'reminded_at',
        'user_id',
        'company_id',
        'feedback_token',
        'feedback_token_expires_at',
        'feedback_requested_at',
    ];

    protected $casts = [
        'data' => 'date',
        'reminded_at' => 'datetime',
        'feedback_token_expires_at' => 'datetime',
        'feedback_requested_at' => 'datetime',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'appointment_service')->withTimestamps();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function feedback()
    {
        return $this->hasOne(AppointmentFeedback::class);
    }

    public function loyaltyRedemption()
    {
        return $this->hasOne(LoyaltyRedemption::class);
    }
}
