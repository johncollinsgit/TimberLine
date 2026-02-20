<?php

namespace App\Livewire\Events;

use App\Models\Event;
use Livewire\Component;

class Create extends Component
{
    public string $name = '';
    public ?string $venue = null;
    public ?string $city = null;
    public ?string $state = null;
    public ?string $starts_at = null;
    public ?string $ends_at = null;
    public ?string $due_date = null;
    public ?string $ship_date = null;
    public string $status = 'planned';
    public ?string $notes = null;

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'due_date' => 'nullable|date',
            'ship_date' => 'nullable|date',
            'status' => 'required|string|max:50',
        ]);

        $event = Event::query()->create([
            'name' => $this->name,
            'venue' => $this->venue,
            'city' => $this->city,
            'state' => $this->state,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'due_date' => $this->due_date,
            'ship_date' => $this->ship_date,
            'status' => $this->status,
            'notes' => $this->notes,
        ]);

        redirect()->route('events.show', $event);
    }

    public function render()
    {
        return view('livewire.events.create')
            ->layout('layouts.app');
    }
}
