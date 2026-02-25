<?php

namespace App\Livewire\Markets;

use App\Models\Market;
use Livewire\Component;

class MarketHistoryShow extends Component
{
    public Market $market;

    public function mount(Market $market): void
    {
        $this->market = $market;
    }

    public function render()
    {
        $events = $this->market->events()
            ->with(['boxShipments', 'marketPourList'])
            ->orderByDesc('year')
            ->orderByDesc('starts_at')
            ->get();

        $grouped = $events->groupBy(fn ($e) => (string) ($e->year ?? optional($e->starts_at)->format('Y') ?? 'Unknown'));

        return view('livewire.markets.market-history-show', [
            'groupedEvents' => $grouped,
        ])->layout('layouts.app');
    }
}

