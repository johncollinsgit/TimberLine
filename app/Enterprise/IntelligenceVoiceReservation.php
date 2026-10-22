<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;

/** Reuse the existing encrypted ledger, tenant lock, exact replay and no-retry transport. */
class IntelligenceVoiceReservation implements VoiceCostReservation
{
    public function __construct(private Intelligence $intelligence) {}

    public function ready(Tenant $tenant): bool
    {
        // A separate per-tenant cap must never escape the controlled trial's shared $5 ledger.
        return ! config('enterprise.trial.enabled') && $this->intelligence->status($tenant)['ready'];
    }

    public function generate(Tenant $tenant, User $user, string $area, array $payload, string $requestId): array
    {
        abort_unless($this->ready($tenant), 503, 'Voice provider and its approved cost reservation are unavailable.');

        $result = $this->intelligence->generate($tenant, $user, $area, 'voice_answer', $payload, $requestId);
        $usage = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'ent_ai_usage'])->findOrFail($result['usage_id']);
        abort_unless(($usage->data['user_id'] ?? null) === (string) $user->id && $usage->area_id === $area, 404);

        return $result + ['usage' => ['receipt_id' => $usage->id, 'feature' => 'voice_assistant', 'state' => ($result['provider_simulated'] ?? false) ? 'simulated_estimate' : 'metered_estimate',
            'reserved_microusd' => $usage->data['reserved_microusd'], 'cost_microusd' => $usage->data['charged_microusd'],
            'input_tokens' => $usage->data['input_tokens'], 'output_tokens' => $usage->data['output_tokens'],
            'replayed' => $result['replayed'], 'provider_invoice_reconciled' => false]];
    }
}
