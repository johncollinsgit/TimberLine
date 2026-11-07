<?php

namespace App\Services\Pos;

use App\Services\Inventory\StockLedger;
use App\Services\PaymentsCore\Contract;

/** Read original confirmed Pay receipts for their original stock event, never current register labels. */
class PosEventSales
{
    public function __construct(private PosStore $store, private StockLedger $stock) {}

    public function read(int $tenant, int $event, string $timezone, bool $includeReceipts = false): array
    {
        $pools = $this->stock->rows('pools', $tenant)->where('event_key', 'event:'.$event)->pluck('id')->all();
        $rows = $this->store->rows('sales', $tenant)->where('commerce_source', StockPosCommerce::VERSION)->orderByDesc('created_at')->orderBy('id')->limit(10001)->get();
        $issues = $rows->count() > 10000 ? ['Pay history exceeds this report window; totals require a complete indexed reconciliation.'] : [];
        $receipts = [];
        $count = 0;
        $net = 0;
        $tax = 0;
        $paid = 0;
        $refunds = 0;
        $operators = [];
        $locations = [];
        $daily = [];
        foreach ($rows->take(10000) as $sale) {
            try {
                $snapshot = app(PosService::class)->snapshot($sale);
                if (($snapshot['binding']['environment'] ?? null) !== 'live' || ! in_array($snapshot['terms']['stock_pool_id'] ?? null, $pools, true)) {
                    continue;
                }
                if ($sale->state !== 'succeeded') {
                    continue;
                }
                $evidence = app(PosSalePaymentEvidence::class)->read($sale, $snapshot);
                if (! $evidence || ($evidence['state'] ?? null) !== 'succeeded' || ($evidence['kind'] ?? null) !== 'payment'
                    || ($evidence['sale_id'] ?? null) !== $sale->id || ($evidence['request_id'] ?? null) !== $sale->payment_request_id
                    || ($evidence['amount_minor'] ?? null) !== (int) $sale->amount_minor || ($evidence['currency'] ?? null) !== 'usd'
                    || Contract::hash($evidence['binding'] ?? []) !== Contract::hash($snapshot['binding']) || empty($evidence['provider_id'])) {
                    $issues[] = 'Some succeeded receipts lack matching payment evidence.';

                    continue;
                }
                $terms = $snapshot['terms'];
                if (! is_int($terms['tax_minor'] ?? null) || ! is_int($terms['total_minor'] ?? null)
                    || $terms['total_minor'] !== (int) $sale->amount_minor || $terms['tax_minor'] > $terms['total_minor']) {
                    $issues[] = 'Some receipt tax/subtotal evidence needs review.';

                    continue;
                }
                $refund = ($snapshot['reward_only'] ?? false) ? 0 : app(PosCustomerPurchases::class)->confirmedRefunds($sale);
                $netRefund = $refund === 0 ? 0 : ($refund === (int) $sale->amount_minor ? $sale->amount_minor - $terms['tax_minor'] : null);
                if ($netRefund === null) {
                    $approved = $this->store->rows('events', $tenant)->where('sale_id', $sale->id)->where('code', 'customer_refund_allocation_approved')->orderByDesc('id')->first();
                    $review = $approved ? $this->store->decrypt($approved->details) : [];
                    if (($review['owner_attested'] ?? false) === true && ($review['snapshot_hash'] ?? null) === $sale->snapshot_hash
                        && ($review['confirmed_refunded_minor'] ?? null) === $refund && ($review['local_revision'] ?? null) === app(PosCustomerPurchases::class)->refundRevision($sale)) {
                        $allocation = app(PosRefundAllocation::class)->validate($terms, (int) $sale->amount_minor, $refund, $review['allocation']);
                        $netRefund = $allocation['refunded_subtotal_minor'];
                    }
                }
                $count++;
                $receipts[] = $sale->id;
                $paid += (int) $sale->amount_minor;
                $refunds += $refund;
                $day = \Carbon\CarbonImmutable::parse($sale->created_at, 'UTC')->setTimezone($timezone)->toDateString();
                $daily[$day] ??= ['date' => $day, 'sale_count' => 0, 'net_sales_minor' => 0, 'complete' => true];
                $daily[$day]['sale_count']++;
                $operators[(string) $sale->actor_id] = ($operators[(string) $sale->actor_id] ?? 0) + 1;
                $key = Contract::hash([$snapshot['location_name'], $snapshot['configuration']['shopify']['tax_address'] ?? null]);
                $locations[$key] = ['name' => $snapshot['location_name'], 'tax_address' => $snapshot['configuration']['shopify']['tax_address'] ?? null];
                if ($netRefund === null) {
                    $issues[] = 'A partial refund needs its reviewed item/tax allocation before net revenue can be calculated.';
                    $daily[$day]['complete'] = false;

                    continue;
                }
                $saleNet = (int) $sale->amount_minor - $terms['tax_minor'] - $netRefund;
                $net += $saleNet;
                $tax += $terms['tax_minor'] - ($refund - $netRefund);
                $daily[$day]['net_sales_minor'] += $saleNet;
            } catch (\Throwable) {
                $issues[] = 'Some retained Pay receipt evidence is unavailable; totals remain incomplete.';
            }
        }
        ksort($daily);
        foreach ($daily as &$day) {
            if (! $day['complete']) {
                $day['net_sales_minor'] = null;
            }
        }
        unset($day);

        return [...($includeReceipts ? ['receipt_ids' => $receipts] : []), 'source' => 'confirmed_everbranch_pay', 'environment' => 'live', 'coverage' => 'Live Everbranch Pay only; historical Square, cash and online records are not added.',
            'complete' => ! $issues, 'sale_count' => $count, 'paid_total_minor' => $paid, 'confirmed_refunds_minor' => $refunds,
            'net_sales_minor' => $issues ? null : $net, 'known_net_sales_minor' => $net, 'sales_tax_minor' => $issues ? null : $tax,
            'operators' => array_map(fn ($id, $sales) => ['user_id' => (string) $id, 'sale_count' => $sales], array_keys($operators), array_values($operators)),
            'locations' => array_values($locations), 'daily' => array_values($daily), 'review_items' => array_values(array_unique($issues)), 'as_of' => now()->toIso8601String()];
    }
}
