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
        'notify_email',
        'notify_telegram',
        'notify_via_email',
        'notify_via_telegram',
        'telegram_link_token',
        'telegram_link_token_created_at',
        'dashboard_theme',
        'client_theme',
        'subscription_plan',
        'subscription_status',
        'subscription_price',
        'subscription_renews_at',
    ];

    protected $with = [
        'galleryPhotosRelation',
    ];

    protected $appends = [
        'icon_url',
        'gallery_photos',
        'gallery_assets',
    ];

    protected $hidden = [
        'icon_path',
        'telegram_link_token',
        'galleryPhotosRelation',
    ];

    protected $casts = [
        'notify_via_email' => 'boolean',
        'notify_via_telegram' => 'boolean',
        'telegram_link_token_created_at' => 'datetime',
        'subscription_price' => 'decimal:2',
        'subscription_renews_at' => 'datetime',
    ];

    protected const THEME_KEYS = ['primary', 'secondary', 'background', 'surface', 'text', 'accent'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public static function defaultDashboardTheme(): array
    {
        return [
            'primary' => '#0f172a',
            'secondary' => '#1d4ed8',
            'background' => '#f8fafc',
            'surface' => '#ffffff',
            'text' => '#0f172a',
            'accent' => '#f97316',
        ];
    }

    public static function defaultClientTheme(): array
    {
        return [
            'primary' => '#111827',
            'secondary' => '#dc2626',
            'background' => '#fdf2f8',
            'surface' => '#ffffff',
            'text' => '#111827',
            'accent' => '#fbbf24',
        ];
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

    public function galleryPhotosRelation()
    {
        return $this->hasMany(CompanyPhoto::class)->orderBy('position')->orderBy('id');
    }

    public function blockedDays()
    {
        return $this->hasMany(BlockedDay::class);
    }

    public function subscriptionOrders()
    {
        return $this->hasMany(SubscriptionOrder::class);
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

    public function getDashboardThemeAttribute($value): array
    {
        return $this->mergeThemeValues($this->decodeThemeValue($value), self::defaultDashboardTheme());
    }

    public function setDashboardThemeAttribute($value): void
    {
        $this->attributes['dashboard_theme'] = json_encode(
            $this->mergeThemeValues($this->normalizeThemeInput($value), self::defaultDashboardTheme())
        );
    }

    public function getClientThemeAttribute($value): array
    {
        return $this->mergeThemeValues($this->decodeThemeValue($value), self::defaultClientTheme());
    }

    public function setClientThemeAttribute($value): void
    {
        $this->attributes['client_theme'] = json_encode(
            $this->mergeThemeValues($this->normalizeThemeInput($value), self::defaultClientTheme())
        );
    }

    public function getGalleryPhotosAttribute(): array
    {
        return $this->galleryPhotosRelation
            ->map(fn (CompanyPhoto $photo) => $photo->url)
            ->all();
    }

    public function getGalleryAssetsAttribute(): array
    {
        return $this->galleryPhotosRelation
            ->map(fn (CompanyPhoto $photo) => [
                'id' => $photo->id,
                'path' => $photo->path,
                'url' => $photo->url,
            ])
            ->all();
    }

    protected function decodeThemeValue($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    protected function normalizeThemeInput($value): array
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (!is_array($value)) {
            return [];
        }

        return $value;
    }

    protected function mergeThemeValues(array $theme, array $defaults): array
    {
        $allowed = array_intersect_key($theme, array_flip(self::THEME_KEYS));
        return array_merge($defaults, array_filter($allowed, fn ($color) => is_string($color) && $color !== ''));
    }
}
