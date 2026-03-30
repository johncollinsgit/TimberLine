<?php

namespace App\Console\Commands;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Services\Marketing\MarketingSmsExecutionService;
use App\Services\Marketing\MarketingTenantOwnershipService;
use Illuminate\Console\Command;

class MarketingSendApprovedSms extends Command
{
    protected $signature = 'marketing:send-approved-sms
        {--campaign-id= : Send only for a specific campaign}
        {--recipient-id= : Send only for a specific recipient}
        {--tenant-id= : Restrict execution to a tenant-owned campaign surface}
        {--limit=200 : Maximum recipients to process}
        {--dry-run : Simulate successful sends without calling Twilio}';

    protected $description = 'Send approved SMS campaign recipients through Twilio with delivery logging.';

    public function handle(
        MarketingSmsExecutionService $executionService,
        MarketingTenantOwnershipService $ownershipService
    ): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $verbose = $this->getOutput()->isVerbose();
        $campaignId = $this->integerOption('campaign-id');
        $recipientId = $this->integerOption('recipient-id');
        $tenantId = $this->integerOption('tenant-id');
        $limit = max(1, (int) ($this->option('limit') ?: 200));
        $strict = $ownershipService->strictModeEnabled();

        if ($strict && $tenantId === null) {
            $this->error('This command requires --tenant-id once tenant strict mode is active.');

            return self::FAILURE;
        }

        $summary = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'dry_run' => 0,
        ];

        if ($recipientId) {
            $recipient = MarketingCampaignRecipient::query()->with(['campaign', 'profile', 'variant'])->find($recipientId);
            if (! $recipient) {
                $this->error("Recipient {$recipientId} not found.");
                return self::FAILURE;
            }

            if (
                $tenantId !== null
                && (
                    ! $ownershipService->recipientOwnedByTenant((int) $recipient->id, $tenantId)
                    || ! $ownershipService->campaignOwnedByTenant((int) ($recipient->campaign_id ?? 0), $tenantId)
                )
            ) {
                $this->error("Recipient {$recipientId} is outside tenant {$tenantId} ownership scope.");

                return self::FAILURE;
            }

            $result = $executionService->sendRecipient($recipient, [
                'dry_run' => $dryRun,
                'tenant_id' => $tenantId,
            ]);
            $this->accumulate($summary, $result);

            if ($verbose) {
                $this->line($this->formatResult($recipient, $result));
            }

            return $this->renderSummary($summary, $dryRun);
        }

        $campaignsQuery = MarketingCampaign::query()
            ->when($campaignId, fn ($query) => $query->where('id', $campaignId))
            ->where('channel', 'sms')
            ->orderBy('id');

        if ($tenantId !== null) {
            $tenantCampaignIds = $ownershipService->tenantCampaignIds($tenantId);
            if ($tenantCampaignIds->isEmpty()) {
                if ($campaignId) {
                    $this->error("Campaign {$campaignId} is outside tenant {$tenantId} ownership scope.");

                    return self::FAILURE;
                }

                $this->line('No tenant-owned SMS campaigns available for processing.');

                return self::SUCCESS;
            }

            $campaignsQuery->whereIn('id', $tenantCampaignIds->all());
        }

        $campaigns = $campaignsQuery->get();

        if ($campaignId && $campaigns->isEmpty()) {
            $scopeSuffix = $tenantId !== null ? " for tenant {$tenantId}" : '';
            $this->error("Campaign {$campaignId} not found, not SMS-enabled, or outside ownership scope{$scopeSuffix}.");
            return self::FAILURE;
        }

        $remaining = $limit;
        foreach ($campaigns as $campaign) {
            if ($remaining <= 0) {
                break;
            }

            $campaignSummary = $executionService->sendApprovedForCampaign($campaign, [
                'dry_run' => $dryRun,
                'limit' => $remaining,
                'tenant_id' => $tenantId,
            ]);

            foreach (array_keys($summary) as $key) {
                $summary[$key] += (int) ($campaignSummary[$key] ?? 0);
            }

            if ($verbose) {
                $this->line(sprintf(
                    'campaign=%d processed=%d sent=%d failed=%d skipped=%d',
                    (int) $campaign->id,
                    (int) ($campaignSummary['processed'] ?? 0),
                    (int) ($campaignSummary['sent'] ?? 0),
                    (int) ($campaignSummary['failed'] ?? 0),
                    (int) ($campaignSummary['skipped'] ?? 0)
                ));
            }

            $remaining -= (int) ($campaignSummary['processed'] ?? 0);
        }

        return $this->renderSummary($summary, $dryRun);
    }

    /**
     * @param array<string,int> $summary
     * @param array<string,mixed> $result
     */
    protected function accumulate(array &$summary, array $result): void
    {
        $summary['processed']++;
        $outcome = (string) ($result['outcome'] ?? 'skipped');
        if ($outcome === 'sent') {
            $summary['sent']++;
        } elseif ($outcome === 'failed') {
            $summary['failed']++;
        } else {
            $summary['skipped']++;
        }

        if ((bool) ($result['dry_run'] ?? false)) {
            $summary['dry_run']++;
        }
    }

    protected function integerOption(string $key): ?int
    {
        $value = $this->option($key);
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(1, (int) $value) : null;
    }

    /**
     * @param array<string,mixed> $result
     */
    protected function formatResult(MarketingCampaignRecipient $recipient, array $result): string
    {
        return sprintf(
            'recipient=%d outcome=%s reason=%s status=%s delivery=%s',
            (int) $recipient->id,
            (string) ($result['outcome'] ?? 'unknown'),
            (string) ($result['reason'] ?? 'n/a'),
            (string) ($result['status'] ?? $recipient->status),
            (string) ($result['delivery_id'] ?? '-')
        );
    }

    /**
     * @param array<string,int> $summary
     */
    protected function renderSummary(array $summary, bool $dryRun): int
    {
        $this->line($dryRun ? 'mode=dry-run' : 'mode=live');
        $this->line('processed=' . (int) $summary['processed']);
        $this->line('sent=' . (int) $summary['sent']);
        $this->line('failed=' . (int) $summary['failed']);
        $this->line('skipped=' . (int) $summary['skipped']);
        $this->line('dry_run=' . (int) $summary['dry_run']);

        return self::SUCCESS;
    }
}
