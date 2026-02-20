<?php

namespace App\Console\Commands;

use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyOrderIngestor;
use App\Services\Shopify\ShopifyStores;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use App\Models\ShopifyImportRun;

class ShopifyImportOrders extends Command
{
    protected $signature = 'shopify:import-orders {store?} {--store=} {--days=7} {--since=} {--dry-run} {--limit=} {--status=open} {--include-closed}';

    protected $description = 'Import Shopify orders and line items into the local database.';

    public function handle(): int
    {
        $storeArg = $this->option('store') ?: $this->argument('store');
        $stores = ShopifyStores::resolve($storeArg);
        if (empty($stores)) {
            $this->error('No valid Shopify store configuration found for the given store key.');
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

            $response = $client->get('orders.json', $params);
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
