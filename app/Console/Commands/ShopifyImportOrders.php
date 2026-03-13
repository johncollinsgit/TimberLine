<?php

namespace App\Console\Commands;

use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyOrderIngestor;
use App\Services\Shopify\ShopifyStores;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Models\ShopifyImportRun;
use Throwable;

class ShopifyImportOrders extends Command
{
    protected $signature = 'shopify:import-orders {store?} {--store=} {--days=7} {--since=} {--dry-run} {--limit=} {--status=open} {--include-closed}';

    protected $description = 'Import Shopify orders and line items into the local database.';

    public function handle(): int
    {
        $storeArg = $this->option('store') ?: $this->argument('store');
        $stores = ShopifyStores::resolve($storeArg);
        if (empty($stores)) {
            $this->renderStoreResolutionErrors($storeArg);
            return self::FAILURE;
        }

        $since = $this->resolveSince();
        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;
        $dryRun = (bool) $this->option('dry-run');
        $includeClosed = (bool) $this->option('include-closed');
        $status = (string) $this->option('status');
        $ingestor = app(ShopifyOrderIngestor::class);

        foreach ($stores as $store) {
            if (($scopeError = $this->scopeErrorForOrderImport($store)) !== null) {
                $this->error($scopeError);

                return self::FAILURE;
            }

            $summary = [
                'imported_count' => 0,
                'updated_count' => 0,
                'lines_count' => 0,
                'merged_lines_count' => 0,
                'mapping_exceptions_count' => 0,
            ];

            $run = ShopifyImportRun::create([
                'store_key' => $store['key'] ?? null,
                'source' => $store['source'] ?? null,
                'is_dry_run' => $dryRun,
                'started_at' => now(),
            ]);

            $client = new ShopifyClient(
                $store['shop'],
                $store['token'],
                $store['api_version'] ?? '2026-01'
            );

            $params = [
                'status' => $includeClosed ? 'any' : $status,
                'limit' => $limit ? min($limit, 250) : 250,
            ];

            if ($this->option('since')) {
                $params['updated_at_min'] = $since->toIso8601String();
            } else {
                $params['created_at_min'] = $since->toIso8601String();
            }

            try {
                $response = $client->get('orders.json', $params);
            } catch (Throwable $e) {
                $this->error($this->formatSyncFailureMessage((string) $store['key'], 'order import', $e));

                return self::FAILURE;
            }
            $orders = $response['orders'] ?? [];

            if ($limit) {
                $orders = array_slice($orders, 0, $limit);
            }

            foreach ($orders as $orderData) {
                if (!$includeClosed && !$this->isOpenOrder($orderData)) {
                    continue;
                }
                $shopifyOrderId = isset($orderData['id']) ? (int) $orderData['id'] : null;
                if (!$shopifyOrderId) {
                    continue;
                }

                $existingOrder = \App\Models\Order::query()
                    ->where('shopify_store_key', $store['key'])
                    ->where('shopify_order_id', $shopifyOrderId)
                    ->first();

                if ($existingOrder) {
                    $summary['updated_count']++;
                } else {
                    $summary['imported_count']++;
                }

                if ($dryRun) {
                    $note = $this->buildOrderNote($orderData);
                    $mergedLines = $ingestor->mergeLineItems($orderData['line_items'] ?? [], $note, $orderData, $store['key'] ?? null);
                    $summary['merged_lines_count'] += max(0, count($orderData['line_items'] ?? []) - count($mergedLines));
                    $summary['lines_count'] += count($mergedLines);
                    continue;
                }

                $result = $ingestor->ingest($store, $orderData);
                $summary['lines_count'] += $result['lines_count'];
                $summary['merged_lines_count'] += $result['merged_lines_count'];
                $summary['mapping_exceptions_count'] += $result['mapping_exceptions_count'];
            }

            $this->info(sprintf(
                '%s: imported=%d updated=%d lines=%d merged_lines=%d mapping_exceptions=%d',
                $store['key'],
                $summary['imported_count'],
                $summary['updated_count'],
                $summary['lines_count'],
                $summary['merged_lines_count'],
                $summary['mapping_exceptions_count']
            ));

            $run->update([
                'imported_count' => $summary['imported_count'],
                'updated_count' => $summary['updated_count'],
                'lines_count' => $summary['lines_count'],
                'merged_lines_count' => $summary['merged_lines_count'],
                'mapping_exceptions_count' => $summary['mapping_exceptions_count'],
                'finished_at' => now(),
            ]);
        }

        return self::SUCCESS;
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
        $acceptableScopes = [
            'read_orders',
            'read_all_orders',
            'write_orders',
        ];

        if (array_intersect($acceptableScopes, $scopes) !== []) {
            return null;
        }

        $storeKey = (string) ($store['key'] ?? 'unknown');

        return "{$storeKey} store scopes insufficient for order import (missing read_orders/read_all_orders). Run /shopify/reinstall/{$storeKey}.";
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

    protected function resolveSince(): CarbonImmutable
    {
        $since = $this->option('since');
        if (!empty($since)) {
            return CarbonImmutable::parse($since);
        }

        $days = (int) $this->option('days');
        $days = $days > 0 ? $days : 7;

        return CarbonImmutable::now()->subDays($days);
    }

    /**
     * @param array<string, mixed> $orderData
     */
    protected function isOpenOrder(array $orderData): bool
    {
        if (!empty($orderData['cancelled_at'])) {
            return false;
        }

        if (!empty($orderData['closed_at'])) {
            return false;
        }

        $fulfillment = $orderData['fulfillment_status'] ?? null;
        if (in_array($fulfillment, ['fulfilled', 'restocked'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $orderData
     */
    protected function buildOrderNote(array $orderData): ?string
    {
        $parts = [];

        $note = trim((string) ($orderData['note'] ?? ''));
        if ($note !== '') {
            $parts[] = $note;
        }

        $noteAttributes = $orderData['note_attributes'] ?? [];
        if (is_array($noteAttributes)) {
            foreach ($noteAttributes as $attr) {
                $name = trim((string) ($attr['name'] ?? ''));
                $value = trim((string) ($attr['value'] ?? ''));
                if ($name !== '' && $value !== '') {
                    $parts[] = "{$name}: {$value}";
                } elseif ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        if (empty($parts)) {
            return null;
        }

        return implode(' | ', $parts);
    }
}
