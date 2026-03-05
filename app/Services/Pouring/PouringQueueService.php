<?php

namespace App\Services\Pouring;

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\Size;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PouringQueueService
{
    protected array $openStatuses = ['submitted_to_pouring', 'pouring', 'brought_down', 'verified'];

    public function openOrdersQuery(): Builder
    {
        return Order::query()
            ->whereNotNull('published_at')
            ->whereIn('status', $this->openStatuses)
            ->with(['lines' => function ($q) {
                $q->with(['scent.oilBlend.components.baseOil', 'size'])
                    ->orderBy('scent_id')
                    ->orderBy('size_id');
            }]);
    }

    public function openOrders(): Collection
    {
        return $this->openOrdersQuery()->get();
    }

    public function stackSummary(): array
    {
        $orders = $this->openOrders();
        $byChannel = $orders->groupBy(fn ($o) => $o->channel ?? $o->order_type ?? 'retail');
        $today = CarbonImmutable::now()->startOfDay();

        $summary = [];
        foreach (['retail', 'wholesale', 'event'] as $channel) {
            $group = $byChannel->get($channel, collect());
            $ordersCount = $group->count();
            $units = $group->flatMap->lines->sum(function ($line) {
                return (int) (($line->ordered_qty ?? $line->quantity ?? 0) + ($line->extra_qty ?? 0));
            });

            $earliestDue = $group
                ->map(fn ($o) => $o->due_at)
                ->filter()
                ->sort()
                ->first();

            $overdue = $group->filter(function ($o) use ($today) {
                return $o->due_at && CarbonImmutable::parse($o->due_at)->lt($today);
            })->count();

            $pendingPublish = Order::query()
                ->whereNull('published_at')
                ->where('order_type', $channel)
                ->whereIn('status', ['new', 'reviewed'])
                ->count();

            $summary[$channel] = [
                'orders' => $ordersCount,
                'units' => $units,
                'earliest_due' => $earliestDue,
                'overdue' => $overdue,
                'pending_publish' => $pendingPublish,
                'active_orders' => $group->filter(fn ($o) => ($o->status ?? '') === 'pouring')->values(),
            ];
        }

        return $summary;
    }

    public function stackOrders(string $channel): Collection
    {
        $orders = $this->openOrders()
            ->filter(fn ($o) => ($o->channel ?? $o->order_type ?? 'retail') === $channel)
            ->values();

        return $orders;
    }

    public function orderLinesGrouped(Order $order): Collection
    {
        return $order->lines
            ->filter(fn ($l) => $l->scent_id && $l->size_id)
            ->groupBy(fn ($l) => ($l->scent_id ?? 'null') . ':' . ($l->size_id ?? 'null') . ':' . ($l->wick_type ?? ''))
            ->map(function ($lines, $key) {
                $first = $lines->first();
                $statusCounts = $lines
                    ->groupBy(fn ($l) => $l->pour_status ?: 'queued')
                    ->map(fn ($group) => $group->count())
                    ->all();

                return [
                    'key' => $key,
                    'scent' => $first->scent,
                    'size' => $first->size,
                    'wick' => $first->wick_type,
                    'qty' => $lines->sum(fn ($l) => (int) (($l->ordered_qty ?? $l->quantity ?? 0) + ($l->extra_qty ?? 0))),
                    'status' => count($statusCounts) === 1 ? array_key_first($statusCounts) : 'mixed',
                    'status_counts' => $statusCounts,
                ];
            })->values();
    }

    public function allCandles(array $filters = []): Collection
    {
        $channel = (string) ($filters['channel'] ?? 'all');
        $dueWindow = (string) ($filters['due_window'] ?? '7');
        $batchMode = (string) ($filters['batch_mode'] ?? 'by_market');
        $pitcherCapacity = (float) ($filters['pitcher_capacity'] ?? 2300);

        $query = OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->whereNotNull('order_lines.scent_id')
            ->whereNotNull('order_lines.size_id')
            ->whereNotNull('orders.published_at')
            ->whereIn('orders.status', $this->openStatuses);

        if ($channel !== 'all') {
            $query->where('orders.order_type', $channel);
        }

        if ($dueWindow !== 'all') {
            $days = max(0, (int) $dueWindow);
            $query->whereDate('orders.due_at', '<=', now()->addDays($days)->toDateString());
        }

        $aggregated = $query
            ->selectRaw("
                order_lines.scent_id as scent_id,
                order_lines.size_id as size_id,
                coalesce(orders.order_type, 'retail') as channel_key,
                orders.event_id as event_id,
                coalesce(order_lines.pour_status, 'queued') as pour_status,
                min(orders.due_at) as earliest_due_at,
                sum(coalesce(order_lines.ordered_qty, order_lines.quantity, 0) + coalesce(order_lines.extra_qty, 0)) as qty
            ")
            ->groupBy(
                'order_lines.scent_id',
                'order_lines.size_id',
                'orders.order_type',
                'orders.event_id',
                'order_lines.pour_status'
            )
            ->get();

        if ($aggregated->isEmpty()) {
            return collect();
        }

        $scentIds = $aggregated->pluck('scent_id')->filter()->unique()->values();
        $sizeIds = $aggregated->pluck('size_id')->filter()->unique()->values();
        $eventIds = $aggregated->pluck('event_id')->filter()->unique()->values();

        $scents = Scent::query()
            ->whereIn('id', $scentIds)
            ->with(['oilBlend.components.baseOil'])
            ->get()
            ->keyBy('id');
        $sizes = Size::query()->whereIn('id', $sizeIds)->get()->keyBy('id');
        $events = Event::query()->whereIn('id', $eventIds)->get()->keyBy('id');

        /** @var MeasurementResolver $measurement */
        $measurement = app(MeasurementResolver::class);
        $buckets = [];

        foreach ($aggregated as $group) {
            $scentId = (int) ($group->scent_id ?? 0);
            $sizeId = (int) ($group->size_id ?? 0);
            $eventId = (int) ($group->event_id ?? 0);
            $qty = max(0, (int) ($group->qty ?? 0));
            if ($scentId <= 0 || $sizeId <= 0 || $qty <= 0) {
                continue;
            }

            $channelKey = strtolower(trim((string) ($group->channel_key ?? 'retail')));
            if (! in_array($channelKey, ['retail', 'wholesale', 'event'], true)) {
                $channelKey = 'retail';
            }

            $bucketKey = $this->batchBucketKey($batchMode, $channelKey, $eventId, $scentId);
            $scent = $scents->get($scentId);
            $size = $sizes->get($sizeId);
            $sizeCode = trim((string) ($size?->code ?: $size?->label ?: ''));
            $ingredients = $sizeCode !== ''
                ? $measurement->resolveLineIngredients($sizeCode, $qty)
                : null;

            $waxGrams = (float) ($ingredients['wax_grams'] ?? 0);
            $oilGrams = (float) ($ingredients['oil_grams'] ?? 0);
            $pitchers = $this->pitcherCount($ingredients, $pitcherCapacity);
            $dueAt = ($group->earliest_due_at ?? null) ? CarbonImmutable::parse((string) $group->earliest_due_at) : null;
            $status = $this->normalizePourStatus((string) ($group->pour_status ?? 'queued'));

            if (! isset($buckets[$bucketKey])) {
                $market = $channelKey === 'event' ? $events->get($eventId) : null;
                $scentLabel = trim((string) ($scent?->display_name ?: $scent?->name ?: "Scent #{$scentId}"));
                $oilName = trim((string) ($scent?->oil_reference_name ?: $scent?->oilBlend?->name ?: ''));
                $buckets[$bucketKey] = [
                    'key' => $bucketKey,
                    'scent_id' => $scentId,
                    'scent' => $scent,
                    'scent_label' => $scentLabel !== '' ? $scentLabel : "Scent #{$scentId}",
                    'market_event_id' => $market?->id,
                    'market_label' => $market?->display_name ?: $market?->name,
                    'oil_name' => $oilName !== '' ? $oilName : '—',
                    'recipe_components' => $this->recipeComponents($scent),
                    'units' => 0,
                    'wax_grams' => 0.0,
                    'oil_grams' => 0.0,
                    'pitchers' => 0,
                    'inferred_boxes' => 0.0,
                    'earliest_due' => null,
                    'channel_units' => [
                        'event' => 0,
                        'retail' => 0,
                        'wholesale' => 0,
                    ],
                    'status_counts' => [],
                    'size_rows' => [],
                    'missing_recipe' => false,
                    'warnings' => [],
                ];
            }

            $buckets[$bucketKey]['units'] += $qty;
            $buckets[$bucketKey]['wax_grams'] += $waxGrams;
            $buckets[$bucketKey]['oil_grams'] += $oilGrams;
            $buckets[$bucketKey]['pitchers'] += $pitchers;
            $buckets[$bucketKey]['channel_units'][$channelKey] += $qty;
            $buckets[$bucketKey]['status_counts'][$status] = (int) ($buckets[$bucketKey]['status_counts'][$status] ?? 0) + $qty;
            $buckets[$bucketKey]['missing_recipe'] = (bool) ($buckets[$bucketKey]['missing_recipe'] ?? false)
                || ! $ingredients
                || ! $scent?->oilBlend;

            if ($dueAt) {
                $currentDue = $buckets[$bucketKey]['earliest_due'];
                $buckets[$bucketKey]['earliest_due'] = $currentDue && $currentDue->lte($dueAt) ? $currentDue : $dueAt;
            }

            $sizeRowKey = (string) $sizeId;
            if (! isset($buckets[$bucketKey]['size_rows'][$sizeRowKey])) {
                $sizeLabel = trim((string) ($size?->label ?: $size?->code ?: 'Unknown'));
                $sizeSummary = $this->sizeSummaryLabel((string) ($size?->code ?: $sizeLabel), $sizeLabel);
                $buckets[$bucketKey]['size_rows'][$sizeRowKey] = [
                    'size_id' => $sizeId,
                    'size_label' => $sizeLabel,
                    'size_summary' => $sizeSummary,
                    'size_sort' => $this->sizeSortOrder((string) ($size?->code ?: $sizeLabel), $sizeLabel),
                    'qty' => 0,
                    'wax_grams' => 0.0,
                    'oil_grams' => 0.0,
                    'pitchers' => 0,
                ];
            }

            $buckets[$bucketKey]['size_rows'][$sizeRowKey]['qty'] += $qty;
            $buckets[$bucketKey]['size_rows'][$sizeRowKey]['wax_grams'] += $waxGrams;
            $buckets[$bucketKey]['size_rows'][$sizeRowKey]['oil_grams'] += $oilGrams;
            $buckets[$bucketKey]['size_rows'][$sizeRowKey]['pitchers'] += $pitchers;
        }

        $today = CarbonImmutable::now()->startOfDay();
        $dueSoonCutoff = $today->addDays(3);

        return collect(array_values($buckets))
            ->map(function (array $row) use ($today, $dueSoonCutoff): array {
                $sizeRows = collect($row['size_rows'] ?? [])
                    ->sortBy([
                        ['size_sort', 'asc'],
                        ['size_label', 'asc'],
                    ])
                    ->values()
                    ->all();

                $inferredBoxes = $this->inferBoxesFromSizeRows($sizeRows);
                $status = $this->aggregateStatus((array) ($row['status_counts'] ?? []));
                $primaryChannel = $this->primaryChannel((array) ($row['channel_units'] ?? []));
                $sizeSummary = collect($sizeRows)
                    ->map(fn (array $sizeRow): string => ((int) ($sizeRow['qty'] ?? 0)).'×'.(string) ($sizeRow['size_summary'] ?? 'Size'))
                    ->implode(' | ');

                $warnings = [];
                if ((bool) ($row['missing_recipe'] ?? false)) {
                    $warnings[] = ['icon' => '⚠', 'label' => 'Missing recipe'];
                }
                if (($row['earliest_due'] ?? null) instanceof CarbonImmutable && $row['earliest_due']->lte($dueSoonCutoff)) {
                    $warnings[] = ['icon' => '🔥', 'label' => 'Due within 3 days'];
                }
                if ($inferredBoxes >= 8.0) {
                    $warnings[] = ['icon' => '📦', 'label' => 'Large box count'];
                }

                $row['size_rows'] = $sizeRows;
                $row['size_summary'] = $sizeSummary;
                $row['inferred_boxes'] = $inferredBoxes;
                $row['status'] = $status;
                $row['status_label'] = $this->statusLabel($status);
                $row['primary_channel'] = $primaryChannel;
                $row['warnings'] = $warnings;
                $row['wax_grams'] = round((float) ($row['wax_grams'] ?? 0), 1);
                $row['oil_grams'] = round((float) ($row['oil_grams'] ?? 0), 1);

                return $row;
            })
            ->values();
    }

    public function urgencyLabel(?string $dueAt): string
    {
        if (!$dueAt) return 'No due date';
        $due = CarbonImmutable::parse($dueAt)->startOfDay();
        $today = CarbonImmutable::now()->startOfDay();
        $diff = $today->diffInDays($due, false);
        if ($diff < 0) return 'Overdue';
        if ($diff === 0) return 'Due today';
        if ($diff === 1) return '1 day';
        return $diff . ' days';
    }

    protected function batchBucketKey(string $batchMode, string $channel, int $eventId, int $scentId): string
    {
        if ($batchMode === 'by_market' && $channel === 'event') {
            return 'market:'.max(0, $eventId).':scent:'.$scentId;
        }

        return 'scent:'.$scentId;
    }

    protected function normalizePourStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        $known = ['queued', 'laid_out', 'first_pour', 'second_pour', 'waiting_on_oil', 'brought_down'];

        return in_array($normalized, $known, true) ? $normalized : 'queued';
    }

    /**
     * @param  array<string,int>  $statusCounts
     */
    protected function aggregateStatus(array $statusCounts): string
    {
        $statusCounts = array_filter($statusCounts, fn ($value): bool => (int) $value > 0);
        if ($statusCounts === []) {
            return 'queued';
        }

        if (count($statusCounts) === 1) {
            return (string) array_key_first($statusCounts);
        }

        return 'mixed';
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => 'Queued',
            'laid_out' => 'Laid Out',
            'first_pour' => 'First Pour',
            'second_pour' => 'Second Pour',
            'waiting_on_oil' => 'Waiting on Oil',
            'brought_down' => 'Brought Down',
            default => 'Mixed',
        };
    }

    /**
     * @param  array<string,int>  $channelUnits
     */
    protected function primaryChannel(array $channelUnits): string
    {
        $ranked = ['event', 'retail', 'wholesale'];
        $topChannel = 'retail';
        $topUnits = -1;

        foreach ($ranked as $channel) {
            $units = (int) ($channelUnits[$channel] ?? 0);
            if ($units > $topUnits) {
                $topUnits = $units;
                $topChannel = $channel;
            }
        }

        return $topChannel;
    }

    protected function sizeSummaryLabel(string $sizeCode, string $sizeLabel): string
    {
        $haystack = strtolower(trim($sizeCode !== '' ? $sizeCode : $sizeLabel));

        if (str_contains($haystack, 'wax') || str_contains($haystack, 'melt')) {
            return 'WM';
        }

        if (str_contains($haystack, '16')) {
            return '16';
        }

        if (str_contains($haystack, '8')) {
            return '8';
        }

        return trim($sizeLabel) !== '' ? $sizeLabel : 'Size';
    }

    protected function sizeSortOrder(string $sizeCode, string $sizeLabel): int
    {
        $haystack = strtolower(trim($sizeCode !== '' ? $sizeCode : $sizeLabel));

        if (str_contains($haystack, '16')) {
            return 1;
        }

        if (str_contains($haystack, '8')) {
            return 2;
        }

        if (str_contains($haystack, 'wax') || str_contains($haystack, 'melt')) {
            return 3;
        }

        return 9;
    }

    /**
     * @param  array<int,array<string,mixed>>  $sizeRows
     */
    protected function inferBoxesFromSizeRows(array $sizeRows): float
    {
        $count16 = 0;
        $count8 = 0;
        $countWaxMelt = 0;

        foreach ($sizeRows as $row) {
            $qty = (int) ($row['qty'] ?? 0);
            $label = strtolower((string) ($row['size_summary'] ?? ''));

            if ($qty <= 0) {
                continue;
            }

            if (str_contains($label, '16')) {
                $count16 += $qty;
                continue;
            }

            if (str_contains($label, '8')) {
                $count8 += $qty;
                continue;
            }

            if (str_contains($label, 'wm') || str_contains($label, 'wax')) {
                $countWaxMelt += $qty;
            }
        }

        $candidates = [];
        if ($count16 > 0) {
            $candidates[] = $count16 / 2;
        }
        if ($count8 > 0) {
            $candidates[] = $count8 / 4;
        }
        if ($countWaxMelt > 0) {
            $candidates[] = $countWaxMelt / 4;
        }

        if ($candidates === []) {
            return 0.0;
        }

        return round(max($candidates), 1);
    }

    /**
     * @param  array{wax_grams?:float,oil_grams?:float,pitcher_grams?:float,total_grams?:float}|null  $ingredients
     */
    protected function pitcherCount(?array $ingredients, float $capacity): int
    {
        if (! $ingredients || $capacity <= 0) {
            return 0;
        }

        $total = (float) ($ingredients['pitcher_grams'] ?? ($ingredients['wax_grams'] ?? 0) + ($ingredients['oil_grams'] ?? 0));
        if ($total <= 0) {
            return 0;
        }

        return (int) ceil($total / $capacity);
    }

    /**
     * @return array<int,array{oil:string,ratio:mixed}>
     */
    protected function recipeComponents(?Scent $scent): array
    {
        if (! $scent?->oilBlend) {
            return [];
        }

        return $scent->oilBlend->components
            ->map(fn ($component): array => [
                'oil' => (string) ($component->baseOil?->name ?? 'Oil'),
                'ratio' => $component->ratio_weight,
            ])
            ->values()
            ->all();
    }
}
