<?php

namespace App\Proposals;

use App\Enterprise\Provisioning\ClientSilo;
use App\Enterprise\RecordStore;
use App\Enterprise\Trial\TrialBudget;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Accounting/erasure only, including after deactivation. No admission, initialization, refund or redispatch. */
final class ReviewerAiRecovery
{
    private const PIN_FIELDS = ['client_id', 'silo_id', 'mount', 'tenant_id', 'area_id', 'ledger_id', 'inventory_evidence_hash', 'prior_spend_evidence_hash', 'prior_spend_microusd'];

    public function pins(array $approvedSettings): array
    {
        $pins = array_intersect_key($approvedSettings, array_flip(self::PIN_FIELDS));
        $this->anchor($pins);

        return $pins;
    }

    private function anchor(array $pins): array
    {
        $keys = array_keys($pins);
        $expected = self::PIN_FIELDS;
        sort($keys);
        sort($expected);
        abort_unless($keys === $expected && config('enterprise.runtime') === true
            && config('service_proposals.runtime_purpose') === 'proposal_owner', 503);
        foreach (['client_id', 'silo_id', 'area_id', 'ledger_id'] as $key) {
            abort_unless(is_string($pins[$key]) && Str::isUuid($pins[$key]), 503);
        }
        foreach (['inventory_evidence_hash', 'prior_spend_evidence_hash'] as $key) {
            abort_unless(is_string($pins[$key]) && preg_match('/^[a-f0-9]{64}$/D', $pins[$key]), 503);
        }
        abort_unless(is_int($pins['tenant_id']) && $pins['tenant_id'] > 0 && is_int($pins['prior_spend_microusd'])
            && $pins['prior_spend_microusd'] >= 0 && $pins['prior_spend_microusd'] <= TrialBudget::CAP
            && $pins['mount'] === '/enterprise-owner' && $pins['mount'] === config('enterprise_runtime.base_path')
            && $pins['client_id'] === config('enterprise_client.client_id') && $pins['silo_id'] === config('enterprise_client.silo_id')
            && $pins['client_id'] === config('service_proposals.owner_client_id') && $pins['silo_id'] === config('service_proposals.owner_silo_id')
            && config('service_proposals.owner_workspaces') === [$pins['tenant_id'] => 'enterprise'], 503);
        $owner = app(ClientSilo::class)->assertReady();
        abort_unless((int) $owner->tenant_id === $pins['tenant_id'], 503);
        $area = TeamArea::query()->where(['tenant_id' => $pins['tenant_id'], 'id' => $pins['area_id']])->first();
        abort_unless($area && $area->slug === ReviewerAiStore::AREA_SLUG && $area->profile === 'enterprise_shared'
            && ($area->settings['synthetic_fixture'] ?? null) === true && ($area->settings['proposal_review_ai_anchor'] ?? null) === true
            && ($area->settings['inventory_evidence_hash'] ?? null) === $pins['inventory_evidence_hash']
            && ($area->settings['baseline_evidence_hash'] ?? null) === $pins['prior_spend_evidence_hash']
            && ! DB::table('team_area_members')->where('area_id', $area->id)->exists(), 503);
        $ledgers = TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->limit(2)->get();
        $ledger = $ledgers->first();
        abort_unless($ledgers->count() === 1 && $ledger->id === $pins['ledger_id'] && (int) $ledger->tenant_id === $pins['tenant_id']
            && $ledger->area_id === $pins['area_id'] && $ledger->status === 'active' && $ledger->visibility === 'area'
            && $ledger->source_key === hash('sha256', ReviewerAiStore::LEDGER_SOURCE)
            && ($ledger->data['baseline'] ?? null) === ['prior_microusd' => $pins['prior_spend_microusd'], 'evidence_hash' => $pins['prior_spend_evidence_hash']]
            && ($ledger->data['cap_microusd'] ?? null) === TrialBudget::CAP, 503);

        return [Tenant::query()->findOrFail($pins['tenant_id']), $ledger];
    }

    private function locked(array $pins, callable $callback): mixed
    {
        [$tenant] = $this->anchor($pins);
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql) {
            abort_unless((int) DB::selectOne("SELECT GET_LOCK('everbranch-reviewer-trial-budget', 5) AS acquired")->acquired === 1, 409);
        }
        try {
            return app(RecordStore::class)->locked($tenant, function () use ($pins, $callback) {
                [, $ledger] = $this->anchor($pins);
                $ledger = TeamOperationRecord::query()->whereKey($ledger->id)->lockForUpdate()->firstOrFail();

                return $callback($ledger);
            });
        } finally {
            if ($mysql) {
                DB::selectOne("SELECT RELEASE_LOCK('everbranch-reviewer-trial-budget') AS released");
            }
        }
    }

    private function save(TeamOperationRecord $row, array $data, ?string $status, string $event): void
    {
        $row->data = $data;
        $row->status = $status ?? $row->status;
        $row->version++;
        $row->save();
        DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id,
            'record_id' => $row->id, 'version' => $row->version, 'user_id' => null,
            'payload' => Crypt::encryptString(json_encode(['action' => $event, 'actor_type' => 'proposal_reviewer_ai_recovery'], JSON_THROW_ON_ERROR)), 'created_at' => now()]);
    }

    /** Failed validation leaves the original full reservation untouched. Never turns a settled cost into a refund. */
    public function hold(array $pins, string $previewId, string $digest, ?string $chargeId): void
    {
        $this->locked($pins, function ($ledger) use ($pins, $previewId, $digest, $chargeId): void {
            $row = TeamOperationRecord::query()->where(['tenant_id' => $pins['tenant_id'], 'area_id' => $pins['area_id'],
                'kind' => ReviewerAiReceipts::KIND, 'id' => $previewId])->lockForUpdate()->firstOrFail();
            abort_unless(is_string($row->data['digest'] ?? null) && hash_equals($row->data['digest'], $digest)
                && in_array($row->status, ['claimed', 'reserved', 'dispatching', 'uncertain', 'accepted', 'rejected'], true), 503);
            if ($chargeId !== null) {
                $charge = TeamOperationRecord::query()->where(['tenant_id' => $pins['tenant_id'], 'area_id' => $pins['area_id'],
                    'kind' => 'ent_trial_charge', 'id' => $chargeId])->lockForUpdate()->firstOrFail();
                abort_unless(($charge->data['channel'] ?? null) === 'ai' && ($charge->data['input_hash'] ?? null) === $digest
                    && ($charge->data['reserved_microusd'] ?? null) === $row->data['maximum_microusd']
                    && in_array($charge->status, ['reserved', 'dispatching', 'uncertain', 'accepted', 'rejected'], true)
                    && ($row->data['charge_id'] === null || $row->data['charge_id'] === $chargeId), 503);
                if (in_array($charge->status, ['reserved', 'dispatching'], true)) {
                    $this->save($charge, array_replace($charge->data, ['charged_microusd' => $charge->data['reserved_microusd'],
                        'outcome' => ['status' => 'uncertain']]), 'uncertain', 'proposal_review_ai.exposure_held');
                }
            }
            $data = array_replace($row->data, ['charge_id' => $chargeId ?? $row->data['charge_id'], 'reconciliation_required' => true]);
            unset($data['result']);
            $this->save($row, $data, 'uncertain', 'proposal_review_ai.reconciliation_required');
            $this->save($ledger, array_replace($ledger->data, ['paused' => true, 'pause_reason' => 'reviewer_ai_boundary_or_provider_uncertainty']), null, 'proposal_review_ai.campaign_paused');
        });
    }

    /** Explicit operator erasure; --deactivated also removes unexpired content only when BOTH gates are off. */
    public function scrub(array $pins, int $limit = 100, bool $deactivated = false): array
    {
        abort_unless($limit >= 1 && $limit <= 100, 422);
        abort_if($deactivated && (config('service_proposals.reviewer_ai.enabled') !== false || config('service_proposals.reviewer_ai.ui_enabled') !== false), 409);

        return $this->locked($pins, function () use ($pins, $limit, $deactivated): array {
            abort_if($deactivated && (config('service_proposals.reviewer_ai.enabled') !== false || config('service_proposals.reviewer_ai.ui_enabled') !== false), 409);
            $query = TeamOperationRecord::query()->where(['tenant_id' => $pins['tenant_id'], 'area_id' => $pins['area_id'], 'kind' => ReviewerAiReceipts::KIND])->whereNotNull('voice_expires_at');
            if (! $deactivated) {
                $query->where('voice_expires_at', '<=', now());
            }
            $rows = $query->orderBy('voice_expires_at')->limit($limit)->lockForUpdate()->get();
            foreach ($rows as $row) {
                $data = $row->data;
                unset($data['question'], $data['snapshot'], $data['result']);
                $row->voice_expires_at = null;
                $this->save($row, array_replace($data, ['content_scrubbed_at' => now()->timestamp]), null, 'proposal_review_ai.content_scrubbed');
            }

            return ['scrubbed' => $rows->count(), 'limit' => $limit, 'deactivated' => $deactivated];
        });
    }
}
