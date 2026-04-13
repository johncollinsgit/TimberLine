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
use Illuminate\Support\Facades\Schema;

class StripeWebhookIngestService
{
    public function __construct(
        protected LandlordOperatorActionAuditService $auditService,
        protected StripeCommercialFulfillmentService $fulfillmentService,
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

        $object = is_array(data_get($event, 'data.object')) ? (array) data_get($event, 'data.object') : [];
        $metadata = is_array($object['metadata'] ?? null) ? (array) $object['metadata'] : [];

        if (! $this->supportsEventType($eventType)) {
            $receipt = $this->storeReceiptOnce($eventId, $eventType, $livemode, null, null, $event, 'ignored_unsupported');
            $row = $receipt['row'] ?? null;
            if ($row instanceof StripeWebhookEvent) {
                $row->forceFill([
                    'status' => 'ignored_unsupported',
                    'processed_at' => now(),
                ])->save();
            }

            return ['ok' => true, 'status_code' => 200, 'message' => 'ignored'];
        }

        if ($object === []) {
            $receipt = $this->storeReceiptOnce($eventId, $eventType, $livemode, null, null, $event, 'ignored_malformed');
            $row = $receipt['row'] ?? null;
            if ($row instanceof StripeWebhookEvent) {
                $row->forceFill([
                    'status' => 'ignored_malformed',
                    'processed_at' => now(),
                ])->save();
            }

            return ['ok' => true, 'status_code' => 200, 'message' => 'ignored'];
        }

        $checkoutSessionId = (string) ($this->extractCheckoutSessionId($eventType, $object) ?? '');
        $stripeCustomer = trim((string) ($object['customer'] ?? ''));
        $stripeSubscription = trim((string) ($object['subscription'] ?? ''));
        $stripeObjectId = trim((string) ($object['id'] ?? ''));

        if (($object['object'] ?? '') === 'subscription') {
            $stripeSubscription = $stripeSubscription !== '' ? $stripeSubscription : $stripeObjectId;
        }

        if ($eventType !== 'checkout.session.completed') {
            $stripeCustomer = $stripeCustomer !== '' ? $stripeCustomer : trim((string) ($metadata['stripe_customer_reference'] ?? ''));
            $stripeSubscription = $stripeSubscription !== '' ? $stripeSubscription : trim((string) ($metadata['stripe_subscription_reference'] ?? ''));
        }

        $eventCreatedAt = is_numeric($event['created'] ?? null)
            ? Carbon::createFromTimestamp((int) $event['created'])->toIso8601String()
            : now()->toIso8601String();

        $receipt = $this->storeReceiptOnce(
            $eventId,
            $eventType,
            $livemode,
            null,
            $checkoutSessionId !== '' ? $checkoutSessionId : null,
            $event,
            'received'
        );

        $existingRow = $receipt['row'] ?? null;
        if (! $existingRow instanceof StripeWebhookEvent) {
            $existingRow = StripeWebhookEvent::query()->where('event_id', $eventId)->first();
        }

        if (! $existingRow instanceof StripeWebhookEvent) {
            return ['ok' => true, 'status_code' => 200, 'message' => 'ignored'];
        }

        $result = DB::transaction(function () use (
            $eventId,
            $eventType,
            $metadata,
            $checkoutSessionId,
            $stripeCustomer,
            $stripeSubscription,
            $stripeObjectId,
            $eventCreatedAt,
            $event
        ): array {
            /** @var StripeWebhookEvent|null $row */
            $row = StripeWebhookEvent::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return ['status' => 'ignored_missing_receipt', 'tenant_id' => null];
            }

            $priorStatus = (string) ($row->status ?? '');
            if (str_starts_with($priorStatus, 'processed') || str_starts_with($priorStatus, 'ignored')) {
                return ['status' => 'duplicate', 'tenant_id' => $row->tenant_id];
            }

            $tenantId = (int) ($metadata['tenant_id'] ?? 0);
            if ($tenantId < 1) {
                $tenantId = $this->resolveTenantIdFromStripeReferences($stripeCustomer, $stripeSubscription, $stripeObjectId);
            }

            if ($tenantId < 1) {
                Log::warning('stripe.webhook.tenant_resolution_failed', [
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'object_id' => $stripeObjectId,
                    'stripe_customer' => $stripeCustomer,
                    'stripe_subscription' => $stripeSubscription,
                ]);

                $row->forceFill([
                    'status' => 'ignored_missing_tenant',
                    'tenant_id' => null,
                    'processed_at' => now(),
                ])->save();

                return ['status' => 'ignored_missing_tenant', 'tenant_id' => null];
            }

            /** @var Tenant|null $tenant */
            $tenant = Tenant::query()->find($tenantId);
            if (! $tenant) {
                Log::warning('stripe.webhook.tenant_missing', [
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'tenant_id' => $tenantId,
                ]);

                $row->forceFill([
                    'status' => 'ignored_tenant_missing',
                    'tenant_id' => $tenantId,
                    'processed_at' => now(),
                ])->save();

                return ['status' => 'ignored_tenant_missing', 'tenant_id' => $tenantId];
            }

            $override = TenantCommercialOverride::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();
            $before = $override ? $this->snapshotOverride($override) : null;

            $override = $override ?: new TenantCommercialOverride(['tenant_id' => $tenantId]);
            $billingMapping = is_array($override->billing_mapping ?? null) ? (array) $override->billing_mapping : [];
            $stripe = is_array($billingMapping['stripe'] ?? null) ? (array) $billingMapping['stripe'] : [];

            $changed = $this->applyStripeConfirmationFields(
                stripe: $stripe,
                eventType: $eventType,
                metadata: $metadata,
                object: is_array(data_get($event, 'data.object')) ? (array) data_get($event, 'data.object') : [],
                checkoutSessionId: $checkoutSessionId,
                stripeCustomer: $stripeCustomer,
                stripeSubscription: $stripeSubscription,
                eventCreatedAt: $eventCreatedAt,
                eventId: $eventId
            );

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

        $tenantId = (int) ($result['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            try {
                $this->fulfillmentService->reconcileTenant(
                    tenantId: $tenantId,
                    triggeredBy: 'webhook',
                    actorUserId: null,
                    sourceEventId: $eventId,
                    sourceEventType: $eventType
                );
            } catch (\Throwable $exception) {
                Log::warning('stripe.webhook.fulfillment_failed', [
                    'tenant_id' => $tenantId,
                    'event_id' => $eventId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return ['ok' => true, 'status_code' => 200, 'message' => (string) ($result['status'] ?? 'ok')];
    }

    protected function supportsEventType(string $eventType): bool
    {
        $normalized = strtolower(trim($eventType));

        return in_array($normalized, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'invoice.payment_failed',
            'invoice.payment_succeeded',
        ], true);
    }

    protected function extractCheckoutSessionId(string $eventType, array $object): ?string
    {
        if ($eventType === 'checkout.session.completed') {
            $id = trim((string) ($object['id'] ?? ''));

            return $id !== '' ? $id : null;
        }

        if (in_array($eventType, ['checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed'], true)) {
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
                            'customer' => data_get($event, 'data.object.customer'),
                            'subscription' => data_get($event, 'data.object.subscription'),
                        ],
                    ],
                ],
            ]);

            return ['status' => 'created', 'row' => $row];
        } catch (QueryException $exception) {
            if ($this->isDuplicateKeyException($exception)) {
                $row = StripeWebhookEvent::query()->where('event_id', $eventId)->first();

                return $row ? ['status' => 'duplicate', 'row' => $row] : ['status' => 'duplicate'];
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

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $object
     */
    protected function applyStripeConfirmationFields(
        array &$stripe,
        string $eventType,
        array $metadata,
        array $object,
        string $checkoutSessionId,
        string $stripeCustomer,
        string $stripeSubscription,
        string $eventCreatedAt,
        string $eventId
    ): bool {
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

        if (in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            if (($stripe['checkout_completed_at'] ?? '') !== $eventCreatedAt) {
                $stripe['checkout_completed_at'] = $eventCreatedAt;
                $changed = true;
            }
        }

        $paymentStatus = strtolower(trim((string) ($object['payment_status'] ?? '')));
        if ($paymentStatus !== '' && ($stripe['checkout_payment_status'] ?? '') !== $paymentStatus) {
            $stripe['checkout_payment_status'] = $paymentStatus;
            $changed = true;
        }

        $subscriptionStatus = strtolower(trim((string) ($object['status'] ?? '')));
        if (str_starts_with($eventType, 'customer.subscription.') && $subscriptionStatus !== '' && ($stripe['subscription_status'] ?? '') !== $subscriptionStatus) {
            $stripe['subscription_status'] = $subscriptionStatus;
            $changed = true;
        }

        if ($eventType === 'customer.subscription.deleted') {
            if (($stripe['subscription_status'] ?? '') !== 'canceled') {
                $stripe['subscription_status'] = 'canceled';
                $changed = true;
            }
            $stripe['subscription_deleted_at'] = $eventCreatedAt;
            $stripe['billing_ended_at'] = $eventCreatedAt;
            $changed = true;
        }

        if ($eventType === 'invoice.payment_failed') {
            $stripe['last_invoice_payment_failed_at'] = $eventCreatedAt;
            $stripe['action_required'] = true;
            $changed = true;
        }

        if ($eventType === 'invoice.payment_succeeded') {
            $stripe['last_invoice_paid_at'] = $eventCreatedAt;
            $stripe['action_required'] = false;
            $changed = true;
        }

        if ($eventType === 'checkout.session.async_payment_failed') {
            $stripe['checkout_failed_at'] = $eventCreatedAt;
            $stripe['action_required'] = true;
            if (($stripe['checkout_payment_status'] ?? '') === '' && $paymentStatus === '') {
                $stripe['checkout_payment_status'] = 'failed';
            }
            $changed = true;
        }

        $confirmedPlanKey = $this->normalizedPlanKey($metadata['checkout_plan_key'] ?? null)
            ?? $this->normalizedPlanKey($metadata['preferred_plan_key'] ?? null);

        if ($confirmedPlanKey !== null && ($stripe['confirmed_plan_key'] ?? '') !== $confirmedPlanKey) {
            $stripe['confirmed_plan_key'] = $confirmedPlanKey;
            $changed = true;
        }

        $confirmedAddonKeys = $this->normalizedAddonKeys(
            $metadata['checkout_addons_interest'] ?? null,
            $metadata['addons_interest'] ?? null
        );
        if ($confirmedAddonKeys !== [] && $confirmedAddonKeys !== ($stripe['confirmed_addon_keys'] ?? [])) {
            $stripe['confirmed_addon_keys'] = $confirmedAddonKeys;
            $changed = true;
        }

        if (($stripe['billing_confirmed_at'] ?? '') === '') {
            $shouldConfirm = false;

            if (in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
                $shouldConfirm = $paymentStatus === '' || in_array($paymentStatus, ['paid', 'no_payment_required'], true);
            }

            if (str_starts_with($eventType, 'customer.subscription.') && in_array($subscriptionStatus, ['active', 'trialing'], true)) {
                $shouldConfirm = true;
            }

            if ($eventType === 'invoice.payment_succeeded') {
                $shouldConfirm = true;
            }

            if ($shouldConfirm) {
                $stripe['billing_confirmed_at'] = now()->toIso8601String();
                $stripe['billing_confirmed_by_event_id'] = $eventId;
                $stripe['billing_confirmed_by_event_type'] = $eventType;
                $changed = true;
            }
        }

        return $changed;
    }

    protected function normalizedPlanKey(mixed $value): ?string
    {
        $token = strtolower(trim((string) $value));
        if ($token === '') {
            return null;
        }

        return array_key_exists($token, (array) config('module_catalog.plans', [])) ? $token : null;
    }

    /**
     * @return array<int,string>
     */
    protected function normalizedAddonKeys(mixed $primary, mixed $fallback): array
    {
        $value = $primary;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $value = $fallback;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }
        }

        $list = [];
        if (is_array($value)) {
            $list = $value;
        } elseif (is_string($value)) {
            $list = array_filter(array_map('trim', explode(',', $value)));
        }

        $list = array_values(array_filter(array_map(
            static fn (mixed $item): string => strtolower(trim((string) $item)),
            $list
        ), static fn (string $item): bool => $item !== ''));

        $allowed = array_keys((array) config('module_catalog.addons', []));
        $list = array_values(array_filter(
            $list,
            static fn (string $addonKey): bool => in_array($addonKey, $allowed, true)
        ));

        return array_values(array_unique($list));
    }

    protected function resolveTenantIdFromStripeReferences(string $stripeCustomer, string $stripeSubscription, string $stripeObjectId): int
    {
        if (! Schema::hasTable('tenant_commercial_overrides')) {
            return 0;
        }

        $candidates = [];

        $subscription = $stripeSubscription !== '' ? $stripeSubscription : $stripeObjectId;
        if ($subscription !== '') {
            $candidates = TenantCommercialOverride::query()
                ->where('billing_mapping->stripe->subscription_reference', $subscription)
                ->pluck('tenant_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($candidates === [] && $stripeCustomer !== '') {
            $candidates = TenantCommercialOverride::query()
                ->where('billing_mapping->stripe->customer_reference', $stripeCustomer)
                ->pluck('tenant_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $candidates = array_values(array_unique(array_filter($candidates, static fn (int $id): bool => $id > 0)));

        return count($candidates) === 1 ? (int) $candidates[0] : 0;
    }
}
