<?php

namespace App\Console\Commands;

use App\Models\MarketingImportRun;
use App\Services\Marketing\GrowaveMarketingSyncService;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Console\Command;

class MarketingSyncGrowave extends Command
{
    protected $signature = 'marketing:sync-growave
        {--store= : Required store_key filter (retail|wholesale)}
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
        $store = $this->normalizeStoreKey($this->option('store'));
        if ($store === null) {
            $this->error('Missing required --store. Growave sync is tenant-scoped and cannot run globally.');

            return self::FAILURE;
        }

        $tenantId = $this->tenantIdForStore($store);
        if ($tenantId === null) {
            $this->error("Unable to resolve tenant ownership for store '{$store}'.");

            return self::FAILURE;
        }

        $afterCandidateId = $this->optionalInt($this->option('after-candidate-id'));
        $resumeRunId = $this->optionalInt($this->option('resume-run-id'));
        if ($resumeRunId !== null) {
            try {
                $checkpointId = $this->checkpointCandidateIdFromRun($resumeRunId, $tenantId, $store);
            } catch (\RuntimeException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            if ($checkpointId !== null && ($afterCandidateId === null || $checkpointId > $afterCandidateId)) {
                $afterCandidateId = $checkpointId;
            }
        }

        $result = $syncService->sync([
            'store' => $store,
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

    protected function checkpointCandidateIdFromRun(int $runId, int $tenantId, string $storeKey): ?int
    {
        $run = MarketingImportRun::tenantScopedRun($runId, 'growave_customer_sync', $tenantId);

        if (! $run) {
            throw new \RuntimeException("Run {$runId} is not accessible for tenant {$tenantId} (growave_customer_sync).");
        }

        if (! is_numeric($run->tenant_id) || (int) $run->tenant_id <= 0) {
            throw new \RuntimeException("Run {$runId} is missing tenant ownership and cannot be resumed safely.");
        }

        $runStore = strtolower(trim((string) data_get($run->summary, 'store', '')));
        if ($runStore !== '' && $runStore !== strtolower($storeKey)) {
            throw new \RuntimeException("Run {$runId} belongs to store '{$runStore}', not '{$storeKey}'.");
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

    protected function normalizeStoreKey(mixed $value): ?string
    {
        $storeKey = strtolower(trim((string) ($value ?? '')));
        if ($storeKey === '' || $storeKey === 'all') {
            return null;
        }

        return $storeKey;
    }

    protected function tenantIdForStore(string $storeKey): ?int
    {
        $store = ShopifyStores::find($storeKey);
        if (! is_array($store)) {
            return null;
        }

        $tenantId = $this->optionalInt($store['tenant_id'] ?? null);

        return $tenantId !== null && $tenantId > 0 ? $tenantId : null;
    }
}
