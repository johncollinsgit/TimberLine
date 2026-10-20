<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Identity;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Membership;
use App\Models\Marketplace\Setting;
use App\Models\Marketplace\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

class MarketplaceAccess
{
    public function publicHost(string $host): bool
    {
        $verified = strtolower(trim((string) config('marketplace.host')));

        if (! config('marketplace.enabled') || $verified === '' || ! hash_equals($verified, strtolower($host))) {
            return false;
        }

        return config('marketplace.checkout_provider') !== 'everbranch' || Store::where('host', $verified)->whereNotNull('verified_at')->exists();
    }

    public function identity(Request $request): ?Identity
    {
        return Identity::query()->whereKey((int) $request->session()->get('marketplace_identity', 0))->where('active', true)->first();
    }

    public function maker(Request $request, int $id): Maker
    {
        $identity = $this->identity($request);
        abort_unless($identity && Membership::query()->where('identity_id', $identity->id)->where('maker_id', $id)->where('active', true)->where('role', 'owner')->exists(), 403);

        return Maker::query()->findOrFail($id);
    }

    public function operator(?User $user): bool
    {
        if (! $user || ! $user->is_active || ! $user->email_verified_at) {
            return false;
        }
        if (config('marketplace.checkout_provider') === 'everbranch') {
            $store = $this->store();

            return $store && $user->tenants()->whereKey($store->tenant_id)->wherePivot('membership_active', true)->wherePivotIn('role', ['owner', 'tenant_owner', 'admin'])->exists();
        }

        return in_array((int) $user->id, array_map('intval', config('marketplace.operator_user_ids', [])), true);
    }

    public function store(): ?Store
    {
        return Store::query()->where('host', strtolower((string) config('marketplace.host')))->whereNotNull('verified_at')->with('tenant')->first();
    }

    public function assertWorkspaceManager(User $user, int $tenantId): Tenant
    {
        abort_unless($user->is_active && $user->email_verified_at, 403);

        return $user->tenants()->where('tenants.id', $tenantId)->wherePivot('membership_active', true)->wherePivotIn('role', ['owner', 'admin'])->firstOrFail();
    }

    public function settings(): Setting
    {
        return Setting::query()->firstOrCreate(['id' => 1], ['checkout_enabled' => false, 'readiness' => []]);
    }
}
