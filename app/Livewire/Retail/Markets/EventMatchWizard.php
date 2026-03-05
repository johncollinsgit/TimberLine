<?php

namespace App\Livewire\Retail\Markets;

use App\Models\Event;
use App\Models\EventBoxPlan;
use App\Models\EventInstance;
use App\Models\RetailPlanItem;
use App\Models\Scent;
use App\Services\MarketDurationTemplateService;
use App\Services\MarketEventSyncCoordinator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\On;
use Livewire\Component;

class EventMatchWizard extends Component
{
    public int $planId = 0;
    public ?int $selectedUpcomingEventId = null;
    public ?int $upcomingEventId = null;
    public ?int $selectedCandidateEventId = null;
    public ?int $selectedMatchId = null;
    public int $matchWindowDays = 45;
    public int $step = 1;
    public bool $eventChosen = false;
    public bool $matchDecisionMade = false;
    public bool $startFresh = false;
    public ?int $selectedScentId = null;
    public bool $matchScanRan = false;
    public ?int $lastAutoScannedUpcomingEventId = null;
    public bool $templatesOpen = false;
    public ?string $selectedTemplateKey = null;
    public int $draftBoxTotal = 0;
    public ?string $uiError = null;

    /** @var array<int,array<string,mixed>> */
    public array $upcomingEvents = [];

    /** @var array<string,mixed>|null */
    public ?array $upcomingEvent = null;

    /** @var array<int,array<string,mixed>> */
    public array $matches = [];

    /** @var array<string,mixed>|null */
    public ?array $selectedCandidateEvent = null;

    /** @var array<string,mixed> */
    public array $draftSummary = [
        'line_count' => 0,
        'unit_count' => 0,
        'total_boxes' => 0.0,
        'standard_line_count' => 0,
        'top_shelf_line_count' => 0,
        'can_submit' => false,
        'top_scents' => [],
        'breakdown' => [],
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
        $this->upcomingEventId = $upcomingEventId && $upcomingEventId > 0 ? $upcomingEventId : null;
        $this->selectedUpcomingEventId = $this->upcomingEventId;
        $this->selectedCandidateEventId = $selectedCandidateEventId && $selectedCandidateEventId > 0 ? $selectedCandidateEventId : null;
        $this->selectedMatchId = $this->selectedCandidateEventId;
        $this->matchWindowDays = max(14, min(60, $matchWindowDays));
        $this->step = 1;
        $this->eventChosen = $this->upcomingEventId !== null;
        $this->matchDecisionMade = $this->selectedMatchId !== null;

        $this->loadSelectableUpcomingEvents();
        $this->loadUpcomingEvent();
        $this->loadSelectedCandidateEvent();

        if ($this->selectedCandidateEvent) {
            $this->draftBoxTotal = (int) ($this->selectedCandidateEvent['draft_box_total'] ?? 0);
        }

        if ($this->upcomingEventId) {
            $this->loadDraftSummary();

            if ($this->draftHasContent()) {
                $this->matchDecisionMade = true;
            }
        } else {
            $this->resetDraftSummary();
        }
    }

    #[On('marketsUpcomingEventSelected')]
    public function handleUpcomingEventSelected(int $eventId): void
    {
        $this->selectUpcomingEvent($eventId);
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
        $this->selectMatch($candidateEventId);
    }

    #[On('marketsDraftUpdated')]
    public function handleDraftUpdated(int $eventId): void
    {
        if ((int) ($this->upcomingEventId ?: 0) !== (int) $eventId) {
            return;
        }

        $this->loadDraftSummary();
        $this->loadSelectableUpcomingEvents();

        if ($this->draftHasContent()) {
            $this->eventChosen = true;
            $this->matchDecisionMade = true;
        }
    }

    #[On('marketsDraftReset')]
    public function handleDraftReset(int $eventId, int $deletedRows = 0): void
    {
        unset($deletedRows);

        if ((int) ($this->upcomingEventId ?: 0) !== (int) $eventId) {
            return;
        }

        $this->loadDraftSummary();
        $this->loadSelectableUpcomingEvents();
        $this->step = 2;
        $this->matchDecisionMade = $this->hasSelectedMatchOrTemplate();
        $this->setPrefillStatus(
            'start_fresh',
            'Draft reset. Choose a historical match or starter template to rebuild.',
            0
        );
    }

    #[On('marketsDraftCreated')]
    public function handleDraftCreated(int $upcomingEventId, int $durationDays, int $templateRowCount = 0): void
    {
        $this->upcomingEventId = $upcomingEventId > 0 ? $upcomingEventId : $this->upcomingEventId;
        $this->selectedUpcomingEventId = $this->upcomingEventId;
        $this->eventChosen = $this->upcomingEventId !== null;
        $this->startFresh = false;
        $this->loadUpcomingEvent();
        $this->loadDraftSummary();
        $this->loadSelectableUpcomingEvents();

        if ($this->draftHasContent()) {
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
        $this->selectedUpcomingEventId = $this->upcomingEventId;
        $this->selectedMatchId = $candidateEventId > 0 ? $candidateEventId : null;
        $this->selectedCandidateEventId = $this->selectedMatchId;
        $this->eventChosen = $this->upcomingEventId !== null;
        $this->matchDecisionMade = $this->selectedMatchId !== null;
        $this->startFresh = false;
        $this->loadUpcomingEvent();
        $this->loadSelectedCandidateEvent();
        $this->loadDraftSummary();
        $this->loadSelectableUpcomingEvents();
        $this->draftBoxTotal = (int) ($this->selectedCandidateEvent['draft_box_total'] ?? $this->draftBoxTotal);
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

        $currentCandidate = (int) ($this->selectedMatchId ?: $this->selectedCandidateEventId ?: 0);
        if ($candidateEventId > 0 && $currentCandidate > 0 && $candidateEventId !== $currentCandidate) {
            return;
        }

        $this->setPrefillStatus($state, $message, $templateRowCount);

        if ($state === 'error' && trim($message) !== '') {
            $this->uiError = trim($message);
        } elseif ($state === 'applied') {
            $this->uiError = null;
        }
    }

    #[On('marketsOpenDraftRequested')]
    public function handleOpenDraftRequested(int $upcomingEventId): void
    {
        if ($upcomingEventId > 0) {
            $this->upcomingEventId = $upcomingEventId;
            $this->selectedUpcomingEventId = $upcomingEventId;
            $this->eventChosen = true;
            $this->loadUpcomingEvent();
        }

        $this->loadDraftSummary();
        $this->loadSelectableUpcomingEvents();

        if ($this->draftHasContent()) {
            $this->matchDecisionMade = true;
            $this->step = 3;
        }
    }

    public function updatedStep(mixed $value): void
    {
        unset($value);

        $this->maybeAutoScanStep2();
    }

    public function updatedUpcomingEventId(mixed $value): void
    {
        $this->upcomingEventId = $value ? (int) $value : null;

        $this->maybeAutoScanStep2();
    }

    public function selectUpcomingEvent(int $eventId): void
    {
        if ($eventId <= 0) {
            return;
        }

        $this->selectedUpcomingEventId = $eventId;
        $this->uiError = null;

        if ((int) ($this->upcomingEventId ?: 0) !== $eventId) {
            $this->upcomingEventId = null;
            $this->upcomingEvent = null;
            $this->eventChosen = false;
            $this->matchDecisionMade = false;
            $this->startFresh = false;
            $this->selectedScentId = null;
            $this->resetPrefillStatus();
            $this->resetDraftSummary();
            $this->resetStep2State();
        }

        $this->step = 1;
    }

    public function goNextFromStep1(): void
    {
        if (! $this->selectedUpcomingEventId) {
            return;
        }

        if ((int) ($this->upcomingEventId ?: 0) !== (int) $this->selectedUpcomingEventId) {
            $this->upcomingEventId = (int) $this->selectedUpcomingEventId;
            $this->eventChosen = true;
            $this->matchDecisionMade = false;
            $this->startFresh = false;
            $this->selectedScentId = null;
            $this->uiError = null;

            $this->loadUpcomingEvent();
            $this->resetPrefillStatus();
            $this->resetStep2State();
            $this->loadDraftSummary();

            if ($this->draftHasContent()) {
                $this->matchDecisionMade = true;
            }
        }

        $this->loadDraftSummary();
        if ($this->draftHasContent() || $this->hasSelectedMatchOrTemplate()) {
            $this->matchDecisionMade = true;
        }

        $this->step = 2;
        $this->maybeAutoScanStep2();
    }

    public function goToStep(int $step): void
    {
        if ($step >= 4 && ! $this->draftSummaryLoaded) {
            $this->loadDraftSummary();
        }

        if ($this->canAccessStep($step)) {
            $this->step = $step;
            $this->maybeAutoScanStep2();
        }
    }

    public function goBack(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function back(): void
    {
        $this->goBack();
    }

    public function next(): void
    {
        // Progression is handled by explicit step actions and step-nav clicks.
    }

    public function scanHistoricalMatches(): void
    {
        $this->matchScanRan = true;
        $this->matches = [];
        $this->selectedMatchId = null;
        $this->selectedCandidateEventId = null;
        $this->selectedCandidateEvent = null;
        $this->uiError = null;

        if (! $this->selectedTemplateKey) {
            $this->draftBoxTotal = 0;
        }

        if (! $this->upcomingEventId) {
            return;
        }

        try {
            $this->matches = $this->loadMatchesForUpcoming((int) $this->upcomingEventId);
        } catch (\Throwable $e) {
            Log::error('EventMatchWizard historical scan failed', [
                'upcoming_event_id' => $this->upcomingEventId,
                'window_days' => $this->matchWindowDays,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            $this->uiError = 'Historical match scan failed. Try again or use a starter template.';
            $this->matches = [];
        }
    }

    public function runMatchSearch(): void
    {
        $this->scanHistoricalMatches();
    }

    public function selectMatch(int $matchId): void
    {
        if ($matchId <= 0) {
            return;
        }

        $match = collect($this->matches)->first(
            fn (array $row): bool => (int) ($row['event_id'] ?? 0) === $matchId
        );

        if (! $match) {
            return;
        }

        $this->selectedMatchId = $matchId;
        $this->selectedCandidateEventId = $matchId;
        $this->selectedTemplateKey = null;
        $this->loadSelectedCandidateEvent();
        $this->draftBoxTotal = (int) ($match['draft_box_total'] ?? $this->selectedCandidateEvent['draft_box_total'] ?? 0);
        $this->uiError = null;
    }

    public function selectTemplate(string $key): void
    {
        $template = $this->templateDefinitionForKey($key);
        if (! $template) {
            return;
        }

        $this->selectedTemplateKey = (string) $template['key'];
        $this->selectedMatchId = null;
        $this->selectedCandidateEventId = null;
        $this->selectedCandidateEvent = null;
        $this->templatesOpen = false;
        $this->draftBoxTotal = (int) ($template['draft_box_total'] ?? 0);
        $this->uiError = null;
    }

    public function applyMatchAndBuildDraft(): void
    {
        $this->uiError = null;

        try {
            if (! $this->upcomingEventId) {
                $this->uiError = 'No event selected.';

                return;
            }

            if (! $this->selectedMatchId && ! $this->selectedTemplateKey) {
                $this->uiError = 'Select a match or a template first.';

                return;
            }

            $this->eventChosen = true;
            $this->matchDecisionMade = true;
            $this->startFresh = false;

            if ($this->selectedMatchId) {
                if (! EventInstance::query()->whereKey($this->selectedMatchId)->exists()) {
                    $this->uiError = 'The selected historical match no longer exists. Pick another match.';

                    return;
                }

                $this->selectedCandidateEventId = $this->selectedMatchId;
                $this->setPrefillStatus('applying', 'Checking the historical event and copying boxes into this draft...', 0);

                Log::info('EventMatchWizard apply match requested', [
                    'upcoming_event_id' => $this->upcomingEventId,
                    'selected_match_id' => $this->selectedMatchId,
                ]);

                $this->dispatch(
                    'marketsMappingConfirmed',
                    upcomingEventId: (int) $this->upcomingEventId,
                    candidateEventId: (int) $this->selectedMatchId
                );
            } else {
                $template = $this->templateDefinitionForKey($this->selectedTemplateKey);
                if (! $template) {
                    $this->uiError = 'The selected template is no longer available.';

                    return;
                }

                $durationDays = (int) ($template['day_count'] ?? 0);
                if ($durationDays <= 0) {
                    $this->uiError = 'The selected template is invalid.';

                    return;
                }

                $this->setPrefillStatus(
                    'applying',
                    'Applying starter template and building the draft...',
                    (int) ($template['scent_count'] ?? 0)
                );

                Log::info('EventMatchWizard apply template requested', [
                    'upcoming_event_id' => $this->upcomingEventId,
                    'selected_template_key' => $this->selectedTemplateKey,
                    'duration_days' => $durationDays,
                ]);

                $this->dispatch(
                    'marketsDurationTemplateRequested',
                    upcomingEventId: (int) $this->upcomingEventId,
                    durationDays: $durationDays
                );
            }

            $this->step = 3;
        } catch (\Throwable $e) {
            Log::error('applyMatchAndBuildDraft failed', [
                'upcoming_event_id' => $this->upcomingEventId,
                'selected_match_id' => $this->selectedMatchId,
                'selected_template_key' => $this->selectedTemplateKey,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            $this->uiError = 'Something blew up while building the draft. Check logs for the exception.';
        }
    }

    public function useSelectedMatch(): void
    {
        $this->applyMatchAndBuildDraft();
    }

    public function startFreshDraft(): void
    {
        if (! $this->upcomingEventId) {
            return;
        }

        $this->uiError = null;
        $this->eventChosen = true;
        $this->matchDecisionMade = true;
        $this->selectedMatchId = null;
        $this->selectedTemplateKey = null;
        $this->selectedCandidateEventId = null;
        $this->selectedCandidateEvent = null;
        $this->draftBoxTotal = 0;
        $this->startFresh = true;
        $this->setPrefillStatus('start_fresh', 'Starting with an empty draft. Add a scent to begin building boxes.', 0);
        $this->loadDraftSummary();
        $this->step = 3;
    }

    public function resetDraft(): void
    {
        if (! $this->upcomingEventId) {
            return;
        }

        $this->dispatch('marketsResetDraftRequested', upcomingEventId: (int) $this->upcomingEventId);
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

    public function selectedMatchLabel(): string
    {
        if (! empty($this->selectedCandidateEvent['title'])) {
            return (string) $this->selectedCandidateEvent['title'];
        }

        $match = collect($this->matches)->first(
            fn (array $row): bool => (int) ($row['event_id'] ?? 0) === (int) ($this->selectedMatchId ?: 0)
        );

        return $match ? (string) ($match['title'] ?? 'Historical event') : 'Historical event';
    }

    public function selectedTemplateLabel(): string
    {
        $template = $this->templateDefinitionForKey($this->selectedTemplateKey);

        return $template ? (string) ($template['title'] ?? 'Starter Template') : 'Starter Template';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function templateOptions(): array
    {
        return collect(app(MarketDurationTemplateService::class)->templates())
            ->map(function (array $template): array {
                $dayCount = max(1, min(3, (int) ($template['day_count'] ?? 1)));
                $averageBoxes = (float) ($template['average_boxes'] ?? 0);
                $scentCount = (int) ($template['scent_count'] ?? 0);

                return [
                    'key' => 'duration:'.$dayCount,
                    'day_count' => $dayCount,
                    'title' => (string) ($template['label'] ?? "{$dayCount}-Day Starter"),
                    'meta' => 'Avg '.rtrim(rtrim(number_format($averageBoxes, 1), '0'), '.').' boxes'
                        .' · '.$scentCount.' scents',
                    'draft_box_total' => (int) round($averageBoxes),
                    'scent_count' => $scentCount,
                    'available' => (bool) ($template['available'] ?? false),
                ];
            })
            ->filter(fn (array $template): bool => (bool) ($template['available'] ?? false))
            ->values()
            ->all();
    }

    protected function loadSelectableUpcomingEvents(): void
    {
        $query = Event::query()
            ->select(['id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state', 'venue'])
            ->whereNotNull('starts_at')
            ->whereDate('starts_at', '>=', now()->startOfDay()->toDateString())
            ->whereDate('starts_at', '<=', now()->addDays(30)->endOfDay()->toDateString())
            ->orderBy('starts_at')
            ->orderBy('display_name')
            ->limit(50);

        $events = $query->get();

        if ($events->isEmpty()) {
            $events = Event::query()
                ->select(['id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state', 'venue'])
                ->whereNotNull('starts_at')
                ->whereDate('starts_at', '>=', now()->startOfDay()->toDateString())
                ->orderBy('starts_at')
                ->orderBy('display_name')
                ->limit(50)
                ->get();
        }

        $draftCounts = [];
        $eventIds = $events->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($this->supportsRetailPlanItemUpcomingEventColumn() && $eventIds !== []) {
            $draftCounts = RetailPlanItem::query()
                ->selectRaw('upcoming_event_id, COUNT(*) as row_count')
                ->when($this->planId > 0, fn ($query) => $query->where('retail_plan_id', $this->planId))
                ->whereNotNull('upcoming_event_id')
                ->where('status', '!=', 'published')
                ->whereIn('upcoming_event_id', $eventIds)
                ->whereIn('source', RetailPlanItem::marketDraftSources())
                ->groupBy('upcoming_event_id')
                ->pluck('row_count', 'upcoming_event_id')
                ->map(fn ($count) => (int) $count)
                ->all();
        }

        $this->upcomingEvents = $events
            ->map(function (Event $event) use ($draftCounts): array {
                $eventId = (int) $event->id;

                return [
                    'id' => $eventId,
                    'name' => (string) $event->name,
                    'display_name' => (string) ($event->display_name ?? ''),
                    'starts_at' => $event->starts_at?->toDateString(),
                    'ends_at' => $event->ends_at?->toDateString(),
                    'city' => (string) ($event->city ?? ''),
                    'state' => (string) ($event->state ?? ''),
                    'venue' => (string) ($event->venue ?? ''),
                    'draft_rows_count' => (int) ($draftCounts[$eventId] ?? 0),
                ];
            })
            ->values()
            ->all();
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
        $eventId = (int) ($this->selectedMatchId ?: $this->selectedCandidateEventId ?: 0);
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
            'box_preview' => $event->boxPlans->map(function (EventBoxPlan $line): array {
                return [
                    'scent_raw' => (string) $line->scent_raw,
                    'box_count_sent' => $line->box_count_sent !== null ? (float) $line->box_count_sent : null,
                    'is_split_box' => (bool) $line->is_split_box,
                ];
            })->all(),
            'box_plan_count' => $event->boxPlans->count(),
            'draft_box_total' => (int) round((float) $event->boxPlans->sum(fn (EventBoxPlan $line) => (float) ($line->box_count_sent ?? 0))),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function loadMatchesForUpcoming(int $upcomingEventId): array
    {
        $upcoming = Event::query()
            ->select(['id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state', 'source_ref'])
            ->find($upcomingEventId);

        if (! $upcoming || ! $upcoming->starts_at) {
            return [];
        }

        $window = max(14, min(60, (int) $this->matchWindowDays));
        $version = app(MarketEventSyncCoordinator::class)->matchingCacheVersion();
        $cacheKey = "markets:event-instances:v2:wizard:{$version}:{$upcoming->id}:{$window}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($upcoming, $window): array {
            $startedAt = microtime(true);
            $rows = $this->rankMatchesForUpcoming($upcoming, $window);

            Log::info('EventMatchWizard cache miss', [
                'upcoming_event_id' => $upcoming->id,
                'window_days' => $window,
                'candidate_count' => count($rows),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $rows;
        });
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function rankMatchesForUpcoming(Event $upcoming, int $window): array
    {
        $upcomingTitle = (string) ($upcoming->display_name ?: $upcoming->name);
        $upcomingSeriesKey = EventInstance::seriesKey($upcomingTitle);
        $upcomingTokens = array_values(array_filter(explode(' ', $upcomingSeriesKey), fn (string $token): bool => strlen($token) >= 2));
        $upcomingState = trim((string) ($upcoming->state ?? ''));
        $tokensForSql = array_slice(array_values(array_unique($upcomingTokens)), 0, 2);
        $pool = $this->candidatePoolForUpcoming(
            $upcoming,
            $tokensForSql,
            preferSameState: $upcomingState !== '',
            applyTokenFilter: ! empty($tokensForSql)
        );

        if ($pool->isEmpty() && ! empty($tokensForSql)) {
            $pool = $this->candidatePoolForUpcoming(
                $upcoming,
                $tokensForSql,
                preferSameState: $upcomingState !== '',
                applyTokenFilter: false
            );
        }

        if ($pool->isEmpty() && $upcomingState !== '') {
            $pool = $this->candidatePoolForUpcoming(
                $upcoming,
                $tokensForSql,
                preferSameState: false,
                applyTokenFilter: ! empty($tokensForSql)
            );
        }

        if ($pool->isEmpty() && $upcomingState !== '' && ! empty($tokensForSql)) {
            $pool = $this->candidatePoolForUpcoming(
                $upcoming,
                $tokensForSql,
                preferSameState: false,
                applyTokenFilter: false
            );
        }

        if ($pool->isEmpty()) {
            return [];
        }

        $ranked = $pool
            ->map(function (EventInstance $instance) use ($upcoming, $upcomingState, $upcomingSeriesKey, $upcomingTokens, $window): ?array {
                $seriesKey = EventInstance::seriesKey($instance->title);
                $daysDiff = EventInstance::dayDistance($upcoming->starts_at, $instance->starts_at);
                if ($daysDiff === null) {
                    return null;
                }

                $titleScore = $this->titleScore($upcomingSeriesKey, $seriesKey, $upcomingTokens);
                $dateScore = max(0.0, 1 - (min($daysDiff, $window * 2) / max(1, $window * 2)));
                $stateScore = ($upcomingState !== '' && trim((string) ($instance->state ?? '')) === $upcomingState) ? 1.0 : 0.6;
                $matchScore = ($titleScore * 0.6) + ($dateScore * 0.3) + ($stateScore * 0.1);

                if ($titleScore < 0.2 && $daysDiff > $window) {
                    return null;
                }

                return [
                    'event_id' => (int) $instance->id,
                    'title' => (string) $instance->title,
                    'starts_at' => $instance->starts_at?->toDateString(),
                    'ends_at' => $instance->ends_at?->toDateString(),
                    'state' => (string) ($instance->state ?? ''),
                    'match_score' => $matchScore,
                    'match_score_percent' => (int) round($matchScore * 100),
                    'title_score_percent' => (int) round($titleScore * 100),
                    'date_score_percent' => (int) round($dateScore * 100),
                    'location_score_percent' => (int) round($stateScore * 100),
                    'days_diff' => $daysDiff,
                    'notes_snippet' => $instance->notes ? mb_strimwidth((string) $instance->notes, 0, 140, '...') : '',
                ];
            })
            ->filter()
            ->sortByDesc('match_score')
            ->take(10)
            ->values();

        if ($ranked->isEmpty()) {
            return [];
        }

        $topIds = $ranked->pluck('event_id')->all();
        $instancesWithPlans = EventInstance::query()
            ->with(['boxPlans' => fn ($query) => $query->orderByDesc('box_count_sent')->orderBy('id')])
            ->whereIn('id', $topIds)
            ->get(['id', 'title', 'notes', 'starts_at', 'ends_at', 'state'])
            ->keyBy('id');

        return $ranked
            ->map(function (array $row) use ($instancesWithPlans): array {
                /** @var EventInstance|null $instance */
                $instance = $instancesWithPlans->get((int) $row['event_id']);

                if (! $instance) {
                    $row['box_plan_count'] = 0;
                    $row['box_preview'] = [];
                    $row['top_scent'] = null;
                    $row['draft_box_total'] = 0;

                    return $row;
                }

                /** @var EventBoxPlan|null $firstLine */
                $firstLine = $instance->boxPlans->first();

                $row['box_plan_count'] = $instance->boxPlans->count();
                $row['box_preview'] = $instance->boxPlans->map(function (EventBoxPlan $line): array {
                    return [
                        'scent_raw' => (string) $line->scent_raw,
                        'box_count_sent' => $line->box_count_sent !== null ? (float) $line->box_count_sent : null,
                        'is_split_box' => (bool) $line->is_split_box,
                    ];
                })->all();
                $row['top_scent'] = $firstLine?->scent_raw;
                $row['draft_box_total'] = (int) round((float) $instance->boxPlans->sum(
                    fn (EventBoxPlan $line) => (float) ($line->box_count_sent ?? 0)
                ));

                return $row;
            })
            ->values()
            ->all();
    }

    protected function candidatePoolForUpcoming(
        Event $upcoming,
        array $tokensForSql,
        bool $preferSameState,
        bool $applyTokenFilter
    ) {
        $query = EventInstance::query()
            ->whereNotNull('starts_at')
            ->whereDate('starts_at', '<', $upcoming->starts_at->toDateString());

        $upcomingState = trim((string) ($upcoming->state ?? ''));
        if ($preferSameState && $upcomingState !== '') {
            $query->where('state', $upcomingState);
        }

        if ($applyTokenFilter && ! empty($tokensForSql)) {
            $query->where(function ($innerQuery) use ($tokensForSql): void {
                foreach ($tokensForSql as $token) {
                    $innerQuery->orWhere('title', 'like', '%'.$token.'%');
                }
            });
        }

        return $query
            ->orderByDesc('starts_at')
            ->limit(400)
            ->get(['id', 'title', 'starts_at', 'ends_at', 'state', 'notes']);
    }

    protected function titleScore(string $upcomingSeriesKey, string $seriesKey, array $upcomingTokens): float
    {
        if ($upcomingSeriesKey === '' || $seriesKey === '') {
            return 0.0;
        }

        similar_text($upcomingSeriesKey, $seriesKey, $similarityPercent);
        $similarity = $similarityPercent / 100;

        $matchingTokens = 0;
        foreach ($upcomingTokens as $token) {
            if (str_contains($seriesKey, $token)) {
                $matchingTokens++;
            }
        }
        $tokenScore = count($upcomingTokens) > 0 ? ($matchingTokens / count($upcomingTokens)) : 0;

        return min(1.0, ($similarity * 0.7) + ($tokenScore * 0.3));
    }

    protected function templateDefinitionForKey(?string $key): ?array
    {
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        $normalizedKey = trim($key);

        return collect($this->templateOptions())->first(
            fn (array $template): bool => (string) ($template['key'] ?? '') === $normalizedKey
        );
    }

    public function resetStep2State(): void
    {
        $this->matches = [];
        $this->matchScanRan = false;
        $this->templatesOpen = false;
        $this->selectedTemplateKey = null;
        $this->selectedMatchId = null;
        $this->selectedCandidateEventId = null;
        $this->selectedCandidateEvent = null;
        $this->draftBoxTotal = 0;
        $this->uiError = null;
    }

    private function maybeAutoScanStep2(): void
    {
        if ((int) $this->step !== 2) {
            return;
        }

        $eventId = (int) ($this->upcomingEventId ?? 0);
        if ($eventId <= 0) {
            return;
        }

        if ($this->lastAutoScannedUpcomingEventId === $eventId) {
            return;
        }

        $this->scanHistoricalMatches();
        $this->lastAutoScannedUpcomingEventId = $eventId;
    }

    protected function resetDraftSummary(): void
    {
        $this->draftSummaryLoaded = false;
        $this->draftSummary = [
            'line_count' => 0,
            'unit_count' => 0,
            'total_boxes' => 0.0,
            'standard_line_count' => 0,
            'top_shelf_line_count' => 0,
            'can_submit' => false,
            'top_scents' => [],
            'breakdown' => [],
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
            ->select(['id', 'scent_id', 'quantity', 'box_tier', 'notes'])
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

        $breakdown = [];
        $totalBoxes = 0.0;
        $standardLineCount = 0;
        $topShelfLineCount = 0;
        $canSubmit = true;

        $standardScentNames = $items
            ->filter(fn (RetailPlanItem $item): bool => (int) ($item->scent_id ?? 0) > 0)
            ->mapWithKeys(fn (RetailPlanItem $item): array => [
                (int) $item->scent_id => (string) ($item->scent?->display_name ?: $item->scent?->name ?: "Scent #".(int) $item->scent_id),
            ])
            ->all();

        foreach ($items as $item) {
            $boxTier = $this->normalizeBoxTier((string) ($item->box_tier ?? 'standard'));
            $quantity = max(1, (int) ($item->quantity ?? 1));
            $rawQuantity = (int) ($item->quantity ?? 0);
            if ($rawQuantity <= 0) {
                $canSubmit = false;
            }

            if ($boxTier === 'top_shelf') {
                $topShelfLineCount++;
                $totalBoxes += $quantity;

                $configuration = RetailPlanItem::decodeTopShelfConfiguration($item->notes, $item->scent_id ? (int) $item->scent_id : null);
                if (! RetailPlanItem::topShelfConfigurationIsComplete($configuration)) {
                    $canSubmit = false;
                }
                foreach ((array) ($configuration['composition'] ?? []) as $slot) {
                    $scentId = (int) ($slot['scent_id'] ?? 0);
                    $unitsPerBox = max(0, (int) ($slot['units_per_box'] ?? 0));

                    if ($scentId <= 0 || $unitsPerBox <= 0) {
                        continue;
                    }

                    if (! isset($breakdown[$scentId])) {
                        $breakdown[$scentId] = [
                            'scent_id' => $scentId,
                            'name' => (string) ($standardScentNames[$scentId] ?? "Scent #{$scentId}"),
                            'standard_boxes' => 0.0,
                            'top_shelf_units' => 0,
                            'total_weight' => 0,
                        ];
                    }

                    $slotUnits = $quantity * $unitsPerBox;
                    $breakdown[$scentId]['top_shelf_units'] += $slotUnits;
                    $breakdown[$scentId]['total_weight'] += $slotUnits;
                }

                continue;
            }

            $standardLineCount++;
            $standardBoxes = $quantity / 2;
            $totalBoxes += $standardBoxes;
            $scentId = (int) ($item->scent_id ?? 0);

            if ($scentId <= 0) {
                $canSubmit = false;
                continue;
            }

            if (! isset($breakdown[$scentId])) {
                $breakdown[$scentId] = [
                    'scent_id' => $scentId,
                    'name' => (string) ($standardScentNames[$scentId] ?? "Scent #{$scentId}"),
                    'standard_boxes' => 0.0,
                    'top_shelf_units' => 0,
                    'total_weight' => 0,
                ];
            }

            $breakdown[$scentId]['standard_boxes'] += $standardBoxes;
            // Standard rows are stored in half-box units; use this for ordering weight.
            $breakdown[$scentId]['total_weight'] += $quantity;
        }

        $missingScentIds = collect($breakdown)
            ->filter(fn (array $row): bool => str_starts_with((string) ($row['name'] ?? ''), 'Scent #'))
            ->pluck('scent_id')
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($missingScentIds !== []) {
            $resolvedNames = Scent::query()
                ->whereIn('id', $missingScentIds)
                ->get(['id', 'name', 'display_name'])
                ->mapWithKeys(fn (Scent $scent): array => [
                    (int) $scent->id => (string) ($scent->display_name ?: $scent->name ?: "Scent #".(int) $scent->id),
                ])
                ->all();

            foreach ($resolvedNames as $scentId => $name) {
                if (isset($breakdown[(int) $scentId])) {
                    $breakdown[(int) $scentId]['name'] = $name;
                }
            }
        }

        $breakdownRows = collect($breakdown)
            ->sortByDesc('total_weight')
            ->values()
            ->map(function (array $row): array {
                $row['standard_boxes'] = (float) $row['standard_boxes'];
                $row['top_shelf_units'] = (int) $row['top_shelf_units'];
                unset($row['total_weight']);

                return $row;
            })
            ->all();

        $topScents = collect($breakdownRows)
            ->take(4)
            ->map(fn (array $row): array => [
                'name' => (string) ($row['name'] ?? 'Unknown'),
                'units' => (int) ($row['top_shelf_units'] ?? 0),
                'standard_boxes' => (float) ($row['standard_boxes'] ?? 0),
            ])
            ->values()
            ->all();

        $this->draftSummary = [
            'line_count' => $items->count(),
            'unit_count' => (int) $items->sum(fn (RetailPlanItem $item) => (int) ($item->quantity ?? 0)),
            'total_boxes' => $totalBoxes,
            'standard_line_count' => $standardLineCount,
            'top_shelf_line_count' => $topShelfLineCount,
            'can_submit' => $canSubmit,
            'top_scents' => $topScents,
            'breakdown' => $breakdownRows,
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
            return $this->eventChosen && ($this->matchDecisionMade || $this->hasSelectedMatchOrTemplate() || $this->draftHasContent());
        }

        if ($step === 4) {
            return $this->eventChosen && $this->draftHasContent() && $this->draftCanSubmit();
        }

        return false;
    }

    protected function draftHasContent(): bool
    {
        return (int) ($this->draftSummary['line_count'] ?? 0) > 0;
    }

    protected function draftCanSubmit(): bool
    {
        return (bool) ($this->draftSummary['can_submit'] ?? false);
    }

    protected function hasSelectedMatchOrTemplate(): bool
    {
        return (int) ($this->selectedMatchId ?: $this->selectedCandidateEventId ?: 0) > 0
            || (is_string($this->selectedTemplateKey) && trim($this->selectedTemplateKey) !== '');
    }

    protected function normalizeBoxTier(string $value): string
    {
        $value = strtolower(trim($value));

        return $value === 'top_shelf' ? 'top_shelf' : 'standard';
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
            'templates' => $this->templateOptions(),
        ]);
    }
}
