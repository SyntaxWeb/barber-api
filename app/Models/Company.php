<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'nome',
        'slug',
        'descricao',
        'agendamento_url',
        'qr_code_svg',
        'icon_path',
    ];

    protected $appends = [
        'icon_url',
    ];

    protected $hidden = [
        'icon_path',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function settings()
    {
        return $this->hasOne(Setting::class);
    }

    public function blockedDays()
    {
        return $this->hasMany(BlockedDay::class);
    }

    public static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: Str::random(6);
        $slug = $base;
        $suffix = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public function getIconUrlAttribute(): ?string
    {
        if (!$this->icon_path) {
            return null;
        }

        return Storage::disk('public')->url($this->icon_path);
    }

    public function getAgendamentoUrlAttribute(?string $value): ?string
    {
        if (!$this->slug) {
            return $value;
        }

        $baseUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        return "{$baseUrl}/e/{$this->slug}/agendar";
    }
}
