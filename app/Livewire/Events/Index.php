<?php

namespace App\Livewire\Events;

use App\Models\Event;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';
    public string $filter = 'upcoming'; // upcoming|past|all

    public function render()
    {
        $query = Event::query()
            ->withSum('shipments as sent_total', 'sent_qty')
            ->withSum('shipments as returned_total', 'returned_qty');
        if ($this->search !== '') {
            $s = '%' . $this->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)
                    ->orWhere('venue', 'like', $s)
                    ->orWhere('city', 'like', $s);
            });
        }
        if ($this->filter === 'upcoming') {
            $query->whereDate('starts_at', '>=', now()->toDateString());
        } elseif ($this->filter === 'past') {
            $query->whereDate('ends_at', '<', now()->toDateString());
        }

        return view('livewire.events.index', [
            'events' => $query->orderBy('starts_at')->paginate(12),
        ])->layout('layouts.app');
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingFilter()
    {
        $this->resetPage();
    }
}
