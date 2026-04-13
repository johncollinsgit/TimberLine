<?php

namespace App\Services\Billing;

use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantCommercialOverride;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StripeWebhookIngestService
{
    public function __construct(
        protected LandlordOperatorActionAuditService $auditService,
    ) {
    }

    /**
     * @return array{ok:bool,status_code:int,message:string}
     */
    public function ingest(string $payload, string $signatureHeader): array
    {
        $secret = trim((string) config('services.stripe.webhook_secret', ''));
        if ($secret === '') {
            Log::warning('stripe.webhook.secret_missing');

            return ['ok' => false, 'status_code' => 400, 'message' => 'Webhook secret not configured.'];
        }

        if (! $this->verifySignature($payload, $signatureHeader, $secret)) {
            Log::warning('stripe.webhook.signature_invalid');

            return ['ok' => false, 'status_code' => 400, 'message' => 'Invalid signature.'];
        }

        $event = json_decode($payload, true);
        if (! is_array($event)) {
            Log::warning('stripe.webhook.payload_invalid_json');

            return ['ok' => false, 'status_code' => 400, 'message' => 'Invalid payload.'];
        }

        $eventId = trim((string) ($event['id'] ?? ''));
        $eventType = trim((string) ($event['type'] ?? ''));
        $livemode = (bool) ($event['livemode'] ?? false);

        if ($eventId === '' || $eventType === '') {
            Log::warning('stripe.webhook.payload_missing_fields', ['event_id' => $eventId, 'type' => $eventType]);

            return ['ok' => false, 'status_code' => 400, 'message' => 'Invalid event envelope.'];
        }

        if (! $this->supportsEventType($eventType)) {
            $this->storeReceiptOnce($eventId, $eventType, $livemode, null, null, $event, 'ignored_unsupported');

            return ['ok' => true, 'status_code' => 200, 'message' => 'ignored'];
        }

        $object = is_array(data_get($event, 'data.object')) ? (array) data_get($event, 'data.object') : [];
        if ($object === []) {
            $this->storeReceiptOnce($eventId, $eventType, $livemode, null, null, $event, 'ignored_malformed');

            return ['ok' => true, 'status_code' => 200, 'message' => 'ignored'];
        }

        $metadata = is_array($object['metadata'] ?? null) ? (array) $object['metadata'] : [];
        $tenantId = (int) ($metadata['tenant_id'] ?? 0);
        if ($tenantId < 1) {
            Log::warning('stripe.webhook.tenant_metadata_missing', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'object' => (string) ($object['object'] ?? ''),
                'object_id' => (string) ($object['id'] ?? ''),
            ]);

            $this->storeReceiptOnce($eventId, $eventType, $livemode, null, null, $event, 'ignored_missing_tenant');

            return ['ok' => true, 'status_code' => 200, 'message' => 'ignored'];
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            Log::warning('stripe.webhook.tenant_missing', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'tenant_id' => $tenantId,
            ]);

            $this->storeReceiptOnce($eventId, $eventType, $livemode, $tenantId, null, $event, 'ignored_tenant_missing');

            return ['ok' => true, 'status_code' => 200, 'message' => 'ignored'];
        }

        $checkoutSessionId = $this->extractCheckoutSessionId($eventType, $object);
        $stripeCustomer = trim((string) ($object['customer'] ?? ''));
        $stripeSubscription = trim((string) ($object['subscription'] ?? ''));

        if ($eventType !== 'checkout.session.completed') {
            $stripeCustomer = $stripeCustomer !== '' ? $stripeCustomer : trim((string) ($metadata['stripe_customer_reference'] ?? ''));
            $stripeSubscription = $stripeSubscription !== '' ? $stripeSubscription : trim((string) ($metadata['stripe_subscription_reference'] ?? ''));
        }

        $eventCreatedAt = is_numeric($event['created'] ?? null)
            ? Carbon::createFromTimestamp((int) $event['created'])->toIso8601String()
            : now()->toIso8601String();

        $result = DB::transaction(function () use (
            $eventId,
            $eventType,
            $livemode,
            $tenantId,
            $checkoutSessionId,
            $stripeCustomer,
            $stripeSubscription,
            $eventCreatedAt,
            $event
        ): array {
            $receipt = $this->storeReceiptOnce(
                $eventId,
                $eventType,
                $livemode,
                $tenantId,
                $checkoutSessionId,
                $event,
                'received'
            );

        if (($receipt['status'] ?? '') === 'duplicate') {
                return ['status' => 'duplicate', 'tenant_id' => $tenantId];
            }

            /** @var StripeWebhookEvent $row */
            $row = $receipt['row'];

            $override = TenantCommercialOverride::query()->where('tenant_id', $tenantId)->first();
            $before = $override ? $this->snapshotOverride($override) : null;

            $override = $override ?: new TenantCommercialOverride(['tenant_id' => $tenantId]);
            $billingMapping = is_array($override->billing_mapping ?? null) ? (array) $override->billing_mapping : [];
            $stripe = is_array($billingMapping['stripe'] ?? null) ? (array) $billingMapping['stripe'] : [];

            $changed = false;

            if ($stripeCustomer !== '' && ($stripe['customer_reference'] ?? '') !== $stripeCustomer) {
                $stripe['customer_reference'] = $stripeCustomer;
                $changed = true;
            }

            if ($stripeSubscription !== '' && ($stripe['subscription_reference'] ?? '') !== $stripeSubscription) {
                $stripe['subscription_reference'] = $stripeSubscription;
                $changed = true;
            }

            if ($checkoutSessionId !== '' && ($stripe['checkout_session_id'] ?? '') !== $checkoutSessionId) {
                $stripe['checkout_session_id'] = $checkoutSessionId;
                $changed = true;
            }

            if (($stripe['checkout_completed_at'] ?? '') === '' && $checkoutSessionId !== '') {
                $stripe['checkout_completed_at'] = $eventCreatedAt;
                $changed = true;
            }

            $stripe['last_webhook_event_id'] = $eventId;
            $stripe['last_webhook_event_type'] = $eventType;
            $stripe['last_webhook_received_at'] = now()->toIso8601String();

            $billingMapping['stripe'] = $stripe;
            $override->billing_mapping = $billingMapping;

            if ($changed || ! $override->exists) {
                $override->save();
            }

            $after = $this->snapshotOverride($override);

            $row->forceFill([
                'status' => $changed ? 'processed' : 'processed_no_change',
                'tenant_id' => $tenantId,
                'checkout_session_id' => $checkoutSessionId !== '' ? $checkoutSessionId : $row->checkout_session_id,
                'processed_at' => now(),
            ])->save();

            $this->auditService->record(
                tenantId: $tenantId,
                actorUserId: null,
                actionType: 'tenant_billing.stripe_webhook_confirmation',
                status: $changed ? 'success' : 'noop',
                targetType: 'tenant_commercial_override',
                targetId: $override->id,
                context: [
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'checkout_session_id' => $checkoutSessionId !== '' ? $checkoutSessionId : null,
                    'stripe_customer_reference' => $stripeCustomer !== '' ? $stripeCustomer : null,
                    'stripe_subscription_reference' => $stripeSubscription !== '' ? $stripeSubscription : null,
                ],
                beforeState: $before,
                afterState: $after,
            );

            return [
                'status' => $changed ? 'processed' : 'processed_no_change',
                'tenant_id' => $tenantId,
            ];
        });

        return ['ok' => true, 'status_code' => 200, 'message' => (string) ($result['status'] ?? 'ok')];
    }

    protected function supportsEventType(string $eventType): bool
    {
        $normalized = strtolower(trim($eventType));

        return in_array($normalized, [
            'checkout.session.completed',
            'customer.subscription.created',
            'customer.subscription.updated',
        ], true);
    }

    protected function extractCheckoutSessionId(string $eventType, array $object): ?string
    {
        if ($eventType === 'checkout.session.completed') {
            $id = trim((string) ($object['id'] ?? ''));

            return $id !== '' ? $id : null;
        }

        return null;
    }

    /**
     * @return array{status:string,row?:StripeWebhookEvent}
     */
    protected function storeReceiptOnce(
        string $eventId,
        string $eventType,
        bool $livemode,
        ?int $tenantId,
        ?string $checkoutSessionId,
        array $event,
        string $status
    ): array {
        try {
            $row = StripeWebhookEvent::query()->create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'status' => $status,
                'livemode' => $livemode,
                'tenant_id' => $tenantId,
                'checkout_session_id' => $checkoutSessionId,
                'processed_at' => null,
                'payload' => [
                    'id' => $eventId,
                    'type' => $eventType,
                    'created' => $event['created'] ?? null,
                    'data' => [
                        'object' => [
                            'id' => data_get($event, 'data.object.id'),
                            'object' => data_get($event, 'data.object.object'),
                        ],
                    ],
                ],
            ]);

            return ['status' => 'created', 'row' => $row];
        } catch (QueryException $exception) {
            if ($this->isDuplicateKeyException($exception)) {
                return ['status' => 'duplicate'];
            }

            throw $exception;
        }
    }

    protected function isDuplicateKeyException(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique')
            || (string) $exception->getCode() === '23000';
    }

    protected function snapshotOverride(TenantCommercialOverride $override): array
    {
        return [
            'tenant_id' => (int) $override->tenant_id,
            'billing_mapping' => is_array($override->billing_mapping) ? $override->billing_mapping : [],
            'metadata' => is_array($override->metadata) ? $override->metadata : [],
        ];
    }

    protected function verifySignature(string $payload, string $signatureHeader, string $secret): bool
    {
        if ($payload === '' || $signatureHeader === '' || $secret === '') {
            return false;
        }

        $parts = array_filter(array_map('trim', explode(',', $signatureHeader)));
        $timestamp = null;
        $signatures = [];
        foreach ($parts as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $part, 2));
            if ($key === 't') {
                $timestamp = is_numeric($value) ? (int) $value : null;
                continue;
            }
            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        $tolerance = 300;
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $signedPayload = $timestamp.'.'.$payload;
        $computed = hash_hmac('sha256', $signedPayload, $secret);

        foreach ($signatures as $candidate) {
            if (hash_equals($computed, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
