<?php

namespace App\Services\Reporting;

use App\Models\MarketingImportRun;
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
use App\Services\Team\Studio\EventSalesAccess;
use App\Services\Team\Studio\EventSquareCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Original confirmed provider records explicitly allocated to this occurrence, never date-window guesses. */
final class ReviewedSquareEventPurchases
{
    public function read(int $tenant, int $event): array
    {
        $occurrence = app(EventCommerceReport::class)->candidate($tenant, $event);
        if (! $occurrence) {
            return $this->empty();
        }

        $result = $this->forOccurrence($tenant, $occurrence, $event);
        $links = TeamOperationRecord::where(['tenant_id' => $tenant, 'area_id' => $occurrence->area_id, 'kind' => 'event_link', 'status' => 'accepted'])->get()
            ->filter(fn ($link) => ($link->data['occurrence_id'] ?? null) === $occurrence->id);
        if ($links->count() > 1) {
            $result['review_items'][] = 'More than one reviewed Square scope mapping names this occurrence.';

            return $result;
        }
        if (! $link = $links->first()) {
            return $result;
        }
        $timezone = $link->data['timezone'] ?? 'UTC';
        $resolved = $this->forScopes($tenant, $occurrence, $link, $timezone, $event);
        $seen = array_fill_keys(array_column($result['purchases'], 'source_id'), true);
        $result['review_items'] = [...$result['review_items'], ...$resolved['review_items']];
        foreach ($resolved['chunks'] as $chunk) {
            foreach ($chunk['purchases'] as $purchase) {
                if (isset($seen[$purchase['source_id']])) {
                    $result['deduplicated_orders']++;

                    continue;
                }
                $seen[$purchase['source_id']] = true;
                $result['purchases'][] = $purchase;
            }
            $result['customer_review_items'] = [...$result['customer_review_items'], ...$chunk['customer_review_items']];
            $result['deduplicated_orders'] += $chunk['deduplicated_orders'];
            if ($chunk['last_synced_at'] !== null && ($result['last_synced_at'] === null || $chunk['last_synced_at'] < $result['last_synced_at'])) {
                $result['last_synced_at'] = $chunk['last_synced_at'];
            }
        }
        $result['review_items'] = array_values(array_unique($result['review_items']));
        $result['customer_review_items'] = array_values(array_unique($result['customer_review_items']));

        return $result;
    }

    /** One reviewed source-window resolver shared by Pay, history and customer facts. */
    public function forScopes(int $tenant, TeamOperationRecord $occurrence, ?TeamOperationRecord $link, string $timezone, ?int $event = null): array
    {
        $result = ['coverage' => false, 'chunks' => [], 'review_items' => []];
        if (! $link || $link->kind !== 'event_link' || $link->status !== 'accepted'
            || (int) $occurrence->tenant_id !== $tenant || (int) $link->tenant_id !== $tenant
            || (string) $link->area_id !== (string) $occurrence->area_id || ($link->data['occurrence_id'] ?? null) !== $occurrence->id
            || ! app(EventSalesAccess::class)->exported($occurrence, $link)
            || ($link->data['starts_on'] ?? null) !== $occurrence->starts_on?->toDateString()
            || ($link->data['ends_on'] ?? null) !== $occurrence->ends_on?->toDateString()
            || ! in_array($timezone, timezone_identifiers_list(), true)) {
            $result['review_items'][] = 'Review this occurrence before reading its linked Square purchases.';

            return $result;
        }
        $scopes = $link->data['square_scopes'] ?? [];
        $result['coverage'] = $scopes !== [];
        $seen = [];
        foreach ($scopes as $scope) {
            $proven = app(EventSquareCoverage::class)->proven($tenant, $scope, $timezone);
            $result['coverage'] = $result['coverage'] && $proven;
            if (! $proven) {
                $result['review_items'][] = 'Square source coverage is incomplete or its original connection changed.';
            }
            if (! Schema::hasTable('square_orders') || ! Schema::hasTable('square_payments')) {
                $result['coverage'] = false;

                continue;
            }
            $binding = MarketingImportRun::forTenantId($tenant)->find($scope['orders_run_id'] ?? 0)?->summary['source_binding'] ?? null;
            if (! $binding) {
                $result['coverage'] = false;

                continue;
            }
            // A partial import can show known purchases. Original source revocation cannot.
            try {
                app(\App\Services\Marketing\SquareConnectionContext::class)->assertCurrent($tenant, $binding);
            } catch (\Throwable) {
                $result['coverage'] = false;

                continue;
            }
            $from = CarbonImmutable::parse($scope['starts_on'], $timezone)->startOfDay()->utc();
            $through = CarbonImmutable::parse($scope['ends_on'], $timezone)->addDay()->startOfDay()->utc();
            $orders = SquareOrder::forTenantId($tenant)->where('location_id', $scope['location_id'])->where('closed_at', '>=', $from)->where('closed_at', '<', $through->addHours(72))->get();
            $payments = SquarePayment::forTenantId($tenant)->whereIn('square_order_id', $orders->pluck('square_order_id'))->where('status', 'COMPLETED')->get()->groupBy('square_order_id');
            $timestamps = [];
            foreach ($orders as $order) {
                $offline = $payments->get($order->square_order_id, collect())->filter(fn ($payment) => ($payment->raw_payload['is_offline_payment'] ?? false) === true);
                $timestamp = $order->closed_at ? CarbonImmutable::instance($order->closed_at) : null;
                if ($offline->isNotEmpty()) {
                    $times = $offline->map(fn ($payment) => data_get($payment->raw_payload, 'offline_payment_details.client_created_at'));
                    $timestamp = $times->contains(fn ($time) => ! is_string($time) || strtotime($time) === false)
                        ? null : $times->map(fn ($time) => CarbonImmutable::parse($time)->utc())->sort()->first();
                }
                if ($timestamp === null) {
                    $result['review_items'][] = 'An offline purchase needs its original selling timestamp.';
                    $result['coverage'] = false;

                    continue;
                }
                if ($timestamp->lt($from) || $timestamp->gte($through)) {
                    continue;
                }
                if (isset($seen[$order->square_order_id])) {
                    $result['review_items'][] = 'Overlapping source scopes repeat a Square order.';
                    $result['coverage'] = false;

                    continue;
                }
                $seen[$order->square_order_id] = true;
                $timestamps[$order->square_order_id] = $timestamp;
            }
            $map = $link->data;
            $chunk = $this->forOccurrence($tenant, $occurrence, $event, ['ids' => array_keys($timestamps), 'reviewed_by' => $map['reviewed_by'], 'reviewed_at' => $map['reviewed_at'], 'source_binding' => $binding]);
            foreach ($chunk['purchases'] as &$purchase) {
                $purchase['occurred_at'] = $timestamps[$purchase['source_id']]->toIso8601String();
            }
            unset($purchase);
            $result['review_items'] = [...$result['review_items'], ...$chunk['review_items']];
            $result['chunks'][] = $chunk;
        }
        $result['review_items'] = array_values(array_unique($result['review_items']));

        return $result;
    }

    /** A reviewed historical allocation uses the same tender/refund/mirror admission as Pay. */
    public function forOccurrence(int $tenant, TeamOperationRecord $occurrence, ?int $event = null, ?array $allocation = null): array
    {
        abort_unless((int) $occurrence->tenant_id === $tenant, 404);
        $result = $this->empty();
        $review = $allocation ?? ($occurrence->data['_teams_enrichment']['square_orders'] ?? []);
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
        $paymentCustomerLinks = MarketingProfileLink::forTenantId($tenant)->where('source_type', 'square_payment')->whereIn('source_id', $allPayments->pluck('square_payment_id'))->with('marketingProfile')->get()->groupBy('source_id');
        $pools = $event !== null && Schema::hasTable('commerce_stock_pools') ? app(StockLedger::class)->rows('pools', $tenant)->where('event_key', 'event:'.$event)->pluck('id')->all() : [];
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
            if (isset($review['source_binding'])) {
                $binding = $review['source_binding'];
                $matches = static fn ($raw) => collect(['merchant_id', 'environment', 'connection_id', 'connection_generation'])->every(fn ($key) => isset($binding[$key]) && (string) data_get($raw, '_source_binding.'.$key) === (string) $binding[$key]);
                if (! $matches($order->raw_payload) || ! $payments->every(fn ($payment) => $matches($payment->raw_payload))) {
                    $result['review_items'][] = 'Some historical purchases do not match their reviewed original merchant binding.';

                    continue;
                }
            }
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
            $tip = array_key_exists('total_tip_money', $order->raw_payload ?? [])
                ? (data_get($order->raw_payload, 'total_tip_money.currency') === 'USD' ? $this->integer(data_get($order->raw_payload, 'total_tip_money.amount')) : null) : 0;
            $netTip = $tip === 0 ? 0 : ($refunded === 0 ? $tip : ($refunded === $amount ? 0 : (data_get($order->raw_payload, 'net_amounts.tip_money.currency') === 'USD' ? $this->integer(data_get($order->raw_payload, 'net_amounts.tip_money.amount')) : null)));
            if ($netTip !== null && ($netTip > ($tip ?? -1) || $netTax !== null && $netTax + $netTip > $amount - ($refunded ?? 0))) {
                $netTip = null;
            }
            if ($netTax === null || $netTip === null) {
                $result['review_items'][] = 'Some Square refunds or tax allocations need reconciliation before net sales are complete.';
            }
            $links = $customerLinks->get($order->square_order_id, collect());
            $identityLinks = $links;
            foreach ($payments as $payment) {
                $identityLinks = $identityLinks->concat($paymentCustomerLinks->get($payment->square_payment_id, collect()));
            }
            $profiles = $identityLinks->pluck('marketingProfile');
            // A payment-level customer can contradict an order identity, but cannot assign
            // the whole order merely because that person paid one of its split tenders.
            $profile = $links->isEmpty() || $profiles->containsStrict(null) ? null : app(CanonicalMarketingProfileResolver::class)->oneCanonical($profiles, $tenant);
            if ($profile?->archived_at !== null) {
                $profile = null;
            }
            if ($links->isNotEmpty() && ! $profile) {
                $result['customer_review_items'][] = 'Some Square customer identities need review.';
            }
            $occurredAt = $order->closed_at;
            $offline = $payments->filter(fn ($payment) => ($payment->raw_payload['is_offline_payment'] ?? false) === true);
            if ($offline->isNotEmpty()) {
                $timestamps = $offline->map(fn ($payment) => data_get($payment->raw_payload, 'offline_payment_details.client_created_at'));
                if ($timestamps->contains(fn ($time) => ! is_string($time) || strtotime($time) === false)) {
                    $result['review_items'][] = 'An offline Square purchase needs its original selling timestamp.';

                    continue;
                }
                $occurredAt = $timestamps->map(fn ($time) => \Carbon\CarbonImmutable::parse($time))->sort()->first();
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
                ->map(function ($rows, $tender) use ($order): array {
                    $returns = $rows->map(fn ($payment) => app(SquarePurchaseEvidence::class)->recordedPaymentRefund($payment, $order));

                    return ['tender' => $tender, 'collected_minor' => (int) $rows->sum(fn ($payment) => app(SquarePurchaseEvidence::class)->collectedAmount($payment)), 'refunded_minor' => $returns->containsStrict(null) ? null : $returns->sum()];
                })->values()->all();
            $result['purchases'][] = ['source' => 'square_order', 'source_id' => $order->square_order_id, 'amount_minor' => $amount,
                'refunded_minor' => $refunded, 'net_tax_minor' => $netTax, 'net_tip_minor' => $netTip, 'net_sales_minor' => $netTax === null || $netTip === null ? null : $amount - $refunded - $netTax - $netTip,
                'customer_id' => $profile?->id, 'customer_review_required' => $links->isNotEmpty() && ! $profile,
                'occurred_at' => $occurredAt->toIso8601String(), 'lines' => $lines, 'tenders' => $tenders];
            if ($order->synced_at && ($result['last_synced_at'] === null || $order->synced_at->toIso8601String() < $result['last_synced_at'])) {
                $result['last_synced_at'] = $order->synced_at->toIso8601String();
            }
        }
        $result['review_items'] = array_values(array_unique($result['review_items']));
        $result['customer_review_items'] = array_values(array_unique($result['customer_review_items']));

        return $result;
    }

    private function empty(): array
    {
        return ['purchases' => [], 'review_items' => [], 'customer_review_items' => [], 'last_synced_at' => null, 'deduplicated_orders' => 0];
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
