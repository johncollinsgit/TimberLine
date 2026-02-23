<?php

namespace App\Services\Dashboard;

use App\Models\MappingException;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ShopifyImportException;
use App\Models\ShopifyImportRun;
use App\Services\Shipping\BusinessDayCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardMetrics
{
    public function snapshot(int $rangeDays = 7, string $channel = 'all'): array
    {
        $rangeDays = in_array($rangeDays, [1, 7, 30], true) ? $rangeDays : 7;
        $channel = $this->normalizeChannel($channel);

        $cacheKey = sprintf('dashboard:metrics:v2:range:%d:channel:%s', $rangeDays, $channel);

        return Cache::remember($cacheKey, now()->addSeconds(90), function () use ($rangeDays, $channel): array {
            return $this->buildSnapshot($rangeDays, $channel);
        });
    }

    private function buildSnapshot(int $rangeDays, string $channel): array
    {
        $now = CarbonImmutable::now();
        $todayStart = $now->startOfDay();
        $todayEnd = $now->endOfDay();
        $rangeStart = $now->subDays($rangeDays - 1)->startOfDay();
        $days3End = app(BusinessDayCalculator::class)->addBusinessDays($todayStart, 3)->endOfDay();

        $openOrderStatuses = ['complete', 'cancelled'];

        $statusCounts = $this->ordersBase($channel)
            ->selectRaw('COALESCE(status, "unknown") as status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $channelCounts = $this->ordersBase($channel)
            ->selectRaw('COALESCE(order_type, "unknown") as order_type, COUNT(*) as count')
            ->groupBy('order_type')
            ->pluck('count', 'order_type')
            ->all();

        $dueToday = $this->ordersBase($channel)
            ->whereNotIn('status', $openOrderStatuses)
            ->whereBetween('ship_by_at', [$todayStart, $todayEnd])
            ->count();

        $dueNext3Days = $this->ordersBase($channel)
            ->whereNotIn('status', $openOrderStatuses)
            ->whereBetween('ship_by_at', [$todayStart, $days3End])
            ->count();

        $dueByChannel = $this->ordersBase($channel)
            ->whereNotIn('status', $openOrderStatuses)
            ->whereBetween('ship_by_at', [$todayStart, $days3End])
            ->selectRaw('COALESCE(order_type, "unknown") as order_type, COUNT(*) as count')
            ->groupBy('order_type')
            ->pluck('count', 'order_type')
            ->all();

        $dueUpcomingOrders = $this->ordersBase($channel)
            ->whereNotIn('status', $openOrderStatuses)
            ->whereBetween('ship_by_at', [$todayStart, $days3End])
            ->orderBy('ship_by_at')
            ->limit(8)
            ->get([
                'id',
                'order_number',
                'order_label',
                'customer_name',
                'shipping_name',
                'billing_name',
                'shipping_company',
                'shipping_address1',
                'billing_company',
                'billing_address1',
                'order_type',
                'status',
                'ship_by_at',
                'shopify_name',
            ]);

        $lineSummaries = $this->lineSummaryForOrders($dueUpcomingOrders->pluck('id')->all());

        $unpublishedByChannelStatus = $this->ordersBase($channel)
            ->whereNull('published_at')
            ->selectRaw('COALESCE(order_type, "unknown") as order_type, COALESCE(status, "unknown") as status, COUNT(*) as count')
            ->groupBy('order_type', 'status')
            ->orderBy('order_type')
            ->orderBy('status')
            ->get();

        $unpublishedTotal = (int) $unpublishedByChannelStatus->sum('count');

        $shippingOpen = $this->ordersBase($channel)->whereNotIn('status', $openOrderStatuses);
        $shippingReadyStatuses = ['reviewed', 'submitted_to_pouring', 'pouring', 'brought_down', 'verified'];
        $shippingBlockedStatuses = ['hold', 'on_hold'];

        $shippingReady = (clone $shippingOpen)
            ->whereIn('status', $shippingReadyStatuses)
            ->where(function (Builder $query): void {
                $query->whereNull('requires_shipping_review')->orWhere('requires_shipping_review', false);
            })
            ->count();

        $shippingBlocked = (clone $shippingOpen)
            ->where(function (Builder $query) use ($shippingBlockedStatuses): void {
                $query->where('requires_shipping_review', true)
                    ->orWhereIn('status', $shippingBlockedStatuses);
            })
            ->count();

        $shippingOpenCount = (clone $shippingOpen)->count();
        $shippingAvgAgeDays = $this->avgOrderAgeDays(clone $shippingOpen);

        $productionLoad = $this->productionLoad($channel, $openOrderStatuses);
        $topScents = $this->topScents($channel, $rangeStart, $now);
        $revenue = $this->revenueSnapshot($channel, $now);
        $importHealth = $this->importHealth($channel, $now);

        $recentOrders = $this->ordersBase($channel)
            ->orderByDesc('id')
            ->limit(8)
            ->get([
                'id',
                'order_number',
                'order_label',
                'customer_name',
                'shipping_name',
                'billing_name',
                'shipping_company',
                'shipping_address1',
                'billing_company',
                'billing_address1',
                'order_type',
                'status',
                'ship_by_at',
                'created_at',
                'shopify_name',
            ]);

        $openOrders = $this->ordersBase($channel)
            ->whereNotIn('status', $openOrderStatuses)
            ->count();

        $mappingExceptionsOpen = MappingException::query()->whereNull('resolved_at')->count();

        return [
            'statusCounts' => $statusCounts,
            'channelCounts' => $channelCounts,
            'filters' => [
                'range_days' => $rangeDays,
                'channel' => $channel,
            ],
            'todayAtGlance' => [
                'dueToday' => $dueToday,
                'dueNext3Days' => $dueNext3Days,
                'openOrders' => $openOrders,
                'unpublishedOrders' => $unpublishedTotal,
                'exceptions' => $mappingExceptionsOpen,
            ],
            'dueWindow' => [
                'dueToday' => $dueToday,
                'dueNext3Days' => $dueNext3Days,
                'byChannel' => $dueByChannel,
                'upcoming' => $dueUpcomingOrders->map(fn ($order): array => [
                    'id' => $order->id,
                    'number' => $order->order_number,
                    'customer' => $this->displayName($order),
                    'channel' => $order->order_type,
                    'status' => $order->status,
                    'due' => optional($order->ship_by_at)->toDateString(),
                    'lines_preview' => array_slice($lineSummaries[$order->id] ?? [], 0, 4),
                    'lines_more' => max(0, count($lineSummaries[$order->id] ?? []) - 4),
                ])->all(),
            ],
            'unpublished' => [
                'total' => $unpublishedTotal,
                'rows' => $unpublishedByChannelStatus->map(fn ($row): array => [
                    'channel' => $row->order_type,
                    'status' => $row->status,
                    'count' => (int) $row->count,
                ])->all(),
            ],
            'importHealth' => $importHealth,
            'shippingQueue' => [
                'ready' => $shippingReady,
                'blocked' => $shippingBlocked,
                'open' => $shippingOpenCount,
                'avgAgeDays' => $shippingAvgAgeDays,
            ],
            'productionLoad' => $productionLoad,
            'topScents' => $topScents,
            'revenue' => $revenue,
            'recentOrders' => $recentOrders->map(fn ($order): array => [
                'id' => $order->id,
                'number' => $order->order_number,
                'customer' => $this->displayName($order),
                'channel' => $order->order_type,
                'status' => $order->status,
                'due' => optional($order->ship_by_at)->toDateString(),
                'created' => optional($order->created_at)->toDateString(),
            ])->all(),
            'placeholders' => [
                'cashRunway' => [
                    'configured' => false,
                    'message' => 'Needs data: no account balance ledger configured in this database.',
                ],
                'inventoryAlerts' => [
                    'configured' => false,
                    'message' => 'Needs data: inventory alerting is not configured yet.',
                ],
                'capacityStaffing' => [
                    'configured' => false,
                    'message' => 'Needs data: staffing/capacity source not found.',
                ],
                'notesReminders' => [
                    'configured' => false,
                    'message' => 'Needs data: no internal dashboard notes table configured.',
                ],
            ],
        ];
    }

    private function ordersBase(string $channel = 'all'): Builder
    {
        $query = Order::query();

        if ($channel !== 'all') {
            $query->where('order_type', $channel);
        }

        return $query;
    }

    private function normalizeChannel(string $channel): string
    {
        $normalized = strtolower(trim($channel));

        return in_array($normalized, ['all', 'retail', 'wholesale'], true)
            ? $normalized
            : 'all';
    }

    private function displayName(Order $order): string
    {
        return $order->order_label
            ?: $order->customer_name
            ?: $order->shipping_name
            ?: $order->billing_name
            ?: $order->shipping_company
            ?: $order->shipping_address1
            ?: $order->billing_company
            ?: $order->billing_address1
            ?: $order->shopify_name
            ?: (string) ($order->order_number ?? 'Unknown');
    }

    private function lineSummaryForOrders(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $lines = OrderLine::query()
            ->with(['scent:id,name', 'size:id,code,label'])
            ->whereIn('order_id', $orderIds)
            ->get();

        return $lines->groupBy('order_id')->map(function ($group) {
            return $group->map(function ($line) {
                $qty = (int) ($line->ordered_qty ?? 0) + (int) ($line->extra_qty ?? 0);
                if ($qty <= 0) {
                    $qty = (int) ($line->quantity ?? 0);
                }

                $scent = $line->scent?->name ?: $line->scent_name ?: $line->raw_title ?: 'Unknown';
                $size = $line->size?->display ?: $line->size_code ?: null;
                $label = $size ? "{$scent} · {$size}" : $scent;

                return trim($label).' ×'.$qty;
            })->filter()->values()->all();
        })->all();
    }

    private function avgOrderAgeDays(Builder $query): float
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $value = (clone $query)
                ->selectRaw('AVG(julianday(CURRENT_TIMESTAMP) - julianday(created_at)) as avg_days')
                ->value('avg_days');
        } else {
            $value = (clone $query)
                ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, NOW()) / 86400) as avg_days')
                ->value('avg_days');
        }

        return round((float) ($value ?? 0), 1);
    }

    private function productionLoad(string $channel, array $openOrderStatuses): array
    {
        $qtyExpr = 'CASE WHEN (COALESCE(order_lines.ordered_qty,0)+COALESCE(order_lines.extra_qty,0)) > 0 '
            .'THEN (COALESCE(order_lines.ordered_qty,0)+COALESCE(order_lines.extra_qty,0)) '
            .'ELSE COALESCE(order_lines.quantity,0) END';

        $query = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->leftJoin('sizes', 'sizes.id', '=', 'order_lines.size_id')
            ->whereNotIn('orders.status', $openOrderStatuses);

        if ($channel !== 'all') {
            $query->where('orders.order_type', $channel);
        }

        $sizeCodeExpr = 'LOWER(COALESCE(sizes.code, order_lines.size_code, "unknown"))';

        $rows = $query
            ->selectRaw($sizeCodeExpr.' as normalized_size_code, SUM('.$qtyExpr.') as qty')
            ->groupByRaw($sizeCodeExpr)
            ->get();

        $buckets = [
            'candles' => 0,
            'melts' => 0,
            'sprays' => 0,
        ];

        foreach ($rows as $row) {
            $code = (string) ($row->normalized_size_code ?? 'unknown');
            $qty = (int) ($row->qty ?? 0);

            if (str_contains($code, 'melt')) {
                $buckets['melts'] += $qty;
            } elseif (str_contains($code, 'spray')) {
                $buckets['sprays'] += $qty;
            } else {
                $buckets['candles'] += $qty;
            }
        }

        return [
            'openLineItemsTotal' => array_sum($buckets),
            'byType' => $buckets,
            'available' => array_sum($buckets) > 0,
        ];
    }

    private function topScents(string $channel, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        $qtyExpr = 'CASE WHEN (COALESCE(order_lines.ordered_qty,0)+COALESCE(order_lines.extra_qty,0)) > 0 '
            .'THEN (COALESCE(order_lines.ordered_qty,0)+COALESCE(order_lines.extra_qty,0)) '
            .'ELSE COALESCE(order_lines.quantity,0) END';

        $query = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->leftJoin('scents', 'scents.id', '=', 'order_lines.scent_id')
            ->whereBetween('orders.created_at', [$rangeStart, $rangeEnd]);

        if ($channel !== 'all') {
            $query->where('orders.order_type', $channel);
        }

        $orderTypeExpr = 'COALESCE(orders.order_type, "unknown")';
        $scentNameExpr = 'COALESCE(scents.name, order_lines.scent_name, order_lines.raw_title, "Unknown")';

        $rows = $query
            ->selectRaw($orderTypeExpr.' as grouped_order_type, '.$scentNameExpr.' as grouped_scent_name, SUM('.$qtyExpr.') as qty')
            ->groupByRaw($orderTypeExpr.', '.$scentNameExpr)
            ->orderByDesc('qty')
            ->get();

        $byChannel = $rows->groupBy('grouped_order_type')->map(fn ($group) => $group
            ->sortByDesc('qty')
            ->take(5)
            ->map(fn ($row): array => [
                'scent' => (string) $row->grouped_scent_name,
                'qty' => (int) $row->qty,
            ])->values()->all())->all();

        return [
            'rangeStart' => $rangeStart->toDateString(),
            'rangeEnd' => $rangeEnd->toDateString(),
            'byChannel' => $byChannel,
            'available' => !empty($byChannel),
        ];
    }

    private function revenueSnapshot(string $channel, CarbonImmutable $now): array
    {
        $start30 = $now->subDays(29)->startOfDay();
        $start7 = $now->subDays(6)->startOfDay();

        $qtyExpr = 'CASE WHEN (COALESCE(order_lines.ordered_qty,0)+COALESCE(order_lines.extra_qty,0)) > 0 '
            .'THEN (COALESCE(order_lines.ordered_qty,0)+COALESCE(order_lines.extra_qty,0)) '
            .'ELSE COALESCE(order_lines.quantity,0) END';

        $priceExpr = 'CASE WHEN LOWER(COALESCE(orders.order_type, "retail")) = "wholesale" '
            .'THEN sizes.wholesale_price ELSE sizes.retail_price END';

        $query = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->leftJoin('sizes', 'sizes.id', '=', 'order_lines.size_id')
            ->whereBetween('orders.created_at', [$start30, $now]);

        if ($channel !== 'all') {
            $query->where('orders.order_type', $channel);
        }

        $orderTypeExpr = 'COALESCE(orders.order_type, "unknown")';

        $rows = $query
            ->selectRaw($orderTypeExpr.' as grouped_order_type')
            ->selectRaw('SUM(CASE WHEN orders.created_at >= ? THEN ('.$qtyExpr.') * COALESCE('.$priceExpr.', 0) ELSE 0 END) as gross_7', [$start7])
            ->selectRaw('SUM(('.$qtyExpr.') * COALESCE('.$priceExpr.', 0)) as gross_30')
            ->selectRaw('SUM(CASE WHEN '.$priceExpr.' IS NULL THEN '.$qtyExpr.' ELSE 0 END) as qty_missing_price')
            ->groupByRaw($orderTypeExpr)
            ->get();

        $byChannel = $rows->mapWithKeys(fn ($row): array => [
            (string) $row->grouped_order_type => [
                'gross_7' => round((float) $row->gross_7, 2),
                'gross_30' => round((float) $row->gross_30, 2),
                'qty_missing_price' => (int) $row->qty_missing_price,
            ],
        ])->all();

        $configured = !empty($byChannel);

        return [
            'available' => $configured,
            'isEstimate' => true,
            'note' => 'Estimated from order line quantities and size retail/wholesale price fields; no order gross total field found.',
            'byChannel' => $byChannel,
        ];
    }

    private function importHealth(string $channel, CarbonImmutable $now): array
    {
        $runQuery = ShopifyImportRun::query();
        if ($channel !== 'all') {
            $runQuery->where('store_key', $channel);
        }

        $lastRun = (clone $runQuery)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();

        $ordersImportedLast24h = (clone $runQuery)
            ->where('started_at', '>=', $now->subDay())
            ->selectRaw('SUM(COALESCE(imported_count,0) + COALESCE(updated_count,0)) as c')
            ->value('c');

        $importExceptionsQuery = ShopifyImportException::query();
        if ($channel !== 'all') {
            // No explicit channel field on exceptions. Keep global and disclose in UI.
        }

        $importExceptionsLast24h = (clone $importExceptionsQuery)
            ->where('created_at', '>=', $now->subDay())
            ->count();

        $mappingExceptionsOpen = MappingException::query()
            ->whereNull('resolved_at')
            ->count();

        return [
            'lastRunAt' => optional($lastRun?->finished_at ?? $lastRun?->started_at)?->toDateTimeString(),
            'lastRunStore' => $lastRun?->store_key,
            'ordersImportedLast24h' => (int) ($ordersImportedLast24h ?? 0),
            'importExceptionsLast24h' => $importExceptionsLast24h,
            'mappingExceptionsOpen' => $mappingExceptionsOpen,
        ];
    }
}
