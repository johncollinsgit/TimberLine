<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSiteVersion extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'tenant_site_id', 'version_number', 'status', 'settings', 'navigation', 'seo',
        'thumbnail_path', 'source_manifest', 'created_by_user_id', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'version_number' => 'integer',
            'settings' => 'array', 'navigation' => 'array', 'seo' => 'array', 'source_manifest' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(TenantSite::class, 'tenant_site_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
