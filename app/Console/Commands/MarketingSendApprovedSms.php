<?php

namespace App\Console\Commands;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Services\Marketing\MarketingSmsExecutionService;
use Illuminate\Console\Command;

class MarketingSendApprovedSms extends Command
{
    protected $signature = 'marketing:send-approved-sms
        {--campaign-id= : Send only for a specific campaign}
        {--recipient-id= : Send only for a specific recipient}
        {--limit=200 : Maximum recipients to process}
        {--dry-run : Simulate successful sends without calling Twilio}';

    protected $description = 'Send approved SMS campaign recipients through Twilio with delivery logging.';

    public function handle(MarketingSmsExecutionService $executionService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $verbose = $this->getOutput()->isVerbose();
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

            $result = $executionService->sendRecipient($recipient, ['dry_run' => $dryRun]);
            $this->accumulate($summary, $result);

            if ($verbose) {
                $this->line($this->formatResult($recipient, $result));
            }

            return $this->renderSummary($summary, $dryRun);
        }

        $campaigns = MarketingCampaign::query()
            ->when($campaignId, fn ($query) => $query->where('id', $campaignId))
            ->where('channel', 'sms')
            ->orderBy('id')
            ->get();

        if ($campaignId && $campaigns->isEmpty()) {
            $this->error("Campaign {$campaignId} not found or not SMS-enabled.");
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
