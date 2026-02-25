<?php

namespace App\Livewire\Markets;

use App\Models\Event;
use Livewire\Component;

class EventBrowserShow extends Component
{
    public Event $event;

    public function mount(Event $event): void
    {
        $this->event = $event;
    }

    public function render()
    {
        $event = $this->event->load([
            'market',
            'boxShipments',
            'marketPourList.lines.scent',
            'marketPourList.lines.size',
        ]);

        return view('livewire.markets.event-browser-show', [
            'event' => $event,
            'boxLines' => $event->boxShipments,
            'draftList' => $event->marketPourList,
        ])->layout('layouts.app');
    }
}

