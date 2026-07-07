<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Opt-in tenant ownership for a model: the ->forTenant() helpers of HasTenantScope
 * PLUS the enforced global TenantScope (default-on isolation, gated + null-safe)
 * PLUS auto-stamping tenant_id on create from the active tenant context.
 *
 * Adopt this ONLY on models whose data is fully tenant-owned and backfilled. It is
 * intentionally separate from HasTenantScope so that landlord-global models which
 * merely want the ->forTenant() helper are never swept into enforced scoping.
 *
 * Escape hatch: Model::query()->forAllTenants() (or TenantContext::withoutScope())
 * for audited cross-tenant work.
 */
trait BelongsToTenant
{
    use HasTenantScope;

    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            if (! config('features.enforced_tenant_scope')) {
                return;
            }

            $tenantId = app(TenantContext::class)->id();
            if ($tenantId !== null) {
                $model->setAttribute('tenant_id', $tenantId);
            }
        });
    }

    /**
     * Escape the enforced tenant scope for a query (audited cross-tenant reads).
     */
    public function scopeForAllTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
