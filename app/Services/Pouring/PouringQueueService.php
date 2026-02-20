<?php

namespace App\Services\Pouring;

use App\Models\Order;
use App\Models\OrderLine;
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
        $query = OrderLine::query()
            ->whereNotNull('scent_id')
            ->whereNotNull('size_id')
            ->whereHas('order', function (Builder $q) use ($filters) {
                $q->whereNotNull('published_at')
                    ->whereIn('status', $this->openStatuses);
                if (!empty($filters['channel']) && $filters['channel'] !== 'all') {
                    $q->where('order_type', $filters['channel']);
                }
                if (!empty($filters['due_window']) && $filters['due_window'] !== 'all') {
                    $days = (int) $filters['due_window'];
                    $q->whereDate('due_at', '<=', now()->addDays($days)->toDateString());
                }
            })
            ->with(['scent', 'size', 'order']);

        $lines = $query->get();

        return $lines->groupBy(fn ($l) => ($l->scent_id ?? 'null') . ':' . ($l->size_id ?? 'null') . ':' . ($l->wick_type ?? ''))
            ->map(function ($lines) {
                $first = $lines->first();
                $earliestDue = $lines->map(fn ($l) => $l->order?->due_at)->filter()->sort()->first();
                return [
                    'scent' => $first->scent,
                    'size' => $first->size,
                    'wick' => $first->wick_type,
                    'qty' => $lines->sum(fn ($l) => (int) (($l->ordered_qty ?? $l->quantity ?? 0) + ($l->extra_qty ?? 0))),
                    'earliest_due' => $earliestDue,
                    'breakdown' => $lines->groupBy(fn ($l) => $l->order?->channel ?? $l->order?->order_type ?? 'retail')
                        ->map(fn ($g) => $g->sum(fn ($l) => (int) (($l->ordered_qty ?? $l->quantity ?? 0) + ($l->extra_qty ?? 0))))
                        ->all(),
                ];
            })->values();
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
}
