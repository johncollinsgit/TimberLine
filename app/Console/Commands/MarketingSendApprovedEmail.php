<?php

namespace App\Console\Commands;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Services\Marketing\MarketingEmailExecutionService;
use Illuminate\Console\Command;

class MarketingSendApprovedEmail extends Command
{
    protected $signature = 'marketing:send-approved-email
        {--campaign-id= : Send only for a specific campaign}
        {--recipient-id= : Send only for a specific recipient}
        {--limit=200 : Maximum recipients to process}
        {--dry-run : Simulate successful sends without calling SendGrid}';

    protected $description = 'Send approved email campaign recipients through SendGrid with delivery logging.';

    public function handle(MarketingEmailExecutionService $executionService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $campaignId = $this->integerOption('campaign-id');
        $recipientId = $this->integerOption('recipient-id');
        $limit = max(1, (int) ($this->option('limit') ?: 200));

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

            $this->accumulate($summary, $executionService->sendRecipient($recipient, ['dry_run' => $dryRun]));

            return $this->renderSummary($summary, $dryRun);
        }

        $campaigns = MarketingCampaign::query()
            ->when($campaignId, fn ($query) => $query->where('id', $campaignId))
            ->where('channel', 'email')
            ->orderBy('id')
            ->get();

        if ($campaignId && $campaigns->isEmpty()) {
            $this->error("Campaign {$campaignId} not found or not email-enabled.");

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
            ]);

            foreach (array_keys($summary) as $key) {
                $summary[$key] += (int) ($campaignSummary[$key] ?? 0);
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

