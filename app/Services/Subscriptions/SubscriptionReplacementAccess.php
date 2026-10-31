<?php

namespace App\Services\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionPilotMember;
use App\Models\User;

class SubscriptionReplacementAccess
{
    public function owner(int $tenantId, User $actor, int $memberId, string $gate): void
    {
        abort_unless(in_array($gate, ['setup', 'servicing', 'collection', 'handoff'], true), 422);
        app(SubscriptionAccessService::class)->assertManager($tenantId, $actor);
        $membership = $actor->tenants()->whereKey($tenantId)->first();
        abort_unless($actor->email_verified_at && $membership && in_array(strtolower($membership->pivot->role), ['owner', 'tenant_owner', 'admin'], true), 403, 'An active verified workspace owner or admin is required.');
        abort_unless(config('subscriptions.replacement_'.$gate.'_enabled') === true
            && in_array($tenantId, config('subscriptions.replacement_tenant_ids', []), true)
            && in_array($memberId, config('subscriptions.replacement_member_ids', []), true), 423, 'This member is outside the controlled replacement rollout.');
    }

    public function member(SubscriptionBillingAgreement $agreement): SubscriptionPilotMember
    {
        $terms = $agreement->terms;
        $member = SubscriptionPilotMember::query()->forTenantId($agreement->tenant_id)->findOrFail($agreement->member_id);
        $store = ShopifyStore::query()->forTenantId($agreement->tenant_id)->findOrFail($member->shopify_store_id);
        abort_unless(($terms['member_id'] ?? null) === $member->id && ($terms['shopify_store_id'] ?? null) === $store->id
            && ($terms['shop_domain'] ?? null) === $store->shop_domain && ($member->details['shop_domain'] ?? null) === $store->shop_domain
            && ($terms['shopify_customer_id'] ?? null) === $member->shopify_customer_id
            && ($terms['recharge_customer_id'] ?? null) === $member->recharge_customer_id
            && ($terms['currency'] ?? null) === $member->currency, 409, 'The original subscription customer or store changed.');

        return $member;
    }
}
