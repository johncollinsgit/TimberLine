<?php

namespace App\Livewire\Retail\Markets;

use Carbon\CarbonInterface;
use App\Models\Event;
use App\Models\EventMapping;
use App\Models\RetailPlanItem;
use App\Services\MarketEventSyncCoordinator;
use Illuminate\Support\Carbon;
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
    public int $historyDays = 365;
    public int $pickerLimit = 50;
    public string $dateMode = 'future';
    public string $searchTerm = '';
    public string $fromDate = '';
    public string $toDate = '';
    public string $sourceLabel = 'DB (synced calendar events)';
    public string $windowLabel = '';
    public ?string $lastSyncAt = null;

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
        $this->historyDays = max(30, $this->historyDays);
        $this->applyDefaultDateWindow();

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

    public function setDateMode(string $mode): void
    {
        $this->dateMode = in_array($mode, ['future', 'past', 'all'], true)
            ? $mode
            : 'future';

        $this->applyDefaultDateWindow();
        $this->loadRows();
    }

    public function selectEvent(int $eventId): void
    {
        $this->selectedEventId = $eventId;
        $this->dispatch('marketsUpcomingEventSelected', eventId: $eventId);
        $this->dispatch('markets-upcoming-event-selected', id: $eventId);
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

    public function updatedSearchTerm(string $value): void
    {
        $this->searchTerm = trim($value);
        $this->loadRows();
    }

    public function updatedFromDate(string $value): void
    {
        $this->fromDate = $this->normalizeDateInput($value, $this->defaultWindowBounds($this->dateMode)[0]);
        $this->loadRows();
    }

    public function updatedToDate(string $value): void
    {
        $this->toDate = $this->normalizeDateInput($value, $this->defaultWindowBounds($this->dateMode)[1]);
        $this->loadRows();
    }

    protected function loadRows(): void
    {
        $startedAt = microtime(true);
        [$from, $to] = $this->resolveWindowBounds();
        $this->windowLabel = sprintf('Showing events from %s - %s', $from->format('M j, Y'), $to->format('M j, Y'));

        $syncState = app(MarketEventSyncCoordinator::class)->queueStatus();
        $this->lastSyncAt = ! empty($syncState['last_sync_at']) ? (string) $syncState['last_sync_at'] : null;

        $events = $this->getEventsForPicker($this->dateMode, $from, $to, $this->searchTerm);

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
                'date_mode' => $this->dateMode,
                'search_term' => $this->searchTerm,
                'from_date' => $this->fromDate,
                'to_date' => $this->toDate,
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

    protected function getEventsForPicker(string $mode, CarbonInterface $from, CarbonInterface $to, string $searchTerm = ''): Collection
    {
        $searchTerm = trim($searchTerm);
        $query = Event::query()
            ->select(['id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state', 'status', 'source_ref'])
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$from->toDateString(), $to->toDateString()])
            ->when($searchTerm !== '', function ($q) use ($searchTerm) {
                $like = '%'.$searchTerm.'%';

                $q->where(function ($inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('display_name', 'like', $like)
                        ->orWhere('city', 'like', $like)
                        ->orWhere('state', 'like', $like);
                });
            });

        if ($mode === 'past') {
            $query->orderByDesc('starts_at');
        } else {
            $query->orderBy('starts_at');
        }

        return $query
            ->orderBy('display_name')
            ->limit($this->pickerLimit)
            ->get();
    }

    protected function applyDefaultDateWindow(): void
    {
        [$from, $to] = $this->defaultWindowBounds($this->dateMode);
        $this->fromDate = $from->toDateString();
        $this->toDate = $to->toDateString();
    }

    /**
     * @return array{0:CarbonInterface,1:CarbonInterface}
     */
    protected function defaultWindowBounds(string $mode): array
    {
        $today = now()->startOfDay();

        return match ($mode) {
            'past' => [$today->copy()->subDays($this->historyDays), $today->copy()->subDay()],
            'all' => [$today->copy()->subDays($this->historyDays), $today->copy()->addDays($this->lookaheadDays)],
            default => [$today, $today->copy()->addDays($this->lookaheadDays)],
        };
    }

    /**
     * @return array{0:CarbonInterface,1:CarbonInterface}
     */
    protected function resolveWindowBounds(): array
    {
        [$defaultFrom, $defaultTo] = $this->defaultWindowBounds($this->dateMode);

        $from = Carbon::parse($this->normalizeDateInput($this->fromDate, $defaultFrom))->startOfDay();
        $to = Carbon::parse($this->normalizeDateInput($this->toDate, $defaultTo))->endOfDay();

        if ($to->lt($from)) {
            $to = $from->copy()->endOfDay();
            $this->toDate = $to->toDateString();
        }

        return [$from, $to];
    }

    protected function normalizeDateInput(?string $value, CarbonInterface $fallback): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $fallback->toDateString();
        }
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
        ]);
    }
}
