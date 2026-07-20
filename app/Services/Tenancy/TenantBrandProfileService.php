<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantBrandAsset;
use App\Models\TenantBrandProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TenantBrandProfileService
{
    /** @return array<string,mixed> */
    public function defaultAttributes(Tenant $tenant): array
    {
        $isCollins = strtolower(trim((string) $tenant->slug)) === 'collins-electric';

        return [
            'display_name' => $isCollins ? 'Collins Upstate Electric' : (string) $tenant->name,
            'tagline' => $isCollins ? 'Residential · Commercial · Reliable Power' : null,
            'light_logo_path' => $isCollins ? 'brand/kits/collins-upstate-electric/collins-lockup-navy.svg' : null,
            'dark_logo_path' => $isCollins ? 'brand/kits/collins-upstate-electric/collins-lockup-white.svg' : null,
            'icon_path' => $isCollins ? 'brand/kits/collins-upstate-electric/collins-icon.svg' : null,
            'asset_sources' => $isCollins ? [
                'light_logo' => 'bundled',
                'dark_logo' => 'bundled',
                'icon' => 'bundled',
            ] : [],
            'primary_color' => $isCollins ? '#061D42' : '#123C43',
            'accent_color' => $isCollins ? '#1464E8' : '#1E5A63',
            'surface_color' => '#FFFFFF',
            'text_color' => $isCollins ? '#0B1B36' : '#0F1C1F',
            'display_style' => $isCollins ? 'technical' : 'classic',
            'corner_style' => $isCollins ? 'standard' : 'soft',
            'decor_preset' => $isCollins ? 'signal' : 'none',
            'theme_key' => $isCollins ? 'collins-upstate-electric' : 'custom',
            'metadata' => $isCollins ? [
                'package' => 'collins-upstate-electric-starter-kit',
                'contact_tokens' => ['{{PHONE}}', '{{WEBSITE}}', '{{EMAIL}}'],
            ] : [],
        ];
    }

    public function ensureForTenant(Tenant $tenant, ?User $actor = null): TenantBrandProfile
    {
        $profile = TenantBrandProfile::query()->firstOrCreate(
            ['tenant_id' => (int) $tenant->id],
            [
                ...$this->defaultAttributes($tenant),
                'created_by_user_id' => $actor?->id,
                'updated_by_user_id' => $actor?->id,
            ],
        );

        if (strtolower(trim((string) $tenant->slug)) === 'collins-electric') {
            $this->registerBundledCollinsAssets($profile);
        }

        return $profile;
    }

    /** @return array<string,mixed> */
    public function presentationFor(?Tenant $tenant): array
    {
        if (! $tenant instanceof Tenant) {
            $assets = (array) config('everbranch.brand_assets', []);
            $version = (string) ($assets['cache_tag'] ?? 'eb1');

            return [
                'themed' => false,
                'theme_key' => 'everbranch',
                'display_name' => (string) config('everbranch.product_name', 'Everbranch'),
                'tagline' => null,
                'light_logo_url' => asset((string) ($assets['mark'] ?? 'brand/everbranch-mark.svg')).'?v='.$version,
                'dark_logo_url' => asset((string) ($assets['mark'] ?? 'brand/everbranch-mark.svg')).'?v='.$version,
                'icon_url' => asset((string) ($assets['mark'] ?? 'brand/everbranch-mark.svg')).'?v='.$version,
                'has_light_logo' => false,
                'primary_color' => '#123C43',
                'accent_color' => '#1E5A63',
                'surface_color' => '#FFFFFF',
                'text_color' => '#0F1C1F',
                'display_style' => 'classic',
                'corner_style' => 'soft',
                'decor_preset' => 'none',
            ];
        }

        $profile = $this->ensureForTenant($tenant);
        $default = $this->defaultAttributes($tenant);
        $light = $profile->assetUrl('light_logo') ?: asset('brand/everbranch-mark.svg');
        $dark = $profile->assetUrl('dark_logo') ?: $light;
        $icon = $profile->assetUrl('icon') ?: $light;

        return [
            'themed' => true,
            'theme_key' => $this->safeThemeKey((string) ($profile->theme_key ?: 'custom')),
            'display_name' => trim((string) $profile->display_name) ?: (string) $default['display_name'],
            'tagline' => trim((string) ($profile->tagline ?? '')) ?: null,
            'light_logo_url' => $light,
            'dark_logo_url' => $dark,
            'icon_url' => $icon,
            'has_light_logo' => filled($profile->light_logo_path),
            'primary_color' => $this->normalizedHex((string) $profile->primary_color, (string) $default['primary_color']),
            'accent_color' => $this->normalizedHex((string) $profile->accent_color, (string) $default['accent_color']),
            'surface_color' => $this->normalizedHex((string) $profile->surface_color, (string) $default['surface_color']),
            'text_color' => $this->normalizedHex((string) $profile->text_color, (string) $default['text_color']),
            'display_style' => in_array($profile->display_style, ['classic', 'technical', 'editorial', 'bold'], true) ? $profile->display_style : 'classic',
            'corner_style' => in_array($profile->corner_style, ['soft', 'standard', 'sharp'], true) ? $profile->corner_style : 'soft',
            'decor_preset' => in_array($profile->decor_preset, ['none', 'signal', 'grid', 'dawn'], true) ? $profile->decor_preset : 'none',
        ];
    }

    /** @param array<string,mixed> $attributes */
    public function update(TenantBrandProfile $profile, array $attributes, User $actor): TenantBrandProfile
    {
        $this->assertAccessiblePalette(
            (string) $attributes['surface_color'],
            (string) $attributes['text_color'],
            (string) $attributes['primary_color'],
            (string) $attributes['accent_color'],
        );

        $profile->fill($attributes);
        $profile->updated_by_user_id = (int) $actor->id;
        $profile->save();

        return $profile->refresh();
    }

    public function reset(Tenant $tenant, User $actor): TenantBrandProfile
    {
        $profile = $this->ensureForTenant($tenant, $actor);
        $profile->fill($this->defaultAttributes($tenant));
        $profile->updated_by_user_id = (int) $actor->id;
        $profile->save();

        return $profile->refresh();
    }

    public function storeLogo(TenantBrandProfile $profile, UploadedFile $file, string $slot, User $actor): void
    {
        $field = match ($slot) {
            'light_logo' => 'light_logo_path',
            'dark_logo' => 'dark_logo_path',
            'icon' => 'icon_path',
            default => throw ValidationException::withMessages(['logo' => ['Unknown brand asset.']]),
        };
        $sources = is_array($profile->asset_sources) ? $profile->asset_sources : [];
        $previousPath = trim((string) $profile->{$field});
        $previousSource = (string) ($sources[$slot] ?? 'bundled');
        $path = $file->store("tenant-brand/{$profile->tenant_id}/{$slot}", 'public');

        $profile->{$field} = $path;
        $sources[$slot] = 'upload';
        $profile->asset_sources = $sources;
        $profile->updated_by_user_id = (int) $actor->id;
        $profile->save();

        TenantBrandAsset::query()->create([
            'tenant_id' => (int) $profile->tenant_id,
            'tenant_brand_profile_id' => (int) $profile->id,
            'uploaded_by_user_id' => (int) $actor->id,
            'kind' => $slot,
            'label' => match ($slot) {
                'light_logo' => 'Light workspace logo',
                'dark_logo' => 'Dark workspace logo',
                default => 'Workspace app icon',
            },
            'source' => 'upload',
            'storage_disk' => 'public',
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'metadata' => ['replaced' => $previousPath !== '' ? $previousPath : null],
        ]);

        if ($previousSource === 'upload' && $previousPath !== '' && $previousPath !== $path) {
            Storage::disk('public')->delete($previousPath);
        }
    }

    public function userCanCustomize(?User $user, ?Tenant $tenant): bool
    {
        if (! $user instanceof User || ! $tenant instanceof Tenant || $user->getAttribute('is_active') === false) {
            return false;
        }

        $role = $user->tenants()->whereKey((int) $tenant->id)->value('tenant_user.role');
        $role = strtolower(trim((string) $role));

        return in_array($role, ['admin', 'owner', 'tenant_owner'], true);
    }

    public function assertAccessiblePalette(string $surface, string $text, string $primary, string $accent): void
    {
        $errors = [];
        if ($this->contrastRatio($surface, $text) < 4.5) {
            $errors['text_color'] = ['Text color needs at least 4.5:1 contrast against the workspace surface.'];
        }
        if ($this->contrastRatio($surface, $primary) < 3) {
            $errors['primary_color'] = ['Primary color needs at least 3:1 contrast against the workspace surface.'];
        }
        if ($this->contrastRatio($surface, $accent) < 3) {
            $errors['accent_color'] = ['Accent color needs at least 3:1 contrast against the workspace surface.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected function normalizedHex(string $value, string $fallback): string
    {
        $value = strtoupper(trim($value));

        return preg_match('/^#[A-F0-9]{6}$/', $value) ? $value : $fallback;
    }

    protected function safeThemeKey(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-z0-9-]{1,80}$/', $value) ? $value : 'custom';
    }

    protected function contrastRatio(string $first, string $second): float
    {
        return (max($this->relativeLuminance($first), $this->relativeLuminance($second)) + 0.05)
            / (min($this->relativeLuminance($first), $this->relativeLuminance($second)) + 0.05);
    }

    protected function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [substr($hex, 0, 2), substr($hex, 2, 2), substr($hex, 4, 2)];
        $linear = array_map(static function (string $channel): float {
            $value = hexdec($channel) / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }

    protected function registerBundledCollinsAssets(TenantBrandProfile $profile): void
    {
        $base = 'brand/kits/collins-upstate-electric/';
        $assets = [
            ['logo_light', 'Collins navy logo', $base.'collins-lockup-navy.svg', 'image/svg+xml'],
            ['logo_dark', 'Collins white logo', $base.'collins-lockup-white.svg', 'image/svg+xml'],
            ['app_icon', 'Collins compact icon', $base.'collins-icon.svg', 'image/svg+xml'],
            ['service_icon', 'Residential icon', $base.'icons/residential.svg', 'image/svg+xml'],
            ['service_icon', 'Commercial icon', $base.'icons/commercial.svg', 'image/svg+xml'],
            ['service_icon', 'Reliable power icon', $base.'icons/reliable-power.svg', 'image/svg+xml'],
            ['service_icon', 'Licensed and insured icon', $base.'icons/licensed-insured.svg', 'image/svg+xml'],
            ['service_icon', 'Quality service icon', $base.'icons/quality-service.svg', 'image/svg+xml'],
            ['marketing_template', 'Email header', $base.'templates/email-header.svg', 'image/svg+xml'],
            ['marketing_template', 'Square social post', $base.'templates/social-square.svg', 'image/svg+xml'],
            ['marketing_template', 'Social story', $base.'templates/social-story.svg', 'image/svg+xml'],
            ['marketing_template', 'Service flyer', $base.'templates/service-flyer.svg', 'image/svg+xml'],
            ['marketing_template', 'Yard-sign layout', $base.'templates/yard-sign.svg', 'image/svg+xml'],
            ['marketing_template', 'Vehicle decals reference', $base.'templates/vehicle-decals.svg', 'image/svg+xml'],
            ['campaign_backdrop', 'Blue-hour campaign backdrop', $base.'campaign-backdrop.png', 'image/png'],
        ];

        foreach ($assets as [$kind, $label, $path, $mimeType]) {
            TenantBrandAsset::query()->firstOrCreate(
                [
                    'tenant_brand_profile_id' => (int) $profile->id,
                    'kind' => $kind,
                    'path' => $path,
                ],
                [
                    'tenant_id' => (int) $profile->tenant_id,
                    'label' => $label,
                    'source' => 'bundled',
                    'storage_disk' => null,
                    'mime_type' => $mimeType,
                    'metadata' => ['package' => 'collins-upstate-electric-starter-kit'],
                ],
            );
        }
    }
}
