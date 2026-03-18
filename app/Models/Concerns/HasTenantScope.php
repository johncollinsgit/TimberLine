<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

trait HasTenantScope
{
    public function scopeForTenantId(Builder $query, ?int $tenantId): Builder
    {
        if ($tenantId === null) {
            return $query;
        }

        return $query->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId);
    }

    public function scopeForTenant(Builder $query, Tenant|int|string|null $tenant): Builder
    {
        if ($tenant instanceof Tenant) {
            return $this->scopeForTenantId($query, (int) $tenant->id);
        }

        if (is_numeric($tenant)) {
            return $this->scopeForTenantId($query, (int) $tenant);
        }

        return $query;
    }
}

