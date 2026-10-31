<?php

namespace App\Services\Subscriptions;

use App\Models\IntegrationConnection;
use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionPilotCycle;
use App\Models\SubscriptionPilotEnrollment;
use App\Models\SubscriptionPilotMember;
use App\Models\SubscriptionRechargeConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Everbranch schedules, Braintree collects, and Shopify only records the verified external payment. */
class SubscriptionBraintreeExecutionService
{
    public function acceptMerchant(int $tenantId, User $actor, int $agreementId, string $expectedHash): array
    {
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $this->access()->owner($tenantId, $actor, $row->member_id, 'setup');
        abort_unless(hash_equals($row->terms_hash, $expectedHash), 409, 'Review this exact agreement before accepting the merchant.');
        $sourceOrder = data_get($row->authorization_evidence, 'provider_proof.source.source_order_id');
        abort_unless(is_string($sourceOrder), 409, 'Verify original method ownership before merchant acceptance.');
        $proof = app(SubscriptionBraintreeMethodService::class)->verify($tenantId, $actor, $row->id, $sourceOrder, false);
        app(SubscriptionBillingShopifySource::class)->currentTerms($row);

        return DB::transaction(function () use ($row, $actor, $proof) {
            $connection = IntegrationConnection::query()->forTenant($row->tenant_id)->whereKey($row->method_binding['connection_id'])->lockForUpdate()->firstOrFail();
            $current = $this->locked($row);
            $this->access()->owner($row->tenant_id, $actor->fresh(), $row->member_id, 'setup');
            abort_unless($current->terms_hash === $row->terms_hash && $this->context($current) === $proof['processor_context'], 409);
            $acceptance = ['processor_context' => $proof['processor_context'], 'accepted_at' => now()->toISOString(), 'accepted_by' => $actor->id,
                'reconciliation_mode' => 'transaction_polling', 'source_mapping_digest' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical($proof))];
            $metadata = $connection->metadata;
            $metadata['subscription_acceptance'] = $acceptance;
            $connection->update(['metadata' => $metadata]);
            $this->audit($current, 'merchant_accepted', [], ['processor_context' => $proof['processor_context'], 'reconciliation_mode' => 'transaction_polling'], $actor->id);

            return ['merchant_accepted' => true, 'reconciliation_mode' => 'transaction_polling', 'collection_enabled' => false];
        });
    }

    public function activate(int $tenantId, User $actor, int $agreementId, int $sourceConnectionId, string $expectedHash, int $expectedRevision): SubscriptionBillingAgreement
    {
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $this->access()->owner($tenantId, $actor, $row->member_id, 'collection');
        abort_unless(config('subscriptions.replacement_activation_enabled') === true
            && in_array($row->status, ['prepared', 'active'], true) && hash_equals($row->terms_hash, $expectedHash)
            && $row->revision === $expectedRevision && $row->terms['first_billing_date'] >= now($row->terms['timezone'])->toDateString(), 409, 'Review the exact future agreement before activation.');
        $this->ready($row, $actor);
        $sourceOrder = data_get($row->authorization_evidence, 'provider_proof.source.source_order_id');
        abort_unless(is_string($sourceOrder), 409, 'Verify the original source order and vaulted method first.');
        $method = app(SubscriptionBraintreeMethodService::class)->verify($tenantId, $actor, $row->id, $sourceOrder, false);
        app(SubscriptionBillingShopifySource::class)->currentTerms($row);
        $member = $this->access()->member($row);
        SubscriptionRechargeConnection::query()->forTenantId($tenantId)->findOrFail($sourceConnectionId);
        $proof = app(SubscriptionPilotSourceGuard::class)->stopped($member, $sourceConnectionId, $row->terms['first_billing_date'], $actor);

        return DB::transaction(function () use ($row, $actor, $method, $proof, $sourceConnectionId) {
            $member = SubscriptionPilotMember::query()->forTenantId($row->tenant_id)->whereKey($row->member_id)->lockForUpdate()->firstOrFail();
            $current = $this->locked($row);
            $this->ready($current, $actor->fresh());
            abort_unless($current->revision === $row->revision && $current->terms_hash === $row->terms_hash
                && in_array($current->status, ['prepared', 'active'], true) && in_array($member->billing_owner, ['recharge', 'everbranch'], true)
                && $this->freshProof($proof) && $this->context($current) === $method['processor_context'], 409, 'The original activation proof changed.');
            $before = app(SubscriptionBillingAgreementService::class)->state($current);
            $evidence = $current->authorization_evidence;
            abort_if(isset($evidence['source_connection_id']) && $evidence['source_connection_id'] !== $sourceConnectionId, 409, 'The original source connection cannot be replaced.');
            $evidence['source_connection_id'] = $sourceConnectionId;
            $evidence['status'] = 'activated';
            $evidence['provider_verified'] = true;
            $evidence['source_stop_verified'] = true;
            $evidence['provider_proof'] = $method;
            $evidence['source_stop_proof'] = $proof;
            $current->update(['status' => 'active', 'method_verified_at' => now(), 'authorization_evidence' => $evidence,
                'activated_at' => $current->activated_at ?? now(), 'activated_by' => $actor->id,
                'next_billing_date' => $current->next_billing_date ?? $current->terms['first_billing_date'],
                'revision' => $current->revision + ($current->status === 'prepared' ? 1 : 0)]);
            $member->update(['billing_owner' => 'everbranch', 'status' => 'active']);
            $this->audit($current, 'activated', $before, app(SubscriptionBillingAgreementService::class)->state($current), $actor->id);

            return $current->fresh();
        });
    }

    public function collect(SubscriptionBillingAgreement $agreement): SubscriptionBillingCycle
    {
        $row = $agreement->fresh();
        if ($row->status === 'paused' && $row->resume_on?->toDateString() === now($row->terms['timezone'])->toDateString()) {
            $this->ready($row, User::findOrFail($row->activated_by));
            $row = app(SubscriptionBillingAgreementService::class)->resumeScheduled($row);
        }
        abort_unless($row->status === 'active' && $row->activated_at && $row->activated_by && $row->method_verified_at && $row->next_billing_date, 409, 'The replacement agreement is not active.');
        $actor = User::findOrFail($row->activated_by);
        $date = $row->next_billing_date->toDateString();
        $clock = now($row->terms['timezone']);
        abort_unless($date === $clock->toDateString() && $clock->hour >= $row->terms['billing_hour'], 409, 'Only the reviewed current billing day may be collected.');
        $this->access()->owner($row->tenant_id, $actor, $row->member_id, 'collection');
        $existing = SubscriptionBillingCycle::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->whereDate('cycle_date', $date)->first();
        if ($existing?->payment_started_at) {
            return $this->reconcile($existing);
        }
        $this->ready($row, $actor);
        $binding = $row->method_binding;
        $method = app(SubscriptionBraintreeMethodService::class)->verify($row->tenant_id, $actor, $row->id,
            (string) data_get($row->authorization_evidence, 'provider_proof.source.source_order_id'), false);
        app(SubscriptionBillingShopifySource::class)->currentTerms($row);
        $day = CarbonImmutable::parse($date, $row->terms['timezone'])->startOfDay();
        $payments = app(SubscriptionBraintreeTransport::class)->window($row->tenant_id, $binding, $day, $day->addDay());
        abort_unless(collect($payments)->every(fn ($payment) => $this->definitelyFailed($payment['status'])), 409, 'Another Braintree payment exists in this customer billing window.');
        $sourceConnection = data_get($row->authorization_evidence, 'source_connection_id');
        abort_unless(is_int($sourceConnection), 409);
        $proof = app(SubscriptionPilotSourceGuard::class)->stopped($this->access()->member($row), $sourceConnection, $date, $actor);
        $cycle = DB::transaction(function () use ($row, $actor, $method, $proof, $date) {
            $member = SubscriptionPilotMember::query()->forTenantId($row->tenant_id)->whereKey($row->member_id)->lockForUpdate()->firstOrFail();
            $current = $this->locked($row);
            $this->ready($current, $actor->fresh());
            abort_unless($member->billing_owner === 'everbranch' && $current->status === 'active' && $current->revision === $row->revision
                && $current->terms_hash === $row->terms_hash && $current->next_billing_date?->toDateString() === $date
                && $this->freshProof($proof) && $this->context($current) === $method['processor_context'], 409, 'Original billing authority or source proof changed.');
            $existing = SubscriptionBillingCycle::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->whereDate('cycle_date', $date)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $cycle = new SubscriptionBillingCycle(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'public_id' => (string) Str::uuid(),
                'processor' => 'braintree', 'merchant_fingerprint' => $method['processor_context']['merchant_fingerprint'], 'cycle_date' => $date,
                'agreement_revision' => $current->revision, 'amount_cents' => $current->terms['amount_cents'], 'currency' => $current->terms['currency'],
                'terms' => $current->terms + ['terms_hash' => $current->terms_hash, 'method_type' => $method['method_type']],
                'method_binding' => $current->method_binding, 'source_evidence' => $proof, 'request_uuid' => (string) Str::uuid()]);
            $cycle->provider_request = SubscriptionBraintreeTransport::payload($cycle);
            $cycle->save();

            return $cycle->fresh();
        });
        $claimed = DB::transaction(function () use ($row, $cycle, $actor) {
            $current = $this->locked($row);
            $attempt = SubscriptionBillingCycle::query()->forTenantId($row->tenant_id)->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            $this->ready($current, $actor->fresh());
            if ($attempt->payment_started_at) {
                return false;
            }
            abort_unless($current->status === 'active' && $current->revision === $attempt->agreement_revision
                && $current->next_billing_date?->toDateString() === $attempt->cycle_date->toDateString()
                && $attempt->payment_status === 'prepared' && $this->freshProof($attempt->source_evidence), 409);
            $attempt->update(['payment_status' => 'sending', 'payment_started_at' => now()]);

            return true;
        });
        if (! $claimed) {
            return $this->reconcile($cycle->fresh());
        }
        try {
            $response = app(SubscriptionBraintreeTransport::class)->sale($cycle->fresh());
            if (($response['request_rejected'] ?? null) === true) {
                $cycle->fresh()->update(['payment_status' => 'declined', 'last_error_code' => 'braintree_request_rejected']);

                return $cycle->fresh();
            }
            $payment = $response['transaction'] ?? [];
            $this->identity($cycle->fresh(), $payment);
            $cycle->fresh()->update(['provider_reference' => $payment['id']]);
        } catch (\Throwable) {
            $cycle->fresh()->update(['payment_status' => 'unknown', 'last_error_code' => 'original_braintree_result_unconfirmed']);

            return $cycle->fresh();
        }

        return $this->reconcile($cycle->fresh());
    }

    public function reconcile(SubscriptionBillingCycle $cycle, bool $handoff = true): SubscriptionBillingCycle
    {
        $cycle = $cycle->fresh();
        $agreement = SubscriptionBillingAgreement::query()->forTenantId($cycle->tenant_id)->findOrFail($cycle->agreement_id);
        $actor = User::findOrFail($agreement->activated_by);
        $this->access()->owner($agreement->tenant_id, $actor, $agreement->member_id, 'setup');
        $this->access()->member($agreement);
        abort_unless($cycle->processor === 'braintree' && $cycle->payment_started_at
            && $cycle->method_binding === $agreement->method_binding && $cycle->merchant_fingerprint === $this->context($agreement)['merchant_fingerprint'], 409);
        $transport = app(SubscriptionBraintreeTransport::class);
        $matches = $transport->cycleTransactions($cycle);
        if (count($matches) > 1) {
            $cycle->update(['payment_status' => 'review', 'last_error_code' => 'possible_duplicate_braintree_transactions',
                'reconciliation' => array_replace($cycle->reconciliation ?? [], ['possible_duplicate' => true, 'checked_at' => now()->toISOString()])]);

            return $cycle->fresh();
        }
        if (! $cycle->provider_reference && count($matches) === 1) {
            $this->identity($cycle, $matches[0]);
            $cycle->update(['provider_reference' => $matches[0]['id']]);
        }
        if (! $cycle->provider_reference) {
            if ($cycle->payment_status !== 'declined') {
                $cycle->update(['payment_status' => 'unknown', 'last_error_code' => 'original_braintree_result_unconfirmed']);
            }

            return $cycle->fresh();
        }
        abort_unless(count($matches) === 1 && $matches[0]['id'] === $cycle->provider_reference, 409, 'Braintree original-result search coverage is incomplete.');
        $payment = $transport->transaction($cycle->tenant_id, $cycle->method_binding, $cycle->provider_reference);
        $this->identity($cycle, $payment);
        $day = CarbonImmutable::parse($cycle->cycle_date->toDateString(), $cycle->terms['timezone'])->startOfDay();
        $window = $transport->window($cycle->tenant_id, $cycle->method_binding, $day, $day->addDay());
        $additional = collect($window)->contains(fn ($row) => $row['id'] !== $cycle->provider_reference && ! $this->definitelyFailed($row['status']));
        $status = $payment['dispute_count'] > 0 ? 'disputed' : ($payment['refund_ids'] !== [] ? 'refunded'
            : ($payment['status'] === 'settled' ? 'succeeded' : ($this->definitelyFailed($payment['status']) || $payment['status'] === 'settlement_declined' ? 'declined' : 'processing')));
        $coverage = ['status' => 'not_verified', 'checked_at' => null];
        if ($status === 'succeeded' && $cycle->cycle_date->gte(now($cycle->terms['timezone'])->subDays(7)->startOfDay())) {
            try {
                $sourceProof = app(SubscriptionPilotSourceGuard::class)->stopped($this->access()->member($agreement),
                    $agreement->authorization_evidence['source_connection_id'], $cycle->cycle_date->toDateString(), $actor, $cycle->shopify_order_id);
                $coverage = ['status' => 'observed_clear', 'checked_at' => $sourceProof['checked_at'], 'source_stop_proof' => $sourceProof,
                    'braintree_checked_at' => now()->toISOString()];
            } catch (\App\Exceptions\SubscriptionPilotCoverageException) {
                $additional = true;
                $coverage['reason'] = 'additional_source_collection_observed';
            } catch (\Throwable) {
                $coverage['reason'] = 'source_billing_or_coverage_requires_review';
            }
        }
        $providerSettled = $status === 'succeeded';
        if ($status === 'succeeded' && $cycle->handoff_status !== 'confirmed' && $coverage['status'] !== 'observed_clear') {
            $status = 'review';
        }
        if (($cycle->paid_at && $status === 'declined') || $additional || data_get($cycle->reconciliation, 'possible_duplicate') === true) {
            $status = 'review';
        }
        DB::transaction(function () use ($cycle, $agreement, $actor, $payment, $status, $additional, $coverage, $providerSettled) {
            $currentAgreement = $this->locked($agreement);
            $current = SubscriptionBillingCycle::query()->forTenantId($cycle->tenant_id)->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            $this->access()->owner($agreement->tenant_id, $actor->fresh(), $agreement->member_id, 'setup');
            abort_unless($current->provider_reference === $payment['id'] && $currentAgreement->method_binding === $cycle->method_binding, 409);
            $before = ['payment_status' => $current->payment_status, 'provider_reference' => $current->provider_reference];
            $current->update(['payment_status' => $status, 'paid_at' => $providerSettled ? ($current->paid_at ?? now()) : $current->paid_at,
                'reconciliation' => array_replace($current->reconciliation ?? [], ['checked_at' => now()->toISOString(), 'provider_status' => $payment['status'],
                    'refund_ids' => $payment['refund_ids'], 'dispute_count' => $payment['dispute_count'],
                    'coverage' => $coverage,
                    'possible_duplicate' => $additional || data_get($current->reconciliation, 'possible_duplicate') === true]),
                'last_error_code' => in_array($status, ['refunded', 'disputed', 'review', 'declined'], true) ? 'braintree_'.$status.'_requires_review' : null]);
            if ($status === 'succeeded' && $currentAgreement->status === 'active'
                && $currentAgreement->next_billing_date?->toDateString() === $cycle->cycle_date->toDateString()) {
                $anchor = $currentAgreement->billing_anchor_date;
                $months = ($cycle->cycle_date->year - $anchor->year) * 12 + $cycle->cycle_date->month - $anchor->month;
                abort_unless($months >= 0 && $months % $currentAgreement->terms['billing_months'] === 0
                    && $anchor->addMonthsNoOverflow($months)->toDateString() === $cycle->cycle_date->toDateString(), 409);
                $currentAgreement->update(['next_billing_date' => $anchor->addMonthsNoOverflow($months + $currentAgreement->terms['billing_months'])->toDateString(), 'revision' => $currentAgreement->revision + 1]);
            }
            if ($before['payment_status'] !== $status) {
                $this->audit($currentAgreement, 'payment_reconciled', $before, ['payment_status' => $status, 'cycle_id' => $cycle->public_id, 'provider_status' => $payment['status']], $actor->id);
            }
        });
        $current = $cycle->fresh();
        if ($status === 'succeeded' && $handoff) {
            $current = app(SubscriptionPilotShopifyBridge::class)->handoff($current);
        }

        return $current;
    }

    /** Current provider read, not local status or a redirect, authorizes external paid-order recording. */
    public function confirmed(SubscriptionBillingCycle $cycle): bool
    {
        abort_unless($cycle->provider_reference && data_get($cycle->reconciliation, 'possible_duplicate') !== true, 409);
        if ($cycle->handoff_status !== 'confirmed') {
            abort_unless(data_get($cycle->reconciliation, 'coverage.status') === 'observed_clear'
                && CarbonImmutable::parse(data_get($cycle->reconciliation, 'coverage.checked_at'))->gt(now()->subMinutes(5)), 409, 'Fresh original source coverage is required before the first Shopify handoff.');
        }
        $transport = app(SubscriptionBraintreeTransport::class);
        $matches = $transport->cycleTransactions($cycle);
        abort_unless(count($matches) === 1 && $matches[0]['id'] === $cycle->provider_reference, 409, 'The original Braintree transaction coverage is incomplete or conflicting.');
        $payment = $transport->transaction($cycle->tenant_id, $cycle->method_binding, $cycle->provider_reference);
        $this->identity($cycle, $payment);
        $day = CarbonImmutable::parse($cycle->cycle_date->toDateString(), $cycle->terms['timezone'])->startOfDay();
        $window = $transport->window($cycle->tenant_id, $cycle->method_binding, $day, $day->addDay());

        return $payment['status'] === 'settled' && $payment['refund_ids'] === [] && $payment['dispute_count'] === 0
            && collect($window)->every(fn ($row) => $row['id'] === $cycle->provider_reference || $this->definitelyFailed($row['status']));
    }

    public function identity(SubscriptionBillingCycle $cycle, array $payment): void
    {
        $started = $cycle->payment_started_at;
        $created = isset($payment['created_at']) ? CarbonImmutable::parse($payment['created_at']) : null;
        abort_unless($cycle->processor === 'braintree' && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $payment['id'] ?? '')
            && (! $cycle->provider_reference || $cycle->provider_reference === $payment['id'])
            && ($payment['order_id'] ?? null) === $cycle->provider_request['orderId'] && ($payment['type'] ?? null) === 'sale'
            && ($payment['customer_id'] ?? null) === $cycle->method_binding['customer_id']
            && ($payment['merchant_account_id'] ?? null) === $cycle->method_binding['merchant_account_id']
            && ($payment['currency'] ?? null) === $cycle->currency && SubscriptionPilotShopifyBridge::cents((string) ($payment['amount'] ?? '')) === $cycle->amount_cents
            && ($payment['instrument_type'] ?? null) === ($cycle->terms['method_type'] === 'card' ? 'credit_card' : 'paypal_account')
            && ($payment['refunded_transaction_id'] ?? null) === null && is_array($payment['refund_ids'] ?? null)
            && is_int($payment['dispute_count'] ?? null) && is_string($payment['status'] ?? null)
            && $started && $created && $created->betweenIncluded($started->subSeconds(30), $started->addMinutes(5)), 409, 'Braintree returned another payment or different original cycle terms.');
    }

    public function ready(SubscriptionBillingAgreement $row, User $actor): void
    {
        $this->access()->owner($row->tenant_id, $actor, $row->member_id, 'collection');
        $this->access()->owner($row->tenant_id, $actor, $row->member_id, 'handoff');
        $this->access()->member($row);
        SubscriptionBillingAuthorization::assertCurrent($row);
        abort_unless($row->processor === 'braintree' && config('subscriptions.replacement_braintree_collection_enabled') === true, 423, 'Braintree replacement collection is paused.');
        abort_if(SubscriptionPilotEnrollment::query()->forTenantId($row->tenant_id)->where('member_id', $row->member_id)->whereIn('status', ['authorized', 'active', 'paused'])->exists(), 409, 'An existing Stripe authorization needs a reviewed handoff first.');
        $legacy = SubscriptionPilotEnrollment::query()->forTenantId($row->tenant_id)->where('member_id', $row->member_id)->pluck('id');
        abort_if(SubscriptionPilotCycle::query()->forTenantId($row->tenant_id)->whereIn('enrollment_id', $legacy)
            ->where(function ($query) use ($row) {
                $query->whereIn('payment_status', ['creating', 'prepared', 'unknown', 'processing', 'requires_action', 'review'])
                    ->orWhere(function ($query) use ($row) {
                        $query->whereDate('cycle_date', '>=', $row->terms['first_billing_date'])->whereNotIn('payment_status', ['canceled', 'requires_payment_method']);
                    });
            })->exists(), 409, 'An original Stripe cycle needs reconciliation before another processor can take over.');
        $context = $this->context($row);
        abort_unless($context['currency'] === $row->terms['currency']
            && ($context['environment'] !== 'production' || config('subscriptions.replacement_braintree_live_acceptance_verified') === true), 423, 'Actual merchant acceptance is required before live Braintree collection.');
        if ($context['environment'] === 'production') {
            $connection = IntegrationConnection::query()->forTenant($row->tenant_id)->findOrFail($context['connection_id']);
            abort_unless(data_get($connection->metadata, 'subscription_acceptance.processor_context') === $context
                && data_get($connection->metadata, 'subscription_acceptance.reconciliation_mode') === 'transaction_polling'
                && is_int(data_get($connection->metadata, 'subscription_acceptance.accepted_by')), 423, 'This original merchant needs verified mapping and polling acceptance.');
        }
        $cycles = SubscriptionBillingCycle::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->get();
        abort_if($cycles->contains(fn ($cycle) => data_get($cycle->reconciliation, 'possible_duplicate') === true
            || in_array($cycle->payment_status, ['unknown', 'processing', 'review', 'refunded', 'disputed'], true)
            || ($cycle->payment_status === 'succeeded' && $cycle->handoff_status !== 'confirmed')), 423, 'An original payment or Shopify handoff needs reconciliation before another collection.');
    }

    private function context(SubscriptionBillingAgreement $row): array
    {
        return app(SubscriptionBraintreeTransport::class)->context($row->tenant_id, $row->method_binding);
    }

    private function definitelyFailed(string $status): bool
    {
        return in_array($status, ['voided', 'processor_declined', 'gateway_rejected', 'failed', 'authorization_expired'], true);
    }

    private function freshProof(array $proof): bool
    {
        return isset($proof['checked_at']) && CarbonImmutable::parse($proof['checked_at'])->betweenIncluded(now()->subMinute(), now()->addSeconds(5));
    }

    private function access(): SubscriptionReplacementAccess
    {
        return app(SubscriptionReplacementAccess::class);
    }

    private function locked(SubscriptionBillingAgreement $row): SubscriptionBillingAgreement
    {
        return SubscriptionBillingAgreement::query()->forTenantId($row->tenant_id)->whereKey($row->id)->lockForUpdate()->firstOrFail();
    }

    private function audit(SubscriptionBillingAgreement $row, string $kind, array $before, array $after, int $actorId): void
    {
        SubscriptionBillingAction::create(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'request_uuid' => (string) Str::uuid(),
            'kind' => $kind, 'actor_type' => 'user', 'actor_reference' => (string) $actorId, 'expected_revision' => $row->revision,
            'request' => ['kind' => $kind, 'terms_hash' => $row->terms_hash], 'before_state' => $before, 'after_state' => $after]);
    }
}
