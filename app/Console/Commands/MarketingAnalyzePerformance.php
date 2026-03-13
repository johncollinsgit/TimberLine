<?php

namespace App\Console\Commands;

use App\Services\Marketing\MarketingPerformanceAnalyticsService;
use App\Services\Marketing\MarketingTimingRecommendationService;
use Illuminate\Console\Command;

class MarketingAnalyzePerformance extends Command
{
    protected $signature = 'marketing:analyze-performance
        {--campaign-id= : Restrict analytics to one campaign ID}
        {--window-start= : Optional window start datetime}
        {--window-end= : Optional window end datetime}
        {--dry-run : Compute without storing snapshot or insight rows}
        {--verbose : Output per-row detail}';

    protected $description = 'Analyze campaign performance across SMS/email deliveries and conversion outcomes.';

    public function handle(
        MarketingPerformanceAnalyticsService $performanceAnalytics,
        MarketingTimingRecommendationService $timingRecommendationService
    ): int {
        $campaignId = $this->option('campaign-id');
        $windowStart = $this->option('window-start');
        $windowEnd = $this->option('window-end');
        $dryRun = (bool) $this->option('dry-run');
        $verbose = (bool) $this->option('verbose');

        $snapshot = $performanceAnalytics->snapshotVariantPerformance([
            'campaign_id' => is_numeric($campaignId) ? (int) $campaignId : null,
            'window_start' => $windowStart ?: null,
            'window_end' => $windowEnd ?: null,
            'dry_run' => $dryRun,
        ]);

        $timing = $timingRecommendationService->generateInsights([
            'campaign_id' => is_numeric($campaignId) ? (int) $campaignId : null,
            'dry_run' => $dryRun,
        ]);

        $this->info(sprintf(
            'Performance analysis complete. processed=%d created=%d updated=%d skipped=%d',
            (int) $snapshot['processed'],
            (int) $snapshot['created'],
            (int) $snapshot['updated'],
            (int) $snapshot['skipped']
        ));
        $this->line(sprintf(
            'Timing insights: processed=%d created=%d updated=%d skipped=%d',
            (int) $timing['processed'],
            (int) $timing['created'],
            (int) $timing['updated'],
            (int) $timing['skipped']
        ));

        if ($verbose) {
            foreach ((array) $snapshot['rows'] as $row) {
                $this->line(json_encode($row));
            }
        }

        if ($dryRun) {
            $this->comment('Dry run mode enabled: no analytics snapshot rows were written.');
        }

        return self::SUCCESS;
    }
}

