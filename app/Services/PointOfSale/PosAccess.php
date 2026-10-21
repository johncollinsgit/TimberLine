<?php

namespace App\Services\PointOfSale;

use App\Models\PosRegister;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteFulfillmentLocation;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\DB;

/** Local simulation authorization. No configuration can enable this in production. */
class PosAccess
{
    private const MANAGERS = ['owner', 'tenant_owner', 'admin', 'manager'];

    public function authorize(Tenant $tenant, User $actor, bool $manager = false): void
    {
        abort_unless(app()->environment(['local', 'testing']) && config('pos.enabled')
            && in_array((int) $tenant->id, (array) config('pos.tenant_ids', []), true), 404);
        // Shared current reads see revocation after a mutex wait without taking
        // exclusive parent locks that could cycle with native order foreign keys.
        $currentActor = User::query()->whereKey($actor->id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first();
        abort_unless($currentActor && $currentActor->is_active && $currentActor->hasVerifiedEmail()
            && app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'managed_website'), 403);
        $roles = $manager ? self::MANAGERS : [...self::MANAGERS, 'member'];
        $membership = $this->membership($tenant, $actor);
        abort_unless($membership && $membership->membership_active && in_array($membership->role, $roles, true), 403);
    }

    private function membership(Tenant $tenant, User $actor): ?object
    {
        return DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $actor->id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first();
    }

    public function register(Tenant $tenant, User $actor, int $id, bool $manager = false): PosRegister
    {
        $this->authorize($tenant, $actor, $manager);
        $register = PosRegister::query()->forTenantId($tenant->id)->where('active', true)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->findOrFail($id);
        $membership = $this->membership($tenant, $actor);
        if (! in_array((string) $membership->role, self::MANAGERS, true)) {
            abort_unless(in_array((int) $actor->id, (array) $register->cashier_user_ids, true), 403);
        }
        abort_unless(TenantSite::query()->forTenantId($tenant->id)->whereKey($register->tenant_site_id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first(), 404);
        abort_unless(WebsiteFulfillmentLocation::query()->forTenantId($tenant->id)
            ->where('tenant_site_id', $register->tenant_site_id)->where('active', true)
            ->whereKey($register->website_fulfillment_location_id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first(), 404);
        abort_unless(preg_match('/^sim_[A-Za-z0-9_-]{1,116}$/', (string) $register->reader_id)
            && preg_match('/^sim_[A-Za-z0-9_-]{1,116}$/', (string) $register->merchant_id)
            && $register->currency === 'usd' && $register->tax_rate_bps >= 0 && $register->tax_rate_bps <= 10000
            && trim((string) $register->tax_basis) !== '' && $register->tax_confirmed_at
            && ! $register->tax_confirmed_at->isFuture(), 422, 'This simulated register needs a valid location, reader and confirmed tax policy.');

        return $register;
    }
}
