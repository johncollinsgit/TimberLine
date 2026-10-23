<?php

namespace App\Enterprise\Conversation;

use App\Enterprise\Planning;
use App\Enterprise\Trial\TrialBudget;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;

/** Read-only availability hint. Admission always repeats the atomic budget and current ACL checks. */
class Readiness
{
    public function __construct(private Connection $connection) {}

    public function inspect(Tenant $tenant, User $user, ?string $area): array
    {
        $base = ['ready' => false, 'state' => 'unavailable', 'scope' => ['tenant_id' => (string) $tenant->id, 'user_id' => (string) $user->id, 'area_id' => $area],
            'message' => 'Text conversation setup is unavailable. Current record checks can still be used when permitted.',
            'voice' => 'Queued separately; no microphone, audio or voice provider is active.'];
        if (! config('enterprise.conversation.ui_enabled') || ! config('enterprise.conversation.enabled') || $area === null) {
            return $base;
        }
        try {
            $authority = $this->connection->authority($tenant, $user, $area);
            $settings = $authority['settings'];
            $intents = ['overview' => (bool) config('enterprise.read_only_assistant_enabled') && (bool) config('enterprise.briefing_enabled'),
                'building' => (bool) config('enterprise.read_only_assistant_enabled') && (bool) config('enterprise.resources_enabled'),
                'costs' => (bool) config('enterprise.read_only_assistant_enabled') && (bool) config('enterprise.planning_enabled') && Planning::financial($authority['actor'])];
            $ledgers = TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->get();
            abort_if($ledgers->count() !== 1 || $ledgers->first()?->id !== $settings['ledger_id'], 503);
            $ledger = $ledgers->first();
            $used = $settings['prior_spend_microusd'];
            if ($ledger) {
                abort_unless((string) $ledger->tenant_id === (string) $tenant->id && $ledger->area_id === $area
                    && $ledger->data['baseline'] === ['prior_microusd' => $used, 'evidence_hash' => $settings['prior_spend_evidence_hash']], 503);
                if ($ledger->data['paused'] ?? false) {
                    return array_replace($base, ['state' => 'paused', 'message' => 'The shared trial is paused for cost review. Existing reservations stay held; no new question will be sent.']);
                }
            }
            foreach (TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'ent_trial_charge'])->cursor() as $charge) {
                $amount = $charge->data['charged_microusd'] ?? $charge->data['reserved_microusd'] ?? null;
                abort_unless(is_int($amount) && $amount >= 0, 503);
                $used += $amount;
            }
            $remaining = max(0, TrialBudget::CAP - $used);
            if ($remaining < $settings['maximum_microusd']) {
                return array_replace($base, ['state' => 'limited', 'message' => 'The shared $5 trial cannot cover another bounded reply. Nothing will be sent.']);
            }
            if (! in_array(true, $intents, true)) {
                return $base;
            }

            return array_replace($base, ['ready' => true, 'state' => 'ready', 'intents' => $intents,
                'message' => 'OpenAI text replies use current saved records you can access. Check their linked sources before acting.',
                'remaining_microusd' => $remaining, 'maximum_turn_microusd' => $settings['maximum_microusd'],
                'budget_notice' => 'One shared $5 lifetime trial. The final allowance and access are checked again on Send.']);
        } catch (\Throwable) {
            return $base;
        }
    }
}
