<?php

namespace App\Console\Commands;

use App\Services\Marketing\GrowaveWishlistBackfillService;
use Illuminate\Console\Command;

class MarketingImportGrowaveWishlists extends Command
{
    protected $signature = 'marketing:import-growave-wishlists
        {--store= : Optional store_key filter}
        {--limit=500 : Maximum Growave external profiles to process}
        {--profile-id= : Optional marketing_profile_id filter}
        {--after-candidate-id= : Resume after customer_external_profiles.id}
        {--latest-only=1 : Use only the latest Growave snapshot per store/customer identity}
        {--dry-run : Preview import actions without writing}
        {--per-page=50 : Growave API page size (max 50)}
        {--max-wishlist-pages=20 : Max wishlist pages per customer}
        {--max-item-pages=20 : Max wishlist item pages per wishlist}
        {--page-delay-ms=150 : Milliseconds to pause between paginated API requests}
        {--request-min-interval-ms= : Override minimum milliseconds between Growave API requests}
        {--request-jitter-ms= : Override request pacing jitter in milliseconds}
    {--retry-attempts= : Override Growave API retry attempts}
        {--backoff-base-ms= : Override Growave API retry backoff base milliseconds}
        {--backoff-max-ms= : Override Growave API retry backoff max milliseconds}
        {--tenant-id= : Optional tenant ID when --store is not provided}';

    protected $description = 'Backfill legacy Growave wishlist data into canonical marketing_profile_wishlist_items rows.';

    public function handle(GrowaveWishlistBackfillService $service): int
    {
        $latestOnly = $this->option('latest-only');
        $latestOnly = ! in_array(strtolower(trim((string) $latestOnly)), ['0', 'false', 'no'], true);

        $storeKey = $this->normalizeStoreKey($this->option('store'));
        $tenantIdOption = $this->option('tenant-id');
        $tenantId = is_numeric($tenantIdOption) ? (int) $tenantIdOption : null;

        if ($storeKey === null && $tenantId === null) {
            $this->error('A Shopify store key (--store) or --tenant-id is required for the wishlist backfill.');

            return self::FAILURE;
        }

        try {
            $result = $service->backfill([
                'store' => $storeKey,
                'tenant_id' => $tenantId,
                'limit' => $this->optionalInt($this->option('limit')),
                'profile_id' => $this->optionalInt($this->option('profile-id')),
                'after_candidate_id' => $this->optionalInt($this->option('after-candidate-id')),
                'latest_only' => $latestOnly,
                'dry_run' => (bool) $this->option('dry-run'),
                'per_page' => max(1, (int) $this->option('per-page')),
                'max_wishlist_pages' => max(1, (int) $this->option('max-wishlist-pages')),
                'max_item_pages' => max(1, (int) $this->option('max-item-pages')),
                'page_delay_ms' => max(0, (int) $this->option('page-delay-ms')),
                'request_min_interval_ms' => $this->optionalInt($this->option('request-min-interval-ms')),
                'request_jitter_ms' => $this->optionalInt($this->option('request-jitter-ms')),
                'retry_attempts' => $this->optionalInt($this->option('retry-attempts')),
                'backoff_base_ms' => $this->optionalInt($this->option('backoff-base-ms')),
                'backoff_max_ms' => $this->optionalInt($this->option('backoff-max-ms')),
            ]);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];

        $this->line((bool) $this->option('dry-run') ? 'mode=dry-run' : 'mode=live-import');
        $this->line('status=' . (string) ($result['status'] ?? 'unknown'));
        $this->line('run_id=' . (string) ($result['run_id'] ?? 'n/a'));

        if (isset($result['reason'])) {
            $this->line('reason=' . (string) $result['reason']);
        }

        foreach ([
            'processed_candidates',
            'candidates_skipped_duplicate_snapshot',
            'wishlists_seen',
            'wishlist_items_seen',
            'mapped_items',
            'created',
            'updated',
            'unchanged',
            'dry_run_would_create',
            'dry_run_would_update',
            'skipped_native_authoritative',
            'skipped_unresolved_profile',
            'skipped_missing_store_key',
            'skipped_missing_customer_identifier',
            'skipped_unmappable_product',
            'errors',
        ] as $key) {
            $this->line($key . '=' . (int) ($summary[$key] ?? 0));
        }

        $reasonSummary = is_array($summary['unmappable_by_reason'] ?? null)
            ? $summary['unmappable_by_reason']
            : [];

        foreach ($reasonSummary as $reason => $count) {
            $this->line('reason_' . $reason . '=' . (int) $count);
        }

        return ((int) ($summary['errors'] ?? 0) > 0) ? self::FAILURE : self::SUCCESS;
    }

    protected function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function normalizeStoreKey(?string $value): ?string
    {
        $storeKey = strtolower(trim((string) ($value ?? '')));
        if ($storeKey === '' || $storeKey === 'all') {
            return null;
        }

        return $storeKey;
    }
}
