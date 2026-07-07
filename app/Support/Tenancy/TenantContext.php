<?php

namespace App\Support\Tenancy;

/**
 * Holds the tenant the current request/job is acting for, so the enforced global
 * tenant scope (see App\Models\Scopes\TenantScope) can read it from anywhere —
 * including query/CLI contexts where there is no HTTP request.
 *
 * Set by EnsureTenantAccess once a tenant is resolved. Unset means "no tenant
 * context" → the global scope applies NO filter (landlord/CLI/queue keep seeing
 * all tenants), which is what preserves existing behavior.
 *
 * Bound as a `scoped` singleton so it is flushed between requests/jobs (safe under
 * Octane and queue workers).
 */
class TenantContext
{
    protected ?int $tenantId = null;

    protected bool $suppressed = false;

    public function set(?int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function id(): ?int
    {
        return $this->suppressed ? null : $this->tenantId;
    }

    public function has(): bool
    {
        return $this->id() !== null;
    }

    public function forget(): void
    {
        $this->tenantId = null;
    }

    /**
     * Run a callback with tenant scoping suppressed — the audited escape hatch for
     * genuinely cross-tenant work (landlord reports, backfills). Restores the prior
     * state afterward, even on exception.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function withoutScope(callable $callback): mixed
    {
        $previous = $this->suppressed;
        $this->suppressed = true;

        try {
            return $callback();
        } finally {
            $this->suppressed = $previous;
        }
    }
}
