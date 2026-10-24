<?php

namespace App\Enterprise\Trial;

use App\Models\TeamOperationRecord;

/** One $5 lifetime campaign, all reviewers/channels/acceptance calls, never a customer wallet. */
class TrialBudget
{
    public const CAP = 5000000;

    public function __construct(private TrialStore $store) {}

    private function baseline(): array
    {
        $prior = config('enterprise.trial.prior_spend_microusd');
        $evidence = config('enterprise.trial.prior_spend_evidence');
        abort_unless(config('enterprise.trial.prior_spend_verified') === true && is_int($prior) && $prior >= 0 && $prior <= self::CAP
            && is_string($evidence) && trim($evidence) !== '', 503, 'All prior trial and acceptance spend must be verified before paid calls.');

        return ['prior_microusd' => $prior, 'evidence_hash' => hash('sha256', $evidence)];
    }

    private function ledger(?string $approvedLedgerId = null): TeamOperationRecord
    {
        $baseline = $this->baseline();
        $ledgers = TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->get();
        $existing = $ledgers->first();
        if ($approvedLedgerId !== null) {
            abort_unless($ledgers->count() === 1 && $existing->id === $approvedLedgerId, 503,
                'The separately approved existing lifetime ledger is unavailable. Reconciliation is required; no ledger will be initialized.');
        }
        if ($existing) {
            abort_unless((int) $existing->tenant_id === (int) $this->store->tenant()->id && $existing->area_id === config('enterprise.trial.area_id')
                && $existing->data['baseline'] === $baseline, 503, 'The lifetime ledger anchor or prior-spend baseline changed. Reconcile rather than resetting the cap.');

            return $existing;
        }
        $row = $this->store->create('ent_trial_budget', 'enterprise-reviewer-campaign-2026', ['baseline' => $baseline, 'cap_microusd' => self::CAP]);
        $this->store->audit($row, 'trial.budget_initialized', ['cap_microusd' => self::CAP, 'prior_microusd' => $baseline['prior_microusd']]);

        return $row;
    }

    public function status(): array
    {
        return $this->store->locked(function (): array {
            $ledger = $this->ledger();
            $used = $ledger->data['baseline']['prior_microusd'];
            foreach (TeamOperationRecord::query()->where(['tenant_id' => $ledger->tenant_id, 'kind' => 'ent_trial_charge'])->cursor() as $row) {
                $used += $row->data['charged_microusd'] ?? $row->data['reserved_microusd'];
            }

            return ['cap_microusd' => self::CAP, 'used_or_reserved_microusd' => $used, 'remaining_microusd' => max(0, self::CAP - $used),
                'paused' => (bool) ($ledger->data['paused'] ?? false),
                'prior_microusd' => $ledger->data['baseline']['prior_microusd'], 'scope' => 'Shared lifetime total: SMS, email, AI and acceptance calls; not per channel, reviewer, session or day.'];
        });
    }

    /** Must commit before provider IO; callers use the same immutable key on interrupted retries. */
    public function reserve(string $channel, string $key, string $inputHash, int $maximum, ?string $approvedLedgerId = null): array
    {
        abort_unless(in_array($channel, ['sms', 'email', 'ai', 'acceptance'], true) && $maximum > 0 && $maximum <= self::CAP, 422);

        return $this->store->locked(function () use ($channel, $key, $inputHash, $maximum, $approvedLedgerId): array {
            $ledger = $this->ledger($approvedLedgerId);
            abort_if($ledger->data['paused'] ?? false, 503, 'The shared trial ledger is paused pending provider-cost reconciliation.');
            $source = hash('sha256', 'trial-charge:'.$key);
            $existing = TeamOperationRecord::query()->where(['tenant_id' => $ledger->tenant_id, 'kind' => 'ent_trial_charge', 'source_key' => $source])->first();
            if ($existing) {
                abort_unless($existing->data['channel'] === $channel && hash_equals($existing->data['input_hash'], $inputHash), 409, 'This trial key belongs to a different paid action.');
                abort_unless(in_array($existing->status, ['accepted', 'rejected'], true), 409, 'The existing paid action is pending or uncertain. Do not repeat it.');

                return ['id' => $existing->id, 'replayed' => true, 'outcome' => $existing->data['outcome']];
            }
            $used = $ledger->data['baseline']['prior_microusd'];
            foreach (TeamOperationRecord::query()->where(['tenant_id' => $ledger->tenant_id, 'kind' => 'ent_trial_charge'])->cursor() as $row) {
                $used += $row->data['charged_microusd'] ?? $row->data['reserved_microusd'];
            }
            abort_if($used + $maximum > self::CAP, 429, 'The shared $5 lifetime cap cannot cover this maximum provider cost.');
            $charge = $this->store->create('ent_trial_charge', 'trial-charge:'.$key, ['channel' => $channel, 'input_hash' => $inputHash, 'reserved_microusd' => $maximum], 'reserved');
            $this->store->audit($charge, 'trial.cost_reserved', ['channel' => $channel, 'maximum_microusd' => $maximum]);

            return ['id' => $charge->id, 'replayed' => false];
        });
    }

    public function settle(string $id, string $status, array $outcome, ?int $actual = null, ?string $approvedLedgerId = null): void
    {
        $overrun = $this->store->locked(function () use ($id, $status, $outcome, $actual, $approvedLedgerId): bool {
            if ($approvedLedgerId !== null) {
                $this->ledger($approvedLedgerId);
            }
            $row = $this->store->row('ent_trial_charge', $id);
            abort_unless(in_array($row->status, ['reserved', 'dispatching'], true) && in_array($status, ['accepted', 'rejected', 'uncertain'], true), 409);
            if ($actual !== null && ($actual < 0 || $actual > $row->data['reserved_microusd'])) {
                $ledger = $this->ledger($approvedLedgerId);
                $this->store->save($ledger, array_replace($ledger->data, ['paused' => true, 'pause_reason' => 'invalid_or_over_bound_provider_usage']));
                $this->store->save($row, $row->data + ['outcome' => ['status' => 'uncertain'], 'charged_microusd' => max($row->data['reserved_microusd'], $actual)], 'uncertain');
                $this->store->audit($ledger, 'trial.budget_frozen');

                return true;
            }
            // Unknown/rejected provider price is conservatively held at the full maximum, never auto-refunded.
            $data = $row->data + ['outcome' => $outcome, 'charged_microusd' => $status === 'accepted' && $actual !== null ? $actual : $row->data['reserved_microusd']];
            $this->store->save($row, $data, $status);
            $this->store->audit($row, 'trial.cost_'.$status, ['charged_microusd' => $data['charged_microusd']]);

            return false;
        });
        abort_if($overrun, 503, 'Provider usage exceeded its approved bound. The shared trial ledger is frozen until reconciliation.');
    }

    public function pause(?string $approvedLedgerId = null): void
    {
        $this->store->locked(function () use ($approvedLedgerId): void {
            $ledger = $this->ledger($approvedLedgerId);
            $this->store->save($ledger, array_replace($ledger->data, ['paused' => true, 'pause_reason' => 'unverifiable_ai_usage']));
            $this->store->audit($ledger, 'trial.budget_frozen');
        });
    }

    public function claimDispatch(string $id, string $digest, ?string $approvedLedgerId = null): void
    {
        $this->store->locked(function () use ($id, $digest, $approvedLedgerId): void {
            $ledger = $this->ledger($approvedLedgerId);
            abort_if($ledger->data['paused'] ?? false, 503, 'The shared trial campaign is paused. No new provider dispatch is permitted.');
            $row = $this->store->row('ent_trial_charge', $id);
            abort_unless($row->status === 'reserved' && hash_equals($row->data['input_hash'], $digest), 409, 'This paid action was already dispatched or its input changed.');
            $this->store->save($row, $row->data + ['dispatch_claimed_at' => now()->toIso8601String()], 'dispatching');
            $this->store->audit($row, 'trial.provider_dispatch_claimed');
        });
    }
}
