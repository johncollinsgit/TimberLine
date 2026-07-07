<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The enforced tenant scope: a global query constraint that filters a model to the
 * tenant the current request/job is acting for, so isolation is default-on rather
 * than something every query must remember to add.
 *
 * Two deliberate fail-open conditions keep it from ever breaking existing behavior
 * before the platform is fully backfilled/migrated:
 *   1. It is gated behind config('features.enforced_tenant_scope') — OFF by default.
 *   2. When no tenant context is set (CLI, queue, landlord, unauthenticated), it
 *      applies NO filter, exactly matching today's "opt-in" behavior.
 *
 * Applied via the BelongsToTenant trait, model by model, only to models whose data
 * is fully tenant-owned (backfilled) — never blanket-attached to the shared
 * HasTenantScope trait, which some landlord-global models also use.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! config('features.enforced_tenant_scope')) {
            return;
        }

        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
