<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantSitePage extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'tenant_site_id', 'slug', 'page_type', 'title', 'is_navigation_visible', 'draft_version_id', 'published_version_id'];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'is_navigation_visible' => 'boolean', 'draft_version_id' => 'integer', 'published_version_id' => 'integer'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(TenantSite::class, 'tenant_site_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TenantSitePageVersion::class);
    }

    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(TenantSitePageVersion::class, 'draft_version_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(TenantSitePageVersion::class, 'published_version_id');
    }
}
