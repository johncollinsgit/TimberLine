<?php

namespace App\Livewire\PouringRoom;

use App\Services\Pouring\PouringQueueService;
use Carbon\CarbonImmutable;
use Livewire\Component;

class Timeline extends Component
{
    public function render()
    {
        $orders = app(PouringQueueService::class)->openOrders();
        $groups = $orders
            ->filter(fn ($o) => $o->due_at)
            ->groupBy(fn ($o) => CarbonImmutable::parse($o->due_at)->toDateString())
            ->sortKeys();

        return view('livewire.pouring-room.timeline', [
            'groups' => $groups,
        ])->layout('layouts.app');
    }
}
