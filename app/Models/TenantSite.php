<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantSite extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'status', 'public_enabled', 'subdomain', 'settings', 'published_at',
        'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'public_enabled' => 'boolean', 'settings' => 'array', 'published_at' => 'datetime'];
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
}
