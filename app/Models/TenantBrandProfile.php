<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantBrandProfile extends Model
{
    protected $fillable = [
        'tenant_id', 'display_name', 'tagline', 'light_logo_path', 'dark_logo_path', 'icon_path',
        'asset_sources', 'primary_color', 'accent_color', 'surface_color', 'text_color',
        'display_style', 'corner_style', 'decor_preset', 'theme_key', 'metadata',
        'created_by_user_id', 'updated_by_user_id',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'created_by_user_id' => 'integer',
        'updated_by_user_id' => 'integer',
        'asset_sources' => 'array',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(TenantBrandAsset::class);
    }

    public function assetUrl(string $asset): string
    {
        $path = trim((string) match ($asset) {
            'light_logo' => $this->light_logo_path,
            'dark_logo' => $this->dark_logo_path,
            'icon' => $this->icon_path,
            default => '',
        });
        $source = (string) (($this->asset_sources ?? [])[$asset] ?? 'bundled');

        if ($path === '') {
            return '';
        }

        if ($source === 'upload') {
            $version = $this->updated_at?->getTimestamp() ?? $this->getKey();

            return route('tenant.brand.assets.show', [
                'profile' => $this->getKey(),
                'slot' => $asset,
                'v' => $version,
            ]);
        }

        return asset(ltrim($path, '/'));
    }
}
