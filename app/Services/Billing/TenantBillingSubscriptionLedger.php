<?php

namespace App\Services\Billing;

use App\Models\TenantBillingSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class TenantBillingSubscriptionLedger
{
    /**
     * Normalize a verified Stripe lifecycle event into tenant-scoped purchases.
     * The webhook receipt remains the idempotency boundary for this write.
     *
     * @param  array<string,mixed>  $object
     * @param  array<string,mixed>  $metadata
     */
    public function recordStripeEvent(
        int $tenantId,
        string $eventId,
        string $eventType,
        array $object,
        array $metadata,
        string $customerReference,
        string $subscriptionReference
    ): void {
        if ($tenantId < 1 || $subscriptionReference === '' || ! Schema::hasTable('tenant_billing_subscriptions')) {
            return;
        }

        $purchaseKeys = $this->purchaseKeys($metadata);
        $attributes = [
            'tenant_id' => $tenantId,
            'provider_customer_reference' => $customerReference !== '' ? $customerReference : null,
            'status' => $this->status($eventType, $object),
            'started_at' => $this->timestamp($object['start_date'] ?? null),
            'current_period_ends_at' => $this->timestamp($object['current_period_end'] ?? null),
            'canceled_at' => $eventType === 'customer.subscription.deleted'
                ? now()
                : $this->timestamp($object['canceled_at'] ?? null),
            'ended_at' => $this->timestamp($object['ended_at'] ?? null),
            'last_event_id' => $eventId,
            'last_event_type' => $eventType,
            'metadata' => [
                'plan_key' => $this->planKey($metadata),
                'addon_keys' => $this->addonKeys($metadata),
            ],
        ];

        if ($purchaseKeys === []) {
            TenantBillingSubscription::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('provider', 'stripe')
                ->where('provider_subscription_reference', $subscriptionReference)
                ->update(array_filter($attributes, static fn (mixed $value): bool => $value !== null));

            return;
        }

        foreach ($purchaseKeys as $purchaseKey) {
            TenantBillingSubscription::withoutGlobalScopes()->updateOrCreate([
                'provider' => 'stripe',
                'provider_subscription_reference' => $subscriptionReference,
                'purchase_key' => $purchaseKey,
            ], $attributes);
        }
    }

    /** @param array<string,mixed> $metadata @return array<int,string> */
    protected function purchaseKeys(array $metadata): array
    {
        $keys = [];
        $planKey = $this->planKey($metadata);
        if ($planKey !== null) {
            $keys[] = (string) data_get(config('module_catalog.plans.'.$planKey, []), 'purchase_key', 'plan.'.$planKey);
        }

        foreach ($this->addonKeys($metadata) as $addonKey) {
            $keys[] = (string) data_get(config('module_catalog.addons.'.$addonKey, []), 'purchase_key', 'addon.'.$addonKey);
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /** @param array<string,mixed> $metadata */
    protected function planKey(array $metadata): ?string
    {
        $key = strtolower(trim((string) ($metadata['checkout_plan_key'] ?? $metadata['preferred_plan_key'] ?? '')));

        return $key !== '' && array_key_exists($key, (array) config('module_catalog.plans', [])) ? $key : null;
    }

    /** @param array<string,mixed> $metadata @return array<int,string> */
    protected function addonKeys(array $metadata): array
    {
        $value = $metadata['checkout_addons_interest'] ?? $metadata['addons_interest'] ?? [];
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            $value = json_decode($value, true) ?: [];
        } elseif (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $key): string => strtolower(trim((string) $key)),
            is_array($value) ? $value : []
        ), static fn (string $key): bool => array_key_exists($key, (array) config('module_catalog.addons', [])))));
    }

    /** @param array<string,mixed> $object */
    protected function status(string $eventType, array $object): string
    {
        if ($eventType === 'customer.subscription.deleted') {
            return 'canceled';
        }

        if ($eventType === 'invoice.payment_failed' || $eventType === 'checkout.session.async_payment_failed') {
            return 'past_due';
        }

        $status = strtolower(trim((string) ($object['status'] ?? '')));
        if ($status !== '') {
            return $status;
        }

        return in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'invoice.payment_succeeded'], true)
            ? 'active'
            : 'pending';
    }

    protected function timestamp(mixed $value): ?Carbon
    {
        return is_numeric($value) && (int) $value > 0 ? Carbon::createFromTimestamp((int) $value) : null;
    }
}
