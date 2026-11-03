<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTaskCompletion;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CandleClubMembershipService;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\PaymentJournal;

/** Original sale terms + confirmed payment/refunds -> the existing rewards ledger. */
final class PosCustomerRewards
{
    public function __construct(private PosStore $store, private PaymentJournal $journal,
        private CanonicalMarketingProfileResolver $identities) {}

    public function policy(int $tenant): ?array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('tenant_marketing_settings')) {
            return null;
        }
        $settings = TenantMarketingSetting::query()->forTenantId($tenant)
            ->whereIn('key', ['candle_cash_program_config', 'candle_cash_policy_config', 'candle_cash_access_state'])->get()->keyBy('key');
        if (! isset($settings['candle_cash_program_config'], $settings['candle_cash_policy_config'])) {
            return null;
        }
        $program = $settings['candle_cash_program_config']->value;
        $policy = $settings['candle_cash_policy_config']->value;

        $access = $settings->get('candle_cash_access_state')?->value ?? ($policy['access_state'] ?? []);

        return ['version' => Contract::hash([$program, $policy, $access]), 'program' => $program, 'policy' => $policy, 'access' => $access];
    }

    public function freeze(array $scope, ?array $customer): array
    {
        $setup = $this->store->decrypt($scope['register']->configuration)['customer_rewards'] ?? [];
        $current = $this->policy($scope['tenant_id']);
        if (($setup['enabled'] ?? false) !== true) {
            return ['enabled' => false, 'version' => $current['version'] ?? null];
        }
        if (! $current || ($setup['policy_version'] ?? null) !== $current['version']) {
            throw new PosException('customer_rewards_policy_unreviewed', 503);
        }
        if (($current['access']['launch_state'] ?? 'published') !== 'published' || ($current['access']['test_mode'] ?? false)) {
            throw new PosException('published_live_tenant_program_required', 503);
        }
        $rules = $current['policy']['earning_rules'] ?? [];
        $channel = $rules['rewardable_channels'] ?? $current['program']['rewardable_channels'] ?? null;
        if ($channel !== 'show_issued_online_redeemed' || ($rules['event_rewards_enabled'] ?? false) !== true) {
            throw new PosException('show_rewards_not_enabled', 503);
        }
        if (! empty($rules['spend_threshold_rewards']) && ($setup['threshold_mode'] ?? null) !== 'highest_per_purchase') {
            throw new PosException('reward_threshold_terms_unreviewed', 503);
        }
        if ($customer === null) {
            return ['enabled' => true, 'anonymous' => true, 'version' => $current['version'], 'rules' => $rules,
                'program' => $current['program'], 'threshold_mode' => $setup['threshold_mode'] ?? null, 'redemption' => 'online_only',
                'captured_at' => now()->toIso8601String(), 'refund_policy' => 'recalculate_original_rules'];
        }
        $profile = $this->identities->byId($scope['tenant_id'], $customer['id']);
        $history = app(CustomerCommerceSnapshotService::class)->forProfile($profile, $scope['tenant_id'], 100);
        $prior = collect($history['purchases'])->filter(fn ($p) => $p['confirmed']
            && $p['amount_minor'] !== null && ($p['refunded_minor'] ?? 0) < $p['amount_minor'])->count();

        return ['enabled' => true, 'version' => $current['version'], 'rules' => $rules,
            'program' => $current['program'], 'order_number' => $prior + 1,
            'member' => app(CandleClubMembershipService::class)->isActiveMember($profile),
            'threshold_mode' => $setup['threshold_mode'] ?? null, 'redemption' => 'online_only',
            'refund_policy' => 'recalculate_original_rules'];
    }

    public function apply(object $sale, ?array $refund = null): void
    {
        $snapshot = app(PosReceiptCustomers::class)->decorate($sale, app(PosService::class)->snapshot($sale));
        if (empty($snapshot['customer']['id'])) {
            return;
        }
        if (($snapshot['customer_rewards']['historical_review_required'] ?? false) === true) {
            throw new PosException('customer_rewards_historical_review');
        }
        $payment = $this->journal->get((int) $sale->tenant_id, $sale->attempt_id);
        if ($sale->state !== 'succeeded' || $payment['state'] !== 'succeeded' || $payment['kind'] !== 'payment'
            || $payment['sale_id'] !== $sale->id || ! $payment['provider_id']
            || $payment['request_id'] !== $sale->payment_request_id || (int) $payment['amount_minor'] !== (int) $sale->amount_minor
            || Contract::hash($payment['binding']) !== Contract::hash($snapshot['binding'])) {
            throw new PosException('paid_evidence_required');
        }
        if (($payment['binding']['environment'] ?? '') !== 'live') {
            return; // Sandbox collection can never change a real rewards balance.
        }
        if ($refund && ($refund['kind'] !== 'refund' || $refund['state'] !== 'succeeded'
            || $refund['original_id'] !== $sale->attempt_id || $refund['original_provider_id'] !== $payment['provider_id'])) {
            throw new PosException('confirmed_refund_required');
        }
        // Journal verification owns its committed boundary. Never call it from the wallet transaction.
        $purchases = app(PosCustomerPurchases::class);
        $refundRevision = $purchases->refundRevision($sale);
        $refunded = $purchases->confirmedRefunds($sale, $refund);
        if (! hash_equals($refundRevision, $purchases->refundRevision($sale))) {
            throw new PosException('customer_refund_evidence_changed');
        }
        $approvedEvidence = null;
        if (config('pos_live.partial_refund_reward_review_enabled', false) === true
            && config('pos_live.partial_refund_reward_resolution_enabled', false) === true
            && $refunded > 0 && $refunded < (int) $sale->amount_minor) {
            $approvalExists = $this->store->rows('events', (int) $sale->tenant_id)->where('sale_id', $sale->id)
                ->where('code', 'customer_refund_allocation_approved')->get()->contains(function ($event) use ($refundRevision): bool {
                    return ($this->store->decrypt($event->details)['local_revision'] ?? null) === $refundRevision;
                });
            if ($approvalExists) {
                // A delayed retry must refresh Square evidence before changing the ledger.
                $approvedEvidence = app(PosRefundReviewEvidence::class)->inspect($sale);
            }
        }
        $review = $this->store->db->transaction(function () use ($sale, $snapshot, $refund, $refunded, $refundRevision, $purchases, $approvedEvidence): ?string {
            $tenant = (int) $sale->tenant_id;
            $id = (int) $snapshot['customer']['id'];
            if ($approvedEvidence) {
                app(\App\Services\Inventory\StockLedger::class)->rows('mutexes', $tenant)->lockForUpdate()->firstOrFail();
            }
            MarketingProfile::query()->forTenantId($tenant)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($this->identities->byId($tenant, $id)?->id !== $id) {
                throw new PosException('customer_identity_review_required');
            }
            $sale = $this->store->rows('sales', $tenant)->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($refundRevision, $purchases->refundRevision($sale))) {
                throw new PosException('customer_refund_evidence_changed');
            }
            if ($approvedEvidence) {
                $currentSnapshot = app(PosReceiptCustomers::class)->decorate($sale, app(PosService::class)->snapshot($sale));
                if (! hash_equals($approvedEvidence['reward_context_hash'], PosRefundReviewEvidence::rewardContextHash($currentSnapshot))
                    || ! hash_equals($approvedEvidence['reward_context_hash'], PosRefundReviewEvidence::rewardContextHash($snapshot))) {
                    throw new PosException('customer_refund_evidence_changed');
                }
            }
            if ($refund) {
                $seen = $this->store->rows('events', $tenant)->where('sale_id', $sale->id)->where('code', 'customer_refund_confirmed')
                    ->get()->contains(fn ($e) => $this->store->decrypt($e->details)['operation_id'] === $refund['id']);
                if (! $seen) {
                    $this->store->event($tenant, $sale->id, 'customer_refund_confirmed', ['operation_id' => $refund['id']]);
                }
            }
            $terms = $snapshot['customer_rewards'] ?? ['enabled' => false];
            if (! $terms['enabled']) {
                return null;
            }
            $subtotal = $snapshot['terms']['rewardable_subtotal_minor'] ?? array_sum(array_map(fn ($line) => $line['unit_amount_minor'] * $line['quantity'], $snapshot['sale']['lines']));
            $remaining = null;
            $resolvedAllocation = false;
            $approvedRequestId = null;
            if (config('pos_live.partial_refund_reward_review_enabled', false) !== true && $subtotal > 0 && $refunded > 0
                && $refunded < (int) $sale->amount_minor && $this->store->rows('events', $tenant)->where('sale_id', $sale->id)
                    ->whereIn('code', ['customer_refund_allocation_review', 'customer_refund_allocation_approved'])->lockForUpdate()->exists()) {
                // Never replace a previously reviewed sale with the legacy
                // estimate simply because the rollout flag was switched off.
                return 'refund_allocation_unavailable';
            }
            if (config('pos_live.partial_refund_reward_review_enabled', false) === true
                && $subtotal > 0 && $refunded > 0 && $refunded < (int) $sale->amount_minor) {
                if ($approvedEvidence && ($approvedEvidence['profile_id'] ?? null) === $id
                    && ($approvedEvidence['refunded_minor'] ?? null) === $refunded
                    && hash_equals($refundRevision, $approvedEvidence['local_revision'])) {
                    foreach ($this->store->rows('events', $tenant)->where('sale_id', $sale->id)
                        ->where('code', 'customer_refund_allocation_approved')->lockForUpdate()->get() as $event) {
                        $approved = $this->store->decrypt($event->details);
                        if (($approved['owner_attested'] ?? null) !== true || ($approved['profile_id'] ?? null) !== $id
                            || ($approved['local_revision'] ?? null) !== $refundRevision || ($approved['snapshot_hash'] ?? null) !== $sale->snapshot_hash
                            || ($approved['reward_context_hash'] ?? null) !== $approvedEvidence['reward_context_hash']
                            || ($approved['confirmed_refunded_minor'] ?? null) !== $refunded
                            || ($approved['review_version'] ?? null) !== $approvedEvidence['review_version']
                            || ($approved['coverage_fingerprint'] ?? null) !== $approvedEvidence['coverage_fingerprint']) {
                            continue;
                        }
                        $allocation = app(PosRefundAllocation::class)->validate($snapshot['terms'], (int) $sale->amount_minor, $refunded, $approved['allocation']);
                        if ($allocation !== ($approved['calculated'] ?? null)) {
                            throw new PosException('refund_allocation_requires_review', 409);
                        }
                        $remaining = $allocation['remaining_rewardable_minor'];
                        $resolvedAllocation = true;
                        $approvedRequestId = $approved['request_id'] ?? null;
                        if (! is_string($approvedRequestId) || ! \Illuminate\Support\Str::isUuid($approvedRequestId)) {
                            throw new PosException('refund_allocation_requires_review', 409);
                        }
                        break;
                    }
                }
                if (! $resolvedAllocation) {
                    // Square's amount-only refund does not identify returned items or tax allocation.
                    // Preserve confirmed evidence but leave the wallet untouched until allocation is reviewed.
                    $key = Contract::hash(['allocation_review', $tenant, $sale->id, $id, $refunded, (int) $sale->amount_minor, $subtotal]);
                    $existing = $this->store->rows('events', $tenant)->where('sale_id', $sale->id)->where('code', 'customer_refund_allocation_review')
                        ->get()->contains(fn ($e) => ($this->store->decrypt($e->details)['review_key'] ?? null) === $key);
                    if (! $existing) {
                        $this->store->event($tenant, $sale->id, 'customer_refund_allocation_review', [
                            'review_key' => $key, 'profile_id' => $id, 'refunded_minor' => $refunded,
                            'payment_minor' => (int) $sale->amount_minor, 'rewardable_subtotal_minor' => $subtotal,
                        ]);
                    }

                    return 'customer_rewards_refund_allocation_review';
                }
            }
            $remaining ??= self::proportionalRemaining($subtotal, (int) $sale->amount_minor, $refunded);
            $rules = $terms['rules'];
            $desired = 0;
            if ($remaining > 0 && ($rules['enable_order_based_earning'] ?? true)) {
                $ordinal = (int) $terms['order_number'];
                $field = [1 => 'first_order_reward_amount', 2 => 'second_order_reward_amount', 3 => 'third_order_reward_amount'][$ordinal] ?? null;
                $milestone = $field && ($rules['enable_milestone_rewards'] ?? true) ? ($rules[$field] ?? ($ordinal === 2 ? ($terms['program'][$field] ?? 0) : 0)) : 0;
                $milestoneKey = substr(Contract::hash(['milestone', $tenant, $id, $ordinal]), 0, 32);
                $claim = $this->store->rows('events', $tenant)->where('sale_id', $milestoneKey)->where('code', 'customer_milestone_claimed')->first();
                $legacy = $ordinal === 2 && CandleCashTaskCompletion::query()->where('marketing_profile_id', $id)
                    ->whereNotNull('awarded_at')->whereHas('task', fn ($q) => $q->where('handle', 'second-order'))->exists();
                if ($milestone > 0 && ! $legacy && (! $claim || $this->store->decrypt($claim->details)['sale_id'] === $sale->id)) {
                    $desired += $this->minor($milestone);
                    if (! $claim) {
                        $this->store->event($tenant, $milestoneKey, 'customer_milestone_claimed', ['sale_id' => $sale->id, 'profile_id' => $id]);
                    }
                }
                $threshold = 0;
                foreach ($rules['spend_threshold_rewards'] ?? [] as $row) {
                    if ($remaining >= $this->minor($row['spend_dollars'])) {
                        $threshold = max($threshold, $this->minor($row['reward_dollars']));
                    }
                }
                $desired += $threshold;
                if ($terms['member'] && ($rules['candle_club_multiplier_enabled'] ?? false)) {
                    $desired = (int) round($desired * (float) $rules['candle_club_multiplier_value']);
                }
            }
            $prefix = substr(Contract::hash([$this->store->runtime(), $tenant, $sale->id]), 0, 40);
            $posted = (int) round(CandleCashTransaction::query()->where('marketing_profile_id', $id)
                ->where('source', 'everbranch_pay')->where('source_id', 'like', $prefix.':%')->sum('candle_cash_delta') * 100);
            $delta = $desired - $posted;
            $profile = MarketingProfile::query()->forTenantId($tenant)->findOrFail($id);
            $balance = app(CandleCashService::class)->ensureBalance($profile);
            $balance = CandleCashBalance::query()->whereKey($balance->getKey())->lockForUpdate()->firstOrFail();
            $originalTarget = $this->store->rows('events', $tenant)->where('sale_id', $sale->id)->where('code', 'customer_reward_target')->first();
            if ($originalTarget) {
                $desired = min($desired, $this->store->decrypt($originalTarget->details)['maximum_minor']);
            } elseif ($delta >= 0) {
                $cap = $terms['program']['max_wallet_balance_dollars'] ?? null;
                if ($cap !== null) {
                    $desired = min($desired, max(0, $this->minor($cap) - (int) round($balance->balance * 100)));
                }
                $this->store->event($tenant, $sale->id, 'customer_reward_target', ['maximum_minor' => $desired, 'policy_version' => $terms['version']]);
            }
            $delta = $desired - $posted;
            if ($delta < 0 && (int) round($balance->balance * 100) < -$delta) {
                $this->store->event($tenant, $sale->id, 'customer_rewards_shortfall', ['profile_id' => $id,
                    'required_minor' => -$delta, 'available_minor' => (int) round($balance->balance * 100)]);

                return 'customer_rewards_shortfall_review'; // Commit refund evidence; never obstruct the provider refund.
            }
            if ($delta !== 0) {
                $eventKey = $prefix.':'.substr(Contract::hash(['target_minor' => $desired]), 0, 32);
                app(CandleCashService::class)->addRewardAmountIdempotent($profile, $delta / 100,
                    'everbranch_pay', $eventKey, $delta > 0 ? 'earn' : 'adjust', 'Everbranch Pay purchase or refund');
            }

            if ($resolvedAllocation) {
                // The provider can change after the pre-write read. Persist a
                // second verification effect atomically with the ledger change.
                app(PosService::class)->enqueue($tenant, $sale->id, 'reward_allocation_postcheck_'.substr(hash('sha256', $approvedRequestId), 0, 32), [
                    'review_version' => $approvedEvidence['review_version'], 'profile_id' => $id,
                ]);
            }

            if ($refunded === (int) $sale->amount_minor) {
                // A full refund determines the remaining eligible amount exactly; retire only this
                // sale's prior allocation-only reviews after the reward adjustment succeeds.
                $superseded = $this->store->rows('outbox', $tenant)->where('sale_id', $sale->id)
                    ->whereIn('state', ['review', 'retry'])->where('error_code', 'customer_rewards_refund_allocation_review')
                    ->update(['state' => 'completed', 'completed_at' => now(), 'error_code' => null]);
                if ($superseded) {
                    $this->store->event($tenant, $sale->id, 'customer_refund_review_superseded', ['profile_id' => $id, 'refunded_minor' => $refunded]);
                }
            }

            return null;
        }, 3);
        if ($review) {
            throw new PosException($review);
        }
    }

    /** Amount-only refunds cannot identify returned units; this is a proportional estimate. */
    public static function proportionalRemaining(int $subtotal, int $payment, int $refunded): int
    {
        if ($subtotal < 0 || $payment < 1 || $refunded < 0 || $refunded > $payment) {
            throw new PosException('customer_refund_evidence_changed');
        }

        return intdiv($subtotal * ($payment - $refunded), $payment);
    }

    private function minor(mixed $amount): int
    {
        if (! is_numeric($amount) || (float) $amount < 0 || (float) $amount > 1000000) {
            throw new PosException('reward_terms_invalid');
        }

        return (int) round((float) $amount * 100);
    }
}
