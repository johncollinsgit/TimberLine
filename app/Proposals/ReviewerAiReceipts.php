<?php

namespace App\Proposals;

use App\Enterprise\RecordStore;
use App\Models\TeamOperationRecord;
use Illuminate\Support\Str;

/** Encrypted, short-lived sharing receipts. This class NEVER reserves money, calls a provider or grants access. */
final class ReviewerAiReceipts
{
    public const KIND = 'ent_proposal_ai';

    public const PREVIEW_SECONDS = 120;

    public const CONTENT_SECONDS = 600;

    public function __construct(private ReviewerAiStore $store, private ReviewerAiConnection $connection, private ReviewerAiResponses $provider) {}

    private function binding(array $scope): array
    {
        abort_unless(is_string($scope['browser_hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $scope['browser_hash']), 403);

        return ['version_hash' => $scope['version']->content_hash, 'build_id' => $scope['manifest']['build_id'], 'source_hash' => $scope['manifest']['source_hash']];
    }

    private function locked(array $scope, callable $callback): mixed
    {
        $this->connection->settings($scope);
        $tenant = $this->store->tenant();

        // Receipt tenant transaction ONLY. Never nest a budget/global lock or provider IO inside this callback.
        return app(RecordStore::class)->locked($tenant, function () use ($scope, $callback) {
            $this->connection->settings($scope);

            return $callback();
        });
    }

    private function preview(TeamOperationRecord $row): array
    {
        $data = $row->data;
        abort_unless(now()->timestamp < $data['content_expires_at'] && ! isset($data['content_scrubbed_at']), 410,
            'The private sharing content expired. No new provider request was made.');

        return ['id' => $row->id, 'request_id' => $data['request_id'], 'digest' => $data['digest'], 'snapshot_digest' => $data['snapshot_digest'],
            'question' => $data['question'], 'snapshot' => $data['snapshot'],
            ...($data['history'] ?? [] ? ['history' => $data['history']] : []),
            ...(isset($data['workspace_context']) ? ['workspace' => $data['workspace_context']] : []), 'maximum_microusd' => $data['maximum_microusd'], 'expires_at' => $data['preview_expires_at'], 'content_expires_at' => $data['content_expires_at']];
    }

    private function row(array $scope, array $receipt): TeamOperationRecord
    {
        $keys = array_keys($receipt);
        sort($keys, SORT_STRING);
        $binding = $this->binding($scope);
        abort_unless($keys === ['binding', 'digest', 'preview_id', 'request_id', 'snapshot_digest']
            && is_string($receipt['preview_id']) && Str::isUuid($receipt['preview_id'])
            && is_string($receipt['request_id']) && Str::isUuid($receipt['request_id'])
            && is_array($receipt['binding']) && count($receipt['binding']) === 3
            && array_diff_key($receipt['binding'], $binding) === [], 422);
        foreach ($binding as $key => $value) {
            abort_unless(is_string($receipt['binding'][$key]) && hash_equals($value, $receipt['binding'][$key]), 422);
        }
        foreach (['digest', 'snapshot_digest'] as $key) {
            abort_unless(is_string($receipt[$key]) && preg_match('/^[a-f0-9]{64}$/D', $receipt[$key]), 422);
        }
        $row = $this->store->row(self::KIND, $receipt['preview_id']);
        $data = $row->data;
        abort_unless(hash_equals($data['browser_hash'], $scope['browser_hash']) && $data['version_id'] === $scope['version']->id
            && $data['binding'] === $this->binding($scope), 404);
        abort_unless($data['request_id'] === $receipt['request_id'] && hash_equals($data['digest'], $receipt['digest'])
            && hash_equals($data['snapshot_digest'], $receipt['snapshot_digest']), 409, 'This receipt belongs to different sharing fields.');
        abort_unless(hash_equals($data['settings_sha256'], $this->connection->settings($scope)['settings_sha256']), 409,
            'The private AI connection changed. Retain the original request for reconciliation.');

        return $row;
    }

    /** Resolve only accepted, still-private receipts under this exact browser/edition/settings and evidence digest.
     * No browser-provided answer text, native-user bridge, new storage lifetime or provider-side memory.
     */
    private function history(array $scope, array $receipts, string $snapshotDigest): array
    {
        abort_unless(array_is_list($receipts) && count($receipts) <= 2, 422);
        $turns = [];
        $ids = [];
        $expiry = now()->timestamp + self::CONTENT_SECONDS;
        foreach ($receipts as $receipt) {
            abort_unless(is_array($receipt), 422);
            $row = $this->row($scope, $receipt);
            abort_unless($row->status === 'accepted' && ! in_array($row->id, $ids, true)
                && hash_equals($row->data['snapshot_digest'], $snapshotDigest)
                && ($row->data['workspace_context'] ?? null) === ($scope['workspace_context'] ?? null), 409,
                'Follow-ups require accepted answers for these exact current fictional fields.');
            $preview = $this->preview($row);
            $result = $row->data['result'];
            $turns[] = ['question' => $preview['question'], 'answer' => $result['answer'], 'refs' => $result['refs']];
            $ids[] = $row->id;
            $expiry = min($expiry, $row->data['content_expires_at']);
        }

        return ['turns' => $turns, 'expires_at' => $expiry];
    }

    public function prepare(array $scope, string $question, array $snapshot, string $requestId, array $historyReceipts = []): array
    {
        abort_unless(Str::isUuid($requestId), 422);
        $settings = $this->connection->settings($scope);
        $binding = $this->binding($scope);
        $payload = $this->provider->payload(trim($question), $snapshot);
        $this->provider->requestBody($payload, $settings['max_output_tokens']); // Actual escaped wire bound BEFORE receipt or reservation.
        $snapshotDigest = hash('sha256', json_encode($payload['snapshot'], JSON_THROW_ON_ERROR));
        $history = $this->history($scope, $historyReceipts, $snapshotDigest);
        $payload = $this->provider->payload(trim($question), $snapshot, $history['turns']);
        $this->provider->requestBody($payload, $settings['max_output_tokens']);
        $digest = hash('sha256', json_encode([$scope['version']->id, $binding, $scope['browser_hash'], $settings['settings_sha256'], $requestId, $payload, ...(isset($scope['workspace_context']) ? [$scope['workspace_context']] : [])], JSON_THROW_ON_ERROR));
        $source = 'proposal-review-ai:'.$scope['version']->id.':'.$scope['browser_hash'].':'.$requestId;

        return $this->locked($scope, function () use ($scope, $settings, $binding, $payload, $requestId, $snapshotDigest, $digest, $source, $history, $historyReceipts): array {
            $freshHistory = $this->history($scope, $historyReceipts, $snapshotDigest);
            abort_unless($freshHistory['turns'] === $history['turns']
                && ($historyReceipts === [] || $freshHistory['expires_at'] === $history['expires_at']), 409);
            $existing = TeamOperationRecord::query()->where(['tenant_id' => $this->store->tenant()->id, 'area_id' => $this->store->areaId(),
                'kind' => self::KIND, 'source_key' => hash('sha256', $source)])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->data['digest'], $digest), 409, 'This immutable preview key belongs to other sharing fields.');

                return $this->preview($existing);
            }
            $active = TeamOperationRecord::query()->where(['tenant_id' => $this->store->tenant()->id, 'kind' => self::KIND])->where('voice_expires_at', '>', now())->limit(101)->get();
            abort_if($active->count() >= 100 || $active->filter(fn ($row) => hash_equals($row->data['browser_hash'], $scope['browser_hash']))->count() >= 10,
                429, 'Wait for an existing private sharing preview to expire before preparing another.');
            $row = $this->store->create(self::KIND, $source, ['schema_version' => 1, 'browser_hash' => $scope['browser_hash'],
                'version_id' => $scope['version']->id, 'binding' => $binding, 'settings_sha256' => $settings['settings_sha256'], 'request_id' => $requestId,
                'digest' => $digest, 'snapshot_digest' => $snapshotDigest, 'created_at' => now()->timestamp,
                'preview_expires_at' => min(now()->timestamp + self::PREVIEW_SECONDS, $history['expires_at']), 'content_expires_at' => $history['expires_at'],
                'history' => $history['turns'], 'history_receipts' => $historyReceipts,
                ...(isset($scope['workspace_context']) ? ['workspace_context' => $scope['workspace_context']] : []),
                'question' => $payload['question'], 'snapshot' => $payload['snapshot'], 'maximum_microusd' => $settings['maximum_microusd'], 'charge_id' => null], 'prepared');
            $row->voice_expires_at = now()->setTimestamp($history['expires_at']);
            $row->save();
            $this->store->audit($row, 'proposal_review_ai.preview_prepared', ['request_id' => $requestId, 'digest' => $digest]);

            return $this->preview($row);
        });
    }

    /** Durable once-only claim. A crash after this point is recovery/reconciliation, never automatic redispatch. */
    public function claim(array $scope, array $receipt): array
    {
        return $this->locked($scope, function () use ($scope, $receipt): array {
            $row = $this->row($scope, $receipt);
            abort_unless($row->status === 'prepared', 409, 'The existing request was already claimed. Check its status; do not send again.');
            abort_unless(now()->timestamp < $row->data['preview_expires_at'], 410, 'Review fresh sharing fields after this preview expires.');
            abort_if(TeamOperationRecord::query()->where(['tenant_id' => $row->tenant_id, 'kind' => self::KIND])
                ->whereIn('status', ['claimed', 'reserved', 'dispatching', 'uncertain'])->exists(), 409,
                'An earlier campaign request is pending or uncertain. Reconcile it before another provider request.');
            $this->history($scope, $row->data['history_receipts'] ?? [], $row->data['snapshot_digest']);
            $this->store->save($row, $row->data + ['claim_at' => now()->timestamp], 'claimed');
            $this->store->audit($row, 'proposal_review_ai.request_claimed', ['request_id' => $row->data['request_id'], 'digest' => $row->data['digest']]);

            return ['preview' => $this->preview($row), 'settings_sha256' => $row->data['settings_sha256']];
        });
    }

    /** Last content/consent read after all admission work and immediately before HTTP. No transaction or paid side effect. */
    public function dispatchPayload(array $scope, array $receipt): array
    {
        $row = $this->row($scope, $receipt);
        abort_unless($row->status === 'dispatching' && is_string($row->data['charge_id'])
            && $this->charge($row, $row->data['charge_id'])->status === 'dispatching', 409);
        $preview = $this->preview($row);
        $history = $this->history($scope, $row->data['history_receipts'] ?? [], $row->data['snapshot_digest']);
        abort_unless($history['turns'] === ($row->data['history'] ?? []), 409);

        abort_unless(($row->data['workspace_context'] ?? null) === ($scope['workspace_context'] ?? null), 409);
        $payload = $this->provider->payload($preview['question'], $preview['snapshot'], $history['turns']);
        if (isset($scope['workspace_context'])) {
            abort_unless(is_callable($scope['workspace_recheck'] ?? null), 503);
            ($scope['workspace_recheck'])(); // Last server revision/permission read before HTTP, outside every lock.
        }
        // Authority reads can consume the remainder of consent's lifetime. This
        // final pure check follows every DB callback and cannot renew old turns.
        abort_unless(now()->timestamp < min($preview['content_expires_at'], $history['expires_at']), 410,
            'The private sharing content expired. No new provider request was made.');

        return $payload;
    }

    private function charge(TeamOperationRecord $row, string $id): TeamOperationRecord
    {
        $charge = $this->store->row('ent_trial_charge', $id);
        abort_unless(($charge->data['channel'] ?? null) === 'ai' && ($charge->data['input_hash'] ?? null) === $row->data['digest']
            && ($charge->data['reserved_microusd'] ?? null) === $row->data['maximum_microusd'], 409, 'The exact campaign charge does not match this receipt.');

        return $charge;
    }

    public function attachCharge(array $scope, array $receipt, string $id): void
    {
        $this->locked($scope, function () use ($scope, $receipt, $id): void {
            $row = $this->row($scope, $receipt);
            abort_unless($row->status === 'claimed' && $row->data['charge_id'] === null, 409);
            abort_unless($this->charge($row, $id)->status === 'reserved', 409);
            $this->store->save($row, array_replace($row->data, ['charge_id' => $id]), 'reserved');
            $this->store->audit($row, 'proposal_review_ai.charge_attached', ['charge_id' => $id]);
        });
    }

    public function markDispatched(array $scope, array $receipt): void
    {
        $this->locked($scope, function () use ($scope, $receipt): void {
            $row = $this->row($scope, $receipt);
            abort_unless($row->status === 'reserved' && is_string($row->data['charge_id'])
                && $this->charge($row, $row->data['charge_id'])->status === 'dispatching', 409);
            $this->store->save($row, $row->data + ['dispatch_claimed_at' => now()->timestamp], 'dispatching');
            $this->store->audit($row, 'proposal_review_ai.dispatch_recorded', ['charge_id' => $row->data['charge_id']]);
        });
    }

    /** The caller settles cost truth BEFORE recording any answer. Fresh Access/connection must be rechecked after provider IO. */
    public function finish(array $scope, array $receipt, string $status, ?array $answer = null): array
    {
        abort_unless(in_array($status, ['accepted', 'rejected', 'uncertain'], true), 422);

        return $this->locked($scope, function () use ($scope, $receipt, $status, $answer): array {
            $row = $this->row($scope, $receipt);
            $this->preview($row);
            abort_unless($row->status === 'dispatching' && is_string($row->data['charge_id']), 409);
            $charge = $this->charge($row, $row->data['charge_id']);
            abort_unless($charge->status === $status, 409, 'Settle the exact provider cost before recording its answer.');
            $result = null;
            if ($status === 'accepted') {
                abort_unless(is_array($answer), 422);
                $value = $this->provider->value(json_encode($answer, JSON_THROW_ON_ERROR), $row->data['snapshot']);
                $result = $value + ['provider_mode' => 'openai_generated', 'provider_dispatch' => true,
                    'provider_simulated' => app()->environment('testing'), 'provider_calls_live_verified' => false];
            }
            $this->store->save($row, $row->data + ['result' => $result, 'settled_at' => now()->timestamp], $status);
            $this->store->audit($row, 'proposal_review_ai.outcome_recorded', ['charge_id' => $charge->id, 'status' => $status]);

            return $this->show($scope, $receipt);
        });
    }

    /** Exact owned receipt metadata only. Never returns question, snapshot, answer or a token. */
    public function workspaceContext(array $scope, array $receipt): ?array
    {
        return $this->row($scope, $receipt)->data['workspace_context'] ?? null;
    }

    /** Pure recovery read: no lock upsert, expiry cleanup, budget.status(), reservation, settlement or provider call. */
    public function show(array $scope, array $receipt): array
    {
        $this->connection->settings($scope);
        $row = $this->row($scope, $receipt);
        $value = ['preview_id' => $row->id, 'request_id' => $row->data['request_id'], 'digest' => $row->data['digest'],
            'snapshot_digest' => $row->data['snapshot_digest'], 'status' => $row->status];
        if (($scope['workspace_stale'] ?? false) === true || now()->timestamp >= $row->data['content_expires_at'] || isset($row->data['content_scrubbed_at'])) {
            return $value + ['content_expired' => true];
        }

        return $value + ['content_expired' => false, 'preview' => $this->preview($row)]
            + ($row->status === 'accepted' ? $row->data['result'] : []);
    }

    /** Atomic revocation of consent ONLY before claim. Never releases a paid reservation or an uncertain request. */
    public function cancel(array $scope, array $receipt): array
    {
        return $this->locked($scope, function () use ($scope, $receipt): array {
            $row = $this->row($scope, $receipt);
            if ($row->status === 'cancelled') {
                return $this->show($scope, $receipt);
            }
            abort_unless($row->status === 'prepared' && $row->data['charge_id'] === null, 409,
                'The original request was claimed and cannot be cancelled or refunded.');
            $data = $row->data;
            unset($data['question'], $data['snapshot'], $data['result'], $data['history'], $data['history_receipts']);
            $row->voice_expires_at = null;
            $this->store->save($row, $data + ['content_scrubbed_at' => now()->timestamp, 'cancelled_at' => now()->timestamp], 'cancelled');
            $this->store->audit($row, 'proposal_review_ai.preview_cancelled', ['request_id' => $data['request_id'], 'digest' => $data['digest']]);

            return $this->show($scope, $receipt);
        });
    }

    /** Explicit bounded maintenance only. Retain content-free tombstones and ALL independent charge/cost evidence. */
    public function prune(int $limit = 100): array
    {
        abort_unless($limit >= 1 && $limit <= 100, 422);
        $tenant = $this->store->tenant();

        return app(RecordStore::class)->locked($tenant, function () use ($tenant, $limit): array {
            $this->store->tenant();
            $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $this->store->areaId(), 'kind' => self::KIND])
                ->whereNotNull('voice_expires_at')->where('voice_expires_at', '<=', now())->orderBy('voice_expires_at')->limit($limit)->get();
            foreach ($rows as $row) {
                $data = $row->data;
                unset($data['question'], $data['snapshot'], $data['result'], $data['history'], $data['history_receipts']);
                $this->store->save($row, $data + ['content_scrubbed_at' => now()->timestamp]);
                $row->voice_expires_at = null;
                $row->save();
                $this->store->audit($row, 'proposal_review_ai.content_scrubbed', ['request_id' => $data['request_id'], 'digest' => $data['digest']]);
            }

            return ['scrubbed' => $rows->count(), 'limit' => $limit];
        });
    }
}
