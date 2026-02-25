<?php

namespace App\Livewire\Markets;

use App\Models\Event;
use Livewire\Component;

class YearOverview extends Component
{
    public int $year;

    public function mount(int $year): void
    {
        $this->year = $year;
    }

    public function render()
    {
        $events = Event::query()
            ->with(['market', 'marketPourList'])
            ->where('year', $this->year)
            ->orderBy('starts_at')
            ->orderBy('name')
            ->get();

        return view('livewire.markets.year-overview', [
            'events' => $events,
        ])->layout('layouts.app');
    }
}

