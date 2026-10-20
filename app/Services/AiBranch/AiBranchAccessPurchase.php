<?php

namespace App\Services\AiBranch;

use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantBillingSubscription;
use App\Models\User;

/** Exact $20 price, owner-initiated checkout; existing tenants cannot be charged for included access. */
class AiBranchAccessPurchase extends AiBranchPurchase
{
    public const ADDON = AiBranchOffer::ADDON;

    public const MONTHLY_CENTS = 2000;

    public function preview(Tenant $tenant, User $operator): array
    {
        abort_unless(config('ai_branch.standard_enabled') && ! app(AiFictionalPilot::class)->reserved($tenant), 404);
        app(AiBranchAccess::class)->member($tenant, $operator);
        abort_unless($operator->hasVerifiedEmail(), 403);
        abort_if((config('ai_branch.existing_tenants')[$tenant->id] ?? null) === $tenant->slug, 409, 'AI access is included for this existing workspace.');
        abort_if(TenantAccessAddon::where('tenant_id', $tenant->id)->where('enabled', true)->where('addon_key', 'like', 'ai_branch_%')->exists(), 409, 'Review the existing AI subscription before another purchase.');
        abort_if(TenantBillingSubscription::where('tenant_id', $tenant->id)->where('purchase_key', 'like', 'addon.ai_branch_%')
            ->whereNotIn('status', ['canceled', 'incomplete_expired'])->exists(), 409, 'Review the existing AI subscription before another purchase.');
        abort_unless(config('module_catalog.addons.'.self::ADDON.'.pricing.recurring_price_cents') === self::MONTHLY_CENTS
            && config('commercial.billing_readiness.lifecycle_mutations_enabled'), 503, 'AI access billing is not ready.');
        app(AiBranchProvider::class)->liveSettings();

        return ['tenant_id' => (int) $tenant->id, 'tenant_slug' => $tenant->slug, 'addon_key' => self::ADDON,
            'currency' => 'USD', 'monthly_cents' => self::MONTHLY_CENTS, 'setup_cents' => 0, 'base_service_included' => false];
    }
}
