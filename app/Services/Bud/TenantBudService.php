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

    /** @param array<int,array<string,mixed>> $transcript */
    public function respond(Tenant $tenant, User $user, string $question, array $transcript = []): array
    {
        $setting = TenantBudSetting::query()->firstOrCreate(['tenant_id' => $tenant->id], ['status' => 'disabled']);
        abort_unless($setting->status === 'approved', 403, 'Bud needs Everbranch approval before it can be used in this workspace.');
        $answer = $this->bud->respond($question, ['tenant' => $tenant->name, 'surface' => 'account_help'], $transcript);
        if (($answer['uncertain'] ?? false) === true) {
            $ticket = $this->support->createBudEscalation($tenant, $user, $question, (string) $answer['reply'], (string) $answer['confidence'], $transcript);
            $answer['reply'] = "I’m not sure that I’ve been programmed to answer that. I’ll create a ticket so the Everbranch team can follow up.\n\n".$answer['reply'];
            $answer['ticket_id'] = $ticket['ticket']['id'] ?? null;
        }
        return $answer;
    }
}
