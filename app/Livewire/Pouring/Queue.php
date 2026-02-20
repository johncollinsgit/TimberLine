<?php

namespace App\Livewire\Pouring;

use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Pouring\OopsService;
use App\Services\Pouring\PourBatchService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Component;

class Queue extends Component
{
    public string $viewMode = 'upcoming';
    public array $selectedLines = [];
    public array $selectedOrders = [];
    public array $selectedScents = [];

    public string $batchName = '';
    public array $batchPreview = [];

    public array $reminders = [
        'Check temperature before every pour.',
        'Centered wicks save rework later.',
        'Clean jars = clean brand.',
        'Accuracy now prevents headaches later.',
        'Positive attitude makes long days shorter.',
    ];

    protected $queryString = [
        'viewMode' => ['except' => 'upcoming'],
    ];

    public function mount(?string $viewMode = null): void
    {
        if ($viewMode) {
            $this->viewMode = $viewMode;
        }
        $this->batchName = 'Batch '.now()->format('M j, g:i A');
    }

    public function toggleLine(int $lineId): void
    {
        $this->selectedLines[$lineId] = !($this->selectedLines[$lineId] ?? false);
    }

    public function toggleOrder(int $orderId): void
    {
        $this->selectedOrders[$orderId] = !($this->selectedOrders[$orderId] ?? false);
    }

    public function toggleScent(int $scentId): void
    {
        $this->selectedScents[$scentId] = !($this->selectedScents[$scentId] ?? false);
    }

    public function previewBatch(): void
    {
        $lines = $this->selectedLineModels();
        $calc = app(\App\Services\Pouring\PourBatchCalculator::class)->calculate($lines);

        $this->batchPreview = $calc;
    }

    public function startBatch(PourBatchService $service): void
    {
        $lines = $this->selectedLineModels();
        if (empty($lines)) {
            return;
        }

        $batch = $service->createBatch($lines, [
            'name' => $this->batchName,
            'status' => 'in_progress',
            'selection_mode' => $this->viewMode,
            'order_type' => $this->viewMode,
            'created_by' => auth()->id(),
            'notes' => null,
        ]);

        $this->selectedLines = [];
        $this->selectedOrders = [];
        $this->selectedScents = [];
        $this->batchPreview = [];

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Batch #{$batch->id} started.",
        ]);
    }

    public function oops(int $lineId, OopsService $oops): void
    {
        $line = OrderLine::query()->findOrFail($lineId);
        $oops->recordOops($line, 1, auth()->id());

        $this->dispatch('toast', [
            'type' => 'warning',
            'message' => 'Oops recorded. Replacement added.',
        ]);
    }

    public function clearSelections(): void
    {
        $this->selectedLines = [];
        $this->selectedOrders = [];
        $this->selectedScents = [];
        $this->batchPreview = [];
    }

    protected function selectedLineModels(): array
    {
        $lines = collect();

        if (!empty($this->selectedLines)) {
            $lineIds = array_keys(array_filter($this->selectedLines));
            $lines = $lines->merge(OrderLine::query()->whereIn('id', $lineIds)->get());
        }

        if (!empty($this->selectedOrders)) {
            $orderIds = array_keys(array_filter($this->selectedOrders));
            $lines = $lines->merge($this->baseLineQuery()->whereIn('order_id', $orderIds)->get());
        }

        if (!empty($this->selectedScents)) {
            $scentIds = array_keys(array_filter($this->selectedScents));
            $lines = $lines->merge($this->baseLineQuery()->whereIn('scent_id', $scentIds)->get());
        }

        return $lines->unique('id')->all();
    }

    protected function baseLineQuery(): Builder
    {
        return OrderLine::query()
            ->whereNotNull('scent_id')
            ->whereNotNull('size_id')
            ->whereHas('order', function (Builder $q) {
                $q->whereNotNull('published_at')
                    ->whereIn('status', ['submitted_to_pouring', 'pouring', 'brought_down', 'verified']);
            });
    }

    protected function orderQuery(): Builder
    {
        return Order::query()
            ->whereNotNull('published_at')
            ->whereIn('status', ['submitted_to_pouring', 'pouring', 'brought_down', 'verified'])
            ->withCount([
                'mappingExceptions as open_mapping_exceptions_count' => function ($q) {
                    $q->whereNull('resolved_at');
                },
            ])
            ->with(['lines' => function ($q) {
                $q->with(['scent.oilBlend.components.baseOil', 'size'])
                    ->orderBy('scent_id')
                    ->orderBy('size_id');
            }]);
    }

    protected function applyPrioritySort(Collection $orders): Collection
    {
        $now = CarbonImmutable::now();

        return $orders->sortBy(function (Order $order) use ($now) {
            $dueAt = $order->due_at ? CarbonImmutable::parse($order->due_at) : null;
            $orderedAt = $order->ordered_at ? CarbonImmutable::parse($order->ordered_at) : null;
            $priority = 3;

            if ($order->order_type === 'retail') {
                $priority = 1;
            } elseif ($order->order_type === 'event') {
                $priority = 2;
            } elseif ($order->order_type === 'wholesale') {
                $priority = 3;
            }

            if ($order->order_type === 'wholesale' && $dueAt && $orderedAt) {
                $total = max(1, $orderedAt->diffInMinutes($dueAt));
                $elapsed = $orderedAt->diffInMinutes($now);
                $elapsedRatio = $elapsed / $total;
                $daysLeft = $now->diffInDays($dueAt, false);

                if ($elapsedRatio >= 0.6 && $daysLeft <= 2) {
                    $priority = 0;
                }
            }

            return [$priority, $dueAt?->timestamp ?? PHP_INT_MAX];
        });
    }

    protected function urgencyStyle(?CarbonImmutable $dueAt, string $type): string
    {
        $base = $this->typeBaseColor($type);
        $now = CarbonImmutable::now();
        $daysLeft = $dueAt ? $now->diffInDays($dueAt, false) : null;
        $window = 7;
        $ratio = 0.0;

        if ($daysLeft !== null) {
            $ratio = ($window - min($window, max(0, $daysLeft))) / $window;
            if ($daysLeft <= 0) {
                $ratio = 1.0;
            }
        }

        $mix = $this->mixColor($base, [239, 68, 68], $ratio);
        $baseFill = $this->rgba($base, 0.18);
        $mixFill = $this->rgba($mix, 0.38);
        $border = $this->rgba($mix, 0.45);

        return "background: linear-gradient(135deg, {$baseFill}, {$mixFill}); border-color: {$border};";
    }

    protected function typeBadgeStyle(string $type): string
    {
        $base = $this->typeBaseColor($type);
        $bg = $this->rgba($base, 0.22);
        $border = $this->rgba($base, 0.35);
        return "background-color: {$bg}; border-color: {$border};";
    }

    protected function typeBaseColor(string $type): array
    {
        return match ($type) {
            'market', 'event' => [168, 85, 247],
            'wholesale' => [234, 179, 8],
            default => [59, 130, 246],
        };
    }

    protected function typeLabel(string $type): string
    {
        return $type === 'event' ? 'market' : $type;
    }

    protected function mixColor(array $base, array $target, float $ratio): array
    {
        $ratio = max(0, min(1, $ratio));
        return [
            (int) round($base[0] * (1 - $ratio) + $target[0] * $ratio),
            (int) round($base[1] * (1 - $ratio) + $target[1] * $ratio),
            (int) round($base[2] * (1 - $ratio) + $target[2] * $ratio),
        ];
    }

    protected function rgba(array $rgb, float $alpha): string
    {
        $a = max(0, min(1, $alpha));
        return sprintf('rgba(%d,%d,%d,%.2f)', $rgb[0], $rgb[1], $rgb[2], $a);
    }

    public function render()
    {
        $orders = $this->orderQuery()->get();

        if ($this->viewMode === 'retail') {
            $orders = $orders->where('order_type', 'retail');
        } elseif ($this->viewMode === 'wholesale') {
            $orders = $orders->where('order_type', 'wholesale');
        } elseif ($this->viewMode === 'market') {
            $orders = $orders->where('order_type', 'event');
        } elseif ($this->viewMode === 'upcoming') {
            $orders = $this->applyPrioritySort($orders);
        } elseif ($this->viewMode === 'ship_date') {
            $orders = $orders->sortBy(fn (Order $o) => $o->ship_by_at ?? now()->addYears(5));
        }

        $byScent = $this->baseLineQuery()
            ->with(['scent:id,name,display_name', 'size:id,code,label'])
            ->get()
            ->groupBy('scent_id');

        return view('livewire.pouring.queue', [
            'orders' => $orders,
            'byScent' => $byScent,
            'reminder' => $this->reminders[array_rand($this->reminders)],
        ]);
    }
}
