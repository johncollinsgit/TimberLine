<?php

namespace App\Proposals;

use App\Enterprise\Provisioning\ClientSilo;
use App\Enterprise\Trial\TrialBudget;
use App\Enterprise\Trial\TrialStore;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** One explicitly installed owner-only synthetic storage anchor. Never enables legacy Trial or grants native access. */
final class ReviewerAiStore extends TrialStore
{
    public const AREA_SLUG = 'proposal-review-ai';

    public const LEDGER_SOURCE = 'enterprise-reviewer-campaign-2026';

    public function areaId(): string
    {
        $id = config('service_proposals.reviewer_ai.area_id');
        abort_unless(is_string($id) && Str::isUuid($id), 503, 'The proposal AI storage anchor is unavailable.');

        return $id;
    }

    public function baseline(): array
    {
        $config = (array) config('service_proposals.reviewer_ai');
        abort_unless(($config['prior_spend_verified'] ?? null) === true
            && is_int($config['prior_spend_microusd'] ?? null) && $config['prior_spend_microusd'] >= 0 && $config['prior_spend_microusd'] <= TrialBudget::CAP
            && is_string($config['prior_spend_evidence_hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $config['prior_spend_evidence_hash'])
            && is_string($config['inventory_evidence_hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $config['inventory_evidence_hash']), 503,
            'Verified aggregate campaign baseline evidence is required.');

        return ['prior_microusd' => $config['prior_spend_microusd'], 'evidence_hash' => $config['prior_spend_evidence_hash']];
    }

    public function ledgerId(): string
    {
        $id = config('service_proposals.reviewer_ai.ledger_id');
        abort_unless(is_string($id) && Str::isUuid($id), 503, 'The existing proposal AI lifetime ledger must be pinned.');

        return $id;
    }

    public function tenant(): Tenant
    {
        abort_unless(config('enterprise.runtime') === true && config('service_proposals.runtime_purpose') === 'proposal_owner'
            && config('service_proposals.reviewer_ai.enabled') === true, 404);
        try {
            $owner = app(ClientSilo::class)->assertReady();
        } catch (Throwable) {
            abort(503, 'The proposal AI owner silo is unavailable.');
        }
        $id = config('service_proposals.reviewer_ai.tenant_id');
        abort_unless((is_int($id) || is_string($id) && ctype_digit($id)) && (int) $id > 0 && (int) $owner->tenant_id === (int) $id
            && $owner->client_id === config('service_proposals.owner_client_id') && $owner->silo_id === config('service_proposals.owner_silo_id')
            && config('service_proposals.owner_workspaces') === [(int) $id => 'enterprise'], 503, 'The proposal AI owner binding changed.');
        $baseline = $this->baseline();
        $area = TeamArea::query()->where(['tenant_id' => (int) $id, 'id' => $this->areaId()])->first();
        abort_unless($area && $area->slug === self::AREA_SLUG && $area->profile === 'enterprise_shared'
            && ($area->settings['synthetic_fixture'] ?? null) === true && ($area->settings['proposal_review_ai_anchor'] ?? null) === true
            && ($area->settings['inventory_evidence_hash'] ?? null) === config('service_proposals.reviewer_ai.inventory_evidence_hash')
            && ($area->settings['baseline_evidence_hash'] ?? null) === $baseline['evidence_hash']
            && ! DB::table('team_area_members')->where('area_id', $area->id)->exists(), 503, 'The explicit proposal AI synthetic anchor is unavailable.');
        // This read NEVER creates a ledger, including inherited status/cleanup/uncertainty paths.
        $ledgers = TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->limit(2)->get();
        $ledger = $ledgers->first();
        abort_unless($ledgers->count() === 1 && $ledger->id === $this->ledgerId() && (int) $ledger->tenant_id === (int) $id
            && $ledger->area_id === $area->id && $ledger->source_key === hash('sha256', self::LEDGER_SOURCE)
            && $ledger->status === 'active' && $ledger->visibility === 'area' && ($ledger->data['baseline'] ?? null) === $baseline
            && ($ledger->data['cap_microusd'] ?? null) === TrialBudget::CAP, 503, 'The pinned lifetime ledger changed. Reconcile; never initialize a replacement.');

        return Tenant::query()->findOrFail((int) $id);
    }

    public function create(string $kind, string $source, array $data, string $status = 'active'): TeamOperationRecord
    {
        abort_unless(in_array($kind, ['ent_trial_charge', 'ent_proposal_ai'], true), 503, 'Only reviewed charge and private receipt storage is allowed.');

        return parent::create($kind, $source, $data, $status);
    }

    public function row(string $kind, string $id): TeamOperationRecord
    {
        abort_unless(in_array($kind, ['ent_trial_budget', 'ent_trial_charge', 'ent_proposal_ai'], true), 503);

        return parent::row($kind, $id);
    }

    private function assertRow(TeamOperationRecord $row): void
    {
        abort_unless((int) $row->tenant_id === (int) $this->tenant()->id && $row->area_id === $this->areaId()
            && in_array($row->kind, ['ent_trial_budget', 'ent_trial_charge', 'ent_proposal_ai'], true)
            && ($row->kind !== 'ent_trial_budget' || $row->id === $this->ledgerId()), 503, 'The proposal AI storage row is out of scope.');
    }

    public function save(TeamOperationRecord $row, array $data, ?string $status = null): void
    {
        $this->assertRow($row);
        parent::save($row, $data, $status);
    }

    public function audit(TeamOperationRecord $row, string $event, array $evidence = []): void
    {
        $this->assertRow($row);
        DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id, 'record_id' => $row->id,
            'version' => $row->version, 'user_id' => null, 'payload' => Crypt::encryptString(json_encode(['action' => $event,
                'actor_type' => 'proposal_reviewer_ai'] + $evidence, JSON_THROW_ON_ERROR)), 'created_at' => now()]);
    }
}
