<?php

namespace App\Livewire\Markets;

use App\Models\Event;
use App\Models\MarketPourList;
use Livewire\Component;

class MarketPourListBuilder extends Component
{
    public string $title = '';
    public array $selectedEvents = [];

    public function create(): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
        ]);

        $list = MarketPourList::query()->create([
            'title' => $this->title,
            'status' => 'draft',
            'generated_by_user_id' => auth()->id(),
        ]);

        if (!empty($this->selectedEvents)) {
            $list->events()->sync($this->selectedEvents);
        }

        redirect()->route('markets.lists.show', $list);
    }

    public function render()
    {
        return view('livewire.markets.builder', [
            'events' => Event::query()->orderByDesc('starts_at')->get(),
        ])->layout('layouts.app');
    }
}
