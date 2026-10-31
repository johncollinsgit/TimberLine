<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionPilotCycle;
use App\Models\SubscriptionPilotEnrollment;
use App\Models\SubscriptionPilotMember;
use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeCredentialRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Everbranch owns scheduling; the original Shopify app bills the saved method and creates its own order. */
class SubscriptionShopifyExecutionService
{
    public function acceptMerchant(int $tenantId, User $actor, int $id, string $hash): array
    {
        $row = $this->row($tenantId, $actor, $id);
        SubscriptionBillingAuthorization::assertCurrent($row);
        abort_unless(hash_equals($row->terms_hash, $hash), 409, 'Review the exact original agreement before accepting this merchant.');
        $proof = $this->method($row, $actor);
        $this->contract($row, true);
        $this->transport()->billingReadiness($row);

        return DB::transaction(function () use ($row, $actor, $proof) {
            $current = $this->locked($row);
            $this->row($row->tenant_id, $actor->fresh(), $row->id);
            SubscriptionBillingAuthorization::assertCurrent($current);
            abort_unless($current->terms_hash === $row->terms_hash && $proof['processor_context'] === $this->transport()->context($current), 409);
            $evidence = $current->authorization_evidence;
            $evidence['shopify_billing_acceptance'] = ['processor_context' => $proof['processor_context'], 'accepted_by' => $actor->id,
                'accepted_at' => now()->toISOString(), 'reconciliation_mode' => 'original_attempt_polling'];
            $current->update(['authorization_evidence' => $evidence]);
            $this->audit($current, $actor, 'shopify_merchant_accepted', ['terms_hash' => $current->terms_hash, 'collection_enabled' => false]);

            return ['merchant_accepted' => true, 'reconciliation_mode' => 'original_attempt_polling', 'collection_enabled' => false];
        });
    }

    public function activate(int $tenantId, User $actor, int $id, string $hash, int $revision): SubscriptionBillingAgreement
    {
        $row = $this->row($tenantId, $actor, $id);
        $original = data_get($row->authorization_evidence, 'shopify_activation.original');
        if ($row->activated_at) {
            abort_unless(is_array($original) && $original['terms_hash'] === $hash && $original['expected_revision'] === $revision
                && $original['actor_id'] === $actor->id, 409, 'This agreement already has an original activation.');

            return $row; // A replay must never undo a subsequent customer pause, skip or cancellation.
        }
        $this->ready($row, $actor);
        abort_unless(config('subscriptions.replacement_activation_enabled') === true && $row->status === 'prepared'
            && $row->terms_hash === $hash && $row->revision === $revision
            && data_get($row->authorization_evidence, 'shopify_adoption.status') === 'adopted_paused'
            && $row->terms['first_billing_date'] >= now($row->terms['timezone'])->toDateString(), 409, 'Review the exact future paused-contract adoption before activation.');
        $this->method($row, $actor);
        $this->contract($row, true);
        $proof = $this->source($row, $row->terms['first_billing_date'], $actor);
        DB::transaction(function () use ($row, $actor, $proof, $hash, $revision) {
            $current = $this->locked($row);
            $this->ready($current, $actor->fresh());
            abort_unless($current->status === 'prepared' && ! $current->activated_at && $current->revision === $revision
                && $current->terms_hash === $hash && $this->sourceFresh($current, $proof), 409);
            $evidence = $current->authorization_evidence;
            $original = data_get($evidence, 'shopify_activation.original');
            if ($original) {
                abort_unless($original['terms_hash'] === $hash && $original['expected_revision'] === $revision && $original['actor_id'] === $actor->id, 409);
            } else {
                $evidence['shopify_activation']['original'] = ['request_uuid' => (string) Str::uuid(), 'terms_hash' => $hash,
                    'expected_revision' => $revision, 'actor_id' => $actor->id, 'processor_context' => $this->transport()->context($current),
                    'source_stop_proof' => $proof, 'requested_at' => now()->toISOString()];
                $this->audit($current, $actor, 'shopify_activation_requested', ['terms_hash' => $hash, 'expected_revision' => $revision]);
            }
            $evidence['shopify_activation']['send_proof'] = $proof;
            $current->update(['authorization_evidence' => $evidence]);
        });
        $row = $row->fresh();
        $contract = $this->contract($row, true);
        abort_unless(SubscriptionShopifyBillingProtocol::sameInstant($contract['nextBillingDate'] ?? null,
            SubscriptionShopifyContractProtocol::firstBillingInstant($row)), 409, 'The original first billing instant changed.');
        if (($contract['status'] ?? null) === 'PAUSED') {
            $this->patch($row, $actor, 'activate');
        }
        $contract = $this->contract($row, true);
        abort_unless(($contract['status'] ?? null) === 'ACTIVE', 409, 'Confirm the original contract activation before switching local billing ownership.');
        $proof = $this->source($row, $row->terms['first_billing_date'], $actor);

        return DB::transaction(function () use ($row, $actor, $proof) {
            $current = $this->locked($row);
            $this->ready($current, $actor->fresh());
            $member = app(SubscriptionReplacementAccess::class)->member($current);
            abort_unless(! $current->activated_at && $current->status === 'prepared' && $member->billing_owner === 'recharge'
                && $current->revision === $row->revision && $this->sourceFresh($current, $proof), 409, 'Local billing authority changed during activation.');
            $evidence = $current->authorization_evidence;
            $evidence['status'] = 'activated';
            $evidence['source_stop_verified'] = true;
            $evidence['source_connection_id'] = $current->method_binding['source_connection_id'];
            $evidence['source_stop_proof'] = $proof;
            $current->update(['status' => 'active', 'activated_at' => now(), 'activated_by' => $actor->id,
                'next_billing_date' => $current->terms['first_billing_date'], 'revision' => $current->revision + 1, 'authorization_evidence' => $evidence]);
            $member->update(['billing_owner' => 'everbranch', 'status' => 'active']);
            $this->audit($current, $actor, 'shopify_activated', app(SubscriptionBillingAgreementService::class)->state($current));

            return $current->fresh();
        });
    }

    public function collect(SubscriptionBillingAgreement $agreement): SubscriptionBillingCycle
    {
        $row = $agreement->fresh();
        $actor = User::findOrFail($row->activated_by);
        if ($row->status === 'paused' && $row->resume_on?->toDateString() === now($row->terms['timezone'])->toDateString()) {
            $this->ready($row, $actor);
            $row = app(SubscriptionBillingAgreementService::class)->resumeScheduled($row);
        }
        abort_unless($row->status === 'active' && $row->activated_at && $row->method_verified_at && $row->next_billing_date, 409);
        $date = $row->next_billing_date->toDateString();
        $existing = SubscriptionBillingCycle::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->whereDate('cycle_date', $date)->first();
        if ($existing?->payment_started_at) {
            return $this->reconcile($existing);
        }
        $this->ready($row, $actor, $existing?->id);
        $clock = now($row->terms['timezone']);
        abort_unless($date === $clock->toDateString() && $clock->hour >= $row->terms['billing_hour'], 409, 'Collect only the reviewed current billing day; never catch up overdue charges.');
        $method = $this->method($row, $actor);
        $this->syncSchedule($row, $existing?->id, true);
        $row = $row->fresh();
        $providerCycle = $this->transport()->billingCycle($row, $date);
        SubscriptionShopifyBillingProtocol::unbilled($row, $providerCycle['subscriptionBillingCycle'] ?? [], $date);
        $proof = $this->source($row, $date, $actor);
        $cycle = DB::transaction(function () use ($row, $actor, $method, $proof, $date) {
            $current = $this->locked($row);
            $existing = SubscriptionBillingCycle::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->whereDate('cycle_date', $date)->lockForUpdate()->first();
            $this->ready($current, $actor->fresh(), $existing?->id);
            abort_unless($current->status === 'active' && $current->revision === $row->revision && $current->next_billing_date?->toDateString() === $date
                && $method['processor_context'] === $this->transport()->context($current) && $this->sourceFresh($current, $proof), 409);
            if ($existing) {
                return $existing;
            }
            $cycle = new SubscriptionBillingCycle(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'processor' => 'shopify',
                'merchant_fingerprint' => SubscriptionEvidence::digest(SubscriptionEvidence::canonical($method['processor_context'])),
                'cycle_date' => $date, 'agreement_revision' => $current->revision, 'amount_cents' => $current->terms['amount_cents'], 'currency' => $current->terms['currency'],
                'terms' => $current->terms + ['terms_hash' => $current->terms_hash, 'original_terms_hash' => $method['original_terms_hash'],
                    'method_type' => $method['method_type'], 'shopify_contract_id' => data_get($current->authorization_evidence, 'shopify_adoption.contract_id')],
                'method_binding' => $current->method_binding, 'source_evidence' => $proof + ['processor_context' => $method['processor_context']], 'request_uuid' => (string) Str::uuid()]);
            $cycle->provider_request = SubscriptionShopifyBillingProtocol::payload($cycle);
            $cycle->save();

            return $cycle->fresh();
        });
        if ($cycle->payment_started_at) {
            return $this->reconcile($cycle);
        }
        try {
            $result = $this->transport()->bill($cycle, $proof);
            $attempt = data_get($result, 'subscriptionBillingAttemptCreate.subscriptionBillingAttempt');
            if (is_array($attempt)) {
                SubscriptionShopifyBillingProtocol::attempt($cycle->fresh(), $attempt);
                $cycle->fresh()->update(['provider_reference' => $attempt['id']]);
            } else {
                $cycle->fresh()->update(['payment_status' => 'unknown', 'last_error_code' => 'original_shopify_attempt_unconfirmed']);
            }
        } catch (\Throwable) {
            $current = $cycle->fresh();
            $current->update(['payment_status' => $current->payment_started_at ? 'unknown' : 'prepared',
                'last_error_code' => $current->payment_started_at ? 'original_shopify_attempt_unconfirmed' : 'shopify_billing_preflight_review']);

            return $current->fresh();
        }

        return $this->reconcile($cycle->fresh());
    }

    /** Reconcile only the original attempt; native Shopify receipts never call the external order bridge. */
    public function reconcile(SubscriptionBillingCycle $original): SubscriptionBillingCycle
    {
        $cycle = $original->fresh();
        $row = SubscriptionBillingAgreement::query()->forTenantId($cycle->tenant_id)->findOrFail($cycle->agreement_id);
        $actor = User::findOrFail($row->activated_by);
        $this->row($row->tenant_id, $actor, $row->id);
        abort_unless($cycle->processor === 'shopify' && $cycle->payment_started_at, 409);
        $attempt = $cycle->provider_reference ? ($this->transport()->billingAttempt($cycle)['subscriptionBillingAttempt'] ?? null) : $this->recoverAttempt($row, $cycle);
        if (! is_array($attempt)) {
            $cycle->update(['payment_status' => $cycle->paid_at ? 'review' : 'unknown', 'last_error_code' => 'original_shopify_attempt_unconfirmed']);

            return $cycle->fresh();
        }
        $receipt = SubscriptionShopifyBillingProtocol::attempt($cycle, $attempt);
        if (in_array($receipt['status'], ['refunded', 'disputed'], true)) {
            $receipt += collect(data_get($cycle->reconciliation, 'latest_shopify_receipt', []))->only(['payment_ids', 'paid_at', 'receipt_digest'])->all();
        }
        $coverage = data_get($cycle->reconciliation, 'coverage', ['status' => 'not_verified', 'checked_at' => null]);
        $cycle->update(['provider_reference' => $attempt['id']]);
        if ($receipt['status'] === 'succeeded') {
            $cycle->update(['shopify_order_id' => $receipt['order_id'], 'reconciliation' => array_replace($cycle->reconciliation ?? [], ['latest_shopify_receipt' => $receipt])]);
            if (! $cycle->paid_at || $cycle->handoff_status !== 'confirmed' || $cycle->cycle_date->gte(now($cycle->terms['timezone'])->subDays(7)->startOfDay())) {
                try {
                    $sourceProof = $this->source($row, $cycle->cycle_date->toDateString(), $actor, $cycle->fresh());
                    $coverage = ['status' => 'observed_clear', 'checked_at' => $sourceProof['checked_at'], 'source_stop_proof' => $sourceProof];
                } catch (\Throwable $error) {
                    $cycle->update(['payment_status' => 'review', 'last_error_code' => 'source_or_duplicate_collection_review',
                        'reconciliation' => array_replace($cycle->reconciliation ?? [], ['coverage' => ['status' => 'not_verified', 'checked_at' => now()->toISOString()],
                            'possible_duplicate' => data_get($cycle->reconciliation, 'possible_duplicate', false) || $error instanceof \App\Exceptions\SubscriptionPilotCoverageException])]);

                    return $cycle->fresh();
                }
            }
        }

        $confirmed = DB::transaction(function () use ($row, $cycle, $receipt, $actor, $coverage) {
            $current = $this->locked($row);
            $attempt = SubscriptionBillingCycle::query()->forTenantId($cycle->tenant_id)->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            $values = ['payment_status' => $receipt['status'], 'last_error_code' => null,
                'reconciliation' => array_replace($attempt->reconciliation ?? [], ['latest_shopify_receipt' => $receipt, 'coverage' => $coverage])];
            if ($receipt['status'] === 'succeeded') {
                $values += ['paid_at' => $attempt->paid_at ?? CarbonImmutable::parse($receipt['paid_at']), 'shopify_order_id' => $receipt['order_id'], 'handoff_status' => 'confirmed'];
            }
            if ($attempt->paid_at && ! in_array($receipt['status'], ['succeeded', 'refunded', 'disputed'], true)) {
                $values['payment_status'] = 'review';
                $values['last_error_code'] = 'original_shopify_paid_attempt_changed';
            }
            if (data_get($attempt->reconciliation, 'possible_duplicate') === true) {
                $values['payment_status'] = 'review';
                $values['last_error_code'] = 'possible_duplicate_shopify_collection';
            }
            $attempt->update($values);
            if ($values['payment_status'] === 'succeeded' && $current->status === 'active'
                && app(SubscriptionReplacementAccess::class)->member($current)->billing_owner === 'everbranch'
                && $current->next_billing_date?->toDateString() === $cycle->cycle_date->toDateString()) {
                $anchor = $current->billing_anchor_date;
                $months = ($cycle->cycle_date->year - $anchor->year) * 12 + $cycle->cycle_date->month - $anchor->month;
                abort_unless($months >= 0 && $months % $cycle->terms['billing_months'] === 0
                    && $anchor->addMonthsNoOverflow($months)->toDateString() === $cycle->cycle_date->toDateString(), 409);
                $current->update(['next_billing_date' => $anchor->addMonthsNoOverflow($months + $cycle->terms['billing_months'])->toDateString(), 'revision' => $current->revision + 1]);
                $this->audit($current, $actor, 'shopify_cycle_confirmed', ['cycle_id' => $cycle->id, 'order_id' => $receipt['order_id']]);
            }

            return $attempt->fresh();
        });
        if ($confirmed->paid_at && $confirmed->handoff_status === 'confirmed' && in_array($confirmed->payment_status, ['succeeded', 'refunded', 'disputed'], true)) {
            app(SubscriptionPilotShopifyReadService::class)->projectNativeReceipt($confirmed, data_get($attempt, 'state.order'));
        }

        return $confirmed;
    }

    private function recoverAttempt(SubscriptionBillingAgreement $row, SubscriptionBillingCycle $cycle): ?array
    {
        $after = null;
        $seen = $matches = [];
        for ($page = 0; $page < 10; $page++) {
            $data = $this->transport()->billingAttempts($cycle, $after);
            $contract = $data['subscriptionContract'] ?? [];
            SubscriptionShopifyBillingProtocol::owned($row, $contract);
            $connection = $contract['billingAttempts'] ?? [];
            abort_unless(is_array($connection['nodes'] ?? null) && is_bool(data_get($connection, 'pageInfo.hasNextPage')), 409, 'Original attempt recovery is incomplete.');
            foreach ($connection['nodes'] as $attempt) {
                abort_unless(is_string($attempt['id'] ?? null) && ! isset($seen[$attempt['id']]) && is_string($attempt['idempotencyKey'] ?? null), 409);
                $seen[$attempt['id']] = true;
                if ($attempt['idempotencyKey'] === $cycle->request_uuid) {
                    $matches[] = $attempt;
                }
            }
            if (! $connection['pageInfo']['hasNextPage']) {
                abort_unless(count($matches) <= 1, 409, 'Multiple provider attempts identify this original cycle; do not collect again.');

                return $matches[0] ?? null;
            }
            $next = data_get($connection, 'pageInfo.endCursor');
            abort_unless(is_string($next) && $next !== '' && $next !== $after && $page < 9, 409, 'Original attempt recovery requires complete bounded coverage.');
            $after = $next;
        }
        abort(409);
    }

    public function syncSchedule(SubscriptionBillingAgreement $original, ?int $excludeCycleId = null, bool $force = false): SubscriptionBillingAgreement
    {
        $row = $original->fresh();
        $actor = User::findOrFail($row->activated_by);
        $this->row($row->tenant_id, $actor, $row->id);
        $sync = ['revision' => $row->revision, 'status' => $row->status, 'next_billing_date' => $row->next_billing_date?->toDateString(),
            'processor_context' => $this->transport()->context($row)];
        if (! $force && data_get($row->authorization_evidence, 'shopify_schedule_sync.state') === $sync) {
            return $row;
        }
        $contract = $this->contract($row, false);
        if (in_array($row->status, ['paused', 'cancelled'], true)) {
            $status = $row->status === 'cancelled' ? 'CANCELLED' : 'PAUSED';
            if (($contract['status'] ?? null) !== $status) {
                $this->patch($row, $actor, $row->status === 'cancelled' ? 'cancel' : 'pause');
            }
        } else {
            $this->ready($row, $actor, $excludeCycleId);
            $this->contract($row, true);
            $this->method($row, $actor);
            abort_unless($row->status === 'active' && $row->next_billing_date && $row->next_billing_date->toDateString() >= now($row->terms['timezone'])->toDateString(), 409);
            $proof = $this->source($row, $row->next_billing_date->toDateString(), $actor);
            DB::transaction(function () use ($row, $proof) {
                $current = $this->locked($row);
                abort_unless($current->revision === $row->revision && $current->status === 'active', 409);
                $evidence = $current->authorization_evidence;
                $evidence['shopify_schedule_proof'] = $proof;
                $current->update(['authorization_evidence' => $evidence]);
            });
            $date = SubscriptionShopifyBillingProtocol::instant($row->terms, $row->next_billing_date->toDateString());
            if (! SubscriptionShopifyBillingProtocol::sameInstant($contract['nextBillingDate'] ?? null, $date)) {
                $this->patch($row, $actor, 'set_date');
            }
            if (($contract['status'] ?? null) !== 'ACTIVE') {
                $this->patch($row, $actor, 'activate');
            }
        }
        $confirmed = $this->contract($row->fresh(), false);
        abort_unless(($confirmed['status'] ?? null) === ['active' => 'ACTIVE', 'paused' => 'PAUSED', 'cancelled' => 'CANCELLED'][$row->status], 409, 'The original Shopify schedule patch is unconfirmed.');
        if ($row->status === 'active') {
            abort_unless(SubscriptionShopifyBillingProtocol::sameInstant($confirmed['nextBillingDate'] ?? null,
                SubscriptionShopifyBillingProtocol::instant($row->terms, $row->next_billing_date->toDateString())), 409);
        }

        DB::transaction(function () use ($row, $sync) {
            $current = $this->locked($row);
            abort_unless($current->revision === $row->revision && $current->status === $row->status, 409);
            $evidence = $current->authorization_evidence;
            $evidence['shopify_schedule_sync'] = ['state' => $sync, 'confirmed_at' => now()->toISOString()];
            $current->update(['authorization_evidence' => $evidence]);
        });

        return $row->fresh();
    }

    public function ready(SubscriptionBillingAgreement $row, User $actor, ?int $excludeCycleId = null): void
    {
        $access = app(SubscriptionReplacementAccess::class);
        $access->owner($row->tenant_id, $actor, $row->member_id, 'collection');
        $access->owner($row->tenant_id, $actor, $row->member_id, 'handoff');
        $access->member($row);
        SubscriptionBillingAuthorization::assertCurrent($row);
        app(SubscriptionShopifyUpdateService::class)->assertNoPendingCommit($row);
        abort_if(SubscriptionBillingAction::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)->where('kind', 'terms_amended')->exists(),
            423, 'Fresh native amended-tax collection preflight must be connected before billing these accepted delivery terms.');
        abort_unless($row->processor === 'shopify' && config('subscriptions.replacement_shopify_collection_enabled') === true, 423, 'Shopify replacement collection is paused.');
        $context = $this->transport()->context($row);
        abort_unless($row->method_binding['environment'] === 'sandbox' || config('subscriptions.replacement_shopify_live_acceptance_verified') === true, 423, 'Actual original Shopify merchant acceptance is required.');
        abort_unless(data_get($row->authorization_evidence, 'shopify_billing_acceptance.processor_context') === $context
            && data_get($row->authorization_evidence, 'shopify_billing_acceptance.reconciliation_mode') === 'original_attempt_polling'
            && is_int(data_get($row->authorization_evidence, 'shopify_billing_acceptance.accepted_by')), 423, 'Accept this original merchant and polling path before activation.');
        abort_if(SubscriptionPilotEnrollment::query()->forTenantId($row->tenant_id)->where('member_id', $row->member_id)->whereIn('status', ['authorized', 'active', 'paused'])->exists(), 409);
        $legacy = SubscriptionPilotEnrollment::query()->forTenantId($row->tenant_id)->where('member_id', $row->member_id)->pluck('id');
        abort_if(SubscriptionPilotCycle::query()->forTenantId($row->tenant_id)->whereIn('enrollment_id', $legacy)
            ->where(fn ($q) => $q->whereIn('payment_status', ['creating', 'prepared', 'unknown', 'processing', 'requires_action', 'review'])
                ->orWhere(fn ($q) => $q->whereDate('cycle_date', '>=', $row->terms['first_billing_date'])->whereNotIn('payment_status', ['canceled', 'requires_payment_method'])))->exists(), 409, 'An original Stripe cycle needs reconciliation.');
        abort_if(SubscriptionBillingCycle::query()->forTenantId($row->tenant_id)->where('agreement_id', $row->id)
            ->when($excludeCycleId, fn ($q) => $q->whereKeyNot($excludeCycleId))->where(fn ($q) => $q
            ->whereIn('payment_status', ['prepared', 'sending', 'unknown', 'processing', 'requires_action', 'review', 'refunded', 'disputed'])
            ->orWhere(fn ($q) => $q->where('payment_status', 'succeeded')->where('handoff_status', '!=', 'confirmed')))->exists(), 423, 'An original payment needs reconciliation before another collection.');
    }

    public function assertDispatch(SubscriptionBillingAgreement $row, SubscriptionBillingCycle $cycle, array $proof): void
    {
        $this->ready($row, User::findOrFail($row->activated_by), $cycle->id);
        $member = app(SubscriptionReplacementAccess::class)->member($row);
        $clock = now($row->terms['timezone']);
        abort_unless($member->billing_owner === 'everbranch' && $row->status === 'active' && $row->activated_at && $row->method_verified_at
            && $row->revision === $cycle->agreement_revision && $cycle->method_binding === $row->method_binding
            && $cycle->provider_request === SubscriptionShopifyBillingProtocol::payload($cycle)
            && $cycle->terms['terms_hash'] === $row->terms_hash && $cycle->cycle_date->toDateString() === $clock->toDateString()
            && $row->next_billing_date?->toDateString() === $cycle->cycle_date->toDateString() && $clock->hour >= $row->terms['billing_hour']
            && $this->sourceFresh($row, $proof), 409, 'Original billing authority or fresh source-stop coverage changed before dispatch.');
    }

    public function assertSchedule(SubscriptionBillingAgreement $row, string $operation): void
    {
        $actor = User::findOrFail($row->activated_by ?? data_get($row->authorization_evidence, 'shopify_activation.original.actor_id'));
        app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor, $row->member_id, 'setup');
        abort_unless(($operation !== 'pause' || $row->status === 'paused') && ($operation !== 'cancel' || $row->status === 'cancelled'), 409);
        if (in_array($operation, ['activate', 'set_date'], true)) {
            $this->ready($row, $actor);
            abort_unless(in_array($row->status, ['prepared', 'active'], true)
                && $this->sourceFresh($row, data_get($row->authorization_evidence, $row->status === 'prepared' ? 'shopify_activation.send_proof' : 'shopify_schedule_proof', [])), 409);
        }
    }

    private function sourceFresh(SubscriptionBillingAgreement $row, array $proof, bool $currentRead = false): bool
    {
        $binding = $row->method_binding;
        $revision = SubscriptionRechargeCredentialRevision::query()->forTenantId($row->tenant_id)->where('connection_id', $binding['source_connection_id'])
            ->whereKey($binding['source_credential_revision_id'])->when($currentRead, fn ($q) => $q->lockForUpdate())->first();

        return ($proof['connection_id'] ?? null) === $binding['source_connection_id'] && ($proof['credential_revision_id'] ?? null) === $binding['source_credential_revision_id']
            && ($proof['source_customer_id'] ?? null) === $row->terms['recharge_customer_id'] && ($proof['pending_charges'] ?? null) === 0
            && ($proof['collected_cycle_charges'] ?? null) === 0 && ($proof['paid_shopify_cycle_orders'] ?? null) === 0
            && is_string($proof['checked_at'] ?? null) && CarbonImmutable::parse($proof['checked_at'])->betweenIncluded(now()->subMinute(), now()->addSeconds(5))
            && SubscriptionRechargeOperationService::enabled($row->tenant_id) && $revision && $revision->status === 'active' && $revision->verified_at && ! $revision->revoked_at && ! $revision->expires_at?->isPast()
            && ! array_diff(['read_store', 'read_customers', 'read_subscriptions', 'read_orders'], $revision->scopes ?? [])
            && data_get($row->authorization_evidence, 'shopify_adoption.original.source_context.credential_fingerprint') === SubscriptionEvidence::digest((string) ($revision->credentials['access_token'] ?? ''))
            && SubscriptionRechargeConnection::query()->forTenantId($row->tenant_id)->whereKey($binding['source_connection_id'])->where('status', 'verified')
                ->where('active_credential_revision_id', $binding['source_credential_revision_id'])->whereNull('read_pause_reason')
                ->when($currentRead, fn ($q) => $q->lockForUpdate())->first() !== null;
    }

    private function source(SubscriptionBillingAgreement $row, string $date, User $actor, ?SubscriptionBillingCycle $cycle = null): array
    {
        $proof = app(SubscriptionPilotSourceGuard::class)->stoppedForShopify($row, $date, $actor, $cycle);
        abort_unless($this->sourceFresh($row, $proof), 409, 'The original source credential or stop coverage changed.');

        return $proof;
    }

    private function contract(SubscriptionBillingAgreement $row, bool $terms): array
    {
        $contract = $this->transport()->contractRead($row, 'contract')['subscriptionContract'] ?? [];
        SubscriptionShopifyBillingProtocol::owned($row, $contract);
        if ($terms) {
            SubscriptionShopifyContractProtocol::assertTerms($row, $contract, data_get($row->authorization_evidence, 'shopify_adoption.original.request_uuid', ''), SubscriptionShopifyUpdateHistory::attributes($row));
        }

        return $contract;
    }

    private function method(SubscriptionBillingAgreement $row, User $actor): array
    {
        $order = data_get($row->authorization_evidence, 'provider_proof.source.source_order_id');
        abort_unless($row->method_verified_at && is_string($order), 409);

        return app(SubscriptionShopifyMethodService::class)->verify($row->tenant_id, $actor, $row->id, $order, false);
    }

    public function amendmentAuthority(SubscriptionBillingAgreement $row, User $actor): array
    {
        abort_unless($row->next_billing_date, 409);

        return ['method' => $this->method($row, $actor), 'source_stop_proof' => $this->source($row, $row->next_billing_date->toDateString(), $actor)];
    }

    public function assertAmendmentAuthority(SubscriptionBillingAgreement $row, array $proof): void
    {
        $method = $proof['method'] ?? [];
        abort_unless($this->sourceFresh($row, $proof['source_stop_proof'] ?? [], true)
            && ($method['provider_verified'] ?? null) === true && ($method['original_method_accessible'] ?? null) === true
            && ($method['original_terms_hash'] ?? null) === SubscriptionBillingAuthorization::original($row)['terms_hash']
            && ($method['processor_context'] ?? null) === $this->transport()->context($row, true)
            && is_string($method['checked_at'] ?? null) && CarbonImmutable::parse($method['checked_at'])->betweenIncluded(now()->subMinute(), now()->addSeconds(5)),
            409, 'Fresh original method and complete source-stop evidence is required before native update dispatch.');
    }

    private function patch(SubscriptionBillingAgreement $row, User $actor, string $operation): void
    {
        $this->audit($row, $actor, 'shopify_schedule_patch_requested', ['operation' => $operation, 'revision' => $row->revision]);
        $result = $this->transport()->schedule($row, $operation, $row->revision);
        $field = ['activate' => 'subscriptionContractActivate', 'pause' => 'subscriptionContractPause', 'cancel' => 'subscriptionContractCancel', 'set_date' => 'subscriptionContractSetNextBillingDate'][$operation];
        abort_unless(isset($result[$field]['userErrors']) && $result[$field]['userErrors'] === [], 409, 'The original Shopify schedule patch needs confirmation.');
    }

    private function row(int $tenantId, User $actor, int $id): SubscriptionBillingAgreement
    {
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->where('processor', 'shopify')->findOrFail($id);
        app(SubscriptionReplacementAccess::class)->owner($tenantId, $actor, $row->member_id, 'setup');
        app(SubscriptionReplacementAccess::class)->member($row);

        return $row;
    }

    private function locked(SubscriptionBillingAgreement $row): SubscriptionBillingAgreement
    {
        SubscriptionPilotMember::query()->forTenantId($row->tenant_id)->whereKey($row->member_id)->lockForUpdate()->firstOrFail();

        return SubscriptionBillingAgreement::query()->forTenantId($row->tenant_id)->whereKey($row->id)->lockForUpdate()->firstOrFail();
    }

    private function audit(SubscriptionBillingAgreement $row, User $actor, string $kind, array $request): void
    {
        SubscriptionBillingAction::create(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'request_uuid' => (string) Str::uuid(),
            'kind' => $kind, 'actor_type' => 'user', 'actor_reference' => (string) $actor->id, 'expected_revision' => $row->revision,
            'request' => $request, 'before_state' => [], 'after_state' => app(SubscriptionBillingAgreementService::class)->state($row)]);
    }

    private function transport(): SubscriptionShopifyBillingTransport
    {
        return app(SubscriptionShopifyBillingTransport::class);
    }
}
