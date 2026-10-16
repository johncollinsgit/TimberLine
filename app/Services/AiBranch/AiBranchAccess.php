<?php

namespace App\Services\AiBranch;

use App\Models\AiBranchAccount;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;

class AiBranchAccess
{
    public function __construct(private TenantModuleAccessResolver $modules) {}

    public function authorize(Tenant $tenant, User $user, bool $optedIn = true): array
    {
        abort_unless(config('ai_branch.enabled') && in_array((int) $tenant->id, config('ai_branch.tenant_ids', []), true), 404);
        $this->member($tenant, $user);
        abort_unless(app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'ai_branch'), 403);
        $addons = TenantAccessAddon::query()->where('tenant_id', $tenant->id)->where('enabled', true)
            ->where('source', 'stripe_fulfillment')->whereIn('addon_key', ['ai_branch_starter', 'ai_branch_growth', 'ai_branch_pro'])
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->get();
        abort_unless($addons->count() === 1, 403, 'One confirmed AI subscription is required.');
        $tier = config('module_catalog.addons.'.$addons->first()->addon_key);
        // The verified subscription ledger blocks failed-payment access even
        // before commercial fulfillment removes the corresponding add-on.
        $subscriptions = \App\Models\TenantBillingSubscription::query()->where('tenant_id', $tenant->id)
            ->where('provider', 'stripe')->where('purchase_key', $tier['purchase_key'])
            ->where('status', 'active')->whereNull('ended_at')
            ->where(fn ($q) => $q->where('current_period_ends_at', '>', now())
                ->orWhere(fn ($pending) => $pending->whereNull('current_period_ends_at')->where('updated_at', '>', now()->subDay())))
            ->count();
        abort_unless($subscriptions === 1, 403, 'A current paid AI subscription is required.');
        if ($optedIn) {
            abort_unless(AiBranchAccount::query()->where('tenant_id', $tenant->id)->where('enabled', true)->exists(), 403, 'An owner must enable AI for this workspace.');
        }

        return $tier;
    }

    public function member(Tenant $tenant, User $user): void
    {
        $membership = $user->tenants()->whereKey($tenant->id)->wherePivot('membership_active', true)->first();
        abort_unless($user->is_active !== false && $membership && in_array($membership->pivot->role, ['owner', 'tenant_owner', 'admin'], true), 403);
    }

    public function visible(?Tenant $tenant, User $user): bool
    {
        if (! $tenant) {
            return false;
        }
        try {
            $this->authorize($tenant, $user, false);

            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            return false;
        }
    }
}
