<?php

namespace App\Services\Billing;

use App\Models\Agreement;
use App\Models\SubscriptionAuthorization;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingSubscription;

class AgreementBillingActivationGuard
{
    /** @return array{allowed:bool,reasons:array<int,string>} */
    public function evaluate(SubscriptionAuthorization $authorization): array
    {
        $result = $this->evaluateForFulfillment($authorization);
        $reasons = $result['reasons'];
        $fulfilled = TenantBillingFulfillment::query()->forTenantId((int) $authorization->tenant_id)
            ->where('provider', $authorization->provider)
            ->where('provider_subscription_reference', $authorization->provider_subscription_id)
            ->whereIn('status', ['applied', 'noop'])
            ->exists();
        if (! $fulfilled) {
            $reasons[] = 'audited_entitlement_fulfillment_required';
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons];
    }

    /** @return array{allowed:bool,reasons:array<int,string>} */
    public function evaluateForFulfillment(SubscriptionAuthorization $authorization): array
    {
        $authorization->loadMissing(['agreement', 'acceptance']);
        $reasons = [];
        if ($authorization->agreement?->agreement_type === Agreement::TYPE_SANDBOX_VALIDATION
            || data_get($authorization->metadata, 'validation_only') === true) {
            $reasons[] = 'sandbox_validation_cannot_activate';
        }
        if (! $authorization->acceptance || ! in_array($authorization->agreement?->status, ['active', 'termination_pending'], true)) {
            $reasons[] = 'accepted_active_agreement_required';
        }
        if ((int) $authorization->agreement_version_id !== (int) $authorization->acceptance?->agreement_version_id) {
            $reasons[] = 'exact_accepted_version_required';
        }
        $approvedLanes = ['shopify' => 'shopify_app_pricing', 'stripe' => 'stripe_direct'];
        if (($approvedLanes[$authorization->provider] ?? null) !== $authorization->billing_lane) {
            $reasons[] = 'approved_provider_billing_lane_required';
        }
        if (! filled($authorization->provider_subscription_id) || $authorization->status !== 'provider_verified') {
            $reasons[] = 'verified_provider_subscription_required';
        }
        $subscriptionVerified = TenantBillingSubscription::query()->forTenantId((int) $authorization->tenant_id)
            ->where('provider', $authorization->provider)
            ->where('provider_subscription_reference', $authorization->provider_subscription_id)
            ->whereIn('status', ['active', 'trialing'])
            ->exists();
        if (! $subscriptionVerified) {
            $reasons[] = 'active_provider_ledger_subscription_required';
        }
        $order = $authorization->agreement?->billingOrders()->where('agreement_version_id', $authorization->agreement_version_id)->first();
        if (! $order || $order->status !== 'paid') {
            $reasons[] = 'settled_agreement_billing_order_required';
        }
        if ((int) $authorization->promotional_cycles > 0 && data_get($order?->metadata, 'schedule_status') !== 'configured') {
            $reasons[] = 'configured_promotional_schedule_required';
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons];
    }
}
