<?php

namespace App\Console\Commands;

use App\Models\MarketingImportRun;
use App\Models\Order;
use App\Services\Marketing\MarketingProfileSyncService;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyOrderIngestor;
use App\Services\Shopify\ShopifyStores;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class ShopifyBackfillOrders extends Command
{
    protected $signature = 'shopify:backfill-orders
        {store? : retail|wholesale|all}
        {--store= : retail|wholesale|all}
        {--created-since= : Start created_at boundary for historical backfill}
        {--created-until= : End created_at boundary for historical backfill}
        {--window-days=30 : Fixed created_at window size in days}
        {--limit= : Soft maximum orders to process before stopping at the next page boundary}
        {--resume-run-id= : Resume from the checkpoint of a previous shopify_orders_backfill run}
        {--checkpoint-every=100 : Persist checkpoint every N processed orders}
        {--dry-run : Preview the historical backfill without writing orders or links}';

    protected $description = 'Backfill historical Shopify orders into local orders and canonical marketing profile links without replaying loyalty side effects.';

    public function handle(
        ShopifyOrderIngestor $ingestor,
        MarketingProfileSyncService $profileSyncService
    ): int {
        $storeArg = $this->option('store') ?: $this->argument('store');
        $stores = ShopifyStores::resolve(is_string($storeArg) ? $storeArg : null);
        if ($stores === []) {
            $this->renderStoreResolutionErrors($storeArg);

            return self::FAILURE;
        }

        $resumeRunId = $this->optionalInt($this->option('resume-run-id'));
        if ($resumeRunId !== null && count($stores) !== 1) {
            $this->error('Resuming a Shopify historical backfill requires targeting exactly one store.');

            return self::FAILURE;
        }

        $createdSince = $this->resolveCreatedSince();
        $createdUntil = $this->resolveCreatedUntil();
        if ($createdUntil->lessThan($createdSince)) {
            $this->error('--created-until must be on or after --created-since.');

            return self::FAILURE;
        }

        $windowDays = max(1, (int) ($this->optionalInt($this->option('window-days')) ?? 30));
        $checkpointEvery = max(1, (int) ($this->optionalInt($this->option('checkpoint-every')) ?? 100));
        $softLimit = $this->optionalInt($this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        foreach ($stores as $store) {
            if (($scopeError = $this->scopeErrorForOrderImport($store)) !== null) {
                $this->error($scopeError);

                return self::FAILURE;
            }

            $run = null;
            $summary = [
                'processed_orders' => 0,
                'created' => 0,
                'updated' => 0,
                'lines_count' => 0,
                'merged_lines_count' => 0,
                'mapping_exceptions_count' => 0,
                'profiles_created' => 0,
                'profiles_updated' => 0,
                'links_created' => 0,
                'links_reused' => 0,
                'reviews_created' => 0,
                'records_skipped' => 0,
                'errors' => 0,
                'checkpoint_every' => $checkpointEvery,
                'checkpoint' => [
                    'window_start' => $createdSince->toIso8601String(),
                    'window_end' => null,
                    'next_page_url' => null,
                    'processed_orders' => 0,
                    'last_shopify_order_id' => null,
                ],
            ];

            $windowStart = $createdSince;
            $nextPageUrl = null;

            if ($resumeRunId !== null) {
                try {
                    [$resumeRun, $summary, $windowStart, $createdUntil, $nextPageUrl] = $this->resumeState(
                        $resumeRunId,
                        $store,
                        $createdSince,
                        $createdUntil,
                        $checkpointEvery,
                        $dryRun
                    );
                } catch (\RuntimeException $exception) {
                    $this->error($exception->getMessage());

                    return self::FAILURE;
                }

                $run = $resumeRun;
            }

            if (! $run) {
                $run = MarketingImportRun::query()->create([
                    'tenant_id' => $this->positiveInt($store['tenant_id'] ?? null),
                    'type' => 'shopify_orders_backfill',
                    'status' => 'running',
                    'source_label' => (string) ($store['key'] ?? 'shopify'),
                    'started_at' => now(),
                    'summary' => [
                        ...$summary,
                        'store' => (string) ($store['key'] ?? ''),
                        'created_since' => $createdSince->toIso8601String(),
                        'created_until' => $createdUntil->toIso8601String(),
                        'window_days' => $windowDays,
                        'soft_limit' => $softLimit,
                        'dry_run' => $dryRun,
                    ],
                ]);
            }

            $client = new ShopifyClient(
                (string) $store['shop'],
                (string) $store['token'],
                $store['api_version'] ?? '2026-01'
            );

            try {
                $stopEarly = false;

                while ($windowStart->lessThanOrEqualTo($createdUntil)) {
                    $windowEnd = $this->windowEnd($windowStart, $createdUntil, $windowDays);

                    while (true) {
                        $remainingPageLimit = $this->pageSize($softLimit, (int) ($summary['processed_orders'] ?? 0));
                        if ($remainingPageLimit <= 0) {
                            $stopEarly = true;
                            break;
                        }

                        $page = $nextPageUrl
                            ? $client->getPage($nextPageUrl)
                            : $client->getPage('orders.json', [
                                'status' => 'any',
                                'limit' => $remainingPageLimit,
                                'created_at_min' => $windowStart->toIso8601String(),
                                'created_at_max' => $windowEnd->toIso8601String(),
                            ]);

                        $orders = is_array($page['items'] ?? null) ? $page['items'] : [];
                        foreach ($orders as $orderData) {
                            $shopifyOrderId = isset($orderData['id']) ? (int) $orderData['id'] : null;
                            if (! $shopifyOrderId) {
                                continue;
                            }

                            $existingOrder = Order::query()
                                ->where('shopify_store_key', $store['key'])
                                ->where('shopify_order_id', $shopifyOrderId)
                                ->first();

                            if ($existingOrder) {
                                $summary['updated']++;
                            } else {
                                $summary['created']++;
                            }

                            if ($dryRun) {
                                $note = $this->buildOrderNote($orderData);
                                $mergedLines = $ingestor->mergeLineItems(
                                    $orderData['line_items'] ?? [],
                                    $note,
                                    $orderData,
                                    $store['key'] ?? null
                                );
                                $summary['merged_lines_count'] += max(0, count($orderData['line_items'] ?? []) - count($mergedLines));
                                $summary['lines_count'] += count($mergedLines);
                            } else {
                                $result = $ingestor->ingest($store, $orderData, [
                                    'dispatch_profile_sync' => false,
                                ]);

                                $summary['lines_count'] += (int) ($result['lines_count'] ?? 0);
                                $summary['merged_lines_count'] += (int) ($result['merged_lines_count'] ?? 0);
                                $summary['mapping_exceptions_count'] += (int) ($result['mapping_exceptions_count'] ?? 0);

                                $orderId = isset($result['order_id']) && is_numeric($result['order_id'])
                                    ? (int) $result['order_id']
                                    : null;
                                if ($orderId !== null && $orderId > 0) {
                                    $order = Order::query()->find($orderId);
                                    if ($order) {
                                        $sync = $profileSyncService->syncOrder($order, [
                                            'tenant_id' => $this->positiveInt($store['tenant_id'] ?? null),
                                        ]);

                                        foreach (['profiles_created', 'profiles_updated', 'links_created', 'links_reused', 'reviews_created', 'records_skipped'] as $key) {
                                            $summary[$key] += (int) ($sync[$key] ?? 0);
                                        }
                                    }
                                }
                            }

                            $summary['processed_orders']++;
                            $summary['checkpoint'] = [
                                'window_start' => $windowStart->toIso8601String(),
                                'window_end' => $windowEnd->toIso8601String(),
                                'next_page_url' => $page['next_url'] ?? null,
                                'processed_orders' => $summary['processed_orders'],
                                'last_shopify_order_id' => $shopifyOrderId,
                            ];

                            if ($summary['processed_orders'] % $checkpointEvery === 0) {
                                $this->persistCheckpoint($run, $summary);
                            }
                        }

                        $nextPageUrl = is_string($page['next_url'] ?? null) && trim((string) $page['next_url']) !== ''
                            ? trim((string) $page['next_url'])
                            : null;

                        $this->persistCheckpoint($run, [
                            ...$summary,
                            'checkpoint' => [
                                'window_start' => $windowStart->toIso8601String(),
                                'window_end' => $windowEnd->toIso8601String(),
                                'next_page_url' => $nextPageUrl,
                                'processed_orders' => $summary['processed_orders'],
                                'last_shopify_order_id' => data_get($summary, 'checkpoint.last_shopify_order_id'),
                            ],
                        ]);

                        if ($softLimit !== null && $summary['processed_orders'] >= $softLimit) {
                            $stopEarly = true;
                            break;
                        }

                        if ($nextPageUrl === null) {
                            break;
                        }
                    }

                    if ($stopEarly) {
                        if ($nextPageUrl === null) {
                            $summary['checkpoint'] = [
                                'window_start' => $windowEnd->addSecond()->toIso8601String(),
                                'window_end' => null,
                                'next_page_url' => null,
                                'processed_orders' => $summary['processed_orders'],
                                'last_shopify_order_id' => data_get($summary, 'checkpoint.last_shopify_order_id'),
                            ];
                            $this->persistCheckpoint($run, $summary);
                        }

                        break;
                    }

                    $summary['checkpoint'] = [
                        'window_start' => $windowEnd->addSecond()->toIso8601String(),
                        'window_end' => null,
                        'next_page_url' => null,
                        'processed_orders' => $summary['processed_orders'],
                        'last_shopify_order_id' => data_get($summary, 'checkpoint.last_shopify_order_id'),
                    ];
                    $this->persistCheckpoint($run, $summary);

                    $windowStart = $windowEnd->addSecond();
                    $nextPageUrl = null;
                }

                $finalStatus = $summary['errors'] > 0
                    ? 'partial'
                    : (($softLimit !== null && $summary['processed_orders'] >= $softLimit) ? 'stopped' : 'completed');

                $run->forceFill([
                    'status' => $finalStatus,
                    'finished_at' => now(),
                    'summary' => [
                        ...$summary,
                        'store' => (string) ($store['key'] ?? ''),
                        'created_since' => $createdSince->toIso8601String(),
                        'created_until' => $createdUntil->toIso8601String(),
                        'window_days' => $windowDays,
                        'soft_limit' => $softLimit,
                        'dry_run' => $dryRun,
                    ],
                ])->save();
            } catch (Throwable $exception) {
                $run->forceFill([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'notes' => $exception->getMessage(),
                    'summary' => [
                        ...$summary,
                        'store' => (string) ($store['key'] ?? ''),
                        'created_since' => $createdSince->toIso8601String(),
                        'created_until' => $createdUntil->toIso8601String(),
                        'window_days' => $windowDays,
                        'soft_limit' => $softLimit,
                        'dry_run' => $dryRun,
                    ],
                ])->save();

                $this->error($this->formatSyncFailureMessage((string) $store['key'], 'historical order backfill', $exception));

                return self::FAILURE;
            }

            $this->line('store=' . (string) ($store['key'] ?? ''));
            foreach (['processed_orders', 'created', 'updated', 'lines_count', 'merged_lines_count', 'mapping_exceptions_count', 'profiles_created', 'profiles_updated', 'links_created', 'links_reused', 'reviews_created', 'records_skipped', 'errors'] as $key) {
                $this->line($key . '=' . (int) ($summary[$key] ?? 0));
            }
            $this->line('run_id=' . (string) $run->id);
            $this->line('status=' . (string) $run->status);
        }

        return self::SUCCESS;
    }

    protected function resolveCreatedSince(): CarbonImmutable
    {
        $value = trim((string) $this->option('created-since'));

        if ($value === '') {
            return CarbonImmutable::parse('2017-01-01 00:00:00');
        }

        return CarbonImmutable::parse($value);
    }

    protected function resolveCreatedUntil(): CarbonImmutable
    {
        $value = trim((string) $this->option('created-until'));

        if ($value === '') {
            return CarbonImmutable::now();
        }

        return CarbonImmutable::parse($value);
    }

    protected function windowEnd(CarbonImmutable $windowStart, CarbonImmutable $createdUntil, int $windowDays): CarbonImmutable
    {
        $candidate = $windowStart
            ->addDays($windowDays)
            ->subSecond();

        return $candidate->lessThan($createdUntil) ? $candidate : $createdUntil;
    }

    /**
     * @return array{0:MarketingImportRun,1:array<string,mixed>,2:CarbonImmutable,3:CarbonImmutable,4:?string}
     */
    protected function resumeState(
        int $runId,
        array $store,
        CarbonImmutable $createdSince,
        CarbonImmutable $createdUntil,
        int $checkpointEvery,
        bool $dryRun
    ): array {
        $run = MarketingImportRun::query()
            ->whereKey($runId)
            ->where('type', 'shopify_orders_backfill')
            ->where('source_label', (string) ($store['key'] ?? ''))
            ->when(
                $this->positiveInt($store['tenant_id'] ?? null) === null,
                fn ($query) => $query->whereNull('tenant_id'),
                fn ($query) => $query->where('tenant_id', $this->positiveInt($store['tenant_id'] ?? null))
            )
            ->first();

        if (! $run) {
            throw new \RuntimeException("Run {$runId} is not accessible for store ".(string) ($store['key'] ?? 'unknown').'.');
        }

        $summary = is_array($run->summary ?? null) ? $run->summary : [];
        $checkpoint = is_array($summary['checkpoint'] ?? null) ? $summary['checkpoint'] : [];

        $windowStartValue = trim((string) ($checkpoint['window_start'] ?? $summary['created_since'] ?? ''));
        if ($windowStartValue === '') {
            throw new \RuntimeException("Run {$runId} does not contain a resumable checkpoint.");
        }

        $windowStart = CarbonImmutable::parse($windowStartValue);
        $resumeCreatedUntil = trim((string) ($summary['created_until'] ?? ''));
        $nextPageUrl = trim((string) ($checkpoint['next_page_url'] ?? ''));

        $summary = [
            'processed_orders' => (int) ($summary['processed_orders'] ?? $checkpoint['processed_orders'] ?? 0),
            'created' => (int) ($summary['created'] ?? 0),
            'updated' => (int) ($summary['updated'] ?? 0),
            'lines_count' => (int) ($summary['lines_count'] ?? 0),
            'merged_lines_count' => (int) ($summary['merged_lines_count'] ?? 0),
            'mapping_exceptions_count' => (int) ($summary['mapping_exceptions_count'] ?? 0),
            'profiles_created' => (int) ($summary['profiles_created'] ?? 0),
            'profiles_updated' => (int) ($summary['profiles_updated'] ?? 0),
            'links_created' => (int) ($summary['links_created'] ?? 0),
            'links_reused' => (int) ($summary['links_reused'] ?? 0),
            'reviews_created' => (int) ($summary['reviews_created'] ?? 0),
            'records_skipped' => (int) ($summary['records_skipped'] ?? 0),
            'errors' => (int) ($summary['errors'] ?? 0),
            'checkpoint_every' => $checkpointEvery,
            'checkpoint' => [
                'window_start' => $windowStart->toIso8601String(),
                'window_end' => $checkpoint['window_end'] ?? null,
                'next_page_url' => $nextPageUrl !== '' ? $nextPageUrl : null,
                'processed_orders' => (int) ($checkpoint['processed_orders'] ?? $summary['processed_orders'] ?? 0),
                'last_shopify_order_id' => $checkpoint['last_shopify_order_id'] ?? null,
            ],
            'dry_run' => $dryRun,
        ];

        $run->forceFill([
            'status' => 'running',
            'finished_at' => null,
            'summary' => [
                ...$summary,
                'store' => (string) ($store['key'] ?? ''),
                'created_since' => $createdSince->toIso8601String(),
                'created_until' => $resumeCreatedUntil !== '' ? CarbonImmutable::parse($resumeCreatedUntil)->toIso8601String() : $createdUntil->toIso8601String(),
                'dry_run' => $dryRun,
            ],
        ])->save();

        return [
            $run,
            $summary,
            $windowStart,
            $resumeCreatedUntil !== '' ? CarbonImmutable::parse($resumeCreatedUntil) : $createdUntil,
            $nextPageUrl !== '' ? $nextPageUrl : null,
        ];
    }

    /**
     * @param array<string,mixed> $summary
     */
    protected function persistCheckpoint(MarketingImportRun $run, array $summary): void
    {
        $run->forceFill([
            'summary' => [
                ...(is_array($run->summary ?? null) ? $run->summary : []),
                ...$summary,
            ],
        ])->save();
    }

    protected function renderStoreResolutionErrors(mixed $storeArg): void
    {
        $normalized = is_string($storeArg) && trim($storeArg) !== '' ? trim($storeArg) : null;
        $issues = ShopifyStores::unresolvedMessages($normalized);

        if ($issues === []) {
            $this->error('No valid Shopify store configuration found for the given store key.');

            return;
        }

        foreach ($issues as $issue) {
            $this->error($issue);
        }
    }

    /**
     * @param array<string,mixed> $store
     */
    protected function scopeErrorForOrderImport(array $store): ?string
    {
        $scopeString = trim((string) ($store['scopes'] ?? ''));
        if ($scopeString === '') {
            return null;
        }

        $scopes = $this->normalizeScopes($scopeString);
        if (array_intersect(['read_orders', 'read_all_orders', 'write_orders'], $scopes) !== []) {
            return null;
        }

        $storeKey = (string) ($store['key'] ?? 'unknown');

        return "{$storeKey} store scopes insufficient for historical order backfill (missing read_orders/read_all_orders). Run /shopify/reinstall/{$storeKey}.";
    }

    protected function formatSyncFailureMessage(string $storeKey, string $context, Throwable $e): string
    {
        $message = trim($e->getMessage());
        $normalized = strtolower($message);

        if (
            str_contains($normalized, '401')
            || str_contains($normalized, 'unauthorized')
            || str_contains($normalized, 'invalid api key or access token')
        ) {
            return "{$storeKey} store token missing or revoked during {$context}. Run /shopify/reinstall/{$storeKey}.";
        }

        if (
            str_contains($normalized, '403')
            || str_contains($normalized, 'access denied')
            || str_contains($normalized, 'insufficient_scope')
        ) {
            return "{$storeKey} store scopes insufficient for {$context}. Run /shopify/reinstall/{$storeKey}.";
        }

        return "{$storeKey} {$context} failed: {$message}";
    }

    /**
     * @return array<int,string>
     */
    protected function normalizeScopes(string $scopeString): array
    {
        return array_values(array_filter(array_map(
            static fn (string $scope): string => trim(strtolower($scope)),
            explode(',', $scopeString)
        )));
    }

    /**
     * @param array<string,mixed> $orderData
     */
    protected function buildOrderNote(array $orderData): ?string
    {
        $note = trim((string) ($orderData['note'] ?? ''));

        return $note !== '' ? $note : null;
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

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    protected function pageSize(?int $softLimit, int $processedOrders): int
    {
        if ($softLimit === null) {
            return 250;
        }

        return max(0, min(250, $softLimit - $processedOrders));
    }
}
