<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSitePageVersion extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'tenant_site_id', 'tenant_site_page_id', 'version_number', 'status', 'title', 'blocks', 'seo', 'created_by_user_id', 'published_at'];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'tenant_site_page_id' => 'integer', 'version_number' => 'integer', 'blocks' => 'array', 'seo' => 'array', 'published_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(TenantSite::class, 'tenant_site_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(TenantSitePage::class, 'tenant_site_page_id');
    }
}
