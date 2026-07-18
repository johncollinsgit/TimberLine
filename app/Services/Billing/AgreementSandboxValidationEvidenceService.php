<?php

namespace App\Services\Billing;

use App\Models\Agreement;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingOrder;
use App\Services\Tenancy\LandlordOperatorActionAuditService;

class AgreementSandboxValidationEvidenceService
{
    public function __construct(protected LandlordOperatorActionAuditService $audit) {}

    public function recordIfComplete(TenantBillingOrder $order, string $eventId, string $eventType): ?TenantBillingFulfillment
    {
        $order->loadMissing(['agreement', 'authorization', 'receipts']);
        if ($order->agreement?->agreement_type !== Agreement::TYPE_SANDBOX_VALIDATION
            || data_get($order->metadata, 'validation_only') !== true
            || $order->status !== 'paid'
            || $order->authorization?->status !== 'provider_verified'
            || ! filled($order->provider_subscription_id)
            || ! $order->receipts->contains(fn ($receipt): bool => $receipt->status === 'paid')) {
            return null;
        }

        $stateHash = hash('sha256', 'agreement-sandbox-validation:'.(int) $order->id.':'.(string) data_get($order->metadata, 'content_hash'));
        $fulfillment = TenantBillingFulfillment::query()->firstOrCreate(
            [
                'tenant_id' => (int) $order->tenant_id,
                'provider' => 'stripe',
                'state_hash' => $stateHash,
            ],
            [
                'provider_customer_reference' => $order->provider_customer_id,
                'provider_subscription_reference' => $order->provider_subscription_id,
                'provider_checkout_session_id' => $order->provider_checkout_session_id,
                'desired_plan_key' => 'validation_only',
                'desired_addon_keys' => [],
                'desired_operating_mode' => 'validation_only',
                'status' => 'noop',
                'message' => 'Sandbox agreement payment evidence verified; no tenant access or commercial state was changed.',
                'source_event_id' => $eventId,
                'source_event_type' => $eventType,
                'triggered_by' => 'webhook_validation_only',
                'attempted_at' => now(),
            ]
        );

        if ($fulfillment->wasRecentlyCreated) {
            $this->audit->record((int) $order->tenant_id, null, 'tenant_billing.sandbox_validation.complete', status: 'noop', targetType: 'tenant_billing_order', targetId: $order->id, context: [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'fulfillment_id' => (int) $fulfillment->id,
                'validation_only' => true,
            ]);
        }

        return $fulfillment;
    }
}
