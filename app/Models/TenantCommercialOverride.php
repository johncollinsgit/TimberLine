<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCommercialOverride extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'template_key',
        'store_channel_allowance',
        'plan_pricing_overrides',
        'addon_pricing_overrides',
        'included_usage_overrides',
        'display_labels',
        'billing_mapping',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'store_channel_allowance' => 'integer',
        'plan_pricing_overrides' => 'array',
        'addon_pricing_overrides' => 'array',
        'included_usage_overrides' => 'array',
        'display_labels' => 'array',
        'billing_mapping' => 'array',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        $refreshCaches = static function (self $override): void {
            $tenantId = (int) ($override->tenant_id ?? 0);
            $tenantId = $tenantId > 0 ? $tenantId : null;

            app(TenantDisplayLabelResolver::class)->forgetTenant($tenantId);
            app(TenantCommercialExperienceService::class)->forgetTenantCache($tenantId);
        };

        static::saved($refreshCaches);
        static::deleted($refreshCaches);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
