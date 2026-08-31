<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'company_id',
        'provider',
        'type',
        'status',
        'credentials',
        'settings',
        'metadata',
        'connected_at',
        'disconnected_at',
    ];

    protected $hidden = [
        'credentials',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'metadata' => 'array',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
