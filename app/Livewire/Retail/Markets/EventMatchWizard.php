<?php

namespace App\Livewire\Retail\Markets;

use App\Models\Event;
use App\Models\RetailPlanItem;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class EventMatchWizard extends Component
{
    protected $listeners = [
        'scentSelected' => 'handleScentSelected',
        'marketsUpcomingEventSelected' => 'handleUpcomingEventSelected',
        'marketsCandidateSelected' => 'handleCandidateSelected',
        'marketsDraftUpdated' => 'handleDraftUpdated',
        'marketsMappingConfirmed' => 'handleMappingConfirmed',
    ];

    public int $planId = 0;
    public ?int $upcomingEventId = null;
    public ?int $selectedCandidateEventId = null;
    public int $matchWindowDays = 30;
    public int $step = 1;
    public bool $startFresh = false;
    public ?int $selectedScentId = null;

    /** @var array<string,mixed>|null */
    public ?array $upcomingEvent = null;

    /** @var array<string,mixed>|null */
    public ?array $selectedCandidateEvent = null;

    /** @var array<string,mixed> */
    public array $draftSummary = [
        'line_count' => 0,
        'unit_count' => 0,
        'top_scents' => [],
    ];

    public function mount(int $planId = 0, ?int $upcomingEventId = null, ?int $selectedCandidateEventId = null, int $matchWindowDays = 30): void
    {
        $this->planId = max(0, $planId);
        $this->upcomingEventId = $upcomingEventId;
        $this->selectedCandidateEventId = $selectedCandidateEventId;
        $this->matchWindowDays = max(14, min(60, $matchWindowDays));

        $this->loadUpcomingEvent();
        $this->loadSelectedCandidateEvent();
        $this->loadDraftSummary();

        if ($this->upcomingEventId) {
            $this->step = 2;
        }

        if ($this->draftHasContent()) {
            $this->step = 3;
        }
    }

    public function handleUpcomingEventSelected(int $eventId): void
    {
        $this->upcomingEventId = $eventId > 0 ? $eventId : null;
        $this->selectedCandidateEventId = null;
        $this->selectedCandidateEvent = null;
        $this->startFresh = false;
        $this->selectedScentId = null;
        $this->loadUpcomingEvent();
        $this->loadDraftSummary();
        $this->step = $this->upcomingEventId ? 2 : 1;
    }

    public function handleScentSelected(string $key, ?int $scentId = null, ?string $scentName = null): void
    {
        unset($scentName);

        if ($key !== 'markets-stepper') {
            return;
        }

        $this->selectedScentId = $scentId && $scentId > 0 ? (int) $scentId : null;
    }

    public function handleCandidateSelected(int $candidateEventId): void
    {
        $this->selectedCandidateEventId = $candidateEventId > 0 ? $candidateEventId : null;
        $this->startFresh = false;
        $this->loadSelectedCandidateEvent();
    }

    public function handleDraftUpdated(int $eventId): void
    {
        if ((int) ($this->upcomingEventId ?: 0) !== (int) $eventId) {
            return;
        }

        $this->loadDraftSummary();
    }

    public function handleMappingConfirmed(int $upcomingEventId, int $candidateEventId): void
    {
        $this->upcomingEventId = $upcomingEventId > 0 ? $upcomingEventId : null;
        $this->selectedCandidateEventId = $candidateEventId > 0 ? $candidateEventId : null;
        $this->startFresh = false;
        $this->loadUpcomingEvent();
        $this->loadSelectedCandidateEvent();
        $this->loadDraftSummary();
        $this->step = 3;
    }

    public function goToStep(int $step): void
    {
        if ($this->canAccessStep($step)) {
            $this->step = $step;
        }
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function next(): void
    {
        if ($this->step === 1 && $this->upcomingEventId) {
            $this->step = 2;

            return;
        }

        if ($this->step === 2 && $this->canAccessStep(3)) {
            $this->step = 3;

            return;
        }

        if ($this->step === 3 && $this->canAccessStep(4)) {
            $this->loadDraftSummary();
            $this->step = 4;
        }
    }

    public function runMatchSearch(): void
    {
        if (! $this->upcomingEventId) {
            return;
        }

        $this->dispatch('marketsRunCandidateMatch', upcomingEventId: (int) $this->upcomingEventId, matchWindowDays: (int) $this->matchWindowDays);
    }

    public function useSelectedMatch(): void
    {
        if (! $this->upcomingEventId || ! $this->selectedCandidateEventId) {
            return;
        }

        $this->dispatch('marketsMappingConfirmed', upcomingEventId: (int) $this->upcomingEventId, candidateEventId: (int) $this->selectedCandidateEventId);
        $this->step = 3;
    }

    public function startFreshDraft(): void
    {
        if (! $this->upcomingEventId) {
            return;
        }

        $this->selectedCandidateEventId = null;
        $this->selectedCandidateEvent = null;
        $this->startFresh = true;
        $this->loadDraftSummary();
        $this->step = 3;
    }

    public function publish(): void
    {
        if (! $this->canAccessStep(4)) {
            return;
        }

        $this->dispatch('marketsPublishRequested');
    }

    public function addHalfBox(): void
    {
        if (! $this->upcomingEventId || ! $this->selectedScentId) {
            return;
        }

        $this->dispatch('marketsAddHalfBoxRequested', scentId: $this->selectedScentId, upcomingEventId: (int) $this->upcomingEventId);
        $this->step = 3;
    }

    public function addFullBox(): void
    {
        if (! $this->upcomingEventId || ! $this->selectedScentId) {
            return;
        }

        $this->dispatch('marketsAddFullBoxRequested', scentId: $this->selectedScentId, upcomingEventId: (int) $this->upcomingEventId);
        $this->step = 3;
    }

    public function addTopShelf(): void
    {
        if (! $this->upcomingEventId) {
            return;
        }

        $this->dispatch('marketsAddTopShelfRequested', upcomingEventId: (int) $this->upcomingEventId);
        $this->step = 3;
    }

    protected function loadUpcomingEvent(): void
    {
        $eventId = (int) ($this->upcomingEventId ?: 0);
        if ($eventId <= 0) {
            $this->upcomingEvent = null;

            return;
        }

        $event = Event::query()->select(['id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state', 'venue', 'status'])->find($eventId);
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
            'venue' => (string) ($event->venue ?? ''),
            'status' => (string) ($event->status ?? 'needs_mapping'),
        ];
    }

    protected function loadSelectedCandidateEvent(): void
    {
        $eventId = (int) ($this->selectedCandidateEventId ?: 0);
        if ($eventId <= 0) {
            $this->selectedCandidateEvent = null;

            return;
        }

        $event = Event::query()->select(['id', 'name', 'display_name', 'starts_at', 'city', 'state', 'venue'])->find($eventId);
        if (! $event) {
            $this->selectedCandidateEvent = null;

            return;
        }

        $this->selectedCandidateEvent = [
            'id' => (int) $event->id,
            'title' => (string) ($event->display_name ?: $event->name ?: 'Historical event'),
            'starts_at' => $event->starts_at?->toDateString(),
            'city' => (string) ($event->city ?? ''),
            'state' => (string) ($event->state ?? ''),
            'venue' => (string) ($event->venue ?? ''),
        ];
    }

    protected function loadDraftSummary(): void
    {
        $this->draftSummary = [
            'line_count' => 0,
            'unit_count' => 0,
            'top_scents' => [],
        ];

        if ($this->planId <= 0 || ! $this->supportsRetailPlanItemUpcomingEventColumn() || ! $this->upcomingEventId) {
            return;
        }

        $items = RetailPlanItem::query()
            ->select(['id', 'scent_id', 'quantity'])
            ->where('retail_plan_id', $this->planId)
            ->where('status', '!=', 'published')
            ->where('upcoming_event_id', $this->upcomingEventId)
            ->whereIn('source', ['market_box_draft', 'market_box_manual', 'market_box_event_prefill', 'event_prefill', 'market_top_shelf_template'])
            ->with(['scent:id,name,display_name'])
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $topScents = $items
            ->filter(fn (RetailPlanItem $item) => (int) ($item->scent_id ?? 0) > 0)
            ->groupBy('scent_id')
            ->map(function ($group): array {
                /** @var RetailPlanItem $first */
                $first = $group->first();

                return [
                    'name' => (string) ($first->scent?->display_name ?: $first->scent?->name ?: 'Unknown'),
                    'units' => (int) $group->sum(fn (RetailPlanItem $item) => (int) ($item->quantity ?? 0)),
                ];
            })
            ->sortByDesc('units')
            ->take(4)
            ->values()
            ->all();

        $this->draftSummary = [
            'line_count' => $items->count(),
            'unit_count' => (int) $items->sum(fn (RetailPlanItem $item) => (int) ($item->quantity ?? 0)),
            'top_scents' => $topScents,
        ];
    }

    public function canAccessStep(int $step): bool
    {
        if ($step <= 1) {
            return true;
        }

        if ($step === 2) {
            return $this->upcomingEventId !== null;
        }

        if ($step === 3) {
            return $this->upcomingEventId !== null && ($this->startFresh || $this->selectedCandidateEventId !== null || $this->draftHasContent());
        }

        if ($step === 4) {
            return $this->upcomingEventId !== null && $this->draftHasContent();
        }

        return false;
    }

    protected function draftHasContent(): bool
    {
        return (int) ($this->draftSummary['line_count'] ?? 0) > 0;
    }

    protected function supportsRetailPlanItemUpcomingEventColumn(): bool
    {
        static $supports = null;

        if ($supports === null) {
            $supports = Schema::hasColumn('retail_plan_items', 'upcoming_event_id');
        }

        return $supports;
    }

    public function render()
    {
        $steps = [
            1 => ['label' => 'Select Event', 'ready' => true],
            2 => ['label' => 'Choose Match', 'ready' => $this->canAccessStep(2)],
            3 => ['label' => 'Build Boxes', 'ready' => $this->canAccessStep(3)],
            4 => ['label' => 'Review', 'ready' => $this->canAccessStep(4)],
        ];

        return view('livewire.retail.markets.event-match-wizard', [
            'steps' => $steps,
        ]);
    }
}
