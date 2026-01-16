<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CompanyPhoto extends Model
{
    use HasFactory;

    protected $table = 'company_gallery_photos';

    protected $fillable = [
        'company_id',
        'path',
        'position',
    ];

    protected $hidden = [
        'company_id',
        'path',
    ];

    protected $appends = [
        'url',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
