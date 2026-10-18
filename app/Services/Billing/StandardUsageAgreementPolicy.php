<?php

namespace App\Services\Billing;

use App\Models\Agreement;
use App\Models\SubscriptionAuthorization;

/** Resolve frozen, accepted rates only after the normal paid activation gates. */
class StandardUsageAgreementPolicy
{
    public function forTenant(int $tenantId): ?array
    {
        $authorization = SubscriptionAuthorization::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('status', 'provider_verified')->whereHas('agreement', fn ($q) => $q->where('tenant_id', $tenantId)->where('template_key', Agreement::TEMPLATE_STANDARD_SERVICES))
            ->with(['agreement', 'acceptance', 'version'])->latest('id')->first();
        if (! $authorization || (int) $authorization->version?->agreement_id !== (int) $authorization->agreement_id
            || (int) $authorization->acceptance?->tenant_id !== $tenantId
            || (int) $authorization->acceptance?->agreement_id !== (int) $authorization->agreement_id
            || ! app(AgreementBillingActivationGuard::class)->evaluate($authorization)['allowed']) {
            return null;
        }
        $policy = data_get($authorization->version->subscription_payload, 'usage_policy');
        if (! is_array($policy) || ($policy['billing_mode'] ?? null) !== 'prepaid_credit' || blank($policy['version'] ?? null)) {
            return null;
        }
        foreach (['email', 'sms', 'mms'] as $channel) {
            if (! is_int(data_get($policy, 'overage_rates_micros.'.$channel)) || data_get($policy, 'overage_rates_micros.'.$channel) < 0
                || ! is_int(data_get($policy, 'included_units.'.$channel)) || data_get($policy, 'included_units.'.$channel) < 0) {
                return null;
            }
        }

        return [...$policy, 'pricing_version' => $policy['version']];
    }
}
