<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlockedDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'data',
        'company_id',
    ];

    protected $casts = [
        'data' => 'date',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
