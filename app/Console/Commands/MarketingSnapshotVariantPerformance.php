<?php

namespace App\Console\Commands;

use App\Services\Marketing\MarketingPerformanceAnalyticsService;
use Illuminate\Console\Command;

class MarketingSnapshotVariantPerformance extends Command
{
    protected $signature = 'marketing:snapshot-variant-performance
        {--campaign-id= : Restrict to one campaign ID}
        {--window-start= : Optional window start datetime}
        {--window-end= : Optional window end datetime}
        {--dry-run : Compute without writing snapshots}
        {--show-details : Output per-row snapshot payload}';

    protected $description = 'Create/update marketing variant performance snapshots from deliveries and conversions.';

    public function handle(MarketingPerformanceAnalyticsService $performanceAnalytics): int
    {
        $campaignId = $this->option('campaign-id');
        $dryRun = (bool) $this->option('dry-run');
        $showDetails = (bool) $this->option('show-details');

        $summary = $performanceAnalytics->snapshotVariantPerformance([
            'campaign_id' => is_numeric($campaignId) ? (int) $campaignId : null,
            'window_start' => $this->option('window-start') ?: null,
            'window_end' => $this->option('window-end') ?: null,
            'dry_run' => $dryRun,
        ]);

        $this->info(sprintf(
            'Variant snapshot complete. processed=%d created=%d updated=%d skipped=%d',
            (int) $summary['processed'],
            (int) $summary['created'],
            (int) $summary['updated'],
            (int) $summary['skipped']
        ));

        if ($showDetails) {
            foreach ((array) $summary['rows'] as $row) {
                $this->line(json_encode($row));
            }
        }

        if ($dryRun) {
            $this->comment('Dry run mode enabled: snapshot rows were not persisted.');
        }

        return self::SUCCESS;
    }
}
