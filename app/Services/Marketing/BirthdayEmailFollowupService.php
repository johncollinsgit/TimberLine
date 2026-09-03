<?php

namespace App\Services\Marketing;

use App\Models\BirthdayRewardIssuance;
use App\Services\Tenancy\TenantMarketingSettingsResolver;

class BirthdayEmailFollowupService
{
    public function __construct(
        protected BirthdayEmailDispatchService $emails,
        protected TenantMarketingSettingsResolver $settings,
    ) {}

    /** @return array<string,int> */
    public function dispatchDue(int $tenantId, int $limit = 500, bool $dryRun = false): array
    {
        $config = $this->settings->array('birthday_campaign_config', $tenantId, []);
        if (! (bool) ($config['email_enabled'] ?? true)) {
            return ['evaluated' => 0, 'sent' => 0, 'already_recorded' => 0, 'failed' => 0, 'disabled' => 1];
        }

        $daysBeforeExpiry = max(0, (int) ($config['followup_send_offset'] ?? 3));
        $issuances = BirthdayRewardIssuance::query()
            ->where('status', 'issued')
            ->whereIn('reward_type', ['discount_code', 'free_shipping'])
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now()->startOfDay(), now()->copy()->addDays($daysBeforeExpiry)->endOfDay()])
            ->whereHas('marketingProfile', fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderBy('expires_at')
            ->limit(max(1, $limit))
            ->get();

        $summary = ['evaluated' => $issuances->count(), 'sent' => 0, 'already_recorded' => 0, 'failed' => 0, 'disabled' => 0];
        if ($dryRun) {
            return $summary;
        }

        foreach ($issuances as $issuance) {
            $result = $this->emails->sendIssuanceEmail($issuance, ['template_key' => 'birthday_email_followup']);
            if ((bool) ($result['success'] ?? false)) {
                $summary['sent']++;
            } elseif ((bool) ($result['already_recorded'] ?? false)) {
                $summary['already_recorded']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }
}
