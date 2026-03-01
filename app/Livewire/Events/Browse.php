<?php

namespace App\Livewire\Events;

use App\Models\EventInstance;
use Livewire\Component;
use Livewire\WithPagination;

class Browse extends Component
{
    use WithPagination;

    public string $search = '';
    public string $status = 'all';
    public string $state = 'all';
    public string $year = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingState(): void
    {
        $this->resetPage();
    }

    public function updatingYear(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = EventInstance::query()
            ->withSum('boxPlans as total_boxes_sent', 'box_count_sent');

        if ($this->search !== '') {
            $search = '%'.$this->search.'%';
            $query->where('title', 'like', $search);
        }

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        if ($this->state !== 'all') {
            $query->where('state', $this->state);
        }

        if ($this->year !== 'all') {
            $query->whereYear('starts_at', (int) $this->year);
        }

        $states = EventInstance::query()
            ->whereNotNull('state')
            ->distinct()
            ->orderBy('state')
            ->pluck('state')
            ->all();

        $years = EventInstance::query()
            ->whereNotNull('starts_at')
            ->get(['starts_at'])
            ->pluck('starts_at')
            ->filter()
            ->map(fn ($date) => $date->format('Y'))
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        return view('livewire.events.browse', [
            'events' => $query->orderByDesc('starts_at')->orderBy('title')->paginate(15),
            'states' => $states,
            'years' => $years,
        ])->layout('layouts.app');
    }
}
