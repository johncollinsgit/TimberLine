<?php

namespace App\Livewire\Retail\Markets;

use App\Models\Event;
use App\Models\EventMapping;
use App\Models\RetailPlanItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class UpcomingEventsPanel extends Component
{
    protected $listeners = [
        'marketsRefreshRequested' => 'handleMarketsRefreshRequested',
        'marketsDraftUpdated' => 'handleMarketsDraftUpdated',
        'marketsMappingConfirmed' => 'handleMarketsMappingConfirmed',
    ];

    public int $planId = 0;
    public ?int $selectedEventId = null;
    public string $stateTab = 'needs_mapping';
    public int $lookaheadDays = 30;

    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    public function mount(int $planId = 0, ?int $selectedEventId = null, string $stateTab = 'needs_mapping', int $lookaheadDays = 30): void
    {
        $this->planId = max(0, $planId);
        $this->selectedEventId = $selectedEventId;
        $this->stateTab = in_array($stateTab, ['needs_mapping', 'mapped', 'drafted', 'submitted'], true)
            ? $stateTab
            : 'needs_mapping';
        $this->lookaheadDays = max(7, min(90, $lookaheadDays));

        $this->loadRows();
    }

    public function updatedSelectedEventId(?int $value): void
    {
        $this->selectedEventId = $value ? (int) $value : null;
    }

    public function setStateTab(string $tab): void
    {
        $this->stateTab = in_array($tab, ['needs_mapping', 'mapped', 'drafted', 'submitted'], true)
            ? $tab
            : 'needs_mapping';

        $this->dispatch('marketsEventStateTabChanged', stateTab: $this->stateTab);
    }

    public function selectEvent(int $eventId): void
    {
        $this->selectedEventId = $eventId;
        $this->dispatch('marketsUpcomingEventSelected', eventId: $eventId);
    }

    public function matchEvent(int $eventId): void
    {
        $this->selectedEventId = $eventId;
        $this->dispatch('marketsMatchSimilarEventRequested', marketEventId: $eventId);
    }

    public function handleMarketsRefreshRequested(?int $eventId = null): void
    {
        $this->loadRows();
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
        $this->stateTab = 'drafted';
        $this->handleMarketsRefreshRequested($upcomingEventId);
    }

    protected function loadRows(): void
    {
        $startedAt = microtime(true);
        $start = now()->startOfDay()->toDateString();
        $end = now()->addDays($this->lookaheadDays)->endOfDay()->toDateString();

        $events = Event::query()
            ->select(['id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state', 'status', 'source_ref'])
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$start, $end])
            ->orderBy('starts_at')
            ->orderBy('display_name')
            ->limit(120)
            ->get();

        $eventIds = $events->pluck('id')->map(fn ($id) => (int) $id)->all();
        $mappedIds = [];
        if (Schema::hasTable('event_mappings') && $eventIds !== []) {
            $mappedIds = EventMapping::query()
                ->whereIn('upcoming_event_id', $eventIds)
                ->pluck('upcoming_event_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }
        $mappedLookup = array_fill_keys($mappedIds, true);

        $draftCounts = [];
        if (Schema::hasColumn('retail_plan_items', 'upcoming_event_id')) {
            $draftCounts = RetailPlanItem::query()
                ->selectRaw('upcoming_event_id, COUNT(*) as row_count')
                ->when($this->planId > 0, fn ($q) => $q->where('retail_plan_id', $this->planId))
                ->whereNotNull('upcoming_event_id')
                ->where('status', '!=', 'published')
                ->when($eventIds !== [], fn ($q) => $q->whereIn('upcoming_event_id', $eventIds))
                ->groupBy('upcoming_event_id')
                ->pluck('row_count', 'upcoming_event_id')
                ->map(fn ($count) => (int) $count)
                ->all();
        }

        $this->rows = $events->map(function (Event $event) use ($mappedLookup, $draftCounts): array {
            $eventId = (int) $event->id;
            $status = strtolower(trim((string) $event->status));
            $hasMapped = (bool) ($mappedLookup[$eventId] ?? false);
            $hasDraft = (int) ($draftCounts[$eventId] ?? 0) > 0;

            $planningState = $status === 'submitted'
                ? 'submitted'
                : ($hasDraft || $status === 'drafted'
                    ? 'drafted'
                    : ($hasMapped || $status === 'mapped' ? 'mapped' : 'needs_mapping'));

            return [
                'id' => $eventId,
                'name' => (string) $event->name,
                'display_name' => (string) ($event->display_name ?? ''),
                'starts_at' => $event->starts_at?->toDateString(),
                'ends_at' => $event->ends_at?->toDateString(),
                'city' => (string) ($event->city ?? ''),
                'state' => (string) ($event->state ?? ''),
                'planning_state' => $planningState,
                'draft_rows_count' => (int) ($draftCounts[$eventId] ?? 0),
            ];
        })->values()->all();

        if (app()->isLocal() || (bool) config('app.debug')) {
            Log::info('UpcomingEventsPanel loadRows', [
                'plan_id' => $this->planId,
                'state_tab' => $this->stateTab,
                'lookahead_days' => $this->lookaheadDays,
                'row_count' => count($this->rows),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function eventsForTab(): Collection
    {
        return collect($this->rows)
            ->where('planning_state', $this->stateTab)
            ->values();
    }

    public function render()
    {
        $rows = collect($this->rows);

        return view('livewire.retail.markets.upcoming-events-panel', [
            'events' => $this->eventsForTab(),
            'counts' => [
                'needs_mapping' => $rows->where('planning_state', 'needs_mapping')->count(),
                'mapped' => $rows->where('planning_state', 'mapped')->count(),
                'drafted' => $rows->where('planning_state', 'drafted')->count(),
                'submitted' => $rows->where('planning_state', 'submitted')->count(),
            ],
            'hasQueueEvents' => $rows->whereIn('planning_state', ['needs_mapping', 'mapped', 'drafted'])->isNotEmpty(),
        ]);
    }
}
