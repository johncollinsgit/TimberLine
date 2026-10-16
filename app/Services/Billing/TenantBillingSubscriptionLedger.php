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
        string $subscriptionReference,
        ?int $eventCreatedAt = null
    ): void {
        if ($tenantId < 1 || $subscriptionReference === '' || ! Schema::hasTable('tenant_billing_subscriptions')) {
            return;
        }

        $existing = TenantBillingSubscription::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('provider', 'stripe')->where('provider_subscription_reference', $subscriptionReference)->get();
        $lastCreated = (int) $existing->max(fn ($row) => data_get($row->metadata, 'stripe_event_created_at', 0));
        $newStatus = $this->status($eventType, $object);
        // A paid invoice/Checkout can arrive after cancellation or a newer
        // failed-payment event. It must not reopen access to that subscription.
        if (($eventCreatedAt !== null && $eventCreatedAt < $lastCreated)
            || ($newStatus !== 'canceled' && $existing->contains(fn ($row) => $row->status === 'canceled' || $row->ended_at !== null))
            || ($newStatus === 'active' && $eventCreatedAt !== null && $eventCreatedAt <= $lastCreated && $existing->contains(fn ($row) => in_array($row->status, ['past_due', 'unpaid'], true)))) {
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
                'stripe_event_created_at' => $eventCreatedAt ?? $lastCreated,
            ],
        ];
        // Checkout and invoice events need not repeat the subscription period.
        // Preserve previously verified coverage instead of replacing it with null.
        if ($attributes['current_period_ends_at'] === null) {
            $items = (array) data_get($object, 'items.data', []);
            $itemEnd = count($items) === 1 && ! data_get($object, 'items.has_more', false)
                ? $this->timestamp(data_get($items, '0.current_period_end')) : null;
            if ($itemEnd) {
                $attributes['current_period_ends_at'] = $itemEnd;
            } else {
                unset($attributes['current_period_ends_at']);
            }
        }

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

        if (in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return in_array(strtolower(trim((string) ($object['payment_status'] ?? ''))), ['paid', 'no_payment_required'], true) ? 'active' : 'pending';
        }
        if (in_array($eventType, ['invoice.paid', 'invoice.payment_succeeded'], true)) {
            return 'active';
        }

        $status = strtolower(trim((string) ($object['status'] ?? '')));
        if ($status !== '') {
            return $status;
        }

        return in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'invoice.paid', 'invoice.payment_succeeded'], true)
            ? 'active'
            : 'pending';
    }

    protected function timestamp(mixed $value): ?Carbon
    {
        return is_numeric($value) && (int) $value > 0 ? Carbon::createFromTimestamp((int) $value) : null;
    }
}
