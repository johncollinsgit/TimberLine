<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Marketing\TenantRewardsOperationsService;
use App\Services\Marketing\TenantRewardsPolicyService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Console\Command;

class MarketingSendTenantRewardsFinanceReports extends Command
{
    protected $signature = 'marketing:send-tenant-rewards-finance-reports
        {--tenant= : Tenant id to process}
        {--force : Send even if the next report is not due yet}
        {--dry-run : Preview scheduled finance reports without sending}';

    protected $description = 'Send scheduled tenant rewards finance reports using the existing exports and email delivery stack.';

    public function handle(
        TenantRewardsPolicyService $policyService,
        TenantRewardsOperationsService $operationsService,
        TenantModuleAccessResolver $moduleAccessResolver
    ): int {
        $tenantOption = is_numeric($this->option('tenant')) ? (int) $this->option('tenant') : null;
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $tenantIds = $tenantOption !== null
            ? [$tenantOption]
            : Tenant::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($tenantIds === []) {
            $this->line('No tenants found to process.');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($tenantIds as $tenantId) {
            $rewardsModule = $moduleAccessResolver->module($tenantId, 'rewards');
            if (! (bool) ($rewardsModule['has_access'] ?? false)) {
                $this->line(sprintf('Tenant %d skipped: rewards plan access is not enabled.', $tenantId));
                $skipped++;
                continue;
            }

            $smsModule = $moduleAccessResolver->module($tenantId, 'sms');
            $policy = $policyService->resolve($tenantId, [
                'editable' => true,
                'sms_channel_enabled' => (bool) ($smsModule['has_access'] ?? false),
            ]);

            $result = $operationsService->sendScheduledFinanceReport($tenantId, $policy, [
                'force' => $force,
                'dry_run' => $dryRun,
            ]);

            $status = (string) ($result['status'] ?? 'disabled');
            if (in_array($status, ['sent', 'preview_ready'], true)) {
                $sent++;
            } elseif (in_array($status, ['failed', 'preview_failed'], true)) {
                $failed++;
            } else {
                $skipped++;
            }

            $this->line(sprintf(
                'Tenant %d [%s] %s',
                $tenantId,
                $dryRun ? 'preview' : 'live',
                $status
            ));
        }

        $this->line(sprintf(
            'Processed %d tenant(s): sent=%d failed=%d skipped=%d',
            count($tenantIds),
            $sent,
            $failed,
            $skipped
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
