<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'horario_inicio',
        'horario_fim',
        'intervalo_minutos',
        'weekly_schedule',
        'company_id',
    ];

    protected $casts = [
        'weekly_schedule' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
