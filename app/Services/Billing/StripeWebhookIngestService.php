<?php

namespace App\Services\Billing;

use App\Models\Agreement;
use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantBillingOrder;
use App\Models\TenantCommercialOverride;
use App\Models\TenantDirectInvoice;
use App\Services\Marketing\Messaging\TenantMessagingUsageService;
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
        protected TenantMessagingUsageService $messagingUsage,
        protected TenantBillingSubscriptionLedger $subscriptionLedger,
        protected AgreementStripeWebhookService $agreementWebhooks,
        protected AgreementStripeScheduleService $agreementSchedules,
        protected AgreementSandboxValidationEvidenceService $sandboxValidationEvidence,
        protected DirectStripeInvoiceWebhookService $directInvoiceWebhooks,
    ) {}

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
        $subscriptionMetadata = data_get($object, 'parent.subscription_details.metadata', data_get($object, 'subscription_details.metadata', []));
        if (is_array($subscriptionMetadata)) {
            $metadata = [...$subscriptionMetadata, ...$metadata];
        }

        if (($metadata['purpose'] ?? null) === 'messaging_credit') {
            return $this->fulfillMessagingCredit($eventId, $eventType, $object, $metadata);
        }

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
            $event,
            $livemode
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

            $agreementContext = null;
            if ($this->shouldResolveAgreementCheckoutContext(
                metadata: $metadata,
                object: is_array(data_get($event, 'data.object')) ? (array) data_get($event, 'data.object') : [],
                checkoutSessionId: $checkoutSessionId,
                stripeSubscription: $stripeSubscription,
                stripeObjectId: $stripeObjectId,
            )) {
                $agreementContext = $this->resolveAgreementCheckoutContext(
                    metadata: $metadata,
                    object: is_array(data_get($event, 'data.object')) ? (array) data_get($event, 'data.object') : [],
                    checkoutSessionId: $checkoutSessionId,
                    stripeSubscription: $stripeSubscription,
                    stripeObjectId: $stripeObjectId,
                );

                if (! ($agreementContext['ok'] ?? false)) {
                    $status = (string) ($agreementContext['status'] ?? 'ignored_agreement_security_mismatch');
                    $tenantId = (int) ($agreementContext['tenant_id'] ?? 0) ?: null;
                    Log::warning('stripe.webhook.agreement_context_rejected', [
                        'event_id' => $eventId,
                        'event_type' => $eventType,
                        'status' => $status,
                        'tenant_id' => $tenantId,
                        'billing_order_id' => $metadata['billing_order_id'] ?? null,
                    ]);

                    $row->forceFill([
                        'status' => $status,
                        'tenant_id' => $tenantId,
                        'processed_at' => now(),
                    ])->save();

                    return ['status' => $status, 'tenant_id' => $tenantId];
                }

                /** @var TenantBillingOrder $agreementOrder */
                $agreementOrder = $agreementContext['order'];
                $tenantId = (int) $agreementOrder->tenant_id;
                $metadata = [
                    ...$metadata,
                    'purpose' => 'agreement_checkout',
                    'tenant_id' => (string) $tenantId,
                    'billing_order_id' => (string) $agreementOrder->id,
                    'agreement_id' => (string) $agreementOrder->agreement_id,
                    'agreement_version_id' => (string) $agreementOrder->agreement_version_id,
                    'agreement_acceptance_id' => (string) $agreementOrder->agreement_acceptance_id,
                    'subscription_authorization_id' => (string) $agreementOrder->subscription_authorization_id,
                ];

                $sandboxType = $agreementOrder->agreement?->agreement_type === Agreement::TYPE_SANDBOX_VALIDATION;
                $validationSnapshot = data_get($agreementOrder->metadata, 'validation_only') === true;
                if ($sandboxType !== $validationSnapshot || ($sandboxType && $livemode)) {
                    $status = $sandboxType && $livemode
                        ? 'ignored_sandbox_live_mode'
                        : 'ignored_agreement_context_invalid';
                    $row->forceFill([
                        'status' => $status,
                        'tenant_id' => $tenantId,
                        'processed_at' => now(),
                    ])->save();
                    $this->auditService->record(
                        tenantId: $tenantId,
                        actorUserId: null,
                        actionType: 'tenant_billing.sandbox_validation_rejected',
                        status: 'ignored',
                        targetType: 'tenant_billing_order',
                        targetId: $agreementOrder->id,
                        context: ['event_id' => $eventId, 'event_type' => $eventType, 'reason' => $status],
                    );

                    return ['status' => $status, 'tenant_id' => $tenantId, 'agreement_order_id' => (int) $agreementOrder->id];
                }

                if ($sandboxType) {
                    $changed = $this->agreementWebhooks->handle(
                        tenantId: $tenantId,
                        eventId: $eventId,
                        eventType: $eventType,
                        object: is_array(data_get($event, 'data.object')) ? (array) data_get($event, 'data.object') : [],
                        metadata: $metadata,
                    );
                    $row->forceFill([
                        'status' => $changed ? 'processed_validation' : 'processed_validation_no_change',
                        'tenant_id' => $tenantId,
                        'checkout_session_id' => $checkoutSessionId !== '' ? $checkoutSessionId : $row->checkout_session_id,
                        'processed_at' => now(),
                    ])->save();
                    $this->auditService->record(
                        tenantId: $tenantId,
                        actorUserId: null,
                        actionType: 'tenant_billing.sandbox_validation_webhook',
                        status: $changed ? 'success' : 'noop',
                        targetType: 'tenant_billing_order',
                        targetId: $agreementOrder->id,
                        context: ['event_id' => $eventId, 'event_type' => $eventType, 'validation_only' => true],
                    );

                    return [
                        'status' => $changed ? 'processed_validation' : 'processed_validation_no_change',
                        'tenant_id' => $tenantId,
                        'agreement_order_id' => (int) $agreementOrder->id,
                        'validation_only' => true,
                    ];
                }
            }

            $tenantId = (int) ($metadata['tenant_id'] ?? 0);
            if ($tenantId < 1 && Schema::hasTable('tenant_billing_orders')) {
                $paymentIntent = trim((string) (data_get($event, 'data.object.payment_intent') ?? ''));
                $invoiceReference = (data_get($event, 'data.object.object') === 'invoice') ? trim((string) data_get($event, 'data.object.id')) : trim((string) data_get($event, 'data.object.invoice'));
                $tenantCandidates = collect();
                if ($paymentIntent !== '') {
                    $tenantCandidates = TenantBillingOrder::withoutGlobalScopes()->where('provider_payment_intent_id', $paymentIntent)->pluck('tenant_id');
                }
                if ($tenantCandidates->isEmpty() && $invoiceReference !== '') {
                    $tenantCandidates = TenantBillingOrder::withoutGlobalScopes()->where('provider_invoice_id', $invoiceReference)->pluck('tenant_id');
                }
                $tenantCandidates = $tenantCandidates->map(fn ($id) => (int) $id)->filter()->unique()->values();
                if ($tenantCandidates->count() === 1) {
                    $tenantId = (int) $tenantCandidates->first();
                }
            }
            if ($tenantId < 1 && Schema::hasTable('tenant_direct_invoices')) {
                $directInvoiceId = (int) ($metadata['direct_invoice_id'] ?? 0);
                $invoiceReference = (data_get($event, 'data.object.object') === 'invoice') ? trim((string) data_get($event, 'data.object.id')) : trim((string) data_get($event, 'data.object.invoice'));
                $paymentIntent = trim((string) data_get($event, 'data.object.payment_intent'));
                $directInvoice = $directInvoiceId > 0
                    ? TenantDirectInvoice::withoutGlobalScopes()->find($directInvoiceId)
                    : TenantDirectInvoice::withoutGlobalScopes()->where('provider_invoice_id', $invoiceReference)->first();
                if (! $directInvoice && $paymentIntent !== '') {
                    $directInvoice = TenantDirectInvoice::withoutGlobalScopes()->where('provider_payment_intent_id', $paymentIntent)->first();
                }
                if ($directInvoice) {
                    $tenantId = (int) $directInvoice->tenant_id;
                }
            }
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

            $directInvoiceChanged = Schema::hasTable('tenant_direct_invoices') && $this->directInvoiceWebhooks->handle(
                tenantId: $tenantId,
                eventId: $eventId,
                eventType: $eventType,
                object: is_array(data_get($event, 'data.object')) ? (array) data_get($event, 'data.object') : [],
                metadata: $metadata,
            );
            if (($metadata['purpose'] ?? null) === 'direct_invoice' || $directInvoiceChanged) {
                $row->forceFill([
                    'status' => $directInvoiceChanged ? 'processed' : 'processed_no_change',
                    'tenant_id' => $tenantId,
                    'processed_at' => now(),
                ])->save();

                return ['status' => $directInvoiceChanged ? 'processed' : 'processed_no_change', 'tenant_id' => $tenantId];
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

            $this->subscriptionLedger->recordStripeEvent(
                tenantId: $tenantId,
                eventId: $eventId,
                eventType: $eventType,
                object: is_array(data_get($event, 'data.object')) ? (array) data_get($event, 'data.object') : [],
                metadata: $metadata,
                customerReference: $stripeCustomer,
                subscriptionReference: $stripeSubscription,
            );

            $agreementChanged = $this->agreementWebhooks->handle(
                tenantId: $tenantId,
                eventId: $eventId,
                eventType: $eventType,
                object: is_array(data_get($event, 'data.object')) ? (array) data_get($event, 'data.object') : [],
                metadata: $metadata,
            );

            $after = $this->snapshotOverride($override);

            $row->forceFill([
                'status' => ($changed || $agreementChanged) ? 'processed' : 'processed_no_change',
                'tenant_id' => $tenantId,
                'checkout_session_id' => $checkoutSessionId !== '' ? $checkoutSessionId : $row->checkout_session_id,
                'processed_at' => now(),
            ])->save();

            $this->auditService->record(
                tenantId: $tenantId,
                actorUserId: null,
                actionType: 'tenant_billing.stripe_webhook_confirmation',
                status: ($changed || $agreementChanged) ? 'success' : 'noop',
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
                'status' => ($changed || $agreementChanged) ? 'processed' : 'processed_no_change',
                'tenant_id' => $tenantId,
                'agreement_order_id' => $agreementContext && isset($agreementContext['order']) ? (int) $agreementContext['order']->id : null,
            ];
        });

        $tenantId = (int) ($result['tenant_id'] ?? 0);
        $resultStatus = (string) ($result['status'] ?? '');
        $canPostProcess = ! str_starts_with($resultStatus, 'ignored') && $resultStatus !== 'duplicate';
        $agreementOrderId = (int) ($result['agreement_order_id'] ?? 0);
        $validationOnly = (bool) ($result['validation_only'] ?? false);
        $isAgreementContext = $agreementOrderId > 0 || ($metadata['purpose'] ?? null) === 'agreement_checkout';
        if ($canPostProcess && $tenantId > 0 && $eventType === 'checkout.session.completed' && $isAgreementContext) {
            $orderId = (int) ($result['agreement_order_id'] ?? ($metadata['billing_order_id'] ?? 0));
            $order = $orderId > 0 ? TenantBillingOrder::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($orderId) : null;
            if ($order && (! $validationOnly || (! $livemode && str_starts_with((string) config('services.stripe.secret'), 'sk_test_')))) {
                $this->agreementSchedules->configure($order);
            }
        }
        $shouldReconcileFulfillment = $canPostProcess && $tenantId > 0 && ($metadata['purpose'] ?? null) !== 'direct_invoice';
        if ($canPostProcess && $isAgreementContext && ! $validationOnly) {
            $billingOrderId = (int) ($result['agreement_order_id'] ?? ($metadata['billing_order_id'] ?? 0));
            $agreementOrder = $billingOrderId > 0 ? TenantBillingOrder::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($billingOrderId) : null;
            $shouldReconcileFulfillment = $agreementOrder !== null
                && filled($agreementOrder->provider_subscription_id)
                && collect((array) $agreementOrder->line_items)->contains(fn (mixed $line): bool => is_array($line) && ($line['payment_timing'] ?? '') === 'recurring_current');
        }
        if ($canPostProcess && $validationOnly && $agreementOrderId > 0) {
            $validationOrder = TenantBillingOrder::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($agreementOrderId);
            if ($validationOrder) {
                $this->sandboxValidationEvidence->recordIfComplete($validationOrder, $eventId, $eventType);
            }
        }
        if ($shouldReconcileFulfillment) {
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
            'checkout.session.expired',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'invoice.paid',
            'invoice.finalized',
            'invoice.finalization_failed',
            'invoice.sent',
            'invoice.voided',
            'invoice.marked_uncollectible',
            'invoice.payment_failed',
            'invoice.payment_succeeded',
            'charge.refunded',
            'refund.created',
            'charge.dispute.created',
        ], true);
    }

    /**
     * Agreement checkout webhooks are allowed to update tenant commercial state
     * only after the signed event can be tied back to one immutable, tenant-owned
     * billing order. Stripe can omit Checkout metadata on follow-up invoice,
     * refund, or subscription events, so agreement context is required whenever
     * the event carries agreement identifiers or matches an agreement order by a
     * provider reference. Unrelated generic Stripe events with no order match are
     * left on the normal path, while direct invoices remain separated.
     *
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $object
     */
    protected function shouldResolveAgreementCheckoutContext(
        array $metadata,
        array $object,
        string $checkoutSessionId,
        string $stripeSubscription,
        string $stripeObjectId,
    ): bool {
        $purpose = trim((string) ($metadata['purpose'] ?? ''));
        $hasAgreementIdentifiers = $this->hasAgreementMetadataIdentifiers($metadata)
            || $this->hasAgreementMetadataIdentifiers((array) data_get($object, 'parent.subscription_details.metadata', []))
            || $this->hasAgreementMetadataIdentifiers((array) data_get($object, 'subscription_details.metadata', []));

        if ($purpose === 'agreement_checkout') {
            return true;
        }

        if ($purpose === 'direct_invoice' && ! $hasAgreementIdentifiers) {
            return false;
        }

        if ((int) ($metadata['direct_invoice_id'] ?? 0) > 0 && ! $hasAgreementIdentifiers) {
            return false;
        }

        if ($hasAgreementIdentifiers) {
            return true;
        }

        if (! Schema::hasTable('tenant_billing_orders')) {
            return false;
        }

        return $this->agreementOrderCandidates($object, $checkoutSessionId, $stripeSubscription, $stripeObjectId)->isNotEmpty();
    }

    /** @param array<string,mixed> $metadata */
    protected function hasAgreementMetadataIdentifiers(array $metadata): bool
    {
        foreach ([
            'billing_order_id',
            'agreement_id',
            'agreement_version_id',
            'agreement_acceptance_id',
            'subscription_authorization_id',
        ] as $key) {
            if ((int) ($metadata[$key] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve and validate the exact agreement billing order before tenant-wide
     * Stripe ledgers can be updated.
     *
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $object
     * @return array{ok:bool,status:string,tenant_id:?int,order?:TenantBillingOrder}
     */
    protected function resolveAgreementCheckoutContext(
        array $metadata,
        array $object,
        string $checkoutSessionId,
        string $stripeSubscription,
        string $stripeObjectId,
    ): array {
        if (! Schema::hasTable('tenant_billing_orders')) {
            return ['ok' => false, 'status' => 'ignored_agreement_order_missing', 'tenant_id' => null];
        }

        $explicitTenantId = (int) ($metadata['tenant_id'] ?? 0);
        $explicitOrderId = (int) ($metadata['billing_order_id'] ?? data_get($object, 'parent.subscription_details.metadata.billing_order_id', data_get($object, 'subscription_details.metadata.billing_order_id', 0)));

        if ($explicitOrderId > 0) {
            $order = $this->agreementOrderWithContext($explicitOrderId);
            if (! $order) {
                return ['ok' => false, 'status' => 'ignored_agreement_order_missing', 'tenant_id' => $explicitTenantId ?: null];
            }
            if ($explicitTenantId > 0 && (int) $order->tenant_id !== $explicitTenantId) {
                return ['ok' => false, 'status' => 'ignored_agreement_security_mismatch', 'tenant_id' => $explicitTenantId];
            }
            if ($this->orderReferenceConflict($order, $object, $checkoutSessionId, $stripeSubscription, $stripeObjectId)) {
                return ['ok' => false, 'status' => 'ignored_agreement_security_mismatch', 'tenant_id' => (int) $order->tenant_id];
            }

            $invalidStatus = $this->agreementOrderInvalidStatus($order, $metadata);

            return $invalidStatus === null
                ? ['ok' => true, 'status' => 'agreement_order_verified', 'tenant_id' => (int) $order->tenant_id, 'order' => $order]
                : ['ok' => false, 'status' => $invalidStatus, 'tenant_id' => (int) $order->tenant_id];
        }

        $candidates = $this->agreementOrderCandidates($object, $checkoutSessionId, $stripeSubscription, $stripeObjectId);
        if ($candidates->isEmpty()) {
            return ['ok' => false, 'status' => 'ignored_agreement_order_unresolved', 'tenant_id' => $explicitTenantId ?: null];
        }
        if ($candidates->count() > 1) {
            return ['ok' => false, 'status' => 'ignored_agreement_order_ambiguous', 'tenant_id' => $explicitTenantId ?: null];
        }

        /** @var TenantBillingOrder $order */
        $order = $candidates->first();
        if ($explicitTenantId > 0 && (int) $order->tenant_id !== $explicitTenantId) {
            return ['ok' => false, 'status' => 'ignored_agreement_security_mismatch', 'tenant_id' => $explicitTenantId];
        }

        $invalidStatus = $this->agreementOrderInvalidStatus($order, $metadata);

        return $invalidStatus === null
            ? ['ok' => true, 'status' => 'agreement_order_verified', 'tenant_id' => (int) $order->tenant_id, 'order' => $order]
            : ['ok' => false, 'status' => $invalidStatus, 'tenant_id' => (int) $order->tenant_id];
    }

    protected function agreementOrderWithContext(int $orderId): ?TenantBillingOrder
    {
        return TenantBillingOrder::withoutGlobalScopes()
            ->with(['agreement.currentVersion', 'acceptance', 'authorization'])
            ->find($orderId);
    }

    /**
     * @param  array<string,mixed>  $object
     * @return \Illuminate\Support\Collection<int,TenantBillingOrder>
     */
    protected function agreementOrderCandidates(array $object, string $checkoutSessionId, string $stripeSubscription, string $stripeObjectId): \Illuminate\Support\Collection
    {
        $ids = collect();
        $checkout = $checkoutSessionId !== '' ? $checkoutSessionId : (str_starts_with($stripeObjectId, 'cs_') ? $stripeObjectId : '');
        $subscription = $stripeSubscription !== '' ? $stripeSubscription : (($object['object'] ?? '') === 'subscription' ? $stripeObjectId : trim((string) ($object['subscription'] ?? '')));
        $paymentIntent = trim((string) ($object['payment_intent'] ?? ''));
        $invoice = ($object['object'] ?? '') === 'invoice'
            ? $stripeObjectId
            : trim((string) ($object['invoice'] ?? ''));

        foreach ([
            'provider_checkout_session_id' => $checkout,
            'provider_subscription_id' => $subscription,
            'provider_payment_intent_id' => $paymentIntent,
            'provider_invoice_id' => $invoice,
        ] as $column => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $ids = $ids->merge(TenantBillingOrder::withoutGlobalScopes()->where($column, $value)->pluck('id'));
        }

        $ids = $ids->map(fn (mixed $id): int => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return TenantBillingOrder::withoutGlobalScopes()
            ->with(['agreement.currentVersion', 'acceptance', 'authorization'])
            ->whereIn('id', $ids->all())
            ->get();
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    protected function agreementOrderInvalidStatus(TenantBillingOrder $order, array $metadata): ?string
    {
        $order->loadMissing(['agreement.currentVersion', 'acceptance', 'authorization']);
        $agreement = $order->agreement;
        $acceptance = $order->acceptance;
        $authorization = $order->authorization;

        if (! $agreement || ! $acceptance || ! $authorization) {
            return 'ignored_agreement_context_invalid';
        }

        $tenantId = (int) $order->tenant_id;
        if ($tenantId < 1
            || (int) $agreement->tenant_id !== $tenantId
            || (int) $acceptance->tenant_id !== $tenantId
            || (int) $authorization->tenant_id !== $tenantId) {
            return 'ignored_agreement_security_mismatch';
        }

        if ((int) $order->agreement_id !== (int) $agreement->id
            || (int) $order->agreement_version_id !== (int) $agreement->current_version_id
            || (int) $order->agreement_version_id !== (int) $acceptance->agreement_version_id
            || (int) $order->agreement_version_id !== (int) $authorization->agreement_version_id
            || (int) $order->agreement_acceptance_id !== (int) $acceptance->id
            || (int) $order->subscription_authorization_id !== (int) $authorization->id
            || (int) $authorization->agreement_id !== (int) $agreement->id
            || (int) $authorization->agreement_acceptance_id !== (int) $acceptance->id) {
            return 'ignored_agreement_context_invalid';
        }

        foreach ([
            'agreement_id' => (int) $order->agreement_id,
            'agreement_version_id' => (int) $order->agreement_version_id,
            'agreement_acceptance_id' => (int) $order->agreement_acceptance_id,
            'subscription_authorization_id' => (int) $order->subscription_authorization_id,
        ] as $key => $expected) {
            $actual = (int) ($metadata[$key] ?? 0);
            if ($actual > 0 && $actual !== $expected) {
                return 'ignored_agreement_security_mismatch';
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $object
     */
    protected function orderReferenceConflict(TenantBillingOrder $order, array $object, string $checkoutSessionId, string $stripeSubscription, string $stripeObjectId): bool
    {
        $checkout = $checkoutSessionId !== '' ? $checkoutSessionId : (str_starts_with($stripeObjectId, 'cs_') ? $stripeObjectId : '');
        $subscription = $stripeSubscription !== '' ? $stripeSubscription : (($object['object'] ?? '') === 'subscription' ? $stripeObjectId : trim((string) ($object['subscription'] ?? '')));
        $paymentIntent = trim((string) ($object['payment_intent'] ?? ''));
        $invoice = ($object['object'] ?? '') === 'invoice'
            ? $stripeObjectId
            : trim((string) ($object['invoice'] ?? ''));

        foreach ([
            'provider_checkout_session_id' => $checkout,
            'provider_subscription_id' => $subscription,
            'provider_payment_intent_id' => $paymentIntent,
            'provider_invoice_id' => $invoice,
        ] as $column => $incoming) {
            $incoming = trim((string) $incoming);
            $existing = trim((string) $order->{$column});
            if ($incoming !== '' && $existing !== '' && $incoming !== $existing) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $object @param array<string,mixed> $metadata */
    protected function fulfillMessagingCredit(string $eventId, string $eventType, array $object, array $metadata): array
    {
        if (! in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return ['ok' => true, 'status_code' => 200, 'message' => 'ignored'];
        }

        $tenantId = (int) ($metadata['tenant_id'] ?? 0);
        $packCents = (int) ($metadata['pack_cents'] ?? 0);
        $amountTotal = (int) ($object['amount_total'] ?? 0);
        $paymentStatus = strtolower(trim((string) ($object['payment_status'] ?? '')));
        $allowedPacks = array_map('intval', (array) config('marketing.messaging.platform.credit_packs_cents', []));
        if ($tenantId < 1 || ! in_array($packCents, $allowedPacks, true) || $amountTotal !== $packCents || ! in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            return ['ok' => false, 'status_code' => 400, 'message' => 'Invalid messaging credit checkout.'];
        }

        $this->messagingUsage->fund($tenantId, $packCents * 10000, $eventId, [
            'stripe_event_id' => $eventId,
            'stripe_checkout_session_id' => $object['id'] ?? null,
            'pack_cents' => $packCents,
        ]);

        return ['ok' => true, 'status_code' => 200, 'message' => 'processed_credit'];
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
        $eventPurpose = strtolower(trim((string) ($metadata['purpose'] ?? '')));
        if (($stripe['last_event_purpose'] ?? '') !== $eventPurpose) {
            $stripe['last_event_purpose'] = $eventPurpose;
            $changed = true;
        }

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
                $shouldConfirm = ($metadata['purpose'] ?? null) === 'agreement_checkout'
                    ? in_array($paymentStatus, ['paid', 'no_payment_required'], true)
                    : ($paymentStatus === '' || in_array($paymentStatus, ['paid', 'no_payment_required'], true));
            }

            if (str_starts_with($eventType, 'customer.subscription.') && in_array($subscriptionStatus, ['active', 'trialing'], true)) {
                $shouldConfirm = true;
            }

            if (in_array($eventType, ['invoice.paid', 'invoice.payment_succeeded'], true)) {
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
        if (Schema::hasTable('tenant_billing_orders')) {
            $orderTenantIds = collect();
            $subscription = $stripeSubscription !== '' ? $stripeSubscription : $stripeObjectId;
            if ($subscription !== '') {
                $orderTenantIds = TenantBillingOrder::withoutGlobalScopes()->where('provider_subscription_id', $subscription)->pluck('tenant_id');
            }
            if ($orderTenantIds->isEmpty() && $stripeCustomer !== '') {
                $orderTenantIds = TenantBillingOrder::withoutGlobalScopes()->where('provider_customer_id', $stripeCustomer)->pluck('tenant_id');
            }
            $resolvedOrderTenants = $orderTenantIds->map(fn ($id) => (int) $id)->filter()->unique()->values();
            if ($resolvedOrderTenants->count() === 1) {
                return (int) $resolvedOrderTenants->first();
            }
        }
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
