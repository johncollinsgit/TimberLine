<?php

namespace App\Services\Pos;

use App\Services\Inventory\StockLedger;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\PaymentsCore\Contract;

/** Read original confirmed Pay receipts for their original stock event, never current register labels. */
class PosEventSales
{
    public function __construct(private PosStore $store, private StockLedger $stock) {}

    public function read(int $tenant, int $event, string $timezone, bool $includeReceipts = false): array
    {
        $pools = $this->stock->rows('pools', $tenant)->where('event_key', 'event:'.$event)->pluck('id')->all();
        $index = app(\App\Services\Reporting\PayEventReceiptIndex::class)->forPools($tenant, $pools);
        $rows = collect();
        foreach (array_chunk($index['ids'], 1000) as $ids) {
            $rows = $rows->concat($this->store->rows('sales', $tenant)->whereIn('id', $ids)->where('state', 'succeeded')->get());
        }
        $issues = $index['review_items'];
        $customerIssues = [];
        $receipts = [];
        $count = 0;
        $net = 0;
        $tax = 0;
        $paid = 0;
        $refunds = 0;
        $refundsComplete = true;
        $operators = [];
        $locations = [];
        $daily = [];
        $tenders = [];
        $products = [];
        $customers = [];
        $attributed = 0;
        foreach ($rows as $sale) {
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
                    || $terms['tax_minor'] < 0 || $terms['total_minor'] < 0
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
                try {
                    $decorated = app(PosReceiptCustomers::class)->decorate($sale, $snapshot);
                    if (isset($decorated['customer']['id'])) {
                        $profile = app(CanonicalMarketingProfileResolver::class)->byId($tenant, (int) $decorated['customer']['id']);
                        if ($profile && ! $profile->archived_at) {
                            $attributed++;
                            $customers[(int) $profile->id] = true;
                        } else {
                            $customerIssues[] = 'Some receipt customer identities need review.';
                        }
                    }
                } catch (\Throwable) {
                    $customerIssues[] = 'Some receipt customer identities could not be verified.';
                }
                $tender = match ($evidence['source'] ?? '') {
                    'everbranch_cash' => 'cash', 'everbranch_rewards_only' => 'rewards', default => 'card'
                };
                $tenders[$tender] ??= ['tender' => $tender, 'sale_count' => 0, 'collected_minor' => 0, 'refunded_minor' => 0];
                $tenders[$tender]['sale_count']++;
                $tenders[$tender]['collected_minor'] += (int) $sale->amount_minor;
                $tenders[$tender]['refunded_minor'] += $refund;
                foreach ($terms['display'] as $line) {
                    $productKey = $line['item_id'] ?? ('custom:'.hash('sha256', $line['title']));
                    $products[$productKey] ??= ['item_id' => $line['item_id'] ?? null, 'title' => $line['title'], 'quantity' => 0, 'subtotal_minor' => 0];
                    $products[$productKey]['quantity'] += $line['quantity'];
                    $products[$productKey]['subtotal_minor'] += $line['net_subtotal_minor'] ?? $line['subtotal_minor'] ?? 0;
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
        $external = app(\App\Services\Reporting\SalesChannelSummaryService::class)->forReviewedSquareEvent($tenant, $event);
        $issues = [...$issues, ...$external['review_items']];
        $customerIssues = [...$customerIssues, ...($external['customer_review_items'] ?? [])];
        foreach ($external['purchases'] as $purchase) {
            $count++;
            $paid += $purchase['amount_minor'];
            $refunds += $purchase['refunded_minor'] ?? 0;
            $refundsComplete = $refundsComplete && $purchase['refunded_minor'] !== null;
            if ($purchase['customer_id']) {
                $attributed++;
                $customers[$purchase['customer_id']] = true;
            }
            $day = \Carbon\CarbonImmutable::parse($purchase['occurred_at'])->setTimezone($timezone)->toDateString();
            $daily[$day] ??= ['date' => $day, 'sale_count' => 0, 'net_sales_minor' => 0, 'complete' => true];
            $daily[$day]['sale_count']++;
            if ($purchase['net_sales_minor'] === null) {
                $daily[$day]['complete'] = false;
            } else {
                $net += $purchase['net_sales_minor'];
                $tax += $purchase['net_tax_minor'];
                $daily[$day]['net_sales_minor'] += $purchase['net_sales_minor'];
            }
            foreach ($purchase['tenders'] as $tender) {
                $key = $tender['tender'];
                $tenders[$key] ??= ['tender' => $key, 'sale_count' => 0, 'collected_minor' => 0, 'refunded_minor' => 0];
                $tenders[$key]['sale_count']++;
                $tenders[$key]['collected_minor'] += $tender['collected_minor'];
                $tenders[$key]['refunded_minor'] = $tenders[$key]['refunded_minor'] === null || $tender['refunded_minor'] === null
                    ? null : $tenders[$key]['refunded_minor'] + $tender['refunded_minor'];
            }
            foreach ($purchase['lines'] as $line) {
                $products[$line['key']] ??= ['item_id' => $line['key'], 'title' => $line['title'], 'quantity' => 0, 'subtotal_minor' => 0];
                $products[$line['key']]['quantity'] += $line['quantity'];
                $products[$line['key']]['subtotal_minor'] = $line['subtotal_minor'] === null || $products[$line['key']]['subtotal_minor'] === null ? null : $products[$line['key']]['subtotal_minor'] + $line['subtotal_minor'];
            }
        }
        ksort($daily);
        foreach ($daily as &$day) {
            if (! $day['complete']) {
                $day['net_sales_minor'] = null;
            }
        }
        unset($day);

        return [...($includeReceipts ? ['receipt_ids' => $receipts, 'square_order_ids' => array_column($external['purchases'], 'source_id')] : []), 'source' => 'confirmed_event_purchases', 'environment' => 'live', 'coverage' => 'Confirmed Pay, cash and explicitly reviewed external Square purchases; workbook comparisons remain separate. Unlinked or unimported sources are unknown.',
            'source_coverage_complete' => false,
            'source_freshness' => ['pay' => now()->toIso8601String(), 'square_oldest_selected_sync' => $external['last_synced_at'], 'square_deduplicated_orders' => $external['deduplicated_orders']],
            'complete' => ! $issues, 'sale_count' => $count, 'paid_total_minor' => $paid, 'confirmed_refunds_minor' => $refunds,
            'refunds_complete' => $refundsComplete, 'collected_total_minor' => $refundsComplete ? $paid - $refunds : null,
            'average_order_minor' => $count > 0 && ! $issues ? (int) round($net / $count) : null,
            'tenders' => array_values($tenders), 'products' => array_values($products),
            'customer_counts' => ['unique_buyers' => count($customers), 'attributed_orders' => $attributed, 'unattributed_orders' => $count - $attributed, 'complete' => ! $customerIssues],
            'customer_review_items' => array_values(array_unique($customerIssues)),
            'net_sales_minor' => $issues ? null : $net, 'known_net_sales_minor' => $net, 'sales_tax_minor' => $issues ? null : $tax,
            'operators' => array_map(fn ($id, $sales) => ['user_id' => (string) $id, 'sale_count' => $sales], array_keys($operators), array_values($operators)),
            'locations' => array_values($locations), 'daily' => array_values($daily), 'review_items' => array_values(array_unique($issues)), 'as_of' => now()->toIso8601String()];
    }
}
