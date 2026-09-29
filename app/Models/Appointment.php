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
        'payment_status',
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

    public function sale()
    {
        return $this->hasOne(Sale::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    protected static function booted(): void
    {
        static::created(function (Appointment $appointment) {
            $appointment->ensureClientCompanyLink();
        });

        static::updated(function (Appointment $appointment) {
            if ($appointment->wasChanged(['company_id', 'user_id'])) {
                $appointment->ensureClientCompanyLink();
            }
        });
    }

    public function ensureClientCompanyLink(): void
    {
        if (!$this->company_id || !$this->user_id) {
            return;
        }

        $user = $this->relationLoaded('user') ? $this->user : User::find($this->user_id);
        if (!$user || $user->role !== 'client') {
            return;
        }

        $user->clientCompanies()->syncWithoutDetaching([
            $this->company_id => ['first_appointment_at' => $this->created_at ?? now()],
        ]);
    }
}
