<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\SquareOrder;
use App\Services\Marketing\CandleCashRedemptionReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarketingReconcileRedemptions extends Command
{
    protected $signature = 'marketing:reconcile-redemptions
        {--source=all : all|shopify|square}
        {--limit=500}
        {--since= : ISO date/time filter}
        {--dry-run}';

    protected $description = 'Reconcile Candle Cash redemptions against Shopify/Square orders.';

    public function handle(CandleCashRedemptionReconciliationService $service): int
    {
        $source = strtolower(trim((string) $this->option('source')));
        if (! in_array($source, ['all', 'shopify', 'square'], true)) {
            $this->error('Invalid --source. Use all|shopify|square.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $since = $this->asDate($this->option('since'));

        $summary = [
            'orders_scanned' => 0,
            'processed' => 0,
            'matched' => 0,
            'reconciled' => 0,
            'already_reconciled' => 0,
            'rejected' => 0,
            'not_found' => 0,
        ];

        if (in_array($source, ['all', 'shopify'], true)) {
            Order::query()
                ->whereNotNull('shopify_order_id')
                ->when($since, fn ($query) => $query->where('updated_at', '>=', $since))
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get(['id', 'shopify_order_id', 'shopify_store_key', 'shopify_store', 'order_number', 'shopify_name', 'internal_notes'])
                ->each(function (Order $order) use (&$summary, $service, $dryRun): void {
                    $summary['orders_scanned']++;
                    $this->mergeSummary($summary, $service->reconcileShopifyOrder($order, ['dry_run' => $dryRun]));
                });
        }

        if (in_array($source, ['all', 'square'], true)) {
            SquareOrder::query()
                ->when($since, fn ($query) => $query->where('updated_at', '>=', $since))
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->get(['id', 'square_order_id', 'square_customer_id', 'source_name', 'raw_tax_names', 'raw_payload'])
                ->each(function (SquareOrder $order) use (&$summary, $service, $dryRun): void {
                    $summary['orders_scanned']++;
                    $this->mergeSummary($summary, $service->reconcileSquareOrder($order, ['dry_run' => $dryRun]));
                });
        }

        $this->line('mode=' . ($dryRun ? 'dry-run' : 'live'));
        foreach ($summary as $key => $value) {
            $this->line($key . '=' . (int) $value);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string,int> $target
     * @param array<string,int> $incoming
     */
    protected function mergeSummary(array &$target, array $incoming): void
    {
        foreach (['processed', 'matched', 'reconciled', 'already_reconciled', 'rejected', 'not_found'] as $key) {
            $target[$key] = (int) ($target[$key] ?? 0) + (int) ($incoming[$key] ?? 0);
        }
    }

    protected function asDate(mixed $value): ?CarbonImmutable
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}

