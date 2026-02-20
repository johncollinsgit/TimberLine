<?php

namespace App\Livewire\PouringRoom;

use App\Models\Order;
use App\Services\Pouring\PouringQueueService;
use Carbon\CarbonImmutable;
use Livewire\Component;

class StackOrders extends Component
{
    public string $channel = 'retail';
    public string $sort = 'due'; // due|largest|recent
    public array $selected = [];

    protected $queryString = [
        'sort' => ['except' => 'due'],
    ];

    public function mount(string $channel): void
    {
        $this->channel = $channel;
    }

    public function startOrder(int $orderId): void
    {
        Order::query()->where('id', $orderId)->where('status', 'submitted_to_pouring')->update(['status' => 'pouring']);
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Order started.']);
    }

    public function completeOrder(int $orderId): void
    {
        Order::query()->where('id', $orderId)->whereIn('status', ['pouring', 'brought_down'])->update(['status' => 'brought_down']);
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Order completed in pouring.']);
    }

    public function toggleSelect(int $orderId): void
    {
        $this->selected[$orderId] = !($this->selected[$orderId] ?? false);
    }

    public function submitSelected(): void
    {
        $ids = array_keys(array_filter($this->selected));
        if (empty($ids)) {
            return;
        }
        Order::query()->whereIn('id', $ids)->whereIn('status', ['pouring', 'brought_down'])->update(['status' => 'brought_down']);
        $this->selected = [];
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Selected orders submitted.']);
    }

    public function render()
    {
        $service = app(PouringQueueService::class);
        $orders = $service->stackOrders($this->channel);

        $orders = $orders->map(function ($o) {
            $units = $o->lines->sum(fn ($l) => (int) (($l->ordered_qty ?? $l->quantity ?? 0) + ($l->extra_qty ?? 0)));
            $o->units = $units;
            return $o;
        });

        if ($this->sort === 'largest') {
            $orders = $orders->sortByDesc('units');
        } elseif ($this->sort === 'recent') {
            $orders = $orders->sortByDesc(fn ($o) => $o->updated_at ?? $o->created_at ?? now());
        } else {
            $orders = $orders->sortBy(function ($o) {
                $due = $o->due_at ? CarbonImmutable::parse($o->due_at) : CarbonImmutable::now()->addYears(5);
                return $due->timestamp;
            });
        }

        return view('livewire.pouring-room.stack-orders', [
            'orders' => $orders,
            'urgency' => fn (?string $due) => $service->urgencyLabel($due),
        ])->layout('layouts.app');
    }
}
