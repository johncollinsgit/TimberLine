<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSitePublishEvent extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'tenant_site_id', 'tenant_site_page_id', 'actor_user_id', 'event_type', 'context'];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'tenant_site_page_id' => 'integer', 'actor_user_id' => 'integer', 'context' => 'array'];
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
