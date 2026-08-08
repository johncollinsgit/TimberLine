<?php

namespace App\Services\Bud;

use App\Models\Tenant;
use App\Models\TenantBudSetting;
use App\Models\User;
use App\Services\Mobile\TenantMobileSupportService;
use App\Services\Operations\OperatorAlertService;

class TenantBudService
{
    public function __construct(
        private BudConversationService $bud,
        private BudWorkspaceContextService $workspaceContext,
        private TenantMobileSupportService $support,
        private OperatorAlertService $alerts,
    ) {}

    public function request(Tenant $tenant, User $user): TenantBudSetting
    {
        $setting = TenantBudSetting::query()->firstOrNew(['tenant_id' => $tenant->id]);
        if ($setting->status !== 'approved') {
            $setting->fill(['status' => 'pending', 'requested_by_user_id' => $user->id, 'requested_at' => now()])->save();
            $this->alerts->notify('bud.activation_requested', "Everbranch: {$tenant->name} requested Bud activation.", [
                'dedupe_key' => 'bud-request:'.$tenant->id,
                'tenant_id' => $tenant->id, 'target_type' => 'tenant_bud_setting', 'target_id' => $setting->id,
            ]);
        }

        return $setting->fresh(['requester']);
    }

    public function review(TenantBudSetting $setting, User $user, bool $approved, ?string $notes = null): TenantBudSetting
    {
        $setting->fill([
            'status' => $approved ? 'approved' : 'disabled',
            'reviewed_by_user_id' => $user->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();

        return $setting->fresh(['tenant', 'requester']);
    }

    public function requestAi(Tenant $tenant, User $user): TenantBudSetting
    {
        $setting = TenantBudSetting::query()->firstOrCreate(['tenant_id' => $tenant->id], ['status' => 'disabled']);

        if ($setting->ai_status !== 'approved') {
            $setting->fill([
                'ai_status' => 'pending',
                'ai_requested_by_user_id' => $user->id,
                'ai_requested_at' => now(),
            ])->save();
            $this->alerts->notify('bud.ai_activation_requested', "Everbranch: {$tenant->name} requested Bud AI activation.", [
                'dedupe_key' => 'bud-ai-request:'.$tenant->id,
                'tenant_id' => $tenant->id,
                'target_type' => 'tenant_bud_setting',
                'target_id' => $setting->id,
            ]);
        }

        return $setting->fresh(['tenant', 'requester']);
    }

    public function reviewAi(TenantBudSetting $setting, User $user, bool $approved, int $monthlyBudgetCents, ?string $notes = null): TenantBudSetting
    {
        abort_unless($monthlyBudgetCents >= 0 && $monthlyBudgetCents <= 100000, 422, 'Choose a monthly Bud AI cap between $0 and $1,000.');

        $setting->fill([
            'ai_status' => $approved ? 'approved' : 'disabled',
            'ai_monthly_budget_cents' => $approved ? $monthlyBudgetCents : 0,
            'ai_used_cents' => 0,
            'ai_period_started_at' => $approved ? now()->startOfMonth() : null,
            'ai_reviewed_by_user_id' => $user->id,
            'ai_reviewed_at' => now(),
            'ai_review_notes' => $notes,
        ])->save();

        return $setting->fresh(['tenant', 'requester']);
    }

    /** @param array<int,array<string,mixed>> $transcript */
    public function respond(Tenant $tenant, User $user, string $question, array $transcript = []): array
    {
        abort_unless((bool) config('bud.core_enabled', true), 503, 'Bud is temporarily unavailable.');
        $answer = $this->bud->respond($question, array_merge([
            'tenant' => $tenant->name,
            'surface' => 'account_help',
            'bud_tier' => 'Bud Core',
        ], $this->workspaceContext->forTenant($tenant)), $transcript);
        if (($answer['uncertain'] ?? false) === true) {
            $ticket = $this->support->createBudEscalation($tenant, $user, $question, (string) $answer['reply'], (string) $answer['confidence'], $transcript);
            $answer['reply'] = "I’m not sure that I’ve been programmed to answer that. I’ll create a ticket so the Everbranch team can follow up.\n\n".$answer['reply'];
            $answer['ticket_id'] = $ticket['ticket']['id'] ?? null;
        }

        return $answer;
    }
}
