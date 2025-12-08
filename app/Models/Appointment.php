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
        'user_id',
    ];

    protected $casts = [
        'data' => 'date',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
