<?php

namespace App\Services\Subscriptions;

use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionPilotMember;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Existing verified Shopify identity selects its original agreement, never a merged profile or email. */
class SubscriptionReplacementCustomerAccess
{
    public function agreement(ModernForestryMobileCustomerSession $session): ?SubscriptionBillingAgreement
    {
        if (($session->identity['source_type'] ?? null) !== 'shopify_customer' || ! Schema::hasTable('subscription_billing_agreements')) {
            return null;
        }
        $id = (string) ($session->identity['shopify_customer_id'] ?? '');
        if (preg_match('#\Agid://shopify/Customer/([1-9][0-9]*)\z#', $id, $matches)) {
            $id = $matches[1];
        }
        if (! preg_match('/\A[1-9][0-9]*\z/', $id)) {
            return null;
        }
        $tenantId = (int) $session->profile->tenant_id;
        // The existing Modern Forestry session validator authenticates this retail store.
        $store = ShopifyStore::query()->forTenantId($tenantId)->where('store_key', 'retail')->first();
        if (! $store) {
            return null;
        }
        $members = SubscriptionPilotMember::query()->forTenantId($tenantId)->where('shopify_store_id', $store->id)
            ->where('shopify_customer_id', $id)->where('billing_owner', 'everbranch')->pluck('id');
        $rows = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->whereIn('member_id', $members)
            ->whereNotNull('activated_at')->whereNotNull('method_verified_at')->get();
        abort_if($rows->count() > 1, 409, 'More than one subscription needs individual selection.');
        $row = $rows->first();
        if ($row) {
            $member = app(SubscriptionReplacementAccess::class)->member($row);
            abort_unless($row->terms['shopify_customer_id'] === $id && $member->shopify_store_id === $store->id
                && $row->terms['shop_domain'] === $store->shop_domain, 409, 'The original subscription identity changed.');
        }

        return $row;
    }

    public function assertOwner(ModernForestryMobileCustomerSession $session, SubscriptionBillingAgreement $row): void
    {
        $owned = $this->agreement($session);
        abort_unless($owned && $owned->id === $row->id && $owned->tenant_id === $row->tenant_id, 404);
    }

    public function payload(ModernForestryMobileCustomerSession $session, array $legacy): array
    {
        $row = $this->agreement($session);
        if (! $row) {
            return $legacy;
        }
        $terms = $row->terms;
        $community = app(SubscriptionReplacementCommunityService::class)->payload($session);

        return array_replace($legacy, collect($community)->except('can_vote')->all(), ['eligible' => $community['eligible'], 'status' => $row->status,
            'message' => $row->status === 'cancelled' ? 'Future subscription billing is cancelled.'
                : ($row->status === 'paused' ? 'Your subscription is paused.' : $terms['currency'].' '.SubscriptionPilotShopifyBridge::decimal($terms['amount_cents']).' every '.$terms['billing_months'].' month(s).'),
            'contract' => null, 'commitment' => null, 'payment_method' => null, 'action_mode' => 'everbranch_replacement',
            'action_menus' => ['swap_options' => [], 'pause_duration_options' => [$terms['billing_months'], 2 * $terms['billing_months']]],
            'cancel_prompt' => 'Cancel future subscription billing. Payments or orders already started may need support review.',
            'actions' => ['can_vote' => $community['can_vote'], 'can_pause' => false, 'can_cancel' => false, 'can_update_address' => false,
                'can_update_card' => false, 'can_swap_to_active_16oz_scent' => false, 'can_manage_subscription' => true],
            'shipping_address' => ['first_name' => $terms['shipping_address']['firstName'], 'last_name' => $terms['shipping_address']['lastName'],
                'address1' => $terms['shipping_address']['address1'], 'address2' => $terms['shipping_address']['address2'] ?? null,
                'city' => $terms['shipping_address']['city'], 'province_code' => $terms['shipping_address']['provinceCode'],
                'zip' => $terms['shipping_address']['zip'], 'country_code' => $terms['shipping_address']['countryCode'], 'phone' => $terms['shipping_address']['phone'] ?? null],
            'everbranch_subscription' => ['status' => $row->status, 'revision' => $row->revision,
                'next_billing_date' => $row->next_billing_date?->toDateString(), 'resume_on' => $row->resume_on?->toDateString(),
                'amount_cents' => $terms['amount_cents'], 'currency' => $terms['currency'], 'billing_months' => $terms['billing_months'],
                'manage_endpoint' => route('subscriptions.pilot.mobile-manage')],
        ]);
    }

    public function action(ModernForestryMobileCustomerSession $session, array $input): ?array
    {
        if (! $this->agreement($session)) {
            return null;
        }
        if (in_array($input['action'] ?? null, ['vote', 'vote_for_next_month', 'cast_vote'], true)) {
            return app(SubscriptionReplacementCommunityService::class)->vote($session, $input);
        }
        $data = \Illuminate\Support\Facades\Validator::make($input, ['action' => ['required', 'in:pause,resume,skip,cancel'],
            'request_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:12']])->validate();
        $action = app(SubscriptionBillingAgreementService::class)->customerChange($session, $data['action'], $data['request_id'], (int) $data['revision'],
            isset($data['duration_months']) ? ['duration_months' => (int) $data['duration_months']] : []);

        return ['ok' => true, 'status' => 'completed', 'action' => $action->kind, 'message' => 'Your future billing schedule has been updated.',
            'candle_club' => $this->payload($session, [])];
    }

    public function canChange(SubscriptionBillingAgreement $row, string $kind): bool
    {
        // Existing customers retain safe stop access if the merchant freezes rollout or loses the add-on.
        if (in_array($kind, ['pause', 'cancel'], true)) {
            return true;
        }

        return app(SubscriptionAccessService::class)->entitled($row->tenant_id)
            && config('subscriptions.replacement_servicing_enabled') === true
            && in_array($row->tenant_id, config('subscriptions.replacement_tenant_ids', []), true)
            && in_array($row->member_id, config('subscriptions.replacement_member_ids', []), true);
    }

    public function issue(ModernForestryMobileCustomerSession $session): string
    {
        $row = $this->agreement($session);
        abort_unless($row && $session->profile->exists, 404);
        $token = Str::random(64);
        Cache::put('subscription-replacement-access:'.hash('sha256', $token), [
            'tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'profile_id' => $session->profile->id,
            'customer_id' => $row->terms['shopify_customer_id'], 'store_id' => $row->terms['shopify_store_id'],
            'shop_domain' => $row->terms['shop_domain'], 'expires_at' => now()->addMinutes(15)->timestamp,
        ], now()->addMinutes(15));

        return route('subscriptions.replacement.customer', ['token' => $token]);
    }

    public function session(string $token): ModernForestryMobileCustomerSession
    {
        abort_unless(preg_match('/\A[A-Za-z0-9]{64}\z/', $token), 404);
        $grant = Cache::get('subscription-replacement-access:'.hash('sha256', $token));
        abort_unless(is_array($grant) && ($grant['expires_at'] ?? 0) > now()->timestamp, 404);
        $profile = MarketingProfile::query()->where('tenant_id', $grant['tenant_id'])->findOrFail($grant['profile_id']);
        $session = new ModernForestryMobileCustomerSession($profile, '', [
            'source_type' => 'shopify_customer', 'shopify_customer_id' => $grant['customer_id'],
        ]);
        $row = $this->agreement($session);
        abort_unless($row && $row->id === $grant['agreement_id'] && $row->terms['shopify_store_id'] === $grant['store_id']
            && $row->terms['shop_domain'] === $grant['shop_domain'], 404);

        return $session;
    }
}
