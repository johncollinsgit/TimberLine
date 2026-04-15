<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Marketing\CandleCashRedemptionReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarketingBackfillRedemptionFinalizations extends Command
{
    protected $signature = 'marketing:backfill-redemption-finalizations
        {--tenant-id= : Restrict to a single tenant id}
        {--from= : Inclusive start date/time (ISO)}
        {--to= : Inclusive end date/time (ISO)}
        {--chunk=250 : Chunk size for order scans}
        {--limit=0 : Max orders per tenant to scan (0 = no limit)}
        {--dry-run : Preview only, no writes}';

    protected $description = 'Backfill legitimate Candle Cash redemptions from Shopify order coupon evidence.';

    public function handle(CandleCashRedemptionReconciliationService $service): int
    {
        $tenantIdOption = is_numeric($this->option('tenant-id')) ? (int) $this->option('tenant-id') : null;
        $from = $this->asDate($this->option('from'))?->startOfDay();
        $to = $this->asDate($this->option('to'))?->endOfDay();
        $chunk = max(50, min(2000, (int) $this->option('chunk')));
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        if ($from && $to && $to->lt($from)) {
            $this->error('Invalid range: --to must be greater than or equal to --from.');

            return self::FAILURE;
        }

        $tenantIds = $this->tenantIds($tenantIdOption);
        if ($tenantIds === []) {
            $this->line('mode=' . ($dryRun ? 'dry-run' : 'live'));
            $this->line('tenants_scanned=0');
            $this->line('orders_scanned=0');
            $this->line('orders_with_codes=0');
            $this->line('reconciled=0');
            $this->line('already_reconciled=0');
            $this->line('rejected=0');
            $this->line('not_found=0');
            $this->line('tenant_context_missing=0');

            return self::SUCCESS;
        }

        $totals = [
            'tenants_scanned' => 0,
            'orders_scanned' => 0,
            'orders_with_codes' => 0,
            'processed' => 0,
            'matched' => 0,
            'reconciled' => 0,
            'already_reconciled' => 0,
            'rejected' => 0,
            'not_found' => 0,
            'tenant_context_missing' => 0,
        ];

        foreach ($tenantIds as $tenantId) {
            $totals['tenants_scanned']++;
            $summary = [
                'orders_scanned' => 0,
                'orders_with_codes' => 0,
                'processed' => 0,
                'matched' => 0,
                'reconciled' => 0,
                'already_reconciled' => 0,
                'rejected' => 0,
                'not_found' => 0,
                'tenant_context_missing' => 0,
            ];

            $query = Order::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('shopify_order_id')
                ->when($from, fn ($builder) => $builder->where('ordered_at', '>=', $from))
                ->when($to, fn ($builder) => $builder->where('ordered_at', '<=', $to))
                ->orderBy('id')
                ->select([
                    'id',
                    'tenant_id',
                    'shopify_order_id',
                    'shopify_store_key',
                    'shopify_store',
                    'order_number',
                    'shopify_name',
                    'internal_notes',
                    'discount_total',
                    'attribution_meta',
                    'ordered_at',
                    'created_at',
                ]);

            $query->chunkById($chunk, function ($orders) use ($service, $tenantId, $dryRun, $limit, &$summary): bool {
                foreach ($orders as $order) {
                    if ($limit > 0 && $summary['orders_scanned'] >= $limit) {
                        return false;
                    }

                    $summary['orders_scanned']++;
                    $codes = $this->codesForOrder($order, $service);
                    if ($codes === []) {
                        continue;
                    }

                    $summary['orders_with_codes']++;
                    $result = $service->reconcileShopifyOrder($order, [
                        'tenant_id' => $tenantId,
                        'dry_run' => $dryRun,
                        'codes' => $codes,
                        'coupon_signals' => $codes,
                        'attribution_meta' => is_array($order->attribution_meta) ? $order->attribution_meta : [],
                    ]);

                    $summary['processed'] += (int) ($result['processed'] ?? 0);
                    $summary['matched'] += (int) ($result['matched'] ?? 0);
                    $summary['reconciled'] += (int) ($result['reconciled'] ?? 0);
                    $summary['already_reconciled'] += (int) ($result['already_reconciled'] ?? 0);
                    $summary['rejected'] += (int) ($result['rejected'] ?? 0);
                    $summary['not_found'] += (int) ($result['not_found'] ?? 0);
                    $summary['tenant_context_missing'] += (int) ($result['tenant_context_missing'] ?? 0);
                }

                return true;
            }, 'id');

            $this->line('tenant_id=' . $tenantId);
            $this->line('orders_scanned=' . $summary['orders_scanned']);
            $this->line('orders_with_codes=' . $summary['orders_with_codes']);
            $this->line('processed=' . $summary['processed']);
            $this->line('matched=' . $summary['matched']);
            $this->line('reconciled=' . $summary['reconciled']);
            $this->line('already_reconciled=' . $summary['already_reconciled']);
            $this->line('rejected=' . $summary['rejected']);
            $this->line('not_found=' . $summary['not_found']);
            $this->line('tenant_context_missing=' . $summary['tenant_context_missing']);

            foreach ($summary as $key => $value) {
                $totals[$key] = (int) ($totals[$key] ?? 0) + (int) $value;
            }
        }

        $this->line('mode=' . ($dryRun ? 'dry-run' : 'live'));
        $this->line('tenants_scanned=' . $totals['tenants_scanned']);
        $this->line('orders_scanned=' . $totals['orders_scanned']);
        $this->line('orders_with_codes=' . $totals['orders_with_codes']);
        $this->line('processed=' . $totals['processed']);
        $this->line('matched=' . $totals['matched']);
        $this->line('reconciled=' . $totals['reconciled']);
        $this->line('already_reconciled=' . $totals['already_reconciled']);
        $this->line('rejected=' . $totals['rejected']);
        $this->line('not_found=' . $totals['not_found']);
        $this->line('tenant_context_missing=' . $totals['tenant_context_missing']);

        return self::SUCCESS;
    }

    /**
     * @return array<int,int>
     */
    protected function tenantIds(?int $tenantIdOption = null): array
    {
        if ($tenantIdOption !== null && $tenantIdOption > 0) {
            return [$tenantIdOption];
        }

        return Order::query()
            ->whereNotNull('tenant_id')
            ->whereNotNull('shopify_order_id')
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    protected function codesForOrder(Order $order, CandleCashRedemptionReconciliationService $service): array
    {
        $attributionMeta = is_array($order->attribution_meta) ? $order->attribution_meta : [];
        $couponSignals = (array) data_get($attributionMeta, 'coupon_signals', []);
        $rawMeta = $attributionMeta !== [] ? json_encode($attributionMeta) : '';

        $textMatches = $service->extractCodesFromText(implode(' ', array_filter([
            (string) ($order->order_number ?? ''),
            (string) ($order->shopify_name ?? ''),
            (string) ($order->internal_notes ?? ''),
            is_string($rawMeta) ? $rawMeta : '',
        ])));

        return $service->normalizeCodes(array_merge($couponSignals, $textMatches));
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

