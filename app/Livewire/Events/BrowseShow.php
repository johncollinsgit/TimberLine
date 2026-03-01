<?php

namespace App\Livewire\Events;

use App\Models\EventInstance;
use Livewire\Component;

class BrowseShow extends Component
{
    public EventInstance $eventInstance;

    public function mount(EventInstance $eventInstance): void
    {
        $this->eventInstance = $eventInstance->load(['boxPlans' => fn ($query) => $query->orderBy('id')]);
    }

    public function render()
    {
        $history = EventInstance::query()
            ->where('id', '!=', $this->eventInstance->id)
            ->where('title', $this->eventInstance->title)
            ->orderByDesc('starts_at')
            ->limit(12)
            ->get(['id', 'title', 'starts_at', 'status']);

        return view('livewire.events.browse-show', [
            'history' => $history,
            'totalBoxesSent' => (float) $this->eventInstance->boxPlans->sum(fn ($row) => (float) ($row->box_count_sent ?? 0)),
        ])->layout('layouts.app');
    }
}
