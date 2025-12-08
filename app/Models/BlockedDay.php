<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlockedDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'data',
    ];

    protected $casts = [
        'data' => 'date',
    ];
}
