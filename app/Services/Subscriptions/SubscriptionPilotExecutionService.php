<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionPilotCycle;
use App\Models\SubscriptionPilotEnrollment;
use App\Models\SubscriptionPilotEvent;
use App\Models\SubscriptionPilotMember;
use App\Models\SubscriptionRechargeConnection;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Customer authorization, native recurring billing and one-way paid Shopify handoff. */
class SubscriptionPilotExecutionService
{
    public function preview(int $tenantId, User $actor, int $memberId, array $input): array
    {
        $this->owner($tenantId, $actor);
        $this->gate($tenantId, 'pilot_setup_enabled');
        $data = Validator::make($input, [
            'recharge_connection_id' => ['required', 'integer', 'min:1'],
            'shipping_cents' => ['required', 'integer', 'min:0', 'max:100000'],
            'tax_rate_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'tax_shipping' => ['required', 'boolean'], 'tax_basis' => ['required', 'string', 'min:10', 'max:500'],
        ])->validate();
        foreach (['recharge_connection_id', 'shipping_cents', 'tax_rate_bps'] as $field) {
            $data[$field] = (int) $data[$field];
        }
        $data['tax_shipping'] = (bool) $data['tax_shipping'];
        $data['tax_basis'] = trim(preg_replace('/\s+/u', ' ', $data['tax_basis']));
        $member = SubscriptionPilotMember::query()->forTenantId($tenantId)->findOrFail($memberId);
        abort_unless($member->currency === 'USD', 422, 'This controlled pilot supports USD subscriptions.');
        $connection = SubscriptionRechargeConnection::query()->forTenantId($tenantId)->findOrFail($data['recharge_connection_id']);
        abort_unless($connection->status === 'verified' && $connection->identifier === $member->details['shop_domain'], 409, 'Select the verified original Recharge store.');
        $lines = $member->details['lines'];
        $months = null;
        $subtotal = 0;
        foreach ($lines as $line) {
            abort_unless(! $line['prepaid'] && $line['billing_months'] === $line['delivery_months'] && ! isset($line['finite_deliveries'])
                && ($months === null || $line['billing_months'] === $months), 422, 'This controlled pilot supports one recurring delivery per charge. Prepaid delivery execution is not enabled.');
            $months = $line['billing_months'];
            $subtotal += $line['unit_price_cents'] * $line['quantity'];
        }
        $taxable = $subtotal + ($data['tax_shipping'] ? $data['shipping_cents'] : 0);
        $tax = intdiv($taxable * $data['tax_rate_bps'] + 5000, 10000);
        $amount = $subtotal + $data['shipping_cents'] + $tax;
        abort_unless($member->expected_amount_cents === $amount && $amount >= 50 && $amount <= 1000000, 422, 'Reviewed product, shipping and tax amounts must equal the prepared recurring total.');
        abort_unless($member->target_date->toDateString() >= now($member->timezone)->toDateString(), 409, 'The first billing date needs a new review; past cycles cannot be charged.');
        $quote = app(SubscriptionPilotShopifyBridge::class)->quote($member);
        $merchant = app(SubscriptionPilotStripeTransport::class)->merchant($tenantId);
        $terms = $quote + ['member_id' => $member->id, 'member_public_id' => $member->public_id, 'name' => $member->details['name'],
            'email' => $member->details['email'], 'shopify_customer_id' => $member->shopify_customer_id, 'recharge_customer_id' => $member->recharge_customer_id,
            'merchant' => $merchant, 'recharge_connection_id' => $connection->id, 'currency' => $member->currency, 'amount_cents' => $amount,
            'subtotal_cents' => $subtotal, 'shipping_cents' => $data['shipping_cents'], 'tax_cents' => $tax, 'tax_rate_bps' => $data['tax_rate_bps'],
            'tax_shipping' => (bool) $data['tax_shipping'], 'tax_basis' => $data['tax_basis'], 'billing_months' => $months,
            'first_billing_date' => $member->target_date->toDateString(), 'billing_hour' => 9, 'timezone' => $member->timezone,
            'policy_version' => 'everbranch-subscriptions-pilot-2026-10-27', 'candle_cash_allowed' => false,
            'cancellation_policy' => 'Pause or cancel future charges from your Modern Forestry account or this subscription page before the next billing date. A payment already processing requires support review.'];
        $terms['terms_hash'] = SubscriptionEvidence::digest(SubscriptionEvidence::canonical($terms));

        return $terms;
    }

    public function prepare(int $tenantId, User $actor, int $memberId, array $input, string $expectedHash): SubscriptionPilotEnrollment
    {
        $terms = $this->preview($tenantId, $actor, $memberId, $input);
        abort_unless(hash_equals($terms['terms_hash'], $expectedHash), 409, 'The merchant, delivery address or quote changed. Review the current terms.');

        return DB::transaction(function () use ($tenantId, $actor, $memberId, $terms) {
            $this->owner($tenantId, $actor->fresh());
            SubscriptionPilotMember::query()->forTenantId($tenantId)->whereKey($memberId)->lockForUpdate()->firstOrFail();
            $existing = SubscriptionPilotEnrollment::query()->forTenantId($tenantId)->where('member_id', $memberId)->first();
            if ($existing) {
                abort_unless($existing->terms_hash === $terms['terms_hash'], 409, 'Existing authorization terms cannot be silently replaced.');

                return $existing;
            }

            return SubscriptionPilotEnrollment::create(['tenant_id' => $tenantId, 'member_id' => $memberId, 'terms' => $terms, 'terms_hash' => $terms['terms_hash']]);
        });
    }

    public function issueAccess(SubscriptionPilotEnrollment $enrollment): string
    {
        $token = Str::random(64);

        return DB::transaction(function () use ($enrollment, $token) {
            $row = $this->locked($enrollment);
            $requests = $row->requests ?? [];
            if (isset($requests['access']) && in_array($row->status, ['draft', 'authorized'], true)) {
                $row->update(['access_expires_at' => now()->addDays(7)]);

                return $this->customerUrl($requests['access']['value']);
            }
            $key = isset($requests['access']) ? 'access_'.Str::uuid() : 'access';
            $requests[$key] = ['value' => $token, 'created_at' => now()->toIso8601String()];
            $row->update(['requests' => $requests, 'access_hash' => hash('sha256', $token), 'access_expires_at' => now()->addDays(7)]);

            return $this->customerUrl($token);
        });
    }

    public function fromAccess(string $token): SubscriptionPilotEnrollment
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);
        $row = SubscriptionPilotEnrollment::query()->where('access_hash', hash('sha256', $token))->firstOrFail();
        // Existing customers retain pause/cancel access if the business loses its Branch.
        abort_unless($row->access_expires_at?->isFuture(), 404);

        return $row;
    }

    public function beginAuthorization(SubscriptionPilotEnrollment $enrollment, string $token, string $termsHash, bool $accepted, string $ip): string
    {
        $this->gate($enrollment->tenant_id, 'pilot_setup_enabled');
        app(SubscriptionAccessService::class)->assertEntitled($enrollment->tenant_id);
        abort_unless($accepted && hash_equals($enrollment->terms_hash, $termsHash) && $this->fromAccess($token)->id === $enrollment->id
            && $enrollment->status === 'draft', 422, 'Review and accept the recurring payment terms first.');
        $stripe = app(SubscriptionPilotStripeTransport::class);
        $terms = $enrollment->terms;
        $stripe->verifyMerchant($enrollment->tenant_id, $terms['merchant']);
        DB::transaction(function () use ($enrollment, $ip) {
            $row = $this->locked($enrollment);
            $requests = $row->requests ?? [];
            if (! isset($requests['consent'])) {
                $requests['consent'] = ['terms_hash' => $row->terms_hash, 'accepted_at' => now()->toIso8601String(), 'ip_digest' => SubscriptionEvidence::digest($ip)];
                $row->update(['requests' => $requests]);
            }
        });
        $row = $enrollment->fresh();
        if (! $row->stripe_customer_id) {
            $request = $this->reserve($row, 'customer', ['email' => $terms['email'], 'name' => $terms['name'],
                'metadata' => ['purpose' => 'everbranch_subscription_pilot', 'enrollment' => $row->public_id]]);
            $customer = $stripe->create($terms['merchant'], 'customer', $request['key'], $request['payload']);
            abort_unless(preg_match('/^cus_[A-Za-z0-9]+$/D', $customer['id'] ?? '') && ($customer['livemode'] ?? null) === $terms['merchant']['livemode']
                && data_get($customer, 'metadata.enrollment') === $row->public_id, 409, 'Stripe customer identity needs review.');
            DB::transaction(function () use ($row, $customer) {
                $locked = $this->locked($row);
                abort_if($locked->stripe_customer_id && $locked->stripe_customer_id !== $customer['id'], 409);
                $locked->update(['stripe_customer_id' => $customer['id']]);
            });
            $row = $row->fresh();
        }
        $metadata = ['purpose' => 'everbranch_subscription_pilot', 'enrollment' => $row->public_id, 'terms_hash' => $row->terms_hash];
        $request = $row->setup_session_id ? $row->requests['setup_session'] : $this->reserve($row, 'setup_session', ['mode' => 'setup', 'currency' => strtolower($terms['currency']), 'customer' => $row->stripe_customer_id, 'payment_method_types' => ['card'],
            'success_url' => $this->customerUrl($token).'?return=1', 'cancel_url' => $this->customerUrl($token),
            'metadata' => $metadata, 'setup_intent_data' => ['metadata' => $metadata],
            'custom_text' => ['submit' => ['message' => 'Authorize '.$terms['currency'].' '.SubscriptionPilotShopifyBridge::decimal($terms['amount_cents']).' every '.$terms['billing_months'].' month(s), starting '.$terms['first_billing_date'].'. '.$terms['cancellation_policy']]]]);
        $session = $row->setup_session_id ? $stripe->read($terms['merchant'], 'setup_session', $row->setup_session_id) : $stripe->create($terms['merchant'], 'setup_session', $request['key'], $request['payload']);
        $this->setupIdentity($row, $session);
        abort_unless(($session['status'] ?? null) === 'open' && parse_url($session['url'] ?? '', PHP_URL_SCHEME) === 'https'
            && parse_url($session['url'] ?? '', PHP_URL_HOST) === 'checkout.stripe.com', 409, 'The original secure setup session needs review.');
        DB::transaction(function () use ($row, $session) {
            $locked = $this->locked($row);
            abort_if($locked->setup_session_id && $locked->setup_session_id !== $session['id'], 409);
            $locked->update(['setup_session_id' => $session['id']]);
        });

        return $session['url'];
    }

    public function reconcileAuthorization(SubscriptionPilotEnrollment $enrollment, ?string $sessionId = null): SubscriptionPilotEnrollment
    {
        $row = $enrollment->fresh();
        $id = $row->setup_session_id ?? $sessionId;
        abort_unless($id && isset($row->requests['setup_session'],$row->requests['consent']), 409, 'The original customer setup request is required.');
        $stripe = app(SubscriptionPilotStripeTransport::class);
        $merchant = $row->terms['merchant'];
        $stripe->verifyMerchant($row->tenant_id, $merchant, false);
        $session = $stripe->read($merchant, 'setup_session', $id);
        $this->setupIdentity($row, $session);
        if (($session['status'] ?? null) !== 'complete') {
            return $row;
        }
        abort_unless(is_string($session['setup_intent'] ?? null), 409);
        $setup = $stripe->read($merchant, 'setup_intent', $session['setup_intent']);
        abort_unless(($setup['id'] ?? null) === $session['setup_intent'] && data_get($setup, 'metadata.purpose') === 'everbranch_subscription_pilot' && ($setup['status'] ?? null) === 'succeeded' && ($setup['usage'] ?? null) === 'off_session' && ($setup['customer'] ?? null) === $row->stripe_customer_id
            && ($setup['livemode'] ?? null) === $merchant['livemode'] && data_get($setup, 'metadata.enrollment') === $row->public_id
            && data_get($setup, 'metadata.terms_hash') === $row->terms_hash && is_string($setup['payment_method'] ?? null), 409, 'Stripe payment authorization is incomplete.');
        $method = $stripe->read($merchant, 'payment_method', $setup['payment_method']);
        abort_unless(($method['id'] ?? null) === $setup['payment_method'] && ($method['type'] ?? null) === 'card'
            && ($method['customer'] ?? null) === $row->stripe_customer_id && ($method['livemode'] ?? null) === $merchant['livemode'], 409, 'The payment method belongs to another customer or merchant.');

        return DB::transaction(function () use ($row, $session, $setup) {
            $current = $this->locked($row);
            abort_if($current->setup_session_id && $current->setup_session_id !== $session['id'], 409);
            abort_if($current->payment_method_id && $current->payment_method_id !== $setup['payment_method'], 409);
            $current->update(['setup_session_id' => $session['id'], 'setup_intent_id' => $setup['id'], 'payment_method_id' => $setup['payment_method'],
                'authorized_at' => $current->authorized_at ?? now(), 'status' => $current->status === 'draft' ? 'authorized' : $current->status]);

            return $current->fresh();
        });
    }

    public function activate(SubscriptionPilotEnrollment $enrollment, User $actor): SubscriptionPilotEnrollment
    {
        $this->owner($enrollment->tenant_id, $actor);
        app(SubscriptionPilotShopifyBridge::class)->assertWriteReady($this->member($enrollment));
        $this->collectionReady($enrollment);
        $row = $this->reconcileAuthorization($enrollment);
        abort_unless(in_array($row->status, ['authorized', 'active'], true) && $row->authorized_at && $row->payment_method_id, 409, 'Customer-present payment authorization is required.');
        $member = $this->member($row);
        abort_unless($row->terms['first_billing_date'] >= now($row->terms['timezone'])->toDateString(), 409, 'Review a new future billing date; past cycles cannot be charged.');
        $proof = app(SubscriptionPilotSourceGuard::class)->stopped($member, $row->terms['recharge_connection_id'], $row->terms['first_billing_date'], $actor);

        return DB::transaction(function () use ($row, $actor, $proof, $member) {
            $current = $this->locked($row);
            $this->owner($row->tenant_id, $actor->fresh());
            abort_unless(in_array($current->status, ['authorized', 'active'], true), 409);
            $current->update(['status' => 'active', 'activated_at' => $current->activated_at ?? now(), 'activated_by' => $actor->id,
                'next_billing_date' => $current->next_billing_date ?? $current->terms['first_billing_date']]);
            $member->update(['billing_owner' => 'stripe', 'status' => 'active']);
            $this->event($member, 'authorization', $current->public_id, 'active', null, null, ['terms_hash' => $current->terms_hash, 'source_stop_proof' => $proof], $actor->id);

            return $current->fresh();
        });
    }

    public function collect(SubscriptionPilotEnrollment $enrollment): SubscriptionPilotCycle
    {
        $row = $enrollment->fresh();
        abort_unless($row->status === 'active' && $row->activated_by && $row->authorized_at && $row->payment_method_id && $row->next_billing_date, 409, 'The reviewed subscription is not active.');
        $actor = User::findOrFail($row->activated_by);
        $this->owner($row->tenant_id, $actor);
        $terms = $row->terms;
        $date = $row->next_billing_date->toDateString();
        $now = now($terms['timezone']);
        abort_unless($date === $now->toDateString() && $now->hour >= $terms['billing_hour'], 409, 'Only the authorized current billing day can be collected.');
        $existing = SubscriptionPilotCycle::query()->forTenantId($row->tenant_id)->where('enrollment_id', $row->id)->whereDate('cycle_date', $date)->first();
        if ($existing?->payment_intent_id) {
            return $this->reconcilePayment($existing);
        }
        if ($existing) {
            $recovered = $this->recoverUnknown($existing);
            if ($recovered->payment_intent_id || data_get($recovered->reconciliation, 'possible_duplicate')) {
                return $recovered;
            }
        }
        $this->collectionReady($row);
        $member = $this->member($row);
        if ($existing?->payment_started_at && $existing->payment_started_at->gt(now()->subMinutes(2))) {
            return $existing;
        }
        abort_if($existing?->payment_started_at?->lt(now()->subHours(23)), 409, 'The original payment must be reconciled before another provider request.');
        $day = CarbonImmutable::parse($date, $terms['timezone'])->startOfDay();
        $payments = app(SubscriptionPilotStripeTransport::class)->customerPayments($terms['merchant'], $row->stripe_customer_id, $day->timestamp, $day->addDay()->timestamp);
        abort_unless(collect($payments)->every(fn ($payment) => ($payment['status'] ?? null) === 'canceled'), 409, 'Another Stripe payment already exists in this billing window.');
        $proof = app(SubscriptionPilotSourceGuard::class)->stopped($member, $terms['recharge_connection_id'], $date, $actor);
        $quote = app(SubscriptionPilotShopifyBridge::class)->quote($member);
        abort_unless($quote['shipping_address'] === $terms['shipping_address'], 409, 'The delivery address changed and needs a new quote review.');
        $cycle = DB::transaction(function () use ($row, $actor, $terms, $date, $proof) {
            $current = $this->locked($row);
            $this->owner($row->tenant_id, $actor->fresh());
            abort_unless($current->status === 'active' && $current->next_billing_date?->toDateString() === $date
                && $current->terms_hash === $row->terms_hash && CarbonImmutable::parse($proof['checked_at'])->gt(now()->subMinutes(1)), 409, 'Billing authorization or source proof changed.');
            if ($current->paused_until && $current->paused_until->toDateString() <= $date) {
                $current->update(['paused_until' => null]);
                $this->member($current)->update(['status' => 'active']);
            }
            $cycle = SubscriptionPilotCycle::query()->forTenantId($row->tenant_id)->where('enrollment_id', $row->id)->whereDate('cycle_date', $date)->lockForUpdate()->first();
            if ($cycle) {
                return $cycle;
            }
            $uuid = (string) Str::uuid();
            $payload = ['amount' => $terms['amount_cents'], 'currency' => strtolower($terms['currency']), 'customer' => $current->stripe_customer_id,
                'payment_method' => $current->payment_method_id, 'payment_method_types' => ['card'], 'off_session' => 'true', 'confirm' => 'true',
                'description' => 'Subscription for '.$terms['first_billing_date'].'; cycle '.$date,
                'metadata' => ['purpose' => 'everbranch_subscription_pilot', 'pilot_cycle' => $uuid, 'pilot_request' => (string) Str::uuid(),
                    'enrollment' => $current->public_id, 'terms_hash' => $current->terms_hash]];

            return SubscriptionPilotCycle::create(['tenant_id' => $row->tenant_id, 'enrollment_id' => $row->id, 'public_id' => $uuid, 'cycle_date' => $date,
                'amount_cents' => $terms['amount_cents'], 'currency' => $terms['currency'], 'terms' => $terms, 'source_evidence' => $proof,
                'stripe_key' => (string) Str::uuid(), 'stripe_payload' => $payload, 'payment_started_at' => now(), 'payment_status' => 'creating']);
        });
        if ($cycle->payment_intent_id) {
            return $this->reconcilePayment($cycle);
        }
        try {
            $payment = app(SubscriptionPilotStripeTransport::class)->create($terms['merchant'], 'payment_intent', $cycle->stripe_key, $cycle->stripe_payload);
            app(SubscriptionPilotPaymentVerifier::class)->identity($cycle, $payment);
            DB::transaction(function () use ($cycle, $payment) {
                $locked = SubscriptionPilotCycle::query()->forTenantId($cycle->tenant_id)->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
                abort_if($locked->payment_intent_id && $locked->payment_intent_id !== $payment['id'], 409, 'A different original payment needs review.');
                $locked->update(['payment_intent_id' => $payment['id']]);
            });
        } catch (\Throwable) {
            $cycle->fresh()->update(['payment_status' => 'unknown', 'last_error_code' => 'original_stripe_payment_unconfirmed']);

            return $cycle->fresh();
        }

        return $this->reconcilePayment($cycle->fresh());
    }

    /** Provider reads recover existing payments even while new collection is paused. */
    public function reconcilePayment(SubscriptionPilotCycle $cycle, bool $handoff = true): SubscriptionPilotCycle
    {
        $verifier = app(SubscriptionPilotPaymentVerifier::class);
        $payment = $verifier->read($cycle);
        $enrollment = SubscriptionPilotEnrollment::query()->forTenantId($cycle->tenant_id)->findOrFail($cycle->enrollment_id);
        if (in_array($enrollment->status, ['paused', 'cancelled'], true)
            && in_array($payment['status'] ?? null, ['requires_action', 'requires_payment_method', 'requires_confirmation', 'requires_capture'], true)) {
            DB::transaction(function () use ($cycle) {
                $row = SubscriptionPilotCycle::query()->forTenantId($cycle->tenant_id)->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
                if (! $row->cancellation_started_at) {
                    $row->update(['cancellation_started_at' => now()]);
                }
            });
            app(SubscriptionPilotStripeTransport::class)->cancel($cycle->terms['merchant'], $cycle->payment_intent_id, $cycle->stripe_key.'_cancel');
            $payment = $verifier->read($cycle);
        }
        $captured = $verifier->captured($cycle, $payment);
        $charge = $payment['latest_charge'] ?? [];
        $refunded = is_array($charge) && (($charge['amount_refunded'] ?? 0) > 0 || ($charge['refunded'] ?? false) === true);
        $status = $captured ? 'succeeded' : ($refunded ? 'refunded' : ($payment['status'] ?? 'unknown'));
        if ($status === 'succeeded' && ! $captured) {
            $status = 'review';
        }
        abort_unless(in_array($status, ['succeeded', 'refunded', 'review', 'processing', 'requires_action', 'requires_payment_method', 'requires_confirmation', 'requires_capture', 'canceled'], true), 409, 'The payment state requires review.');
        DB::transaction(function () use ($cycle, $captured, $status, $payment, $charge) {
            $row = SubscriptionPilotCycle::query()->forTenantId($cycle->tenant_id)->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            $row->update(['payment_status' => $status, 'paid_at' => $captured ? ($row->paid_at ?? now()) : $row->paid_at,
                'last_error_code' => $captured ? null : ($status === 'refunded' ? 'payment_refunded' : 'payment_'.$status)]);
            $enrollment = SubscriptionPilotEnrollment::query()->forTenantId($row->tenant_id)->whereKey($row->enrollment_id)->lockForUpdate()->firstOrFail();
            if ($captured && $enrollment->status === 'active' && $enrollment->next_billing_date?->toDateString() === $row->cycle_date->toDateString()) {
                $first = CarbonImmutable::parse($enrollment->terms['first_billing_date']);
                $months = ($row->cycle_date->year - $first->year) * 12 + $row->cycle_date->month - $first->month;
                abort_unless($months >= 0 && $months % $enrollment->terms['billing_months'] === 0 && $first->addMonthsNoOverflow($months)->toDateString() === $row->cycle_date->toDateString(), 409);
                $enrollment->update(['next_billing_date' => $first->addMonthsNoOverflow($months + $enrollment->terms['billing_months'])->toDateString()]);
            }
            $member = $this->member($enrollment);
            $this->event($member, 'payment', $payment['id'], $captured ? 'success' : ($status === 'refunded' ? 'refunded' : (in_array($status, ['canceled', 'requires_payment_method'], true) ? 'failed' : 'pending')),
                $row->amount_cents, $row->cycle_date->toDateString(), ['payment_intent_id' => $payment['id'], 'charge_id' => is_array($charge) ? ($charge['id'] ?? null) : null,
                    'provider_status' => $status, 'amount_refunded' => is_array($charge) ? ($charge['amount_refunded'] ?? 0) : 0,
                    'account_id' => $row->terms['merchant']['account_id'], 'terms_hash' => $row->terms['terms_hash']]);
        });
        $fresh = $cycle->fresh();
        if ($captured && $handoff) {
            $fresh = app(SubscriptionPilotShopifyBridge::class)->handoff($fresh);
            if ($fresh->handoff_status === 'confirmed') {
                $fresh = $this->verifyCycleCoverage($fresh);
            }
        }

        return $fresh;
    }

    /** Time-bounded observed coverage, never a permanent guarantee about future provider actions. */
    public function verifyCycleCoverage(SubscriptionPilotCycle $cycle): SubscriptionPilotCycle
    {
        $prior = $cycle->fresh()->reconciliation ?? [];
        if (($prior['possible_duplicate'] ?? false) === true) {
            return $cycle->fresh();
        }
        try {
            $row = SubscriptionPilotEnrollment::query()->forTenantId($cycle->tenant_id)->findOrFail($cycle->enrollment_id);
            $actor = User::findOrFail($row->activated_by);
            $proof = app(SubscriptionPilotSourceGuard::class)->stopped($this->member($row), $row->terms['recharge_connection_id'],
                $cycle->cycle_date->toDateString(), $actor, $cycle->shopify_order_id);
            $day = CarbonImmutable::parse($cycle->cycle_date->toDateString(), $cycle->terms['timezone'])->startOfDay();
            $payments = collect(app(SubscriptionPilotStripeTransport::class)->customerPayments($cycle->terms['merchant'],
                $row->stripe_customer_id, $day->timestamp, $day->addDay()->timestamp))->reject(fn ($p) => ($p['status'] ?? null) === 'canceled');
            $extra = $payments->reject(fn ($p) => ($p['id'] ?? null) === $cycle->payment_intent_id);
            abort_unless($payments->count() === 1 && $extra->isEmpty() && $payments->first()['status'] === 'succeeded', 409);
            $coverage = ['status' => 'observed_clear', 'checked_at' => now()->toIso8601String(), 'source' => $proof,
                'stripe_payment_id' => $cycle->payment_intent_id, 'shopify_order_id' => $cycle->shopify_order_id];
        } catch (\Throwable) {
            $coverage = ['status' => 'not_verified', 'checked_at' => now()->toIso8601String()];
            if (isset($extra) && $extra->isNotEmpty()) {
                $coverage['additional_stripe_payment_ids'] = $extra->pluck('id')->all();
            }
        }
        DB::transaction(function () use ($cycle, $coverage) {
            $locked = SubscriptionPilotCycle::query()->forTenantId($cycle->tenant_id)->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            $current = $locked->reconciliation ?? [];
            $current['coverage'] = $coverage;
            if (! empty($coverage['additional_stripe_payment_ids'])) {
                $current['possible_duplicate'] = true;
            }
            $locked->update(['reconciliation' => $current]);
        });

        return $cycle->fresh();
    }

    public function stop(SubscriptionPilotEnrollment $enrollment, string $action): SubscriptionPilotEnrollment
    {
        abort_unless(in_array($action, ['pause', 'cancel'], true), 422);
        $stopped = DB::transaction(function () use ($enrollment, $action) {
            $row = $this->locked($enrollment);
            if ($row->status === 'cancelled') {
                return $row;
            }
            abort_unless(in_array($row->status, ['authorized', 'active', 'paused', 'cancelled'], true), 409, 'No active authorization is available.');
            $row->update(['status' => $action === 'cancel' ? 'cancelled' : 'paused', 'paused_until' => null,
                'next_billing_date' => $action === 'cancel' ? null : $row->next_billing_date]);
            $member = $this->member($row);
            $member->update(['status' => $row->status]);
            $this->event($member, 'customer_action', $row->public_id, $row->status, null, null, ['terms_hash' => $row->terms_hash]);

            return $row->fresh();
        });
        foreach (SubscriptionPilotCycle::query()->forTenantId($stopped->tenant_id)->where('enrollment_id', $stopped->id)
            ->whereNotNull('payment_intent_id')->whereIn('payment_status', ['requires_action', 'requires_payment_method', 'requires_confirmation', 'requires_capture'])->get() as $cycle) {
            try {
                $this->reconcilePayment($cycle, false);
            } catch (\Throwable) {
                $cycle->update(['last_error_code' => 'cancellation_reconciliation_required']);
            }
        }

        return $stopped;
    }

    public function skipCycles(SubscriptionPilotEnrollment $enrollment, int $months): SubscriptionPilotEnrollment
    {
        return DB::transaction(function () use ($enrollment, $months) {
            $row = $this->locked($enrollment);
            $interval = $row->terms['billing_months'];
            abort_unless($row->status === 'active' && $row->next_billing_date && $months > 0 && $months <= 24 && $months % $interval === 0
                && (! $row->paused_until || $row->paused_until->toDateString() <= now($row->terms['timezone'])->toDateString()), 409, 'Review the next delivery before pausing.');
            abort_if(SubscriptionPilotCycle::query()->forTenantId($row->tenant_id)->where('enrollment_id', $row->id)
                ->whereDate('cycle_date', $row->next_billing_date->toDateString())->whereNotNull('payment_started_at')->exists(), 409, 'This cycle is already processing. Review it before pausing another delivery.');
            $first = CarbonImmutable::parse($row->terms['first_billing_date']);
            $elapsed = ($row->next_billing_date->year - $first->year) * 12 + $row->next_billing_date->month - $first->month;
            $resume = $first->addMonthsNoOverflow($elapsed + $months)->toDateString();
            $row->update(['next_billing_date' => $resume, 'paused_until' => $resume]);
            $member = $this->member($row);
            $member->update(['status' => 'paused']);
            $this->event($member, 'customer_action', $row->public_id, 'paused', null, null, ['resume_on' => $resume, 'duration_months' => $months]);

            return $row->fresh();
        });
    }

    /** A signed event only identifies the original object; current Stripe reads prove its state. */
    public function receivePayment(SubscriptionPilotCycle $cycle, string $paymentId): SubscriptionPilotCycle
    {
        $stripe = app(SubscriptionPilotStripeTransport::class);
        $stripe->verifyMerchant($cycle->tenant_id, $cycle->terms['merchant'], false);
        if ($cycle->payment_intent_id && $cycle->payment_intent_id !== $paymentId) {
            $probe = clone $cycle;
            $probe->payment_intent_id = $paymentId;
            $verifier = app(SubscriptionPilotPaymentVerifier::class);
            $additional = $verifier->read($probe);
            // The original order/payment binding never changes. A genuinely
            // observed second object is reported for review, never fulfilled again.
            $captured = $verifier->captured($probe, $additional);
            $enrollment = SubscriptionPilotEnrollment::query()->forTenantId($cycle->tenant_id)->findOrFail($cycle->enrollment_id);
            $additionalRefunded = (int) data_get($additional, 'latest_charge.amount_refunded', 0) > 0;
            $this->event($this->member($enrollment), 'payment', $paymentId, $captured ? 'success' : ($additionalRefunded ? 'refunded' : 'pending'), $cycle->amount_cents,
                $cycle->cycle_date->toDateString(), ['cycle_id' => $cycle->id, 'account_id' => $cycle->terms['merchant']['account_id'], 'additional_payment' => true]);
            $cycle->update(['reconciliation' => ['possible_duplicate' => true, 'original_payment_id' => $cycle->payment_intent_id, 'additional_payment_id' => $paymentId],
                'last_error_code' => 'additional_stripe_payment_requires_review']);

            return $cycle->fresh();
        }
        $payment = $stripe->read($cycle->terms['merchant'], 'payment_intent', $paymentId, ['expand' => ['latest_charge']]);
        app(SubscriptionPilotPaymentVerifier::class)->identity($cycle, $payment);
        DB::transaction(function () use ($cycle, $payment) {
            $row = SubscriptionPilotCycle::query()->forTenantId($cycle->tenant_id)->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            abort_if($row->payment_intent_id && $row->payment_intent_id !== $payment['id'], 409, 'Another payment is already bound to this cycle.');
            $row->update(['payment_intent_id' => $payment['id']]);
        });

        return $this->reconcilePayment($cycle->fresh());
    }

    public function recoverUnknown(SubscriptionPilotCycle $cycle): SubscriptionPilotCycle
    {
        if ($cycle->payment_intent_id) {
            return $this->reconcilePayment($cycle);
        }
        $stripe = app(SubscriptionPilotStripeTransport::class);
        $merchant = $cycle->terms['merchant'];
        $stripe->verifyMerchant($cycle->tenant_id, $merchant, false);
        $day = CarbonImmutable::parse($cycle->cycle_date->toDateString(), $cycle->terms['timezone'])->startOfDay();
        $payments = $stripe->customerPayments($merchant, $cycle->stripe_payload['customer'], $day->timestamp, $day->addDay()->timestamp);
        $matching = collect($payments)->filter(fn ($payment) => data_get($payment, 'metadata.pilot_cycle') === $cycle->public_id
            && data_get($payment, 'metadata.pilot_request') === $cycle->stripe_payload['metadata']['pilot_request']);
        if ($matching->count() === 1) {
            return $this->receivePayment($cycle, $matching->first()['id']);
        }
        if ($matching->count() > 1) {
            $cycle->update(['reconciliation' => ['possible_duplicate' => true, 'payment_ids' => $matching->pluck('id')->all()],
                'last_error_code' => 'multiple_original_payments_require_review']);
        }

        return $cycle->fresh();
    }

    public function authentication(SubscriptionPilotEnrollment $enrollment, SubscriptionPilotCycle $cycle): array
    {
        abort_unless($enrollment->tenant_id === $cycle->tenant_id && $cycle->enrollment_id === $enrollment->id
            && $enrollment->status === 'active' && $enrollment->authorized_at && $enrollment->activated_by, 409);
        $actor = User::findOrFail($enrollment->activated_by);
        $this->owner($enrollment->tenant_id, $actor);
        app(SubscriptionPilotShopifyBridge::class)->assertWriteReady($this->member($enrollment));
        $this->collectionReady($enrollment);
        $verifier = app(SubscriptionPilotPaymentVerifier::class);
        $payment = $verifier->read($cycle);
        abort_unless(($payment['status'] ?? null) === 'requires_action', 409, 'The original payment no longer needs authentication.');
        app(SubscriptionPilotSourceGuard::class)->stopped($this->member($enrollment), $enrollment->terms['recharge_connection_id'], $cycle->cycle_date->toDateString(), $actor);
        $key = (string) config('services.stripe.publishable_key');
        abort_unless(preg_match('/^pk_(?:test|live)_[A-Za-z0-9]+$/D', $key)
            && str_starts_with($key, 'pk_live_') === $enrollment->terms['merchant']['livemode']
            && preg_match('/^pi_[A-Za-z0-9]+_secret_[A-Za-z0-9]+$/D', $payment['client_secret'] ?? ''), 423, 'Secure payment authentication needs review.');

        return ['publishable_key' => $key, 'account_id' => $enrollment->terms['merchant']['account_id'], 'client_secret' => $payment['client_secret']];
    }

    private function setupIdentity(SubscriptionPilotEnrollment $row, array $session): void
    {
        abort_unless(preg_match('/^cs_[A-Za-z0-9_]+$/D', $session['id'] ?? '') && (! $row->setup_session_id || $row->setup_session_id === $session['id'])
            && ($session['mode'] ?? null) === 'setup' && ($session['customer'] ?? null) === $row->stripe_customer_id
            && ($session['livemode'] ?? null) === $row->terms['merchant']['livemode']
            && data_get($session, 'metadata.enrollment') === $row->public_id && data_get($session, 'metadata.terms_hash') === $row->terms_hash, 409, 'Stripe returned a different authorization session.');
    }

    private function reserve(SubscriptionPilotEnrollment $row, string $kind, array $payload): array
    {
        return DB::transaction(function () use ($row, $kind, $payload) {
            $current = $this->locked($row);
            $requests = $current->requests ?? [];
            if (! isset($requests[$kind])) {
                $requests[$kind] = ['key' => (string) Str::uuid(), 'payload' => $payload, 'created_at' => now()->toIso8601String()];
                $current->update(['requests' => $requests]);
            }
            $request = $requests[$kind];
            abort_if(CarbonImmutable::parse($request['created_at'])->lt(now()->subHours(23)), 409, 'The original setup request requires provider reconciliation before retrying.');

            return $request;
        });
    }

    private function owner(int $tenantId, User $actor): void
    {
        app(SubscriptionAccessService::class)->assertManager($tenantId, $actor);
        abort_unless(app(\App\Services\Billing\TenantPaymentSetupAccess::class)->allows(Tenant::findOrFail($tenantId), $actor), 403, 'A verified active owner or admin is required.');
    }

    private function gate(int $tenantId, string $name): void
    {
        abort_unless((bool) config('subscriptions.'.$name) && in_array($tenantId, config('subscriptions.pilot_tenant_ids', []), true), 423, 'This controlled pilot is not enabled.');
    }

    private function collectionReady(SubscriptionPilotEnrollment $row): void
    {
        $this->gate($row->tenant_id, 'pilot_collection_enabled');
        $this->gate($row->tenant_id, 'pilot_handoff_enabled');
        abort_if(SubscriptionPilotCycle::query()->forTenantId($row->tenant_id)->where('enrollment_id', $row->id)->get(['id', 'reconciliation'])
            ->contains(fn ($cycle) => data_get($cycle->reconciliation, 'possible_duplicate') === true), 423, 'An additional payment requires review before another cycle can be collected.');
        abort_unless(app(\App\Services\Billing\TenantPaymentsReadinessService::class)->canCollectCustomerPayments(Tenant::findOrFail($row->tenant_id)), 423, 'Merchant payment readiness is incomplete.');
        abort_unless(! $row->terms['merchant']['livemode'] || config('subscriptions.pilot_live_webhooks_verified'), 423, 'A genuine pilot callback acceptance test is required.');
        if ($row->terms['merchant']['livemode']) {
            $account = \App\Models\TenantPaymentAccount::query()->forTenantId($row->tenant_id)->firstOrFail();
            $proof = data_get($account->metadata, 'subscription_pilot_callback', []);
            abort_unless(($proof['account_id'] ?? null) === $row->terms['merchant']['account_id'] && ($proof['livemode'] ?? null) === true
                && preg_match('/^evt_[A-Za-z0-9]+$/D', $proof['event_id'] ?? ''), 423, 'This merchant needs a genuine connected-account pilot callback.');
        }
        app(SubscriptionPilotStripeTransport::class)->verifyMerchant($row->tenant_id, $row->terms['merchant']);
    }

    private function member(SubscriptionPilotEnrollment $row): SubscriptionPilotMember
    {
        $member = SubscriptionPilotMember::query()->forTenantId($row->tenant_id)->findOrFail($row->member_id);
        $terms = $row->terms;
        abort_unless($member->public_id === $terms['member_public_id'] && $member->shopify_customer_id === $terms['shopify_customer_id']
            && $member->recharge_customer_id === $terms['recharge_customer_id'] && $member->details['lines'] === $terms['lines']
            && $member->shopify_store_id === $terms['shopify_store_id'] && $member->currency === $terms['currency'], 409, 'The reviewed source identities changed.');

        return $member;
    }

    private function locked(SubscriptionPilotEnrollment $row): SubscriptionPilotEnrollment
    {
        return SubscriptionPilotEnrollment::query()->forTenantId($row->tenant_id)->whereKey($row->id)->lockForUpdate()->firstOrFail();
    }

    private function customerUrl(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/subscriptions/manage/'.$token;
    }

    private function event(SubscriptionPilotMember $member, string $kind, string $reference, string $status, ?int $amount, ?string $date, array $evidence, ?int $actorId = null): void
    {
        $key = SubscriptionEvidence::digest(SubscriptionEvidence::canonical([$kind, $reference, $status, $amount, $date, $evidence]));
        SubscriptionPilotEvent::firstOrCreate(['tenant_id' => $member->tenant_id, 'member_id' => $member->id, 'event_key' => $key],
            ['provider' => 'stripe', 'kind' => $kind, 'provider_reference' => $reference, 'status' => $status, 'verification' => 'stripe_read',
                'currency' => $member->currency, 'amount_cents' => $amount, 'cycle_date' => $date, 'occurred_at' => now(), 'observed_at' => now(), 'evidence' => $evidence, 'actor_user_id' => $actorId]);
    }
}
