<?php

namespace App\Livewire\Retail\Markets;

use App\Models\Event;
use App\Models\RetailPlanItem;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class DraftEventNavPills extends Component
{
    protected $listeners = [
        'marketsRefreshRequested' => 'handleMarketsRefreshRequested',
        'marketsDraftUpdated' => 'handleMarketsDraftUpdated',
        'marketsMappingConfirmed' => 'handleMarketsMappingConfirmed',
    ];

    public int $planId;
    public ?int $selectedEventId = null;

    /** @var array<int,array<string,mixed>> */
    public array $events = [];

    public function mount(int $planId, ?int $selectedEventId = null): void
    {
        $this->planId = $planId;
        $this->selectedEventId = $selectedEventId;
        $this->loadEvents();
    }

    public function updatedSelectedEventId(mixed $value): void
    {
        $this->selectedEventId = $value ? (int) $value : null;
    }

    public function selectDraftEvent(int $eventId): void
    {
        $this->selectedEventId = $eventId;
        $this->dispatch('marketsDraftEventSelected', eventId: $eventId);
    }

    public function handleMarketsRefreshRequested(?int $eventId = null): void
    {
        $this->loadEvents();
        $incomingEventId = (int) ($eventId ?: 0);
        if ($incomingEventId > 0) {
            $this->selectedEventId = $incomingEventId;
        }
    }

    public function handleMarketsDraftUpdated(int $eventId): void
    {
        $this->handleMarketsRefreshRequested($eventId);
    }

    public function handleMarketsMappingConfirmed(int $upcomingEventId, int $candidateEventId): void
    {
        unset($candidateEventId);
        $this->handleMarketsRefreshRequested($upcomingEventId);
    }

    protected function loadEvents(): void
    {
        $draftEventIds = [];
        if (Schema::hasColumn('retail_plan_items', 'upcoming_event_id')) {
            $draftEventIds = RetailPlanItem::query()
                ->where('retail_plan_id', $this->planId)
                ->where('status', '!=', 'published')
                ->whereNotNull('upcoming_event_id')
                ->whereIn('source', RetailPlanItem::marketDraftSources())
                ->pluck('upcoming_event_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        if ($draftEventIds === []) {
            $this->events = [];
            return;
        }

        $this->events = Event::query()
            ->select(['id', 'name', 'display_name', 'starts_at'])
            ->whereIn('id', $draftEventIds)
            ->orderBy('starts_at')
            ->orderBy('display_name')
            ->get()
            ->map(fn (Event $event) => [
                'id' => (int) $event->id,
                'title' => (string) ($event->display_name ?: $event->name ?: 'Untitled Event'),
                'starts_at' => $event->starts_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.retail.markets.draft-event-nav-pills');
    }
}
