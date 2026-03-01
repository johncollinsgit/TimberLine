<?php

namespace App\Livewire\Retail\Markets;

use App\Models\Event;
use App\Models\EventInstance;
use App\Models\EventBoxPlan;
use App\Models\RetailPlanItem;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\On;
use Livewire\Component;

class EventMatchWizard extends Component
{
    public int $planId = 0;
    public ?int $upcomingEventId = null;
    public ?int $selectedCandidateEventId = null;
    public int $matchWindowDays = 45;
    public int $step = 1;
    public bool $eventChosen = false;
    public bool $matchDecisionMade = false;
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
    public bool $draftSummaryLoaded = false;
    /** @var array<string,mixed> */
    public array $prefillStatus = [
        'state' => 'idle',
        'message' => '',
        'template_row_count' => 0,
    ];

    public function mount(int $planId = 0, ?int $upcomingEventId = null, ?int $selectedCandidateEventId = null, int $matchWindowDays = 45): void
    {
        $this->planId = max(0, $planId);
        $this->upcomingEventId = $upcomingEventId;
        $this->selectedCandidateEventId = $selectedCandidateEventId;
        $this->matchWindowDays = max(14, min(60, $matchWindowDays));
        $this->step = 1;

        $this->loadUpcomingEvent();
        $this->loadSelectedCandidateEvent();
        $this->resetDraftSummary();
    }

    #[On('marketsUpcomingEventSelected')]
    public function handleUpcomingEventSelected(int $eventId): void
    {
        $this->upcomingEventId = $eventId > 0 ? $eventId : null;
        $this->selectedCandidateEventId = null;
        $this->selectedCandidateEvent = null;
        $this->eventChosen = $this->upcomingEventId !== null;
        $this->matchDecisionMade = false;
        $this->startFresh = false;
        $this->selectedScentId = null;
        $this->loadUpcomingEvent();
        $this->resetPrefillStatus();
        $this->loadDraftSummary();
        if ($this->draftHasContent()) {
            $this->matchDecisionMade = true;
        }
        $this->step = 1;
    }

    #[On('scentSelected')]
    public function handleScentSelected(string $key, ?int $scentId = null, ?string $scentName = null): void
    {
        unset($scentName);

        if ($key !== 'markets-stepper') {
            return;
        }

        $this->selectedScentId = $scentId && $scentId > 0 ? (int) $scentId : null;
    }

    #[On('marketsCandidateSelected')]
    public function handleCandidateSelected(int $candidateEventId): void
    {
        $this->selectedCandidateEventId = $candidateEventId > 0 ? $candidateEventId : null;
        $this->startFresh = false;
        $this->resetPrefillStatus();
        $this->loadSelectedCandidateEvent();
    }

    #[On('marketsDraftUpdated')]
    public function handleDraftUpdated(int $eventId): void
    {
        if ((int) ($this->upcomingEventId ?: 0) !== (int) $eventId) {
            return;
        }

        $this->loadDraftSummary();
        if ($this->draftHasContent()) {
            $this->eventChosen = true;
            $this->matchDecisionMade = true;
        }
    }

    #[On('marketsDraftCreated')]
    public function handleDraftCreated(int $upcomingEventId, int $durationDays, int $templateRowCount = 0): void
    {
        $this->upcomingEventId = $upcomingEventId > 0 ? $upcomingEventId : $this->upcomingEventId;
        $this->loadUpcomingEvent();
        $this->selectedCandidateEventId = null;
        $this->selectedCandidateEvent = null;
        $this->startFresh = false;
        $this->loadDraftSummary();

        if ($this->draftHasContent()) {
            $this->eventChosen = true;
            $this->matchDecisionMade = true;
        }

        $days = max(1, min(3, $durationDays));
        $this->setPrefillStatus(
            'applied',
            "Draft created from {$days}-day starter template.",
            $templateRowCount
        );
    }

    #[On('marketsMappingConfirmed')]
    public function handleMappingConfirmed(int $upcomingEventId, int $candidateEventId): void
    {
        $this->upcomingEventId = $upcomingEventId > 0 ? $upcomingEventId : null;
        $this->selectedCandidateEventId = $candidateEventId > 0 ? $candidateEventId : null;
        $this->eventChosen = $this->upcomingEventId !== null;
        $this->matchDecisionMade = $this->selectedCandidateEventId !== null;
        $this->startFresh = false;
        $this->loadUpcomingEvent();
        $this->loadSelectedCandidateEvent();
        $this->loadDraftSummary();
    }

    #[On('marketsPrefillStatusChanged')]
    public function handlePrefillStatusChanged(
        int $upcomingEventId = 0,
        int $candidateEventId = 0,
        string $state = 'info',
        string $message = '',
        int $templateRowCount = 0
    ): void {
        $currentUpcoming = (int) ($this->upcomingEventId ?: 0);
        if ($upcomingEventId > 0 && $currentUpcoming > 0 && $upcomingEventId !== $currentUpcoming) {
            return;
        }

        $currentCandidate = (int) ($this->selectedCandidateEventId ?: 0);
        if ($candidateEventId > 0 && $currentCandidate > 0 && $candidateEventId !== $currentCandidate) {
            return;
        }

        $this->setPrefillStatus($state, $message, $templateRowCount);
    }

    #[On('marketsOpenDraftRequested')]
    public function handleOpenDraftRequested(int $upcomingEventId): void
    {
        if ($upcomingEventId > 0) {
            $this->upcomingEventId = $upcomingEventId;
            $this->loadUpcomingEvent();
        }

        $this->loadDraftSummary();

        if ($this->draftHasContent()) {
            $this->eventChosen = true;
            $this->matchDecisionMade = true;
            $this->step = 3;
        }
    }

    public function goToStep(int $step): void
    {
        if ($step >= 4 && ! $this->draftSummaryLoaded) {
            $this->loadDraftSummary();
        }

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
        // Progression is handled by explicit step actions and step-nav clicks.
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

        $this->eventChosen = true;
        $this->matchDecisionMade = true;
        $this->startFresh = false;
        $this->setPrefillStatus('applying', 'Checking the historical event and copying boxes into this draft...', 0);
        $this->dispatch('marketsMappingConfirmed', upcomingEventId: (int) $this->upcomingEventId, candidateEventId: (int) $this->selectedCandidateEventId);
        $this->step = 3;
    }

    public function startFreshDraft(): void
    {
        if (! $this->upcomingEventId) {
            return;
        }

        $this->eventChosen = true;
        $this->matchDecisionMade = true;
        $this->selectedCandidateEventId = null;
        $this->selectedCandidateEvent = null;
        $this->startFresh = true;
        $this->setPrefillStatus('start_fresh', 'Starting with an empty draft. Add a scent to begin building boxes.', 0);
        $this->loadDraftSummary();
        $this->step = 3;
    }

    public function publish(): void
    {
        if (! $this->canAccessStep(4)) {
            return;
        }

        $this->dispatch('marketsPublishRequested', upcomingEventId: (int) ($this->upcomingEventId ?? 0));
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

        $event = EventInstance::query()
            ->with(['boxPlans' => fn ($query) => $query->orderByDesc('box_count_sent')->orderBy('id')])
            ->select(['id', 'title', 'starts_at', 'ends_at', 'state', 'notes', 'source_sheet'])
            ->find($eventId);
        if (! $event) {
            $this->selectedCandidateEvent = null;

            return;
        }

        $this->selectedCandidateEvent = [
            'id' => (int) $event->id,
            'title' => (string) ($event->title ?: 'Historical event'),
            'starts_at' => $event->starts_at?->toDateString(),
            'ends_at' => $event->ends_at?->toDateString(),
            'state' => (string) ($event->state ?? ''),
            'notes_snippet' => $event->notes ? mb_strimwidth((string) $event->notes, 0, 160, '...') : '',
            'source_sheet' => (string) ($event->source_sheet ?? ''),
            'box_preview' => $event->boxPlans->take(4)->map(function (EventBoxPlan $line): array {
                return [
                    'scent_raw' => (string) $line->scent_raw,
                    'box_count_sent' => $line->box_count_sent !== null ? (float) $line->box_count_sent : null,
                    'is_split_box' => (bool) $line->is_split_box,
                ];
            })->all(),
            'box_plan_count' => $event->boxPlans->count(),
        ];
    }

    protected function resetDraftSummary(): void
    {
        $this->draftSummaryLoaded = false;
        $this->draftSummary = [
            'line_count' => 0,
            'unit_count' => 0,
            'top_scents' => [],
        ];
    }

    protected function resetPrefillStatus(): void
    {
        $this->prefillStatus = [
            'state' => 'idle',
            'message' => '',
            'template_row_count' => 0,
        ];
    }

    protected function setPrefillStatus(string $state, string $message, int $templateRowCount = 0): void
    {
        $this->prefillStatus = [
            'state' => trim($state) !== '' ? $state : 'info',
            'message' => trim($message),
            'template_row_count' => max(0, $templateRowCount),
        ];
    }

    protected function loadDraftSummary(): void
    {
        $this->resetDraftSummary();

        if ($this->planId <= 0 || ! $this->supportsRetailPlanItemUpcomingEventColumn() || ! $this->upcomingEventId) {
            $this->draftSummaryLoaded = true;
            return;
        }

        $items = RetailPlanItem::query()
            ->select(['id', 'scent_id', 'quantity'])
            ->where('retail_plan_id', $this->planId)
            ->where('status', '!=', 'published')
            ->where('upcoming_event_id', $this->upcomingEventId)
            ->whereIn('source', RetailPlanItem::marketDraftSources())
            ->with(['scent:id,name,display_name'])
            ->get();

        $this->draftSummaryLoaded = true;

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
            return $this->eventChosen;
        }

        if ($step === 3) {
            return $this->eventChosen && $this->matchDecisionMade;
        }

        if ($step === 4) {
            return $this->eventChosen && $this->matchDecisionMade && $this->draftHasContent();
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
