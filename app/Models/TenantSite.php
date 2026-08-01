<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TenantSite extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'status', 'public_enabled', 'subdomain', 'settings', 'draft_site_version_id', 'published_site_version_id', 'published_at',
        'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'draft_site_version_id' => 'integer', 'published_site_version_id' => 'integer', 'public_enabled' => 'boolean', 'settings' => 'array', 'published_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(TenantSitePage::class);
    }

    public function publishEvents(): HasMany
    {
        return $this->hasMany(TenantSitePublishEvent::class);
    }

    public function draftSiteVersion(): BelongsTo
    {
        return $this->belongsTo(TenantSiteVersion::class, 'draft_site_version_id');
    }

    public function publishedSiteVersion(): BelongsTo
    {
        return $this->belongsTo(TenantSiteVersion::class, 'published_site_version_id');
    }

    public function siteVersions(): HasMany
    {
        return $this->hasMany(TenantSiteVersion::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(TenantSiteMedia::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantSiteDomain::class);
    }
}
