<?php

namespace App\Services\Billing;

use App\Models\Agreement;
use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingSubscription;
use App\Models\TenantCommercialOverride;
use App\Models\User;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Platform subscription purchase. Separate from tenant-owned Website checkout. */
class SelfServiceStandardPurchaseService
{
    public const PURPOSE = 'self_service_standard';

    public function __construct(
        private TenantModuleCatalogService $catalog,
        private LandlordCommercialConfigService $commercial,
        private LandlordOperatorActionAuditService $audit,
        private TenantBillingReceiptLedger $receipts,
    ) {}

    public function readiness(): array
    {
        $reasons = [];
        if (! config('self_service_purchase.enabled')) {
            $reasons[] = 'Self service purchases are not enabled.';
        }
        if (! config('self_service_purchase.webhook_verified') || ! config('services.stripe.webhook_secret')) {
            $reasons[] = 'Payment confirmation is not ready.';
        }
        if (! preg_match('/^(sk|rk)_(live|test)_/', (string) config('services.stripe.secret', '')) || ! str_starts_with((string) config('services.stripe.account_id', ''), 'acct_')) {
            $reasons[] = 'The platform payment account is not configured.';
        }
        if (! config('self_service_purchase.automatic_tax_enabled')) {
            $reasons[] = 'Automatic tax must be configured for this offer.';
        }

        return ['ready' => $reasons === [], 'reasons' => $reasons];
    }

    /** Open the original customer's hosted billing controls; never start a payment. */
    public function createBillingPortal(Tenant $tenant, User $actor): array
    {
        $this->assertOwner($tenant, $actor);
        abort_unless(app(SelfServicePurchaseEligibility::class)->hasVerifiedPurchase($tenant), 403);
        $override = TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $intent = (array) data_get($override->billing_mapping, 'self_service_purchase', []);
        abort_unless((int) ($intent['actor_id'] ?? 0) === (int) $actor->id, 403);
        $fulfillment = TenantBillingFulfillment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($intent['fulfillment_id']);
        $customer = (string) ($intent['customer_id'] ?? $fulfillment->provider_customer_reference ?? '');
        $receipt = TenantBillingReceipt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('provider', 'stripe')->where('provider_receipt_id', $intent['invoice_id'])->firstOrFail();
        $source = StripeWebhookEvent::query()->where('tenant_id', $tenant->id)->where('event_id', $receipt->source_event_id)->where('status', 'processed_self_service')->firstOrFail();
        if (! str_starts_with($customer, 'cus_') || $customer !== $fulfillment->provider_customer_reference
            || data_get($source->payload, 'data.object.customer') !== $customer || ! $this->sameAccount($intent)) {
            return $this->failure('The original billing account needs review before billing controls can open.');
        }
        try {
            $this->verifyAccount($intent);
            $returnUrl = 'https://'.trim((string) config('tenancy.landlord.primary_host', 'app.theeverbranch.com'))
                .route('self-service.purchase', ['tenant' => $tenant->slug], false);
            $key = 'everbranch-portal:'.hash('sha256', $intent['id'].'|'.$actor->id.'|'.intdiv(now()->timestamp, 60));
            $response = $this->request()->withHeaders(['Idempotency-Key' => $key])->post($this->base().'/v1/billing_portal/sessions', [
                'customer' => $customer, 'return_url' => $returnUrl,
            ]);
            $session = (array) $response->json();
            $url = (string) ($session['url'] ?? '');
            if ($response->failed() || ($session['object'] ?? '') !== 'billing_portal.session'
                || ($session['customer'] ?? '') !== $customer || (bool) ($session['livemode'] ?? false) !== $intent['livemode']
                || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'billing.stripe.com'
                || ! in_array(parse_url($url, PHP_URL_PORT), [null, 443], true)
                || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
                return $this->failure('Billing controls could not open. Try again shortly.');
            }
            $this->audit->record($tenant->id, $actor->id, 'self_service.billing_portal_opened', targetType: 'tenant_billing_fulfillment', targetId: $fulfillment->id);

            return ['ok' => true, 'url' => $url, 'session_id' => $session['id'] ?? null, 'message' => null];
        } catch (\Throwable $exception) {
            report($exception);

            return $this->failure('Billing controls are temporarily unavailable. Your subscription is unchanged.');
        }
    }

    /** Add included branches after purchase, without starting another payment. */
    public function activateIncludedBranches(Tenant $tenant, User $actor, array $branches): array
    {
        $this->assertOwner($tenant, $actor);
        abort_unless(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant), 403, 'A confirmed active Everbranch purchase is required.');
        $keys = $this->branches($branches);

        return DB::transaction(function () use ($tenant, $actor, $keys): array {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant), 403);
            foreach ($keys as $key) {
                $existing = \App\Models\TenantModuleEntitlement::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('module_key', $key)->first();
                if ($existing && $existing->entitlement_source !== 'self_service_purchase') {
                    continue; // Explicit operator restrictions and existing agreements retain authority.
                }
                $this->commercial->setTenantModuleEntitlement($tenant->id, $key, [
                    'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included',
                    'entitlement_source' => 'self_service_purchase', 'price_source' => 'standard_offer',
                    'metadata' => (array) $existing?->metadata,
                ], $actor->id);
                if (! \App\Models\TenantModuleState::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('module_key', $key)->exists()) {
                    $this->commercial->setTenantModuleState($tenant->id, $key, true, 'not_started', $actor->id);
                }
            }

            return $keys;
        });
    }

    public function quote(Tenant $tenant, User $actor, array $branches): array
    {
        $this->assertOwner($tenant, $actor);
        $selected = $this->branches($branches);
        $offer = (array) config('module_catalog.offers.standard', []);
        $quote = [
            'tenant_id' => (int) $tenant->id, 'actor_id' => (int) $actor->id,
            'offer_version' => (string) $offer['version'], 'terms_version' => (string) config('self_service_purchase.terms_version'),
            'currency' => strtolower((string) $offer['currency']), 'setup_cents' => (int) $offer['setup_cents'],
            'monthly_cents' => (int) $offer['monthly_cents'], 'branches' => $selected,
            'usage_note' => (string) $offer['usage_fees_note'],
        ];
        $quote['subtotal_cents'] = $quote['setup_cents'] + $quote['monthly_cents'];
        $quote['quote_hash'] = hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));

        return $quote;
    }

    /** Review the immutable accepted offer while its original checkout is pending. */
    public function quoteForReview(Tenant $tenant, User $actor, array $branches): array
    {
        $this->assertOwner($tenant, $actor);
        $mapping = TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('billing_mapping');
        $intent = (array) data_get($mapping, 'self_service_purchase', []);
        if (($intent['status'] ?? '') !== 'pending') {
            return $this->quote($tenant, $actor, $branches);
        }
        $frozen = (array) ($intent['quote'] ?? []);
        $hash = (string) ($frozen['quote_hash'] ?? '');
        $content = $frozen;
        unset($content['quote_hash']);
        abort_unless((int) ($frozen['tenant_id'] ?? 0) === (int) $tenant->id
            && (int) ($frozen['actor_id'] ?? 0) === (int) $actor->id && (int) ($intent['actor_id'] ?? 0) === (int) $actor->id, 403);
        if ($hash === '' || ! hash_equals($hash, hash('sha256', json_encode($content, JSON_THROW_ON_ERROR)))) {
            throw ValidationException::withMessages(['purchase' => 'The saved purchase needs review before checkout can resume.']);
        }

        return $frozen;
    }

    /** @return array{ok:bool,url:?string,session_id:?string,message:?string} */
    public function checkout(Tenant $tenant, User $actor, array $branches, array $evidence): array
    {
        $quote = $this->quoteForReview($tenant, $actor, $branches);
        if ($this->branches($branches) !== $quote['branches']) {
            throw ValidationException::withMessages(['branches' => 'Resume your saved selection before changing branches. Included branches can be added after payment.']);
        }
        if (! ($evidence['terms_accepted'] ?? false) || ! hash_equals($quote['quote_hash'], (string) ($evidence['quote_hash'] ?? ''))) {
            throw ValidationException::withMessages(['terms' => 'Review the current offer and accept its terms before paying.']);
        }
        if (! $this->readiness()['ready']) {
            return $this->failure(implode(' ', $this->readiness()['reasons']));
        }

        return Cache::lock('self-service-purchase:'.$tenant->id, 120)->block(5, function () use ($tenant, $actor, $quote, $evidence): array {
            $intent = DB::transaction(function () use ($tenant, $actor, $quote, $evidence): array {
                Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $profile = TenantAccessProfile::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
                if (($profile?->metadata['self_service_registration'] ?? false) !== true) {
                    throw ValidationException::withMessages(['purchase' => 'This account keeps its existing service agreement. Contact Everbranch to change it.']);
                }
                $override = TenantCommercialOverride::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenant->id]);
                $mapping = (array) $override->billing_mapping;
                $existing = (array) ($mapping['self_service_purchase'] ?? []);
                if ($existing !== []) {
                    if (! hash_equals((string) $existing['quote']['quote_hash'], $quote['quote_hash'])) {
                        throw ValidationException::withMessages(['branches' => 'A purchase is already pending. Resume it before changing branches. Included branches can be added after payment.']);
                    }

                    return $existing;
                }
                if (Agreement::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()
                    || TenantBillingSubscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()
                    || TenantBillingReceipt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()
                    || TenantBillingFulfillment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', '!=', 'expired')->exists()) {
                    throw ValidationException::withMessages(['purchase' => 'An existing billing agreement needs review before a new purchase.']);
                }
                $intent = [
                    'id' => (string) Str::uuid(), 'quote' => $quote, 'created_at' => now()->toIso8601String(),
                    'account_id' => (string) config('services.stripe.account_id'), 'livemode' => $this->live(),
                    'status' => 'pending', 'actor_id' => (int) $actor->id,
                    'acceptance' => ['terms_version' => $quote['terms_version'], 'accepted_at' => now()->toIso8601String(), 'ip' => Str::limit((string) ($evidence['ip'] ?? ''), 45, ''), 'user_agent' => Str::limit((string) ($evidence['user_agent'] ?? ''), 500, '')],
                ];
                $fulfillment = TenantBillingFulfillment::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id, 'provider' => 'stripe', 'state_hash' => hash('sha256', $intent['id']),
                    'desired_plan_key' => 'standard', 'desired_addon_keys' => [], 'desired_operating_mode' => 'direct',
                    'status' => 'checkout_pending', 'triggered_by' => 'self_service', 'actor_user_id' => $actor->id,
                    'attempted_at' => now(),
                ]);
                $intent['fulfillment_id'] = (int) $fulfillment->id;
                $intent['request'] = $this->payload($intent, $actor);
                $mapping['self_service_purchase'] = $intent;
                $override->forceFill(['billing_mapping' => $mapping])->save();
                $this->audit->record($tenant->id, $actor->id, 'self_service.purchase_authorized', targetType: 'tenant_billing_fulfillment', targetId: $fulfillment->id, afterState: $quote);

                return $intent;
            });
            if (($intent['status'] ?? '') === 'paid') {
                return $this->failure('This workspace has already purchased Everbranch Simple.');
            }
            if (! $this->sameAccount($intent)) {
                return $this->failure('The payment account changed. Contact Everbranch to recover the original purchase.');
            }
            if (empty($intent['session_id']) && now()->diffInHours(\Illuminate\Support\Carbon::parse($intent['created_at']), true) >= 23) {
                return $this->failure('This payment attempt needs review before another checkout can start.');
            }
            try {
                $this->verifyAccount($intent, true);
                if (! empty($intent['session_id'])) {
                    $session = $this->get('/v1/checkout/sessions/'.$intent['session_id']);
                    $this->assertSession($session, $intent, false);
                    if (($session['status'] ?? '') === 'expired' && ($session['payment_status'] ?? '') === 'unpaid'
                        && empty($session['subscription']) && empty($session['payment_intent']) && empty($session['invoice'])) {
                        $intent = $this->replaceExpiredIntent($tenant, $actor, $intent);
                        $session = null;
                    } elseif (($session['status'] ?? '') !== 'open' || ($session['payment_status'] ?? '') !== 'unpaid') {
                        return $this->failure('Your original payment is being confirmed. Refresh its status before starting another checkout.');
                    }
                } else {
                    $session = null;
                }
                if ($session === null) {
                    $response = $this->request()->withHeaders(['Idempotency-Key' => 'everbranch-standard:'.$intent['id']])->post($this->base().'/v1/checkout/sessions', $intent['request']);
                    if ($response->failed()) {
                        return $this->failure('Checkout could not start. Your selection is saved; try again shortly.');
                    }
                    $session = (array) $response->json();
                }
                $this->assertSession($session, $intent, false);
                $url = (string) ($session['url'] ?? '');
                if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'checkout.stripe.com') {
                    return $this->failure('Checkout did not return a secure payment link.');
                }
                $this->saveIntent($tenant->id, $intent['id'], ['session_id' => $session['id']]);
                TenantBillingFulfillment::withoutGlobalScopes()->whereKey($intent['fulfillment_id'])->update(['provider_checkout_session_id' => $session['id']]);

                return ['ok' => true, 'url' => $url, 'session_id' => $session['id'], 'message' => null];
            } catch (\Throwable $exception) {
                report($exception);

                return $this->failure('Payment setup needs attention. Your original purchase is saved for recovery.');
            }
        });
    }

    /** This is called only AFTER StripeWebhookIngestService verifies the raw signature. */
    public function ingestVerifiedEvent(array $event, bool $refresh = false): ?array
    {
        $object = (array) data_get($event, 'data.object', []);
        $metadata = [...(array) data_get($object, 'parent.subscription_details.metadata', []), ...(array) ($object['metadata'] ?? [])];
        $intentId = (string) ($metadata['purchase_id'] ?? '');
        $override = $intentId !== ''
            ? TenantCommercialOverride::withoutGlobalScopes()->where('billing_mapping->self_service_purchase->id', $intentId)->first()
            : null;
        if (! $override && ! empty($object['id'])) {
            $field = match ((string) ($object['object'] ?? '')) {
                'checkout.session' => 'session_id', 'subscription' => 'subscription_id', 'invoice' => 'invoice_id', default => null
            };
            if ($field) {
                $override = TenantCommercialOverride::withoutGlobalScopes()->where('billing_mapping->self_service_purchase->'.$field, $object['id'])->first();
            }
        }
        if (! $override && ($object['object'] ?? '') === 'charge' && ! empty($object['invoice'])) {
            $override = TenantCommercialOverride::withoutGlobalScopes()->where('billing_mapping->self_service_purchase->invoice_id', $object['invoice'])->first();
        }
        if (! $override && ($object['object'] ?? '') === 'invoice' && ! empty($object['subscription'])) {
            $override = TenantCommercialOverride::withoutGlobalScopes()->where('billing_mapping->self_service_purchase->subscription_id', $object['subscription'])->first();
        }
        if (! $override && ($object['object'] ?? '') === 'dispute' && ! empty($object['payment_intent'])) {
            $override = TenantCommercialOverride::withoutGlobalScopes()->where('billing_mapping->self_service_purchase->payment_intent_id', $object['payment_intent'])->first();
        }
        if (! $override) {
            return ($metadata['purpose'] ?? '') === self::PURPOSE ? ['ok' => false, 'status_code' => 400, 'message' => 'Unknown purchase.'] : null;
        }
        $eventId = (string) ($event['id'] ?? '');
        $intent = (array) data_get($override->billing_mapping, 'self_service_purchase', []);
        $supported = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'customer.subscription.updated', 'customer.subscription.deleted', 'invoice.paid', 'invoice.payment_succeeded', 'invoice.payment_failed', 'charge.refunded', 'charge.dispute.created', 'charge.dispute.closed'];
        if (! in_array((string) ($event['type'] ?? ''), $supported, true)) {
            return ['ok' => true, 'status_code' => 200, 'message' => 'ignored'];
        }
        if (! empty($event['account']) || (bool) ($event['livemode'] ?? false) !== $intent['livemode'] || ! $this->sameAccount($intent)) {
            return ['ok' => false, 'status_code' => 400, 'message' => 'Payment account mismatch.'];
        }
        $receipt = StripeWebhookEvent::query()->firstOrCreate(['event_id' => $eventId], [
            'event_type' => $event['type'], 'status' => 'received_self_service', 'livemode' => $intent['livemode'],
            'tenant_id' => $override->tenant_id, 'checkout_session_id' => $intent['session_id'] ?? null, 'payload' => $event,
        ]);
        if ($receipt->tenant_id !== $override->tenant_id || $receipt->event_type !== $event['type'] || $receipt->livemode !== $intent['livemode']) {
            return ['ok' => false, 'status_code' => 400, 'message' => 'Event receipt mismatch.'];
        }
        try {
            return Cache::lock('self-service-purchase:'.$override->tenant_id, 180)->block(5, function () use ($event, $eventId, $object, $override, $intent, $refresh): array {
                $existing = StripeWebhookEvent::query()->where('event_id', $eventId)->first();
                if ($existing && str_starts_with($existing->status, 'processed_self_service') && ! $refresh) {
                    return ['ok' => true, 'status_code' => 200, 'message' => 'duplicate'];
                }
                $this->verifyAccount($intent);
                $sessionId = (string) ($intent['session_id'] ?? (($object['object'] ?? '') === 'checkout.session' ? $object['id'] : ''));
                if ($sessionId === '') {
                    throw new \RuntimeException('The original checkout session is not known yet.');
                }
                $session = $this->get('/v1/checkout/sessions/'.$sessionId, ['expand' => ['invoice', 'subscription']]);
                $this->assertSession($session, $intent, true);
                $subscription = is_array($session['subscription'] ?? null) ? $session['subscription'] : $this->get('/v1/subscriptions/'.$session['subscription']);
                $invoice = is_array($session['invoice'] ?? null) ? $session['invoice'] : $this->get('/v1/invoices/'.$session['invoice']);
                $this->assertPaid($session, $subscription, $invoice, $intent);
                $payment = $this->verifiedPayment($invoice, $session, $intent);
                $active = ($subscription['status'] ?? '') === 'active' && ! in_array($event['type'], ['customer.subscription.deleted', 'charge.refunded'], true);
                if ((int) data_get($payment, 'latest_charge.amount_refunded', 0) > 0 || data_get($payment, 'latest_charge.disputed', false)) {
                    $active = false;
                }
                $latestInvoiceId = is_array($subscription['latest_invoice'] ?? null) ? $subscription['latest_invoice']['id'] : ($subscription['latest_invoice'] ?? '');
                if ($active && $latestInvoiceId !== $invoice['id']) {
                    $latest = $this->get('/v1/invoices/'.$latestInvoiceId);
                    $active = ($latest['status'] ?? '') === 'paid' && ($latest['paid'] ?? false)
                        && (int) ($latest['subtotal'] ?? -1) === $intent['quote']['monthly_cents']
                        && (int) ($latest['amount_paid'] ?? -1) === (int) ($latest['total'] ?? -2)
                        && ($latest['currency'] ?? '') === $intent['quote']['currency']
                        && ($latest['customer'] ?? '') === $session['customer'] && ($latest['subscription'] ?? '') === $subscription['id'];
                    if ($active) {
                        $renewalPayment = $this->verifiedPayment($latest, $session, $intent);
                        $active = (int) data_get($renewalPayment, 'latest_charge.amount_refunded', 0) === 0 && ! data_get($renewalPayment, 'latest_charge.disputed', false);
                    }
                }
                $this->fulfill($override->tenant_id, $intent, $event, $session, $subscription, $invoice, $active);

                return ['ok' => true, 'status_code' => 200, 'message' => 'confirmed'];
            });
        } catch (\Throwable $exception) {
            report($exception);

            return ['ok' => false, 'status_code' => 503, 'message' => 'Purchase confirmation will retry.'];
        }
    }

    private function fulfill(int $tenantId, array $intent, array $event, array $session, array $subscription, array $invoice, bool $active): void
    {
        DB::transaction(function () use ($tenantId, $intent, $event, $session, $subscription, $invoice, $active): void {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $row = StripeWebhookEvent::query()->firstOrCreate(['event_id' => $event['id']], [
                'event_type' => $event['type'], 'status' => 'received', 'livemode' => $intent['livemode'], 'tenant_id' => $tenantId, 'checkout_session_id' => $session['id'], 'payload' => $event,
            ]);
            $fulfillment = TenantBillingFulfillment::withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($intent['fulfillment_id']);
            if ($fulfillment->applied_at === null && $active) {
                foreach ($intent['quote']['branches'] as $module) {
                    $existingEntitlement = \App\Models\TenantModuleEntitlement::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('module_key', $module)->first();
                    if ($existingEntitlement && $existingEntitlement->entitlement_source !== 'self_service_purchase') {
                        continue;
                    }
                    $this->commercial->setTenantModuleEntitlement($tenantId, $module, [
                        'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included',
                        'entitlement_source' => 'self_service_purchase', 'price_source' => 'standard_offer', 'currency' => 'USD',
                        'metadata' => ['fulfillment_id' => $fulfillment->id, 'purchase_id' => $intent['id']],
                    ], $intent['actor_id']);
                    $this->commercial->setTenantModuleState($tenantId, $module, true, 'not_started', $intent['actor_id']);
                }
            }
            $paidAt = \Illuminate\Support\Carbon::createFromTimestamp((int) data_get($invoice, 'status_transitions.paid_at'));
            $existingReceipt = TenantBillingReceipt::withoutGlobalScopes()->where('provider', 'stripe')->where('provider_receipt_id', $invoice['id'])->first();
            if ($existingReceipt && ($existingReceipt->tenant_id !== $tenantId || ($existingReceipt->metadata['self_service_purchase_id'] ?? '') !== $intent['id'])) {
                throw new \RuntimeException('A provider receipt is already assigned to another purchase.');
            }
            if (! $existingReceipt) {
                $this->receipts->recordVerifiedProviderReceipt(Tenant::query()->findOrFail($tenantId), 'stripe', [
                    'provider_receipt_id' => $invoice['id'], 'provider_subscription_id' => $subscription['id'], 'status' => 'paid',
                    'currency' => $invoice['currency'], 'subtotal_amount_cents' => (int) $invoice['subtotal'],
                    'tax_amount_cents' => (int) $invoice['total'] - (int) $invoice['subtotal'], 'total_amount_cents' => (int) $invoice['total'],
                    'billed_at' => now(), 'paid_at' => $paidAt, 'source_event_id' => $event['id'],
                    'metadata' => ['self_service_purchase_id' => $intent['id'], 'account_id' => $intent['account_id'], 'livemode' => $intent['livemode'], 'fulfillment_id' => $fulfillment->id, 'quote_hash' => $intent['quote']['quote_hash']],
                ]);
            }
            TenantBillingSubscription::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenantId, 'provider' => 'stripe', 'provider_subscription_reference' => $subscription['id'], 'purchase_key' => 'offer.standard'], [
                'status' => $active ? 'active' : (string) ($subscription['status'] ?? 'canceled'), 'purchase_key' => 'offer.standard',
                'provider_customer_reference' => $session['customer'], 'last_event_id' => $event['id'], 'last_event_type' => $event['type'],
                'started_at' => $paidAt, 'current_period_ends_at' => \Illuminate\Support\Carbon::createFromTimestamp((int) ($subscription['current_period_end'] ?? data_get($subscription, 'items.data.0.current_period_end'))),
                'metadata' => ['self_service_purchase_id' => $intent['id'], 'account_id' => $intent['account_id'], 'livemode' => $intent['livemode']],
            ]);
            $fulfillment->forceFill([
                'provider_customer_reference' => $session['customer'], 'provider_subscription_reference' => $subscription['id'],
                'provider_checkout_session_id' => $session['id'], 'status' => $active ? 'applied' : 'suspended',
                'source_event_id' => $event['id'], 'source_event_type' => $event['type'], 'applied_at' => $active ? now() : $fulfillment->applied_at,
            ])->save();
            $this->saveIntent($tenantId, $intent['id'], ['status' => $active ? 'paid' : 'suspended', 'session_id' => $session['id'], 'customer_id' => $session['customer'], 'subscription_id' => $subscription['id'], 'invoice_id' => $invoice['id'], 'payment_intent_id' => $invoice['payment_intent']]);
            $row->forceFill(['status' => 'processed_self_service', 'processed_at' => now()])->save();
            $this->audit->record($tenantId, null, 'self_service.purchase_confirmed', targetType: 'tenant_billing_fulfillment', targetId: $fulfillment->id, afterState: ['status' => $fulfillment->status, 'source_event_id' => $event['id']]);
        });
    }

    private function payload(array $intent, User $actor): array
    {
        $quote = $intent['quote'];
        $tenantSlug = (string) Tenant::query()->whereKey($quote['tenant_id'])->value('slug');
        $return = route('self-service.purchase', ['tenant' => $tenantSlug], true);
        $separator = str_contains($return, '?') ? '&' : '?';
        $payload = ['mode' => 'subscription', 'client_reference_id' => $intent['id'], 'customer_email' => $actor->email,
            'success_url' => $return.$separator.'payment=return', 'cancel_url' => $return.$separator.'payment=cancel',
            'payment_method_types[0]' => 'card', 'billing_address_collection' => 'required',
            'automatic_tax[enabled]' => config('self_service_purchase.automatic_tax_enabled') ? 'true' : 'false',
        ];
        foreach (['purpose' => self::PURPOSE, 'purchase_id' => $intent['id'], 'tenant_id' => (string) $quote['tenant_id'], 'quote_hash' => $quote['quote_hash']] as $key => $value) {
            $payload['metadata['.$key.']'] = $value;
            $payload['subscription_data[metadata]['.$key.']'] = $value;
        }
        foreach (['setup' => $quote['setup_cents'], 'monthly' => $quote['monthly_cents']] as $key => $amount) {
            $index = $key === 'setup' ? 0 : 1;
            $prefix = 'line_items['.$index.']';
            $payload[$prefix.'[quantity]'] = 1;
            $payload[$prefix.'[price_data][currency]'] = $quote['currency'];
            $payload[$prefix.'[price_data][unit_amount]'] = $amount;
            $payload[$prefix.'[price_data][tax_behavior]'] = 'exclusive';
            $payload[$prefix.'[price_data][product_data][name]'] = $key === 'setup' ? 'Everbranch Simple setup' : 'Everbranch Simple';
            if ($key === 'monthly') {
                $payload[$prefix.'[price_data][recurring][interval]'] = 'month';
            }
        }

        return $payload;
    }

    private function assertSession(array $session, array $intent, bool $paid): void
    {
        if (($session['object'] ?? '') !== 'checkout.session' || ($session['mode'] ?? '') !== 'subscription'
            || ($session['client_reference_id'] ?? '') !== $intent['id']
            || ($session['metadata']['purchase_id'] ?? '') !== $intent['id']
            || ($session['metadata']['quote_hash'] ?? '') !== $intent['quote']['quote_hash']
            || ($session['metadata']['tenant_id'] ?? '') !== (string) $intent['quote']['tenant_id']
            || ($session['metadata']['purpose'] ?? '') !== self::PURPOSE
            || ($session['currency'] ?? '') !== $intent['quote']['currency']
            || (bool) ($session['livemode'] ?? false) !== $intent['livemode']
            || (int) ($session['amount_subtotal'] ?? -1) !== $intent['quote']['subtotal_cents']
            || (! empty($intent['session_id']) && $session['id'] !== $intent['session_id'])
            || ($paid && (($session['status'] ?? '') !== 'complete' || ($session['payment_status'] ?? '') !== 'paid'))) {
            throw new \RuntimeException('Checkout does not match the original authorized purchase.');
        }
    }

    private function assertPaid(array $session, array $sub, array $invoice, array $intent): void
    {
        $items = (array) data_get($sub, 'items.data', []);
        $price = (array) data_get($items, '0.price', []);
        if (($sub['object'] ?? '') !== 'subscription' || count($items) !== 1
            || ($sub['customer'] ?? '') !== $session['customer'] || ($invoice['customer'] ?? '') !== $session['customer']
            || ($sub['metadata']['purchase_id'] ?? '') !== $intent['id']
            || (bool) ($sub['livemode'] ?? false) !== $intent['livemode'] || (bool) ($invoice['livemode'] ?? false) !== $intent['livemode']
            || ($price['currency'] ?? '') !== $intent['quote']['currency'] || (int) ($price['unit_amount'] ?? -1) !== $intent['quote']['monthly_cents']
            || data_get($price, 'recurring.interval') !== 'month' || (int) data_get($price, 'recurring.interval_count', 1) !== 1 || (int) ($items[0]['quantity'] ?? 0) !== 1
            || ($invoice['status'] ?? '') !== 'paid' || ! ($invoice['paid'] ?? false)
            || ($invoice['paid_out_of_band'] ?? false) || ! str_starts_with((string) ($invoice['payment_intent'] ?? ''), 'pi_')
            || ($invoice['subscription'] ?? '') !== $sub['id']
            || (! empty($intent['subscription_id']) && $sub['id'] !== $intent['subscription_id'])
            || (int) ($invoice['subtotal'] ?? -1) !== $intent['quote']['subtotal_cents']
            || (int) ($invoice['amount_paid'] ?? -1) !== (int) ($invoice['total'] ?? -2)
            || (int) data_get($invoice, 'status_transitions.paid_at') <= 0
            || ($invoice['currency'] ?? '') !== $intent['quote']['currency'] || (int) ($invoice['amount_remaining'] ?? -1) !== 0
            || (int) ($invoice['total'] ?? -1) < (int) ($invoice['subtotal'] ?? 0)
            || collect((array) ($invoice['total_discount_amounts'] ?? []))->sum('amount') !== 0
            || ! empty($sub['discount']) || ! empty($sub['discounts'])
            || ($sub['latest_invoice'] ?? '') === '' || ($invoice['object'] ?? '') !== 'invoice') {
            throw new \RuntimeException('Provider payment evidence does not match the original subscription.');
        }
    }

    private function branches(array $selected): array
    {
        $available = array_column($this->catalog->publicCatalogPayload()['modules'], null, 'key');
        $result = [];
        $visit = function (string $key) use (&$visit, &$result, $available): void {
            if (in_array($key, $result, true)) {
                return;
            }
            $module = (array) ($available[$key] ?? []);
            if ($module === [] || ! in_array($module['status'], ['live', 'beta'], true) || $module['market_state'] !== 'SAFE_TO_MARKET') {
                throw ValidationException::withMessages(['branches' => 'Choose available included branches from the current catalog.']);
            }
            $result[] = $key;
            foreach ((array) config('module_catalog.modules.'.$key.'.dependencies', []) as $dependency) {
                // Internal connectors and mailboxes retain their existing setup/provider gates.
                // Purchasing a public branch never purchases or activates a hidden dependency.
                if (isset($available[$dependency])) {
                    $visit((string) $dependency);
                }
            }
        };
        foreach (array_unique($selected) as $branch) {
            $visit((string) $branch);
        }
        sort($result);

        return $result;
    }

    private function assertOwner(Tenant $tenant, User $actor): void
    {
        abort_unless($actor->is_active && $actor->email_verified_at && $actor->tenants()->where('tenants.id', $tenant->id)->wherePivot('role', 'owner')->wherePivot('membership_active', true)->exists(), 403);
    }

    private function saveIntent(int $tenantId, string $id, array $changes): void
    {
        DB::transaction(function () use ($tenantId, $id, $changes): void {
            $override = TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();
            $mapping = (array) $override->billing_mapping;
            if (data_get($mapping, 'self_service_purchase.id') !== $id) {
                throw new \RuntimeException('Purchase context changed.');
            }
            $mapping['self_service_purchase'] = [...$mapping['self_service_purchase'], ...$changes];
            $override->forceFill(['billing_mapping' => $mapping])->save();
        });
    }

    private function replaceExpiredIntent(Tenant $tenant, User $actor, array $old): array
    {
        return DB::transaction(function () use ($tenant, $actor, $old): array {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $override = TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $mapping = (array) $override->billing_mapping;
            $current = (array) ($mapping['self_service_purchase'] ?? []);
            if (($current['id'] ?? '') !== $old['id'] || ($current['status'] ?? '') !== 'pending'
                || TenantBillingFulfillment::withoutGlobalScopes()->whereKey($old['fulfillment_id'])->value('status') !== 'checkout_pending') {
                throw new \RuntimeException('The original purchase changed during recovery.');
            }
            $mapping['self_service_purchase_history'][$old['id']] = [...$old, 'status' => 'expired', 'retired_at' => now()->toIso8601String()];
            TenantBillingFulfillment::withoutGlobalScopes()->whereKey($old['fulfillment_id'])->update(['status' => 'expired']);
            $intent = $old;
            unset($intent['session_id']);
            $intent['id'] = (string) Str::uuid();
            $intent['created_at'] = now()->toIso8601String();
            $fulfillment = TenantBillingFulfillment::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id, 'provider' => 'stripe', 'state_hash' => hash('sha256', $intent['id']),
                'desired_plan_key' => 'standard', 'desired_addon_keys' => [], 'desired_operating_mode' => 'direct',
                'status' => 'checkout_pending', 'triggered_by' => 'self_service', 'actor_user_id' => $actor->id, 'attempted_at' => now(),
            ]);
            $intent['fulfillment_id'] = (int) $fulfillment->id;
            $intent['request'] = $this->payload($intent, $actor);
            $mapping['self_service_purchase'] = $intent;
            $override->forceFill(['billing_mapping' => $mapping])->save();
            $this->audit->record($tenant->id, $actor->id, 'self_service.expired_checkout_replaced', targetType: 'tenant_billing_fulfillment', targetId: $fulfillment->id, beforeState: ['fulfillment_id' => $old['fulfillment_id'], 'status' => 'expired'], afterState: ['status' => 'checkout_pending']);

            return $intent;
        });
    }

    private function verifyAccount(array $intent, bool $checkout = false): void
    {
        $account = $this->get('/v1/account');
        if (($account['id'] ?? '') !== $intent['account_id'] || ($checkout && ! ($account['charges_enabled'] ?? false))) {
            throw new \RuntimeException('Stripe platform account does not match the frozen purchase.');
        }
        $tax = $checkout ? $this->get('/v1/tax/settings') : ['status' => 'active'];
        if (($tax['status'] ?? '') !== 'active') {
            throw new \RuntimeException('Stripe automatic tax settings are not active.');
        }
    }

    private function verifiedPayment(array $invoice, array $session, array $intent): array
    {
        if (($invoice['paid_out_of_band'] ?? false) || ! str_starts_with((string) ($invoice['payment_intent'] ?? ''), 'pi_')) {
            throw new \RuntimeException('The invoice does not have a confirmed provider payment.');
        }
        $payment = $this->get('/v1/payment_intents/'.$invoice['payment_intent'], ['expand' => ['latest_charge']]);
        if (($payment['status'] ?? '') !== 'succeeded' || (int) ($payment['amount_received'] ?? -1) !== (int) $invoice['total']
            || ($payment['currency'] ?? '') !== $intent['quote']['currency'] || ($payment['customer'] ?? '') !== $session['customer']
            || (bool) ($payment['livemode'] ?? false) !== $intent['livemode']) {
            throw new \RuntimeException('The provider payment has not been confirmed.');
        }

        return $payment;
    }

    private function sameAccount(array $intent): bool
    {
        return ($intent['account_id'] ?? '') === config('services.stripe.account_id') && ($intent['livemode'] ?? null) === $this->live();
    }

    private function live(): bool
    {
        return preg_match('/^(sk|rk)_live_/', (string) config('services.stripe.secret', '')) === 1;
    }

    private function get(string $path, array $query = []): array
    {
        $response = $this->request()->get($this->base().$path, $query);
        if ($response->failed() || ! is_array($response->json())) {
            throw new \RuntimeException('Stripe verification is temporarily unavailable.');
        }

        return $response->json();
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::asForm()->acceptJson()->withToken((string) config('services.stripe.secret'))->withHeaders(['Stripe-Version' => '2024-06-20'])->timeout(20);
    }

    private function base(): string
    {
        return rtrim((string) config('services.stripe.api_base', 'https://api.stripe.com'), '/');
    }

    private function failure(string $message): array
    {
        return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => $message];
    }
}
