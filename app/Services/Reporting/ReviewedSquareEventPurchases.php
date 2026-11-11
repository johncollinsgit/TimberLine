<?php

namespace App\Services\Reporting;

use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Marketing\SquarePurchaseEvidence;
use App\Services\Pos\PosService;
use App\Services\Pos\PosStore;
use App\Services\Team\Studio\EventCommerceReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Original confirmed provider records explicitly allocated to this occurrence, never date-window guesses. */
final class ReviewedSquareEventPurchases
{
    public function read(int $tenant, int $event): array
    {
        $result = ['purchases' => [], 'review_items' => [], 'customer_review_items' => [], 'last_synced_at' => null, 'deduplicated_orders' => 0];
        $occurrence = app(EventCommerceReport::class)->candidate($tenant, $event);
        $review = $occurrence?->data['_teams_enrichment']['square_orders'] ?? [];
        $ids = array_values(array_unique(array_filter($review['ids'] ?? [], 'is_string')));
        if (! $ids || ! isset($review['reviewed_by'], $review['reviewed_at']) || ! Schema::hasTable('square_orders')) {
            return $result;
        }
        $conflicts = [];
        foreach (TeamOperationRecord::where(['tenant_id' => $tenant, 'kind' => 'event'])->whereKeyNot($occurrence->id)->where('status', '!=', 'withdrawn')->cursor() as $other) {
            foreach ($other->data['_teams_enrichment']['square_orders']['ids'] ?? [] as $id) {
                $conflicts[$id] = true;
            }
        }
        $orders = SquareOrder::forTenantId($tenant)->whereIn('square_order_id', $ids)->get();
        $allPayments = SquarePayment::forTenantId($tenant)->whereIn('square_order_id', $ids)->where('status', 'COMPLETED')->get();
        $paymentGroups = $allPayments->groupBy('square_order_id');
        $customerLinks = MarketingProfileLink::forTenantId($tenant)->where('source_type', 'square_order')->whereIn('source_id', $ids)->with('marketingProfile')->get()->groupBy('source_id');
        $pools = app(StockLedger::class)->rows('pools', $tenant)->where('event_key', 'event:'.$event)->pluck('id')->all();
        $mirrors = $this->payMirrors($tenant, $pools, $ids, $allPayments);
        if ($orders->count() !== count($ids)) {
            $result['review_items'][] = 'Some allocated Square orders are unavailable.';
        }
        foreach ($orders as $order) {
            if (isset($conflicts[$order->square_order_id])) {
                $result['review_items'][] = 'A Square order is allocated to more than one event.';

                continue;
            }
            if (strtoupper((string) $order->state) !== 'COMPLETED' || ! $order->closed_at || $order->closed_at->isFuture()) {
                continue;
            }
            $payments = $paymentGroups->get($order->square_order_id, collect());
            $mirror = $mirrors[$order->square_order_id] ?? ['found' => false, 'conflict' => false];
            if ($mirror['found']) {
                $result['deduplicated_orders']++;
                if ($mirror['conflict']) {
                    $result['review_items'][] = 'A Square allocation conflicts with the original Pay event or retained payment identity.';
                }

                continue;
            }
            $amount = $this->integer($order->total_money_amount);
            if ($order->total_money_currency !== 'USD' || $amount === null || ! app(SquarePurchaseEvidence::class)->confirmedOrder($order, $payments)) {
                $result['review_items'][] = 'Some completed Square orders need matching tender, currency or location evidence.';

                continue;
            }
            $tax = data_get($order->raw_payload, 'total_tax_money.currency') === 'USD' ? $this->integer(data_get($order->raw_payload, 'total_tax_money.amount')) : null;
            $refunded = app(SquarePurchaseEvidence::class)->recordedRefunds($order, $payments);
            $netTax = null;
            if ($tax !== null && $tax <= $amount && $refunded !== null && $refunded <= $amount) {
                if ($refunded === 0) {
                    $netTax = $tax;
                } elseif ($refunded === $amount) {
                    $netTax = 0;
                } elseif (data_get($order->raw_payload, 'net_amounts.total_money.amount') === $amount - $refunded
                    && data_get($order->raw_payload, 'net_amounts.total_money.currency') === 'USD'
                    && data_get($order->raw_payload, 'net_amounts.tax_money.currency') === 'USD') {
                    $netTax = $this->integer(data_get($order->raw_payload, 'net_amounts.tax_money.amount'));
                    if ($netTax > $tax || $netTax > $amount - $refunded) {
                        $netTax = null;
                    }
                }
            }
            if ($netTax === null) {
                $result['review_items'][] = 'Some Square refunds or tax allocations need reconciliation before net sales are complete.';
            }
            $links = $customerLinks->get($order->square_order_id, collect());
            $profiles = $links->pluck('marketingProfile');
            $profile = $profiles->containsStrict(null) ? null : app(CanonicalMarketingProfileResolver::class)->oneCanonical($profiles, $tenant);
            if ($profile?->archived_at !== null) {
                $profile = null;
            }
            if ($links->isNotEmpty() && ! $profile) {
                $result['customer_review_items'][] = 'Some Square customer identities need review.';
            }
            $lines = [];
            foreach ($order->raw_payload['line_items'] ?? [] as $line) {
                $quantity = $this->integer($line['quantity'] ?? null);
                if ($quantity === null) {
                    continue;
                }
                $lineTotal = $this->integer(data_get($line, 'total_money.amount'));
                $lineTax = $this->integer(data_get($line, 'total_tax_money.amount'));
                $lines[] = ['key' => 'square:'.($line['catalog_object_id'] ?? $line['uid'] ?? hash('sha256', $line['name'] ?? 'Recorded item')),
                    'title' => trim(($line['name'] ?? 'Recorded item').' '.($line['variation_name'] ?? '')), 'quantity' => $quantity,
                    'subtotal_minor' => $lineTotal !== null && $lineTax !== null && $lineTotal >= $lineTax ? $lineTotal - $lineTax : null];
            }
            $tenders = $payments->groupBy(fn ($p) => strtoupper((string) $p->source_type) === 'CASH' ? 'cash' : (strtoupper((string) $p->source_type) === 'CARD' ? 'card' : 'other'))
                ->map(function ($rows, $tender): array {
                    $returns = $rows->map(function ($payment): ?int {
                        $refund = data_get($payment->raw_payload, 'refunded_money.currency') === 'USD' ? $this->integer(data_get($payment->raw_payload, 'refunded_money.amount')) : null;

                        return $refund !== null && $refund <= $this->integer($payment->amount_money) ? $refund : null;
                    });

                    return ['tender' => $tender, 'collected_minor' => (int) $rows->sum('amount_money'), 'refunded_minor' => $returns->containsStrict(null) ? null : $returns->sum()];
                })->values()->all();
            $result['purchases'][] = ['source' => 'square_order', 'source_id' => $order->square_order_id, 'amount_minor' => $amount,
                'refunded_minor' => $refunded, 'net_tax_minor' => $netTax, 'net_sales_minor' => $netTax === null ? null : $amount - $refunded - $netTax,
                'customer_id' => $profile?->id, 'customer_review_required' => $links->isNotEmpty() && ! $profile,
                'occurred_at' => $order->closed_at->toIso8601String(), 'lines' => $lines, 'tenders' => $tenders];
            if ($order->synced_at && ($result['last_synced_at'] === null || $order->synced_at->toIso8601String() < $result['last_synced_at'])) {
                $result['last_synced_at'] = $order->synced_at->toIso8601String();
            }
        }
        $result['review_items'] = array_values(array_unique($result['review_items']));
        $result['customer_review_items'] = array_values(array_unique($result['customer_review_items']));

        return $result;
    }

    private function payMirrors(int $tenant, array $pools, array $orders, Collection $payments): array
    {
        if (! Schema::hasTable('pos_live_sales') || ! filled(config('pos_live.runtime_id'))) {
            return [];
        }

        $store = app(PosStore::class);
        $operations = Schema::hasTable('payment_core_operations') ? $store->db->table('payment_core_operations')->where('tenant_id', $tenant)
            ->where('kind', 'payment')->where('state', 'succeeded')->whereIn('provider_id', $payments->pluck('square_payment_id'))->get(['id', 'provider_id'])->keyBy('id') : collect();
        $paymentOrders = $payments->pluck('square_order_id', 'square_payment_id');
        $matches = $store->rows('sales', $tenant)->where('state', 'succeeded')->where(function ($query) use ($orders, $operations) {
            $query->whereIn('provider_order_id', $orders)->orWhereIn('attempt_id', $operations->keys());
        })->get();
        $result = [];
        foreach ($matches as $sale) {
            $conflict = false;
            try {
                $snapshot = app(PosService::class)->snapshot($sale);
                if (($snapshot['binding']['environment'] ?? null) !== 'live' || ! in_array($snapshot['terms']['stock_pool_id'] ?? null, $pools, true)) {
                    $conflict = true;
                }
            } catch (\Throwable) {
                $conflict = true;
            }
            $providerOrder = $paymentOrders->get($operations->get($sale->attempt_id)?->provider_id);
            foreach (array_unique(array_filter([$sale->provider_order_id, $providerOrder])) as $order) {
                if (! in_array($order, $orders, true)) {
                    continue;
                }
                $duplicate = isset($result[$order]);
                $identityConflict = $sale->provider_order_id && $providerOrder && $sale->provider_order_id !== $providerOrder;
                $result[$order] = ['found' => true, 'conflict' => $conflict || $identityConflict || $duplicate || ($result[$order]['conflict'] ?? false)];
            }
        }

        return $result;
    }

    private function integer(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && $value >= 0 && $value <= 10000000000 ? (int) $value : null;
    }
}
