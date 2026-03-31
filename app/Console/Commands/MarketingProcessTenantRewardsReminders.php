<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Marketing\TenantRewardsOperationsService;
use App\Services\Marketing\TenantRewardsPolicyService;
use App\Services\Marketing\TenantRewardsReminderDispatchService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Console\Command;

class MarketingProcessTenantRewardsReminders extends Command
{
    protected $signature = 'marketing:process-tenant-rewards-reminders
        {--tenant= : Tenant id to process}
        {--reward= : Specific reward identifier such as earned-bucket:tx:123}
        {--profile= : Marketing profile id filter}
        {--channel= : Limit to email or sms}
        {--timing= : Limit to a specific timing days-before-expiration value}
        {--limit=200 : Max outstanding rewards to inspect per tenant}
        {--queue : Queue due reminders instead of sending inline}
        {--batch-size=50 : Max queued reminders to enqueue per tenant when --queue is used}
        {--dry-run : Preview what would send without writing logs or delivery rows}
        {--force : Ignore previous reminder history for the selected reminder filter}
        {--mark-skipped= : Mark matching due reminders as skipped with a note instead of sending}
        {--reason= : Optional support note recorded for queued or manual support actions}';

    protected $description = 'Process tenant rewards expiration reminders through the existing tenant policy, scheduling, and delivery stack.';

    public function handle(
        TenantRewardsPolicyService $policyService,
        TenantRewardsReminderDispatchService $dispatchService,
        TenantRewardsOperationsService $operationsService,
        TenantModuleAccessResolver $moduleAccessResolver
    ): int {
        $tenantId = $this->positiveInt($this->option('tenant'));
        $rewardIdentifier = $this->nullableString($this->option('reward'));
        $profileId = $this->positiveInt($this->option('profile'));
        $channel = $this->nullableString($this->option('channel'));
        $timingDays = $this->nonNegativeInt($this->option('timing'));
        $limit = max(1, min(500, (int) $this->option('limit')));
        $queue = (bool) $this->option('queue');
        $batchSize = max(1, min(500, (int) $this->option('batch-size')));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $markSkipped = $this->nullableString($this->option('mark-skipped'));
        $reason = $this->nullableString($this->option('reason'));

        if (($force || $markSkipped !== null) && $rewardIdentifier === null && $profileId === null) {
            $this->error('Force and mark-skipped actions require --reward or --profile so support actions stay narrow and traceable.');

            return self::FAILURE;
        }

        if ($queue && $markSkipped !== null) {
            $this->error('Queue mode cannot be combined with mark-skipped. Choose one support action per run.');

            return self::FAILURE;
        }

        $tenantIds = $tenantId !== null
            ? [$tenantId]
            : Tenant::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($tenantIds === []) {
            $this->line('No tenants found to process.');

            return self::SUCCESS;
        }

        $overall = [
            'tenants' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'due' => 0,
        ];

        foreach ($tenantIds as $tenantIdToProcess) {
            try {
                $rewardsModule = $moduleAccessResolver->module($tenantIdToProcess, 'rewards');
                if (! (bool) ($rewardsModule['has_access'] ?? false)) {
                    $this->line(sprintf('Tenant %d skipped: rewards plan access is not enabled.', $tenantIdToProcess));
                    continue;
                }

                $smsModule = $moduleAccessResolver->module($tenantIdToProcess, 'sms');
                $policy = $policyService->resolve($tenantIdToProcess, [
                    'editable' => true,
                    'sms_channel_enabled' => (bool) ($smsModule['has_access'] ?? false),
                ]);

                $manualScopedAction = $tenantId !== null
                    || $rewardIdentifier !== null
                    || $profileId !== null
                    || $markSkipped !== null
                    || $force;
                $automation = $operationsService->automationDecision($tenantIdToProcess, $policy, [
                    'readiness' => (array) ($policy['readiness'] ?? []),
                ]);

                if (! $manualScopedAction
                    && ! $dryRun
                    && ! (bool) ($automation['automatic_enabled'] ?? $automation['auto_enabled'] ?? false)) {
                    $this->line(sprintf('Tenant %d skipped: rewards automation is off for this tenant.', $tenantIdToProcess));
                    continue;
                }

                if (! $manualScopedAction && ! $dryRun && ! (bool) ($automation['eligible'] ?? false)) {
                    $this->line(sprintf('Tenant %d skipped: rewards automation is unavailable for this tenant.', $tenantIdToProcess));
                    continue;
                }

                if (! $manualScopedAction && ! $dryRun && ! (bool) ($automation['program_active'] ?? false)) {
                    $this->line(sprintf('Tenant %d skipped: rewards automation is waiting for the program to go live.', $tenantIdToProcess));
                    continue;
                }

                if (! $manualScopedAction && ! $dryRun && ! (bool) ($automation['channels_ready'] ?? false)) {
                    $this->line(sprintf('Tenant %d skipped: rewards automation still needs a live reminder channel.', $tenantIdToProcess));
                    continue;
                }

                $dispatchOptions = [
                    'dry_run' => $dryRun,
                    'force' => $force,
                    'reward_identifier' => $rewardIdentifier,
                    'marketing_profile_id' => $profileId,
                    'channel' => $channel,
                    'timing_days_before_expiration' => $timingDays,
                    'limit' => $limit,
                    'mark_skipped' => $markSkipped,
                    'include_content' => false,
                    'policy_version' => data_get($policy, 'versioning.current_version', data_get($policy, 'access_state.policy_version', 0)),
                    'batch_size' => $batchSize,
                    'reason' => $reason,
                ];

                $result = $queue && ! $dryRun
                    ? $dispatchService->queueDueReminders($tenantIdToProcess, $policy, $dispatchOptions)
                    : $dispatchService->processTenant($tenantIdToProcess, $policy, $dispatchOptions);

                if (! $dryRun) {
                    $operationsService->recordAutomationRun($tenantIdToProcess, $policy, $result);
                    $refreshedPolicy = $policyService->resolve($tenantIdToProcess, [
                        'editable' => true,
                        'sms_channel_enabled' => (bool) ($smsModule['has_access'] ?? false),
                    ]);
                    $operationsService->maybeSendAlertEmail($tenantIdToProcess, $refreshedPolicy, [
                        'alerts' => (array) ($refreshedPolicy['alerts'] ?? []),
                    ]);
                }

                $summary = (array) ($result['summary'] ?? []);
                $overall['tenants']++;
                $overall['sent'] += (int) ($summary['sent_count'] ?? 0);
                $overall['failed'] += (int) ($summary['failed_count'] ?? 0);
                $overall['skipped'] += (int) ($summary['skipped_count'] ?? 0);
                $overall['due'] += $queue && ! $dryRun
                    ? (int) data_get($result, 'preview.due_count', 0)
                    : (int) ($summary['due_count'] ?? 0);

                if ($queue && ! $dryRun) {
                    $this->info(sprintf(
                        'Tenant %d [queued] v%s: due=%d queued=%d remaining=%d',
                        $tenantIdToProcess,
                        (string) ($result['policy_version'] ?? data_get($policy, 'versioning.current_version', '0')),
                        (int) data_get($result, 'preview.due_count', 0),
                        (int) ($result['queued_count'] ?? 0),
                        (int) ($result['remaining_due_count'] ?? 0)
                    ));
                } else {
                    $mode = $dryRun ? 'preview' : 'live';
                    $this->info(sprintf(
                        'Tenant %d [%s] v%s: due=%d sent=%d failed=%d skipped=%d upcoming=%d',
                        $tenantIdToProcess,
                        $mode,
                        (string) ($summary['policy_version'] ?? '0'),
                        (int) ($summary['due_count'] ?? 0),
                        (int) ($summary['sent_count'] ?? 0),
                        (int) ($summary['failed_count'] ?? 0),
                        (int) ($summary['skipped_count'] ?? 0),
                        (int) ($summary['upcoming_count'] ?? 0)
                    ));
                }

                if ($this->output->isVerbose()) {
                    $rows = $queue && ! $dryRun
                        ? (array) ($result['items'] ?? [])
                        : (array) ($result['processed_items'] ?? []);

                    foreach ($rows as $row) {
                        if (! is_array($row)) {
                            continue;
                        }

                        $this->line(sprintf(
                            '  - %s %s %s %s',
                            strtoupper((string) ($row['channel'] ?? 'channel')),
                            (string) ($row['status'] ?? 'queued'),
                            (string) ($row['reward_identifier'] ?? 'reward'),
                            trim((string) ($row['reason'] ?? $row['skip_reason'] ?? 'queued'))
                        ));
                    }
                }
            } catch (\Throwable $exception) {
                $operationsService->recordAutomationFailure($tenantIdToProcess, $exception->getMessage());
                $overall['tenants']++;
                $overall['failed']++;
                $this->error(sprintf('Tenant %d failed: %s', $tenantIdToProcess, $exception->getMessage()));
            }
        }

        $this->line(sprintf(
            'Processed %d tenant(s): due=%d sent=%d failed=%d skipped=%d',
            $overall['tenants'],
            $overall['due'],
            $overall['sent'],
            $overall['failed'],
            $overall['skipped']
        ));

        return self::SUCCESS;
    }

    protected function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    protected function nonNegativeInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
