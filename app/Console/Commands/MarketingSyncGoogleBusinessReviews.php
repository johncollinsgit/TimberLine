<?php

namespace App\Console\Commands;

use App\Services\Marketing\GoogleBusinessProfileException;
use App\Services\Marketing\GoogleBusinessProfileConnectionService;
use App\Services\Marketing\GoogleBusinessProfileReviewSyncService;
use Illuminate\Console\Command;

class MarketingSyncGoogleBusinessReviews extends Command
{
    protected $signature = 'marketing:sync-google-business-reviews';

    protected $description = 'Poll Google Business reviews and award Candle Cash matches when review sync is live.';

    public function handle(
        GoogleBusinessProfileConnectionService $connectionService,
        GoogleBusinessProfileReviewSyncService $syncService
    ): int {
        $readiness = $connectionService->reviewReadiness();

        if (! (bool) ($readiness['ready'] ?? false)) {
            $this->line('status=skipped');
            $this->line('reason=' . (string) ($readiness['reason'] ?? 'needs_connection'));
            $this->line('message=' . (string) ($readiness['message'] ?? 'Google review matching is not live yet.'));

            return self::SUCCESS;
        }

        try {
            $result = $syncService->sync();
        } catch (GoogleBusinessProfileException $exception) {
            $this->error($exception->getMessage());
            $this->line('status=failed');
            $this->line('reason=' . $exception->errorCode);

            return self::FAILURE;
        }

        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];

        $this->line('status=completed');
        $this->line('fetched=' . (int) ($counts['fetched'] ?? 0));
        $this->line('matched=' . (int) ($counts['matched'] ?? 0));
        $this->line('awarded=' . (int) ($counts['awarded'] ?? 0));
        $this->line('unmatched=' . (int) ($counts['unmatched'] ?? 0));
        $this->line('duplicates=' . (int) ($counts['duplicates'] ?? 0));

        return self::SUCCESS;
    }
}
