<?php

namespace App\Console\Commands;

use App\Models\MarketingImportRun;
use App\Services\Marketing\GrowaveMarketingSyncService;
use Illuminate\Console\Command;

class MarketingSyncGrowave extends Command
{
    protected $signature = 'marketing:sync-growave
        {--store= : Optional store_key filter (retail|wholesale)}
        {--limit= : Maximum customers to process (omit for full sync)}
        {--after-candidate-id= : Resume from a specific customer_external_profiles.id}
        {--resume-run-id= : Resume from the checkpoint of a previous growave_customer_sync run}
        {--checkpoint-every=100 : Persist run checkpoint every N candidates}
        {--only-missing : Process only Shopify candidates that do not already have a Growave external profile row}
        {--reviews-per-page=50 : Reviews API page size (max 50)}
        {--activities-per-page=100 : Activity API page size (max 250)}
        {--max-review-pages=20 : Max review pages per customer}
        {--max-activity-pages=20 : Max activity pages per customer}
        {--candidate-delay-ms=50 : Milliseconds to pause between customer candidates}
        {--page-delay-ms=150 : Milliseconds to pause between paginated API page requests}
        {--request-min-interval-ms= : Override minimum milliseconds between Growave API requests}
        {--request-jitter-ms= : Override request pacing jitter in milliseconds}
        {--retry-attempts= : Override Growave API retry attempts}
        {--backoff-base-ms= : Override Growave API retry backoff base milliseconds}
        {--backoff-max-ms= : Override Growave API retry backoff max milliseconds}';

    protected $description = 'Read-only Growave enrichment sync for loyalty/reviews/birthday/referral/VIP into marketing source layers.';

    public function handle(GrowaveMarketingSyncService $syncService): int
    {
        $afterCandidateId = $this->optionalInt($this->option('after-candidate-id'));
        $resumeRunId = $this->optionalInt($this->option('resume-run-id'));
        if ($resumeRunId !== null) {
            $checkpointId = $this->checkpointCandidateIdFromRun($resumeRunId);
            if ($checkpointId !== null && ($afterCandidateId === null || $checkpointId > $afterCandidateId)) {
                $afterCandidateId = $checkpointId;
            }
        }

        $result = $syncService->sync([
            'store' => $this->option('store'),
            'limit' => $this->optionalInt($this->option('limit')),
            'after_candidate_id' => $afterCandidateId,
            'checkpoint_every' => max(1, (int) ($this->optionalInt($this->option('checkpoint-every')) ?? 100)),
            'only_missing' => (bool) $this->option('only-missing'),
            'reviews_per_page' => max(1, (int) $this->option('reviews-per-page')),
            'activities_per_page' => max(1, (int) $this->option('activities-per-page')),
            'max_review_pages' => max(1, (int) $this->option('max-review-pages')),
            'max_activity_pages' => max(1, (int) $this->option('max-activity-pages')),
            'candidate_delay_ms' => max(0, (int) $this->option('candidate-delay-ms')),
            'page_delay_ms' => max(0, (int) $this->option('page-delay-ms')),
            'request_min_interval_ms' => $this->optionalInt($this->option('request-min-interval-ms')),
            'request_jitter_ms' => $this->optionalInt($this->option('request-jitter-ms')),
            'retry_attempts' => $this->optionalInt($this->option('retry-attempts')),
            'backoff_base_ms' => $this->optionalInt($this->option('backoff-base-ms')),
            'backoff_max_ms' => $this->optionalInt($this->option('backoff-max-ms')),
        ]);

        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];

        $this->line('status=' . (string) ($result['status'] ?? 'unknown'));
        $this->line('run_id=' . (string) ($result['run_id'] ?? 'n/a'));

        if (isset($result['reason'])) {
            $this->line('reason=' . (string) $result['reason']);
        }

        foreach ([
            'processed',
            'growave_found',
            'growave_not_found',
            'profiles_resolved',
            'profiles_unresolved',
            'external_created',
            'external_updated',
            'review_summaries_created',
            'review_summaries_updated',
            'review_rows_created',
            'review_rows_updated',
            'activity_rows_created',
            'activity_rows_skipped_existing',
            'activity_rows_skipped_no_profile',
            'candle_balance_delta',
            'errors',
        ] as $key) {
            $this->line($key . '=' . (int) ($summary[$key] ?? 0));
        }

        return ((int) ($summary['errors'] ?? 0) > 0) ? self::FAILURE : self::SUCCESS;
    }

    protected function checkpointCandidateIdFromRun(int $runId): ?int
    {
        $run = MarketingImportRun::query()
            ->where('id', $runId)
            ->where('type', 'growave_customer_sync')
            ->first();

        if (! $run) {
            return null;
        }

        return $this->optionalInt(data_get($run->summary, 'checkpoint.last_candidate_id'));
    }

    protected function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max(1, (int) $value);
    }
}
