<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppointmentFeedback extends Model
{
    use HasFactory;

    protected $table = 'appointment_feedback';

    protected $fillable = [
        'appointment_id',
        'service_rating',
        'professional_rating',
        'scheduling_rating',
        'comment',
        'allow_public_testimonial',
        'submitted_at',
    ];

    protected $casts = [
        'service_rating' => 'integer',
        'professional_rating' => 'integer',
        'scheduling_rating' => 'integer',
        'allow_public_testimonial' => 'boolean',
        'submitted_at' => 'datetime',
    ];

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function getAverageRatingAttribute(): float
    {
        $total = ($this->service_rating ?? 0) + ($this->professional_rating ?? 0) + ($this->scheduling_rating ?? 0);
        return round($total / 3, 2);
    }
}
