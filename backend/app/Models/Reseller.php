<?php

declare(strict_types=1);


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reseller extends Model
{
    public const RESERVED_SLUGS = [
        'login',
        'entrar',
        'dashboard',
        'api',
        'admin',
        'esqueceu-senha',
        'redefinir-senha',
        'suporte',
        'minha-conta',
        'companies',
    ];

    protected $fillable = [
        'name',
        'slug',
        'logo',
        'primary_color',
    ];

    protected $appends = ['logo_url'];

    public function getLogoUrlAttribute(): ?string
    {
        $logo = $this->logo;

        if ($logo === null || $logo === '') {
            return null;
        }

        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://')) {
            return $logo;
        }

        return asset('storage/' . ltrim($logo, '/'));
    }

    public static function getBySlug(string $slug): ?self
    {
        $cacheKey = 'reseller_slug_' . $slug;

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return (new static)->newFromBuilder($cached);
        }

        $reseller = static::where('slug', $slug)->first();

        if ($reseller !== null) {
            Cache::put($cacheKey, $reseller->getAttributes(), now()->addHour());
        }

        return $reseller;
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
