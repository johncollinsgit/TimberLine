<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfills tenant_id on the flagship operational tables that were created before
 * multi-tenancy and still carry NULL owners (orders, marketing_profiles).
 *
 * WHY THIS IS SAFE: today the only operational/marketing tenant is the flagship
 * (Modern Forestry) — no other tenant has orders or marketing profiles — so every
 * NULL-owned row provably belongs to it. We only touch rows where tenant_id IS
 * NULL, never re-owning correctly-tagged rows. Idempotent.
 *
 * WHY IT MATTERS: with tenant_id populated, (a) the existing
 * EnsureTenantAccess::assertRouteModelTenantAccess guard no longer 404s flagship
 * rows for having a NULL owner, and (b) the tenant-scoped IDOR fixes at the
 * pouring/shipping/candle-cash sites become behavior-preserving for the flagship
 * (a member of tenant N sees all of tenant N's rows) while blocking cross-tenant
 * access. See docs/architecture/module-standardization-and-readiness-2026-07-07.md.
 */
return new class extends Migration
{
    /**
     * Tables to backfill. Kept intentionally narrow: exactly the tables whose
     * queries the paired IDOR fixes scope. Other operational tables (markets,
     * events, pouring, retail) are handled separately when their modules adopt
     * tenant ownership.
     */
    private const TABLES = ['orders', 'marketing_profiles'];

    public function up(): void
    {
        $flagshipTenantId = $this->resolveFlagshipTenantId();

        if ($flagshipTenantId === null) {
            // Fresh install / test DB with no tenants yet — nothing to backfill.
            return;
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            DB::table($table)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $flagshipTenantId]);
        }
    }

    public function down(): void
    {
        // Non-reversible by design: we cannot know which rows were NULL beforehand,
        // and re-nulling owners would REINTRODUCE the cross-tenant leak. No-op.
    }

    private function resolveFlagshipTenantId(): ?int
    {
        if (! Schema::hasTable('tenants')) {
            return null;
        }

        $slug = (string) config('tenancy.auth.flagship_tenant_slug', 'modern-forestry');

        $bySlug = DB::table('tenants')->where('slug', $slug)->value('id');
        if ($bySlug !== null) {
            return (int) $bySlug;
        }

        // Fallback: the oldest tenant is the flagship.
        $oldest = DB::table('tenants')->orderBy('id')->value('id');

        return $oldest !== null ? (int) $oldest : null;
    }
};
