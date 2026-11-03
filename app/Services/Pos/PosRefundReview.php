<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingProfile;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Owner attestation for a reviewed cumulative refund; never creates a provider refund. */
final class PosRefundReview
{
    public function __construct(private PosStore $store, private PosRefundReviewEvidence $evidence,
        private PosRefundAllocation $allocation, private CanonicalMarketingProfileResolver $identities) {}

    public function preview(array $scope, string $saleId): array
    {
        $this->authorize($scope);
        $sale = $this->sale($scope, $saleId);
        $evidence = $this->evidence->inspect($sale);
        $history = $this->history($sale, $evidence);
        $status = $history['allocation'] === null ? 'none' : $this->status($scope, $saleId)['state'];
        if ($history['revision'] !== $this->history($sale, $evidence)['revision']) {
            throw new PosException('refund_evidence_changed', 409);
        }

        return ['review_version' => Contract::hash([$evidence['review_version'], $history['revision']]), 'confirmed_refunded_minor' => $evidence['refunded_minor'],
            'payment_minor' => (int) $sale->amount_minor, 'tax_minor' => $evidence['snapshot']['terms']['tax_minor'] ?? null,
            'lines' => $evidence['snapshot']['terms']['display'] ?? [],
            'previous_approved_allocation' => $history['allocation'], 'previous_allocation_state' => $status,
            'allocation_is_owner_attested' => true];
    }

    /** Local durable status; a completed provider check is point-in-time evidence only. */
    public function status(array $scope, string $saleId): array
    {
        $this->authorize($scope, false);
        $sale = $this->sale($scope, $saleId);
        $event = $this->store->rows('events', $scope['tenant_id'])->where('sale_id', $sale->id)
            ->where('code', 'customer_refund_allocation_approved')->orderByDesc('id')->first();
        if (! $event) {
            return ['state' => 'not_approved', 'review_reason' => null, 'last_verified_at' => null, 'verification_is_point_in_time' => true];
        }
        $record = $this->store->decrypt($event->details);
        if (! Str::isUuid($record['request_id'] ?? null) || ! is_string($record['local_revision'] ?? null)) {
            throw new PosException('refund_allocation_requires_review', 503);
        }
        $suffix = substr(hash('sha256', $record['request_id']), 0, 32);
        $effect = $this->store->rows('outbox', $scope['tenant_id'])->where('sale_id', $sale->id)
            ->where('effect_key', 'customer_refunds_review_'.$suffix)->first();
        $postcheck = $this->store->rows('outbox', $scope['tenant_id'])->where('sale_id', $sale->id)
            ->where('effect_key', 'reward_allocation_postcheck_'.$suffix)->first();
        $state = $effect->state ?? 'review';
        $reason = $effect->error_code ?? null;
        if ($state === 'completed') {
            $state = $postcheck?->state === 'completed' ? 'completed' : ($postcheck?->state === 'review' ? 'review' : 'verification_pending');
            $reason = $postcheck?->error_code;
        }
        if (! hash_equals($record['local_revision'], app(PosCustomerPurchases::class)->refundRevision($sale))) {
            $state = 'review';
            $reason = 'refund_evidence_changed';
        }
        $snapshot = app(PosReceiptCustomers::class)->decorate($sale, app(PosService::class)->snapshot($sale));
        if (! is_string($record['reward_context_hash'] ?? null)
            || ! hash_equals($record['reward_context_hash'], PosRefundReviewEvidence::rewardContextHash($snapshot))) {
            $state = 'review';
            $reason = 'refund_customer_context_changed';
        }

        return ['state' => $state, 'review_reason' => $reason,
            'last_verified_at' => $postcheck?->state === 'completed' ? $postcheck->completed_at : null,
            'verification_is_point_in_time' => true];
    }

    public function approve(array $scope, string $saleId, array $input): array
    {
        $this->authorize($scope);
        if (($input['confirmed'] ?? null) !== true || ! Str::isUuid($input['request_id'] ?? null)
            || ! is_string($input['review_version'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $input['review_version'])
            || ! is_string($input['reason'] ?? null) || trim($input['reason']) === '' || mb_strlen($input['reason']) > 500
            || preg_match('/[\p{C}]/u', $input['reason']) || ! is_array($input['allocation'] ?? null)) {
            throw new PosException('refund_allocation_invalid', 422);
        }
        $sale = $this->sale($scope, $saleId);
        // Fresh Square reads and journal retrieval happen before any DB lock.
        $evidence = $this->evidence->inspect($sale);
        $calculated = $this->allocation->validate($evidence['snapshot']['terms'], (int) $sale->amount_minor,
            $evidence['refunded_minor'], $input['allocation']);
        $inputHash = Contract::hash([$input['review_version'], $input['allocation'], trim($input['reason'])]);
        $requestId = strtolower($input['request_id']);
        $this->store->db->transaction(function () use ($scope, $sale, $evidence, $input, $inputHash, $requestId, $calculated): void {
            $this->authorize($scope);
            $tenant = $scope['tenant_id'];
            $profileId = $evidence['profile_id'];
            // Receipt association edits use this same tenant stock mutex.
            app(\App\Services\Inventory\StockLedger::class)->rows('mutexes', $tenant)->lockForUpdate()->firstOrFail();
            $profile = MarketingProfile::query()->where('tenant_id', $tenant)->whereKey($profileId)->lockForUpdate()->first();
            if (! $profile || $this->identities->byId($tenant, $profileId)?->id !== $profileId) {
                throw new PosException('customer_identity_review_required', 409);
            }
            $current = $this->store->rows('sales', $tenant)->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            if ($current->state !== 'succeeded' || $current->attempt_id !== $sale->attempt_id
                || ! hash_equals($current->snapshot_hash, $sale->snapshot_hash)
                || ! hash_equals($evidence['local_revision'], app(PosCustomerPurchases::class)->refundRevision($current))) {
                throw new PosException('refund_evidence_changed', 409);
            }
            $currentSnapshot = app(PosReceiptCustomers::class)->decorate($current, app(PosService::class)->snapshot($current));
            if (! hash_equals($evidence['reward_context_hash'], PosRefundReviewEvidence::rewardContextHash($currentSnapshot))) {
                throw new PosException('refund_evidence_changed', 409);
            }
            $reviewExists = $this->store->rows('events', $tenant)->where('sale_id', $sale->id)
                ->where('code', 'customer_refund_allocation_review')->lockForUpdate()->get()->contains(function ($event) use ($profileId, $evidence): bool {
                    $details = $this->store->decrypt($event->details);

                    return ($details['profile_id'] ?? null) === $profileId && ($details['refunded_minor'] ?? null) === $evidence['refunded_minor'];
                });
            if (! $reviewExists) {
                throw new PosException('refund_allocation_requires_review', 409);
            }
            $previous = null;
            foreach ($this->store->rows('events', $tenant)->where('sale_id', $sale->id)
                ->where('code', 'customer_refund_allocation_approved')->orderBy('id')->lockForUpdate()->get() as $event) {
                $record = $this->store->decrypt($event->details);
                if (($record['request_id'] ?? null) === $requestId || ($record['local_revision'] ?? null) === $evidence['local_revision']) {
                    if (($record['request_id'] ?? null) === $requestId && hash_equals((string) ($record['input_hash'] ?? ''), $inputHash)) {
                        // A named owner may retry the same approved allocation after
                        // a spent-credit shortfall has been addressed. Never reset
                        // unrelated review or uncertain provider evidence.
                        $key = 'customer_refunds_review_'.substr(hash('sha256', $requestId), 0, 32);
                        $this->store->rows('outbox', $tenant)->where('sale_id', $sale->id)->where('effect_key', $key)
                            ->where('state', 'review')->whereIn('error_code', ['customer_rewards_shortfall_review', 'refund_allocation_unavailable'])
                            ->update(['state' => 'retry', 'available_at' => now(), 'error_code' => null]);

                        return;
                    }
                    throw new PosException('refund_allocation_conflict', 409);
                }
                $previous = $record;
            }
            $history = $this->history($current, $evidence, true);
            if (! hash_equals(Contract::hash([$evidence['review_version'], $history['revision']]), $input['review_version'])) {
                throw new PosException('refund_evidence_changed', 409);
            }
            if ($previous && ! $this->extendsPrevious($previous['allocation'] ?? [], $input['allocation'])) {
                throw new PosException('refund_allocation_conflict', 409);
            }
            $this->store->event($tenant, $sale->id, 'customer_refund_allocation_approved', [
                'request_id' => $requestId, 'input_hash' => $inputHash, 'actor_id' => $scope['actor_id'],
                'reason' => trim($input['reason']), 'owner_attested' => true, 'snapshot_hash' => $sale->snapshot_hash,
                'profile_id' => $profileId, 'confirmed_refunded_minor' => $evidence['refunded_minor'],
                'local_revision' => $evidence['local_revision'], 'reward_context_hash' => $evidence['reward_context_hash'],
                'coverage_fingerprint' => $evidence['coverage_fingerprint'],
                'review_version' => $evidence['review_version'], 'allocation' => $input['allocation'], 'calculated' => $calculated,
            ]);
            app(PosService::class)->enqueue($tenant, $sale->id, 'customer_refunds_review_'.substr(hash('sha256', $requestId), 0, 32), []);
        }, 3);
        app(PosOutbox::class)->drainSale($scope, $sale->id);
        // A ledger adjustment queues its post-write check in the same transaction.
        app(PosOutbox::class)->drainSale($scope, $sale->id);
        $key = 'customer_refunds_review_'.substr(hash('sha256', $requestId), 0, 32);
        $effect = $this->store->rows('outbox', $scope['tenant_id'])->where('sale_id', $sale->id)->where('effect_key', $key)->first();
        $postcheck = $this->store->rows('outbox', $scope['tenant_id'])->where('sale_id', $sale->id)
            ->where('effect_key', 'reward_allocation_postcheck_'.substr(hash('sha256', $requestId), 0, 32))->first();
        $state = $effect->state ?? 'review';
        $reason = $effect->error_code ?? null;
        if ($state === 'completed') {
            $state = $postcheck?->state === 'completed' ? 'completed' : ($postcheck?->state === 'review' ? 'review' : 'verification_pending');
            $reason = $postcheck?->error_code;
        }

        return ['accepted' => true, 'owner_attested' => true, 'state' => $state, 'review_reason' => $reason];
    }

    /** Validate each durable owner attestation before exposing or extending it. */
    private function history(object $sale, array $evidence, bool $lock = false): array
    {
        $query = $this->store->rows('events', (int) $sale->tenant_id)->where('sale_id', $sale->id)
            ->where('code', 'customer_refund_allocation_approved')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $previous = null;
        $revisions = [];
        foreach ($query->get() as $event) {
            $record = $this->store->decrypt($event->details);
            if (($record['owner_attested'] ?? null) !== true || ($record['snapshot_hash'] ?? null) !== $sale->snapshot_hash
                || ($record['profile_id'] ?? null) !== $evidence['profile_id'] || ! is_array($record['allocation'] ?? null)
                || ! is_int($record['confirmed_refunded_minor'] ?? null)) {
                throw new PosException('refund_allocation_requires_review', 503);
            }
            $this->allocation->validate($evidence['snapshot']['terms'], (int) $sale->amount_minor,
                $record['confirmed_refunded_minor'], $record['allocation']);
            if ($previous !== null && ! $this->extendsPrevious($previous, $record['allocation'])) {
                throw new PosException('refund_allocation_requires_review', 503);
            }
            $previous = $record['allocation'];
            $revisions[] = [$event->id, Contract::hash($record)];
        }

        return ['allocation' => $previous, 'revision' => Contract::hash($revisions)];
    }

    private function extendsPrevious(array $old, array $new): bool
    {
        if (! is_int($old['tax_minor'] ?? null) || ! is_int($new['tax_minor'] ?? null) || $new['tax_minor'] < $old['tax_minor']) {
            return false;
        }
        $next = [];
        foreach ($new['lines'] ?? [] as $line) {
            $next[$line['line_id']] = $line['units'];
        }
        foreach ($old['lines'] ?? [] as $line) {
            if (! isset($next[$line['line_id']]) || array_diff($line['units'], $next[$line['line_id']])) {
                return false;
            }
        }

        return true;
    }

    private function sale(array $scope, string $saleId): object
    {
        $sale = $this->store->rows('sales', $scope['tenant_id'])->where('id', $saleId)->where('register_id', $scope['register']->id)->first();
        if (! $sale) {
            throw new PosException('sale_not_found', 404);
        }

        return $sale;
    }

    private function authorize(array $scope, bool $requireEnabled = true): void
    {
        if ($requireEnabled && (config('pos_live.partial_refund_reward_review_enabled', false) !== true
            || config('pos_live.partial_refund_reward_resolution_enabled', false) !== true)) {
            throw new PosException('refund_allocation_unavailable', 503);
        }
        if (! is_int($scope['tenant_id'] ?? null) || $scope['tenant_id'] < 1 || ! is_int($scope['actor_id'] ?? null)
            || $scope['actor_id'] < 1 || ! in_array($scope['role'] ?? null, ['owner', 'tenant_owner', 'admin'], true)
            || ! is_object($scope['register'] ?? null)) {
            throw new PosException('organization_admin_required', 403);
        }
    }
}
