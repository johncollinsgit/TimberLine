<?php

namespace App\Jobs;

use App\Services\Marketing\TenantRewardsPolicyService;
use App\Services\Marketing\TenantRewardsReminderDispatchService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchTenantRewardsReminderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int,int>
     */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    /**
     * @param  array<string,mixed>  $context
     */
    public function __construct(
        public int $tenantId,
        public string $rewardIdentifier,
        public string $channel,
        public int $timingDaysBeforeExpiration,
        public int $policyVersion = 0,
        public array $context = []
    ) {
        $this->onQueue('marketing');
    }

    public function uniqueId(): string
    {
        return implode('|', [
            'tenant_rewards_reminder',
            $this->tenantId,
            strtolower(trim($this->rewardIdentifier)),
            strtolower(trim($this->channel)),
            max(0, $this->timingDaysBeforeExpiration),
            max(0, $this->policyVersion),
        ]);
    }

    public function handle(
        TenantRewardsPolicyService $policyService,
        TenantRewardsReminderDispatchService $dispatchService,
        TenantModuleAccessResolver $moduleAccessResolver
    ): void {
        if (trim($this->rewardIdentifier) === '') {
            return;
        }

        $smsModule = $moduleAccessResolver->module($this->tenantId, 'sms');
        $policy = $policyService->resolve($this->tenantId, [
            'editable' => true,
            'sms_channel_enabled' => (bool) ($smsModule['has_access'] ?? false),
        ]);

        $dispatchService->dispatchQueuedReminder($this->tenantId, $policy, [
            'reward_identifier' => $this->rewardIdentifier,
            'channel' => $this->channel,
            'timing_days_before_expiration' => $this->timingDaysBeforeExpiration,
            'policy_version' => $this->policyVersion,
        ]);
    }
}
