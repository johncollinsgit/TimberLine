<?php

namespace App\Services\Billing;

use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingSubscription;
use App\Models\TenantCommercialOverride;
use Illuminate\Support\Facades\Schema;

class SelfServicePurchaseEligibility
{
    public function paidForTenant(Tenant|int $tenant): bool
    {
        if (! $this->hasVerifiedPurchase($tenant)) {
            return false;
        }
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : $tenant;
        $mapping = TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('billing_mapping');
        $intent = (array) data_get($mapping, 'self_service_purchase', []);
        if (($intent['status'] ?? '') !== 'paid') {
            return false;
        }
        $subscription = TenantBillingSubscription::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('provider', 'stripe')
            ->where('purchase_key', 'offer.standard')->where('provider_subscription_reference', $intent['subscription_id'])
            ->where('status', 'active')->where('current_period_ends_at', '>', now())->first();

        return $subscription && ($subscription->metadata['self_service_purchase_id'] ?? '') === $intent['id'];
    }

    /** Historical purchase evidence for preserving an already published snapshot. */
    public function hasVerifiedPurchase(Tenant|int $tenant): bool
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : $tenant;
        if (! Schema::hasTable('tenant_access_profiles') || ! Schema::hasTable('tenant_billing_fulfillments')
            || ! Schema::hasTable('tenant_billing_receipts') || ! Schema::hasTable('tenant_billing_subscriptions')
            || ! Schema::hasTable('tenant_commercial_overrides') || ! Schema::hasTable('stripe_webhook_events')) {
            return false;
        }
        $profile = TenantAccessProfile::query()->where('tenant_id', $tenantId)->first();
        if (($profile?->metadata['self_service_registration'] ?? false) !== true) {
            return false;
        }
        $mapping = TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('billing_mapping');
        $intent = (array) data_get($mapping, 'self_service_purchase', []);
        if (! in_array($intent['status'] ?? '', ['paid', 'suspended'], true) || empty($intent['id']) || empty($intent['fulfillment_id']) || empty($intent['session_id']) || empty($intent['subscription_id'])) {
            return false;
        }
        $fulfillment = TenantBillingFulfillment::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('provider', 'stripe')
            ->where('desired_plan_key', 'standard')->whereIn('status', ['applied', 'suspended'])->whereNotNull('applied_at')->find($intent['fulfillment_id']);
        if (! $fulfillment || $fulfillment->state_hash !== hash('sha256', $intent['id'])
            || $fulfillment->provider_checkout_session_id !== $intent['session_id'] || $fulfillment->provider_subscription_reference !== $intent['subscription_id']) {
            return false;
        }
        $receipt = TenantBillingReceipt::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('provider', 'stripe')
            ->where('provider_receipt_id', $intent['invoice_id'] ?? '')->where('provider_subscription_id', $intent['subscription_id'])
            ->where('status', 'paid')->whereNotNull('paid_at')->first();
        if (! $receipt || ($receipt->metadata['self_service_purchase_id'] ?? '') !== $intent['id']
            || ($receipt->metadata['account_id'] ?? '') !== ($intent['account_id'] ?? null)
            || ($receipt->metadata['livemode'] ?? null) !== ($intent['livemode'] ?? null)
            || ($receipt->metadata['quote_hash'] ?? null) !== data_get($intent, 'quote.quote_hash')
            || (int) $receipt->subtotal_amount_cents !== (int) data_get($intent, 'quote.subtotal_cents', -1)) {
            return false;
        }

        return StripeWebhookEvent::query()->where('event_id', $receipt->source_event_id)->where('tenant_id', $tenantId)
            ->where('status', 'processed_self_service')->where('livemode', $intent['livemode'])->whereNotNull('processed_at')->exists();
    }
}
