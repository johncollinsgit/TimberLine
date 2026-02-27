<?php

namespace App\Livewire\Retail\Markets;

use App\Models\Event;
use Livewire\Component;

class EventMatchWizard extends Component
{
    protected $listeners = [
        'marketsCandidateSelected' => 'handleCandidateSelected',
    ];

    public ?int $upcomingEventId = null;
    public ?int $selectedCandidateEventId = null;
    public int $matchWindowDays = 30;
    public int $step = 1;

    /** @var array<string,mixed>|null */
    public ?array $upcomingEvent = null;

    public function mount(?int $upcomingEventId = null, ?int $selectedCandidateEventId = null, int $matchWindowDays = 30): void
    {
        $this->upcomingEventId = $upcomingEventId;
        $this->selectedCandidateEventId = $selectedCandidateEventId;
        $this->matchWindowDays = max(14, min(60, $matchWindowDays));
        $this->loadUpcomingEvent();

        $this->step = $this->upcomingEventId ? 2 : 1;
        if ($this->selectedCandidateEventId) {
            $this->step = 3;
        }
    }

    public function updatedUpcomingEventId(mixed $value): void
    {
        $this->upcomingEventId = $value ? (int) $value : null;
        $this->selectedCandidateEventId = null;
        $this->step = $this->upcomingEventId ? 2 : 1;
        $this->loadUpcomingEvent();
    }

    public function handleCandidateSelected(int $candidateEventId): void
    {
        $this->selectedCandidateEventId = $candidateEventId;
        $this->step = 3;
    }

    public function goToConfirm(): void
    {
        if (! $this->upcomingEventId || ! $this->selectedCandidateEventId) {
            return;
        }
        $this->step = 4;
    }

    public function confirmAndCreateDraft(): void
    {
        if (! $this->upcomingEventId || ! $this->selectedCandidateEventId) {
            return;
        }

        $this->dispatch('marketsMappingConfirmed', upcomingEventId: (int) $this->upcomingEventId, candidateEventId: (int) $this->selectedCandidateEventId);
        $this->step = 5;
    }

    protected function loadUpcomingEvent(): void
    {
        $eventId = (int) ($this->upcomingEventId ?: 0);
        if ($eventId <= 0) {
            $this->upcomingEvent = null;
            return;
        }

        $event = Event::query()->select(['id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state'])->find($eventId);
        if (! $event) {
            $this->upcomingEvent = null;
            return;
        }

        $this->upcomingEvent = [
            'id' => (int) $event->id,
            'name' => (string) $event->name,
            'display_name' => (string) ($event->display_name ?? ''),
            'starts_at' => $event->starts_at?->toDateString(),
            'ends_at' => $event->ends_at?->toDateString(),
            'city' => (string) ($event->city ?? ''),
            'state' => (string) ($event->state ?? ''),
        ];
    }

    public function render()
    {
        return view('livewire.retail.markets.event-match-wizard');
    }
}
