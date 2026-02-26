<?php

namespace App\Livewire\Retail;

use App\Jobs\SyncMarketEventsJob;
use App\Models\Event;
use App\Models\EventMatchOverride;
use App\Models\MarketPlan;
use App\Models\MarketPourList;
use App\Models\MarketPourListLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\RetailPlan;
use App\Models\RetailPlanItem;
use App\Models\Scent;
use App\Models\Size;
use App\Services\EventMatchingService;
use App\Services\MarketEventSyncCoordinator;
use App\Support\MarketEvents\RequestMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Component;

class Plan extends Component
{
    public RetailPlan $plan;
    public string $quote = '';
    public string $queue = 'retail';
    // Legacy property kept for Livewire snapshot compatibility after removing the Draft|Events tab UI.
    public string $marketsPanelTab = 'draft';
    public ?int $marketSelectedEventId = null;
    public ?int $marketSelectedHistoryPlanId = null;
    public ?string $marketEventsErrorBanner = null;

    /** @var array<string,mixed> */
    public array $marketEventsSyncSummary = [];
    /** @var array<string,mixed> */
    public array $marketEventsSyncState = [];
    public ?int $selectedUpcomingEventId = null;
    public ?int $selectedCandidateEventId = null;
    public int $matchWindowDays = 30;
    public ?string $marketEventsLastSyncAt = null;
    public bool $marketEventsPanelLoaded = false;

    /** @var array<int,array<string,mixed>> */
    public array $candidatePriorEvents = [];
    /** @var array<int,array<string,mixed>> */
    public array $candidateScoredEvents = [];

    /** @var array<string,mixed> */
    public array $candidatePreview = [];

    /** @var array<int,array<string,mixed>> */
    public array $upcomingMarketEventRows = [];

    protected ?float $marketsRequestProfileStartedAt = null;
    protected int $marketsRequestProfileQueryCount = 0;
    protected float $marketsRequestProfileQueryTimeMs = 0.0;
    protected bool $marketsRequestProfileListenerAttached = false;
    protected ?string $marketsRequestProfileTrigger = null;

    public ?int $inventoryScentId = null;
    public ?int $inventorySizeId = null;
    public int $inventoryQty = 1;
    public string $inventoryScentSearch = '';
    public string $inventorySizeSearch = '';

    protected $listeners = [
        'scentSelected' => 'handleScentSelected',
    ];

    protected $queryString = [
        'queue' => ['except' => 'retail'],
    ];

    public function mount(): void
    {
        $this->startMarketsRequestProfile('mount');
        $this->queue = $this->normalizeQueue($this->queue);
        $this->marketsPanelTab = 'draft';

        $name = $this->queueDisplayName() . ' / Pour List ' . CarbonImmutable::now()->format('Y-m-d');
        $query = RetailPlan::query()
            ->whereDate('created_at', CarbonImmutable::today())
            ->whereNull('published_at');

        if ($this->supportsQueueTypeColumn()) {
            $query->where('queue_type', $this->queue);
        }

        $this->plan = $query->latest('id')->first()
            ?? $this->createDraftPlan($name);
        $this->quote = $this->enneagramQuote();
        $this->marketSelectedEventId = $this->supportsRetailPlanEventColumn()
            ? (int) ($this->plan->event_id ?: 0) ?: null
            : null;
        $this->selectedUpcomingEventId = $this->marketSelectedEventId;

        if ($this->plan->items()->count() === 0) {
            $this->prefillFromOrdersInternal(true);
        }

        if ($this->queue === 'markets' && $this->marketEventsPanelEnabled()) {
            $this->profileMarketsAction('mount', function (): void {
                $this->refreshMarketEventsPanelData();
                if ($this->selectedUpcomingEventId) {
                    $this->loadCandidatePriorEvents();
                }
            });
        }
    }

    public function hydrate(): void
    {
        $this->startMarketsRequestProfile('hydrate');
    }

    public function dehydrate(): void
    {
        $this->finishMarketsRequestProfile();
    }

    public function prefillFromOrders(): void
    {
        $this->prefillFromOrdersInternal(false);
    }

    protected function prefillFromOrdersInternal(bool $silent): void
    {
        if ($this->queue === 'markets') {
            $this->prefillFromMarketDraftsInternal($silent);
            return;
        }

        $orders = Order::query()
            ->where('order_type', $this->orderTypeForQueue())
            ->whereNull('published_at')
            ->whereIn('status', ['new', 'reviewed'])
            ->with(['lines'])
            ->get();

        $added = 0;

        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                $qty = (int) ($line->ordered_qty ?? 0) + (int) ($line->extra_qty ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                RetailPlanItem::updateOrCreate(
                    [
                        'retail_plan_id' => $this->plan->id,
                        'order_line_id' => $line->id,
                    ],
                    [
                        'order_id' => $order->id,
                        'scent_id' => $line->scent_id,
                        'size_id' => $line->size_id,
                        'sku' => $line->sku,
                        'quantity' => $qty,
                        'source' => 'order',
                        'status' => ($line->scent_id && $line->size_id) ? 'draft' : 'needs_mapping',
                    ]
                );

                $added++;
            }
        }

        if (!$silent) {
            $this->dispatch('toast', [
                'type' => 'success',
                'message' => $added > 0
                    ? "Prefilled {$added} ".strtolower($this->queueDisplayName()).' lines.'
                    : 'No new '.strtolower($this->queueDisplayName()).' lines to add.',
            ]);
        }
    }

    protected function prefillFromMarketDraftsInternal(bool $silent): void
    {
        $drafts = MarketPourList::query()
            ->where('status', 'draft')
            ->with(['event:id,name,starts_at', 'lines'])
            ->orderByDesc('id')
            ->get();

        $added = 0;

        foreach ($drafts as $draft) {
            foreach ($draft->lines as $line) {
                $qty = (int) ($line->edited_qty ?? $line->recommended_qty ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                [$scentId, $sizeId, $sku] = $this->resolveMarketDraftLineMapping($line, $draft);
                $rawReason = is_array($line->reason_json) ? $line->reason_json : [];
                $splitScentIds = $scentId
                    ? []
                    : $this->resolveSplitScentIdsFromText((string) ($rawReason['scent'] ?? ''));

                if (count($splitScentIds) === 2) {
                    // A "ABC / XYZ" market box note means one full box split across two scents.
                    // Store each split scent as half-box units (1 = half box).
                    RetailPlanItem::query()
                        ->where('retail_plan_id', $this->plan->id)
                        ->where('source', 'market_box_draft')
                        ->where('sku', $sku)
                        ->delete();

                    foreach (array_values($splitScentIds) as $index => $splitScentId) {
                        $item = RetailPlanItem::query()->updateOrCreate(
                            [
                                'retail_plan_id' => $this->plan->id,
                                'source' => 'market_box_draft',
                                'sku' => $sku.'#split:'.($index + 1),
                            ],
                            [
                                'order_id' => null,
                                'order_line_id' => null,
                                'scent_id' => $splitScentId,
                                'size_id' => $sizeId,
                                'quantity' => max(1, $qty),
                                'status' => 'draft',
                            ]
                        );

                        if ($item->wasRecentlyCreated) {
                            $added++;
                        }
                    }

                    continue;
                }

                if (!$scentId && str_starts_with($sku, 'mktpl:')) {
                    // No usable scent metadata on this draft line; keep it in the draft editor,
                    // but skip the markets planner row until it can be mapped by a human.
                    continue;
                }

                $item = RetailPlanItem::query()->updateOrCreate(
                    [
                        'retail_plan_id' => $this->plan->id,
                        'source' => 'market_box_draft',
                        'sku' => $sku,
                    ],
                    [
                        'order_id' => null,
                        'order_line_id' => null,
                        'scent_id' => $scentId,
                        'size_id' => $sizeId,
                        // Store market-box quantities in half-box units (1=half, 2=full).
                        'quantity' => max(1, $qty * 2),
                        'status' => $scentId ? 'draft' : 'needs_mapping',
                    ]
                );

                if ($item->wasRecentlyCreated) {
                    $added++;
                }
            }
        }

        if (!$silent) {
            $this->dispatch('toast', [
                'type' => 'success',
                'message' => $added > 0
                    ? "Prefilled {$added} market box scents from drafts."
                    : 'No new markets draft lines to add.',
            ]);
        }
    }

    public function setMarketsPanelTab(string $tab): void
    {
        // Backward-compatible no-op: events are now always shown as a dedicated section.
        $this->marketsPanelTab = 'draft';

        if ($this->queue === 'markets' && $this->marketEventsPanelEnabled() && ! $this->marketEventsPanelLoaded) {
            $this->refreshMarketEventsPanelData();
        }
    }

    public function updatedMatchWindowDays(mixed $value): void
    {
        $this->profileMarketsAction('updatedMatchWindowDays', function () use ($value): void {
            $window = (int) $value;
            $this->matchWindowDays = in_array($window, [14, 30, 45, 60], true) ? $window : 30;

            if ($this->queue === 'markets' && $this->selectedUpcomingEventId) {
                $this->loadCandidatePriorEvents();
            }
        });
    }

    public function syncMarketEventsPanel(): void
    {
        $this->profileMarketsAction('syncMarketEventsPanel', function (): void {
            if ($this->queue !== 'markets' || ! $this->marketEventsPanelEnabled()) {
                return;
            }

            if (! (bool) config('features.market_events_sync_enabled', true)) {
                $this->marketEventsErrorBanner = 'Event sync is disabled by feature flag.';
                $this->refreshMarketEventsPanelData();

                $this->dispatch('toast', [
                    'type' => 'warning',
                    'message' => 'Market event sync is disabled (MARKET_EVENTS_SYNC_ENABLED=false).',
                ]);

                return;
            }

            if (! $this->supportsMarketEventSyncStateTable()) {
                $this->marketEventsErrorBanner = 'Event sync status table missing. Run migrations.';
                $this->refreshMarketEventsPanelData();
                $this->dispatch('toast', [
                    'type' => 'warning',
                    'message' => 'Run migrations before using Market event sync.',
                ]);

                return;
            }

            $this->marketEventsErrorBanner = null;
            $coordinator = app(MarketEventSyncCoordinator::class);
            $gate = $coordinator->canQueue(4, false);

            if (! ($gate['allowed'] ?? false)) {
                $reason = (string) ($gate['reason'] ?? 'unknown');
                $this->refreshMarketEventsPanelData();

                $message = match ($reason) {
                    'running' => 'Market event sync is already running.',
                    'cooldown' => 'Market event sync was run recently. Please wait a few minutes or use artisan --force.',
                    default => 'Market event sync is temporarily unavailable.',
                };

                $this->dispatch('toast', [
                    'type' => 'warning',
                    'message' => $message,
                ]);

                return;
            }

            try {
                $coordinator->markQueued(4, auth()->id());
                if ((string) config('queue.default') === 'sync') {
                    SyncMarketEventsJob::dispatchAfterResponse(4, false, auth()->id());
                } else {
                    SyncMarketEventsJob::dispatch(4, false, auth()->id());
                }
                $this->refreshMarketEventsPanelData();

                $this->dispatch('toast', [
                    'type' => 'success',
                    'message' => 'Market event sync queued. The page will keep using stored events until sync completes.',
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to queue market event sync job', [
                    'queue' => $this->queue,
                    'plan_id' => $this->plan->id ?? null,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);

                $this->marketEventsErrorBanner = 'Failed to queue calendar sync job.';
                $this->refreshMarketEventsPanelData();
                $this->dispatch('toast', [
                    'type' => 'error',
                    'message' => 'Failed to queue market event sync: '.$e->getMessage(),
                ]);
            }
        });
    }

    public function refreshMarketEventsSyncStatus(): void
    {
        if ($this->queue !== 'markets' || ! $this->marketEventsPanelEnabled()) {
            return;
        }

        $this->profileMarketsAction('refreshMarketEventsSyncStatus', function (): void {
            $this->refreshMarketEventsPanelData();
        });
    }

    public function selectUpcomingEvent(int $eventId): void
    {
        $this->profileMarketsAction('selectUpcomingEvent', function () use ($eventId): void {
            if ($this->queue !== 'markets') {
                return;
            }

            $event = Event::query()->find($eventId);
            if (! $event) {
                $this->dispatch('toast', [
                    'type' => 'warning',
                    'message' => 'Selected event was not found.',
                ]);
                return;
            }

            $this->selectedUpcomingEventId = (int) $event->id;
            $this->marketSelectedEventId = $this->selectedUpcomingEventId; // legacy alias for existing code paths
            $this->selectedCandidateEventId = null;
            $this->marketSelectedHistoryPlanId = null; // legacy alias
            $this->candidatePreview = [];
            $this->marketEventsErrorBanner = null;

            if ($this->supportsRetailPlanEventColumn()) {
                $this->plan->event_id = $event->id;
                $this->plan->save();
            }

            $this->loadCandidatePriorEvents();
        });
    }

    public function selectCandidateEvent(int $eventId): void
    {
        $this->profileMarketsAction('selectCandidateEvent', function () use ($eventId): void {
            if ($this->queue !== 'markets') {
                return;
            }

            $candidate = Event::query()->with('market')->find($eventId);
            if (! $candidate) {
                $this->dispatch('toast', [
                    'type' => 'warning',
                    'message' => 'Candidate event no longer exists.',
                ]);
                return;
            }

            $this->selectedCandidateEventId = (int) $candidate->id;
            $this->marketSelectedHistoryPlanId = $this->selectedCandidateEventId; // legacy alias
            $this->candidatePreview = $this->buildCandidatePreview($candidate);
        });
    }

    public function mapUpcomingToCandidate(int $upcomingId, int $candidateId): void
    {
        $this->profileMarketsAction('mapUpcomingToCandidate', function () use ($upcomingId, $candidateId): void {
            if ($this->queue !== 'markets') {
                return;
            }

            $upcoming = Event::query()->find($upcomingId);
            $candidate = Event::query()->find($candidateId);

            if (! $upcoming || ! $candidate) {
                $this->dispatch('toast', [
                    'type' => 'warning',
                    'message' => 'Could not save mapping because one of the events was not found.',
                ]);

                return;
            }

            if (! $this->supportsEventMatchOverridesTable()) {
                $this->dispatch('toast', [
                    'type' => 'warning',
                    'message' => 'Mapping override table missing. Run migrations first.',
                ]);

                return;
            }

            EventMatchOverride::query()->updateOrCreate(
                ['upcoming_event_id' => $upcoming->id],
                [
                    'candidate_event_id' => $candidate->id,
                    'created_by_user_id' => auth()->id(),
                ]
            );

            app(MarketEventSyncCoordinator::class)->bumpMatchingCacheVersion();
            $this->selectedUpcomingEventId = (int) $upcoming->id;
            $this->selectedCandidateEventId = (int) $candidate->id;
            $this->candidatePreview = $this->buildCandidatePreview($candidate);
            $this->loadCandidatePriorEvents();

            $this->dispatch('toast', [
                'type' => 'success',
                'message' => 'Saved event mapping override for this upcoming event.',
            ]);
        });
    }

    public function applyCandidatePrefill(): void
    {
        if ($this->queue !== 'markets') {
            return;
        }

        $upcoming = $this->selectedUpcomingEventForPanel();
        if (! $upcoming) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select an upcoming event first.']);
            return;
        }

        $candidate = $this->selectedCandidateEventForPanel();
        if (! $candidate) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select a candidate event to preview/apply.']);
            return;
        }

        $rows = $this->marketPlanRowsForCandidateEvent($candidate);
        if ($rows->isEmpty()) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'No plan found for this candidate event.']);
            return;
        }

        [$added, $merged, $skipped] = $this->applyMarketPlanRowsPrefill($rows, $upcoming, $candidate);

        $this->candidatePreview = $this->buildCandidatePreview($candidate);
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Applied event prefill from ".($candidate->display_name ?: $candidate->name).". Added {$added}, merged {$merged}".($skipped ? ", skipped {$skipped}" : '').'.',
        ]);
    }

    public function clearEventSelection(): void
    {
        $this->selectedCandidateEventId = null;
        $this->marketSelectedHistoryPlanId = null;
        $this->candidatePreview = [];
    }

    public function selectMarketEvent(int $eventId): void
    {
        // Backward-compatible wrapper (older UI path).
        $this->selectUpcomingEvent($eventId);
    }

    public function selectMarketHistoryCandidate(int $marketPlanId): void
    {
        // Backward-compatible wrapper for newer explicit candidate event selection.
        $this->selectCandidateEvent($marketPlanId);
    }

    public function loadMatchedMarketEventBoxes(): void
    {
        // Deprecated fuzzy flow; explicit candidate selection is now required.
        $this->dispatch('toast', [
            'type' => 'warning',
            'message' => 'Choose a candidate event in Events Prefill, then click Apply Prefill.',
        ]);
    }

    public function loadSelectedMarketHistoryBoxes(): void
    {
        // Backward-compatible wrapper for new explicit apply action.
        $this->applyCandidatePrefill();
    }

    protected function loadCandidatePriorEvents(): void
    {
        $this->candidatePriorEvents = [];
        $this->candidateScoredEvents = [];
        $this->selectedCandidateEventId = null;
        $this->marketSelectedHistoryPlanId = null;
        $this->candidatePreview = [];

        $upcoming = $this->selectedUpcomingEventForPanel();
        if (! $upcoming || ! $upcoming->starts_at) {
            return;
        }

        try {
            $summary = $this->cachedCandidateMatchSummary($upcoming, $this->matchWindowDays);
        } catch (\Throwable $e) {
            Log::error('Retail markets candidate lookup failed', [
                'queue' => $this->queue,
                'plan_id' => $this->plan->id ?? null,
                'upcoming_event_id' => $upcoming->id ?? null,
                'window_days' => $this->matchWindowDays,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            $this->marketEventsErrorBanner = 'Failed to load prior-year candidates for the selected event.';

            return;
        }

        $windowCandidates = collect((array) ($summary['window_candidates'] ?? []));
        $this->candidateScoredEvents = array_values((array) ($summary['scored_candidates'] ?? []));

        $override = $this->supportsEventMatchOverridesTable()
            ? EventMatchOverride::query()->where('upcoming_event_id', $upcoming->id)->first()
            : null;
        $overrideCandidateId = (int) ($override?->candidate_event_id ?? 0) ?: null;
        $suggestedCandidateId = $overrideCandidateId
            ?: ((int) ($summary['suggested_candidate_event_id'] ?? 0) ?: null);

        $grouped = [];

        foreach ($windowCandidates as $row) {
            $candidateId = (int) ($row['candidate_event_id'] ?? 0);
            $year = (int) (($row['year'] ?? 0) ?: ($row['match_year'] ?? 0));
            $grouped[$year] ??= [
                'year' => $year,
                'items' => [],
            ];

            $grouped[$year]['items'][] = [
                'event_id' => $candidateId,
                'year' => $year,
                'title' => (string) ($row['title'] ?? 'Untitled Event'),
                'starts_at' => (string) ($row['starts_at'] ?? '') ?: null,
                'ends_at' => (string) ($row['ends_at'] ?? '') ?: null,
                'match_score' => (float) ($row['match_score'] ?? 0),
                'match_score_percent' => (int) round(((float) ($row['match_score'] ?? 0)) * 100),
                'title_score' => (float) ($row['title_score'] ?? 0),
                'date_score' => (float) ($row['date_score'] ?? 0),
                'days_diff' => (int) ($row['days_diff'] ?? 0),
                'days_diff_signed' => (int) ($row['days_diff_signed'] ?? 0),
                'source' => (string) ($row['source'] ?? ''),
                'market_name' => (string) ($row['market_name'] ?? ''),
                'is_suggested' => $suggestedCandidateId !== null && $candidateId === $suggestedCandidateId,
                'is_override' => $overrideCandidateId !== null && $candidateId === $overrideCandidateId,
            ];
        }

        krsort($grouped);
        foreach ($grouped as &$group) {
            usort($group['items'], fn (array $a, array $b) => [
                -1 * (int) round(((float) ($a['match_score'] ?? 0.0)) * 10000),
                $a['days_diff'] ?? 9999,
                $a['starts_at'] ?? '9999-12-31',
                $a['title'] ?? 'zzzz',
            ] <=> [
                -1 * (int) round(((float) ($b['match_score'] ?? 0.0)) * 10000),
                $b['days_diff'] ?? 9999,
                $b['starts_at'] ?? '9999-12-31',
                $b['title'] ?? 'zzzz',
            ]);
        }
        unset($group);

        $this->candidatePriorEvents = array_values($grouped);

        if ($overrideCandidateId) {
            $overrideCandidate = Event::query()->with('market')->find($overrideCandidateId);
            if ($overrideCandidate) {
                $this->selectedCandidateEventId = (int) $overrideCandidate->id;
                $this->marketSelectedHistoryPlanId = $this->selectedCandidateEventId;
                $this->candidatePreview = $this->buildCandidatePreview($overrideCandidate);
            }
        }
    }

    protected function selectedUpcomingEventForPanel(): ?Event
    {
        $eventId = (int) ($this->selectedUpcomingEventId ?: 0);

        return $eventId > 0
            ? Event::query()->with('market')->find($eventId)
            : null;
    }

    protected function selectedCandidateEventForPanel(): ?Event
    {
        $eventId = (int) ($this->selectedCandidateEventId ?: 0);

        return $eventId > 0
            ? Event::query()->select(['id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state'])->find($eventId)
            : null;
    }

    protected function refreshMarketEventsPanelData(): void
    {
        if (! $this->marketEventsPanelEnabled()) {
            return;
        }

        $events = $this->upcomingMarketEventsForPanel();

        $this->upcomingMarketEventRows = $events
            ->map(fn (Event $event) => [
                'id' => (int) $event->id,
                'name' => (string) $event->name,
                'display_name' => (string) ($event->display_name ?? ''),
                'starts_at' => $event->starts_at?->toDateString(),
                'ends_at' => $event->ends_at?->toDateString(),
                'city' => (string) ($event->city ?? ''),
                'state' => (string) ($event->state ?? ''),
            ])
            ->values()
            ->all();

        if ($this->supportsMarketEventSyncStateTable()) {
            $syncState = app(MarketEventSyncCoordinator::class)->queueStatus();
            $this->marketEventsSyncState = $syncState;
            $this->marketEventsSyncSummary = (array) ($syncState['last_result'] ?? []);
            $this->marketEventsLastSyncAt = (string) ($syncState['last_sync_at'] ?? '')
                ?: Event::query()->where('source', 'asana_calendar')->max('updated_at');
        } else {
            $this->marketEventsSyncState = [
                'status' => 'unavailable',
                'last_sync_status' => null,
                'last_error' => 'market_event_sync_states table missing (run migrations).',
                'last_result' => [],
            ];
            $this->marketEventsSyncSummary = [];
            $this->marketEventsLastSyncAt = Event::query()->where('source', 'asana_calendar')->max('updated_at');
        }

        $this->marketEventsPanelLoaded = true;
    }

    /**
     * @return array<string,mixed>
     */
    protected function cachedCandidateMatchSummary(Event $upcoming, int $windowDays): array
    {
        $windowDays = max(1, min(120, $windowDays));
        $version = app(MarketEventSyncCoordinator::class)->matchingCacheVersion();
        $cacheKey = "markets:event-match:v2:{$version}:{$upcoming->id}:{$windowDays}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($upcoming, $windowDays): array {
            $startedAt = microtime(true);
            $rows = app(EventMatchingService::class)->candidatesForUpcoming($upcoming, $windowDays, 5);

            $serialized = $rows->map(function (array $row): array {
                /** @var Event|null $candidate */
                $candidate = $row['event'] ?? null;

                return [
                    'candidate_event_id' => (int) ($row['candidate_event_id'] ?? $candidate?->id ?? 0),
                    'match_year' => (int) ($row['match_year'] ?? 0),
                    'year' => (int) ($candidate?->starts_at?->year ?? $row['match_year'] ?? 0),
                    'title' => (string) ($candidate?->display_name ?: $candidate?->name ?: 'Untitled Event'),
                    'starts_at' => $candidate?->starts_at?->toDateString(),
                    'ends_at' => $candidate?->ends_at?->toDateString(),
                    'match_score' => (float) ($row['match_score'] ?? 0),
                    'title_score' => (float) ($row['title_score'] ?? 0),
                    'date_score' => (float) ($row['date_score'] ?? 0),
                    'location_score' => (float) ($row['location_score'] ?? 0),
                    'source_ref_score' => (float) ($row['source_ref_score'] ?? 0),
                    'days_diff' => (int) ($row['days_diff'] ?? 0),
                    'days_diff_signed' => (int) ($row['days_diff_signed'] ?? 0),
                    'source' => (string) ($candidate?->source ?? ''),
                    'market_name' => (string) ($candidate?->market?->name ?? ''),
                ];
            })->values()->all();

            Log::info('Retail markets candidate summary cache miss', [
                'queue' => $this->queue,
                'plan_id' => $this->plan->id ?? null,
                'upcoming_event_id' => $upcoming->id,
                'window_days' => $windowDays,
                'candidate_count' => count($serialized),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return [
                'window_candidates' => $serialized,
                'scored_candidates' => array_slice($serialized, 0, 10),
                'suggested_candidate_event_id' => (int) ($serialized[0]['candidate_event_id'] ?? 0) ?: null,
                'cached_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    protected function buildCandidatePreview(Event $candidate): array
    {
        $rows = $this->marketPlanRowsForCandidateEvent($candidate);
        $summary = $this->marketPlanRowsSummary($rows);

        return [
            'candidate_event_id' => (int) $candidate->id,
            'candidate_title' => (string) ($candidate->display_name ?: $candidate->name ?: 'Untitled Event'),
            'candidate_date' => $candidate->starts_at?->toDateString(),
            'rows_found' => $rows->count(),
            'has_plan_data' => $rows->isNotEmpty(),
            'can_apply' => $rows->isNotEmpty(),
            'message' => $rows->isEmpty() ? 'No plan found for this candidate.' : null,
            'summary' => $summary,
        ];
    }

    /**
     * @param  Collection<int,MarketPlan>  $rows
     * @return array{full_boxes:int,half_boxes:int,square_add_ons:int,rows_count:int}
     */
    protected function marketPlanRowsSummary(Collection $rows): array
    {
        $fullBoxes = 0;
        $halfBoxes = 0;
        $squareAddOns = 0;

        foreach ($rows as $row) {
            $type = strtolower(trim((string) $row->box_type));
            $count = max(0, (int) $row->box_count);

            if ($type === 'full') {
                $fullBoxes += $count;
            } elseif ($type === 'half') {
                $halfBoxes += $count;
            } elseif ($type === 'top_shelf') {
                $squareAddOns += $count;
            }
        }

        return [
            'full_boxes' => $fullBoxes,
            'half_boxes' => $halfBoxes,
            'square_add_ons' => $squareAddOns,
            'rows_count' => $rows->count(),
        ];
    }

    /**
     * @return Collection<int,MarketPlan>
     */
    protected function marketPlanRowsForCandidateEvent(Event $candidate): Collection
    {
        if (! $candidate->starts_at) {
            return collect();
        }

        $matcher = app(EventMatchingService::class);
        $normalizedTitle = $matcher->normalizeTitle((string) ($candidate->display_name ?: $candidate->name));
        $eventDate = $candidate->starts_at->toDateString();

        if ($normalizedTitle === '') {
            return collect();
        }

        return MarketPlan::query()
            ->where('status', 'published')
            ->whereDate('event_date', $eventDate)
            ->where('normalized_title', $normalizedTitle)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int,MarketPlan>  $rows
     * @return array{0:int,1:int,2:int}
     */
    protected function applyMarketPlanRowsPrefill(Collection $rows, Event $upcomingEvent, Event $candidateEvent): array
    {
        $added = 0;
        $merged = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $upcomingEvent, $candidateEvent, &$added, &$merged, &$skipped) {
            if ($this->supportsRetailPlanEventColumn()) {
                $this->plan->event_id = $upcomingEvent->id;
                $this->plan->save();
            }

            foreach ($rows as $row) {
                $boxType = strtolower(trim((string) $row->box_type));
                $boxCount = max(0, (int) $row->box_count);

                if ($boxCount <= 0 || ! in_array($boxType, ['full', 'half'], true)) {
                    $skipped++;
                    continue;
                }

                $splitScentIds = $this->resolveSplitScentIdsFromText((string) $row->scent);
                if (count($splitScentIds) === 2) {
                    if ($boxType !== 'full') {
                        $skipped++;
                        continue;
                    }

                    foreach ($splitScentIds as $index => $splitScentId) {
                        $result = $this->mergeMarketEventPrefillItem(
                            $upcomingEvent,
                            (int) $row->id,
                            (int) $splitScentId,
                            $boxCount,
                            (string) $row->scent,
                            'full',
                            $candidateEvent,
                            $index + 1
                        );

                        $added += $result['added'];
                        $merged += $result['merged'];
                    }

                    continue;
                }

                $scentId = $this->resolveScentIdFromText((string) $row->scent);
                $halfBoxUnits = $boxType === 'full' ? ($boxCount * 2) : $boxCount;

                $result = $this->mergeMarketEventPrefillItem(
                    $upcomingEvent,
                    (int) $row->id,
                    $scentId,
                    $halfBoxUnits,
                    (string) $row->scent,
                    $boxType,
                    $candidateEvent
                );

                $added += $result['added'];
                $merged += $result['merged'];
            }
        }, 3);

        return [$added, $merged, $skipped];
    }

    protected function loadMarketHistoryBoxesFromMarketPlanGroup(int $marketPlanId, Event $selectedEvent): void
    {
        $marketPlan = MarketPlan::query()->find($marketPlanId);
        if (! $marketPlan) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'Historical event rows were not found.',
            ]);
            return;
        }

        $rows = MarketPlan::query()
            ->where('status', 'published')
            ->where('normalized_title', (string) $marketPlan->normalized_title)
            ->whereDate('event_date', $marketPlan->event_date)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'No historical box rows found for that event.',
            ]);
            return;
        }

        $added = 0;
        $merged = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $selectedEvent, &$added, &$merged, &$skipped) {
            if ($this->supportsRetailPlanEventColumn()) {
                $this->plan->event_id = $selectedEvent->id;
                $this->plan->save();
            }

            foreach ($rows as $row) {
                $boxType = strtolower(trim((string) $row->box_type));
                $boxCount = max(0, (int) $row->box_count);

                if ($boxCount <= 0 || ! in_array($boxType, ['full', 'half'], true)) {
                    $skipped++;
                    continue;
                }

                $splitScentIds = $this->resolveSplitScentIdsFromText((string) $row->scent);
                if (count($splitScentIds) === 2) {
                    if ($boxType !== 'full') {
                        $skipped++;
                        continue;
                    }

                    foreach ($splitScentIds as $index => $splitScentId) {
                        $result = $this->mergeMarketEventPrefillItem(
                            $selectedEvent,
                            (int) $row->id,
                            (int) $splitScentId,
                            $boxCount,
                            (string) $row->scent,
                            'full',
                            null,
                            $index + 1
                        );

                        $added += $result['added'];
                        $merged += $result['merged'];
                    }

                    continue;
                }

                $scentId = $this->resolveScentIdFromText((string) $row->scent);
                $halfBoxUnits = $boxType === 'full' ? ($boxCount * 2) : $boxCount;

                $result = $this->mergeMarketEventPrefillItem(
                    $selectedEvent,
                    (int) $row->id,
                    $scentId,
                    $halfBoxUnits,
                    (string) $row->scent,
                    $boxType,
                    null
                );

                $added += $result['added'];
                $merged += $result['merged'];
            }
        }, 3);

        $historyLabel = (string) ($marketPlan->event_title ?: 'historical event');
        $message = "Loaded historical boxes from {$historyLabel}. Added {$added}, merged {$merged}";
        if ($skipped > 0) {
            $message .= ", skipped {$skipped}";
        }
        $message .= '.';

        $this->marketsPanelTab = 'draft';
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => $message,
        ]);
    }

    /**
     * @return array{added:int,merged:int}
     */
    protected function mergeMarketEventPrefillItem(Event $selectedEvent, int $sourceMarketPlanLineId, ?int $scentId, int $halfBoxUnits, string $rawScent, string $boxType, ?Event $sourceEvent = null, ?int $splitIndex = null): array
    {
        $halfBoxUnits = max(1, $halfBoxUnits);

        if ($scentId) {
            $existing = RetailPlanItem::query()
                ->where('retail_plan_id', $this->plan->id)
                ->whereNull('size_id')
                ->whereIn('source', ['market_box_manual', 'market_box_draft', 'market_box_event_prefill', 'event_prefill'])
                ->where('scent_id', $scentId)
                ->where('status', '!=', 'published')
                ->first();

            if ($existing) {
                $existing->quantity = max(1, (int) $existing->quantity + $halfBoxUnits);
                if (($existing->status ?? 'draft') === 'needs_mapping') {
                    $existing->status = 'draft';
                }
                if (Schema::hasColumn('retail_plan_items', 'upcoming_event_id')) {
                    $existing->upcoming_event_id = $selectedEvent->id;
                }
                if ($sourceEvent && Schema::hasColumn('retail_plan_items', 'source_event_id')) {
                    $existing->source_event_id = $sourceEvent->id;
                    $existing->source_year = $sourceEvent->starts_at?->year;
                    $existing->source_title = (string) ($sourceEvent->display_name ?: $sourceEvent->name);
                }
                $existing->save();

                return ['added' => 0, 'merged' => 1];
            }
        }

        $skuParts = [
            'mktevt',
            (string) $selectedEvent->id,
            (string) $sourceMarketPlanLineId,
            $boxType,
        ];

        if ($splitIndex !== null) {
            $skuParts[] = 'split'.$splitIndex;
        }

        $skuParts[] = Str::slug($rawScent) ?: 'scent';

        $payload = [
            'retail_plan_id' => $this->plan->id,
            'order_id' => null,
            'order_line_id' => null,
            'scent_id' => $scentId,
            'size_id' => null,
            'quantity' => $halfBoxUnits,
            'source' => 'event_prefill',
            'status' => $scentId ? 'draft' : 'needs_mapping',
            'sku' => Str::limit(implode(':', $skuParts), 255, ''),
        ];

        if (Schema::hasColumn('retail_plan_items', 'upcoming_event_id')) {
            $payload['upcoming_event_id'] = $selectedEvent->id;
        }

        if ($sourceEvent && Schema::hasColumn('retail_plan_items', 'source_event_id')) {
            $payload['source_event_id'] = $sourceEvent->id;
            $payload['source_year'] = $sourceEvent->starts_at?->year;
            $payload['source_title'] = (string) ($sourceEvent->display_name ?: $sourceEvent->name);
        }

        RetailPlanItem::query()->create($payload);

        return ['added' => 1, 'merged' => 0];
    }

    public function addInventoryItem(): void
    {
        if ($this->queue === 'markets') {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'Use Half Box or Full Box for market scents.',
            ]);
            return;
        }

        $this->validate([
            'inventoryScentId' => 'required|exists:scents,id',
            'inventorySizeId' => 'required|exists:sizes,id',
            'inventoryQty' => 'required|integer|min:1',
        ]);

        RetailPlanItem::create([
            'retail_plan_id' => $this->plan->id,
            'scent_id' => $this->inventoryScentId,
            'size_id' => $this->inventorySizeId,
            'quantity' => $this->inventoryQty,
            'source' => 'inventory',
            'status' => 'draft',
        ]);

        $this->inventoryScentId = null;
        $this->inventorySizeId = null;
        $this->inventoryQty = 1;
        $this->inventoryScentSearch = '';
        $this->inventorySizeSearch = '';

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Inventory item added to plan.',
        ]);
    }

    public function addMarketHalfBox(): void
    {
        $this->addMarketBoxUnits(1);
    }

    public function addMarketFullBox(): void
    {
        $this->addMarketBoxUnits(2);
    }

    protected function addMarketBoxUnits(int $halfBoxUnits): void
    {
        if ($this->queue !== 'markets') {
            return;
        }

        $this->validate([
            'inventoryScentId' => 'required|exists:scents,id',
        ]);

        RetailPlanItem::create([
            'retail_plan_id' => $this->plan->id,
            'scent_id' => $this->inventoryScentId,
            'size_id' => null,
            'quantity' => max(1, $halfBoxUnits),
            'source' => 'market_box_manual',
            'status' => 'draft',
            'sku' => 'market-box',
        ]);

        $this->inventoryScentId = null;
        $this->inventoryScentSearch = '';

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Market box scent added to plan.',
        ]);
    }

    public function handleScentSelected(string $key, ?int $scentId = null, ?string $scentName = null): void
    {
        if ($key !== 'retail-plan') {
            return;
        }

        $this->inventoryScentId = $scentId;
        $this->inventoryScentSearch = $scentName ?? '';
    }

    public function selectInventorySize(): void
    {
        $text = trim($this->inventorySizeSearch);
        if ($text === '') {
            $this->inventorySizeId = null;
            return;
        }

        $size = Size::query()
            ->where('code', $text)
            ->orWhere('label', $text)
            ->first();
        $this->inventorySizeId = $size?->id;
    }

    public function removeItem(int $itemId): void
    {
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);

        $item->delete();

        $this->dispatch('toast', [
            'type' => 'warning',
            'message' => 'Item removed from plan.',
        ]);
    }

    public function updateItemQuantity(int $itemId, int $quantity): void
    {
        $quantity = max(1, $quantity);
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);
        $item->quantity = $quantity;
        $item->save();
    }

    public function updateItemInventoryQuantity(int $itemId, int $quantity): void
    {
        $quantity = max(0, $quantity);
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);
        $item->inventory_quantity = $quantity;
        $item->save();
    }

    public function incrementItemQuantity(int $itemId): void
    {
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);
        $item->quantity = max(1, (int) $item->quantity + 1);
        $item->save();
    }

    public function decrementItemQuantity(int $itemId): void
    {
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);
        $item->quantity = max(1, (int) $item->quantity - 1);
        $item->save();
    }

    public function marketBoxLabel(RetailPlanItem $item): string
    {
        $units = max(1, (int) ($item->quantity ?? 0));
        $boxes = $units / 2;

        if (fmod($boxes, 1.0) === 0.0) {
            $value = (string) (int) $boxes;
        } else {
            $value = number_format($boxes, 1);
        }

        return $value.' '.(((float) $boxes) === 1.0 ? 'box' : 'boxes');
    }

    public function incrementItemInventoryQuantity(int $itemId): void
    {
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);
        $item->inventory_quantity = max(0, (int) $item->inventory_quantity + 1);
        $item->save();
    }

    public function decrementItemInventoryQuantity(int $itemId): void
    {
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->findOrFail($itemId);
        $item->inventory_quantity = max(0, (int) $item->inventory_quantity - 1);
        $item->save();
    }

    public function clearScents(): void
    {
        RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->delete();

        $this->dispatch('toast', [
            'type' => 'warning',
            'message' => $this->queueDisplayName().' list cleared.',
        ]);
    }

    public function publishPlan(): void
    {
        if ($this->plan->published_at) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'This '.$this->queueDisplayName().' list is already published.',
            ]);
            return;
        }

        DB::transaction(function () {
            $this->plan->status = 'published';
            $this->plan->published_at = now();
            $this->plan->save();

            $items = $this->plan->items()->get();
            foreach ($items as $item) {
                if ($item->order_id || $item->order_line_id) {
                    $order = $item->order_id
                        ? Order::query()->find($item->order_id)
                        : OrderLine::query()->find($item->order_line_id)?->order;

                    if ($order) {
                        if (in_array($order->status, ['new', 'reviewed'], true)) {
                            $order->status = 'submitted_to_pouring';
                        }
                        if (!$order->published_at) {
                            $order->published_at = now();
                        }
                        $order->save();
                    }

                    $item->status = 'published';
                    $item->save();
                }
            }

            if ($this->queue === 'markets') {
                $marketBoxItems = $items->whereIn('source', ['market_box_draft', 'market_box_manual', 'market_box_event_prefill', 'event_prefill']);
                $publishableBoxes = $marketBoxItems->filter(fn ($item) => (int) ($item->scent_id ?? 0) > 0 && (int) ($item->quantity ?? 0) > 0);

                if ($publishableBoxes->isNotEmpty()) {
                    $today = CarbonImmutable::today();
                    $planEvent = ($this->supportsRetailPlanEventColumn() && $this->plan->event_id)
                        ? Event::query()->find($this->plan->event_id)
                        : null;
                    $marketOrderLabel = $planEvent
                        ? 'Markets Box Plan · '.($planEvent->display_name ?: $planEvent->name)
                        : 'Markets Box Plan';

                    $marketOrderPayload = [
                        'order_type' => 'event',
                        'order_label' => $marketOrderLabel,
                        'order_number' => 'MKT-BOX-' . $today->format('Ymd'),
                        'source' => 'internal',
                        'status' => 'submitted_to_pouring',
                        'ordered_at' => now(),
                        'ship_by_at' => $today,
                        'due_at' => $today,
                        'published_at' => now(),
                    ];

                    if ($this->supportsOrderEventColumn()) {
                        $marketOrderPayload['event_id'] = $planEvent?->id;
                    }

                    $marketOrder = Order::query()->create($marketOrderPayload);

                    [$size16CottonId, $size8CottonId, $sizeWaxMeltId] = $this->marketBoxSizeIds();

                    foreach ($publishableBoxes as $item) {
                        $halfBoxUnits = max(1, (int) $item->quantity);
                        $scentId = (int) $item->scent_id;

                        // One full box = 4x16oz cotton, 8x8oz cotton, 8x wax melts
                        // One half box = 2x16oz cotton, 4x8oz cotton, 4x wax melts
                        $this->createMarketBoxOrderLine($marketOrder->id, $scentId, $size16CottonId, $halfBoxUnits * 2);
                        $this->createMarketBoxOrderLine($marketOrder->id, $scentId, $size8CottonId, $halfBoxUnits * 4);
                        $this->createMarketBoxOrderLine($marketOrder->id, $scentId, $sizeWaxMeltId, $halfBoxUnits * 4);

                        $item->order_id = $marketOrder->id;
                        $item->status = 'published';
                        $item->save();
                    }
                }
            }

            $inventoryItems = $items->where('source', 'inventory');
            $inventoryExtras = $items->filter(fn ($item) => (int) ($item->inventory_quantity ?? 0) > 0);

            if ($inventoryItems->isNotEmpty() || $inventoryExtras->isNotEmpty()) {
                $today = CarbonImmutable::today();

                $inventoryOrder = Order::query()->create([
                    'order_type' => $this->orderTypeForQueue(),
                    'order_label' => $this->queueDisplayName() . ' Inventory',
                    'order_number' => $this->inventoryOrderNumberPrefix() . $today->format('Ymd'),
                    'source' => 'internal',
                    'status' => 'submitted_to_pouring',
                    'ordered_at' => now(),
                    'ship_by_at' => $today,
                    'due_at' => $today,
                    'published_at' => now(),
                ]);

                foreach ($inventoryItems as $item) {
                    $sizeCode = Size::query()->find($item->size_id)?->code;

                    OrderLine::query()->create([
                        'order_id' => $inventoryOrder->id,
                        'scent_id' => $item->scent_id,
                        'size_id' => $item->size_id,
                        'size_code' => $sizeCode,
                        'ordered_qty' => $item->quantity,
                        'extra_qty' => 0,
                    ]);

                    $item->order_id = $inventoryOrder->id;
                    $item->status = 'published';
                    $item->save();
                }

                foreach ($inventoryExtras as $item) {
                    $sizeCode = Size::query()->find($item->size_id)?->code;

                    OrderLine::query()->create([
                        'order_id' => $inventoryOrder->id,
                        'scent_id' => $item->scent_id,
                        'size_id' => $item->size_id,
                        'size_code' => $sizeCode,
                        'ordered_qty' => (int) $item->inventory_quantity,
                        'extra_qty' => 0,
                    ]);

                    $item->status = 'published';
                    $item->save();
                }
            }
        }, 3);

        $this->plan = $this->createDraftPlan(
            $this->queueDisplayName() . ' / Pour List ' . CarbonImmutable::now()->format('Y-m-d H:i')
        );
        $this->selectedUpcomingEventId = null;
        $this->selectedCandidateEventId = null;
        $this->candidatePriorEvents = [];
        $this->candidateScoredEvents = [];
        $this->candidatePreview = [];
        $this->marketSelectedEventId = null;
        $this->marketSelectedHistoryPlanId = null;
        $this->marketEventsErrorBanner = null;
        $this->prefillFromOrdersInternal(true);

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => $this->queueDisplayName().'/Pour list sent to Pouring Room. Candles-to-pour list cleared for the next batch.',
        ]);
        $this->dispatch('retail-plan-published', [
            'pegasus_gif' => asset('images/pegasus.gif'),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function marketEventsPanelViewModel(): array
    {
        $base = [
            'enabled' => $this->marketEventsPanelEnabled(),
            'error' => $this->marketEventsErrorBanner,
            'last_sync_at' => $this->marketEventsLastSyncAt,
            'sync_summary' => $this->marketEventsSyncSummary,
            'sync_state' => $this->marketEventsSyncState,
            'upcoming_events' => collect(),
            'selected_event' => null,
            'match_window_days' => $this->matchWindowDays,
            'candidate_prior_events' => $this->candidatePriorEvents,
            'candidate_scored_events' => $this->candidateScoredEvents,
            'candidate_preview' => $this->candidatePreview,
            'selected_upcoming_event_id' => $this->selectedUpcomingEventId,
            'selected_candidate_event_id' => $this->selectedCandidateEventId,
            'show_sync_error_detail' => (bool) (auth()->user()?->isAdmin()),
        ];

        if (! $base['enabled']) {
            return $base;
        }

        try {
            if (! $this->marketEventsPanelLoaded) {
                $this->refreshMarketEventsPanelData();
            }

            $upcomingEvents = collect($this->upcomingMarketEventRows)->map(function (array $row) {
                return (object) [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'display_name' => (string) ($row['display_name'] ?? ''),
                    'starts_at' => !empty($row['starts_at']) ? \Illuminate\Support\Carbon::parse($row['starts_at']) : null,
                    'ends_at' => !empty($row['ends_at']) ? \Illuminate\Support\Carbon::parse($row['ends_at']) : null,
                    'city' => (string) ($row['city'] ?? ''),
                    'state' => (string) ($row['state'] ?? ''),
                ];
            });
            $selectedEvent = $this->selectedMarketEventForPanel($upcomingEvents);
            $base['upcoming_events'] = $upcomingEvents;
            $base['selected_event'] = $selectedEvent;
        } catch (\Throwable $e) {
            Log::error('Retail markets events panel render failed', [
                'queue' => $this->queue,
                'plan_id' => $this->plan->id ?? null,
                'selected_event_id' => $this->selectedUpcomingEventId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            $base['error'] = 'Market events panel is temporarily unavailable. Draft planning is still available.';
        }

        return $base;
    }

    protected function marketEventsPanelEnabled(): bool
    {
        return $this->queue === 'markets' && (bool) config('features.market_events_panel', true);
    }

    /**
     * @return Collection<int,Event>
     */
    protected function upcomingMarketEventsForPanel(): Collection
    {
        $start = now()->startOfDay()->toDateString();
        $end = now()->addWeeks(4)->endOfDay()->toDateString();

        return Event::query()
            ->select(['id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state', 'source', 'source_ref'])
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$start, $end])
            ->orderBy('starts_at')
            ->orderBy('display_name')
            ->limit(80)
            ->get()
            ->unique(function (Event $event): string {
                $sourceRef = trim((string) ($event->source_ref ?? ''));
                if ($sourceRef !== '') {
                    return 'ref:'.$sourceRef;
                }

                $normalized = Str::lower(trim((string) ($event->display_name ?: $event->name)));
                $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
                $date = $event->starts_at?->toDateString() ?? 'date_tbd';

                return 'fallback:'.$date.'|'.trim($normalized);
            })
            ->sortBy(fn (Event $event) => [
                $event->source === 'asana_calendar' ? 0 : 1,
                (string) ($event->starts_at?->toDateString() ?? '9999-12-31'),
                Str::lower((string) ($event->display_name ?: $event->name)),
                (int) $event->id,
            ])
            ->values();
    }

    /**
     * @param  Collection<int,Event>|null  $upcomingEvents
     */
    protected function selectedMarketEventForPanel(?Collection $upcomingEvents = null): ?Event
    {
        $eventId = (int) ($this->selectedUpcomingEventId ?: $this->marketSelectedEventId ?: 0);
        if ($eventId <= 0) {
            return null;
        }

        if ($upcomingEvents) {
            $fromList = $upcomingEvents->firstWhere('id', $eventId);
            if ($fromList) {
                $event = new Event();
                $event->id = (int) ($fromList->id ?? 0);
                $event->name = (string) ($fromList->name ?? '');
                $event->display_name = (string) ($fromList->display_name ?? '');
                $event->starts_at = !empty($fromList->starts_at) ? \Illuminate\Support\Carbon::parse($fromList->starts_at) : null;
                $event->ends_at = !empty($fromList->ends_at) ? \Illuminate\Support\Carbon::parse($fromList->ends_at) : null;

                return $event;
            }
        }

        return Event::query()->select(['id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state'])->find($eventId);
    }

    /**
     * @return array{best_match:?array,candidates:array<int,array<string,mixed>>,threshold:float}
     */
    protected function marketEventPrefillData(Event $event): array
    {
        $threshold = 0.35;
        $eventDate = $event->starts_at;
        if (! $eventDate) {
            return ['best_match' => null, 'candidates' => [], 'threshold' => $threshold];
        }

        $groups = MarketPlan::query()
            ->where('status', 'published')
            ->whereNotNull('event_date')
            ->selectRaw('MIN(id) as id')
            ->select('event_title', 'normalized_title', 'event_date')
            ->groupBy('event_title', 'normalized_title', 'event_date')
            ->orderByDesc('event_date')
            ->get();

        if ($groups->isEmpty()) {
            return ['best_match' => null, 'candidates' => [], 'threshold' => $threshold];
        }

        $matcher = app(EventMatchingService::class);
        $selectedTitle = (string) ($event->display_name ?: $event->name);
        $normalizedSelectedTitle = $matcher->normalizeTitle($selectedTitle);
        $targetYears = [$eventDate->year - 1, $eventDate->year - 2];

        $scored = [];

        foreach ($groups as $group) {
            $candidateDate = $group->event_date;
            if (! $candidateDate) {
                continue;
            }

            $candidateYear = (int) $candidateDate->year;
            if (! in_array($candidateYear, $targetYears, true)) {
                continue;
            }

            $dateDiffDays = $this->anniversaryDateDiffDays($eventDate, $candidateDate);
            if ($dateDiffDays > 30) {
                continue;
            }

            $normalizedCandidate = (string) ($group->normalized_title ?: $matcher->normalizeTitle((string) $group->event_title));
            $titleScore = $matcher->similarity($normalizedSelectedTitle, $normalizedCandidate);
            $dateBonus = max(0.0, 1 - ($dateDiffDays / 30));
            $score = round(min(1.0, ($titleScore * 0.85) + ($dateBonus * 0.15)), 4);

            $summary = $this->marketPlanGroupSummary(
                (int) $group->id,
                (string) $group->event_title,
                $candidateDate->toDateString(),
                (string) $normalizedCandidate
            );

            $summary['score'] = $score;
            $summary['score_percent'] = (int) round($score * 100);
            $summary['title_score_percent'] = (int) round($titleScore * 100);
            $summary['date_diff_days'] = $dateDiffDays;

            $scored[] = $summary;
        }

        usort($scored, function (array $a, array $b): int {
            return [$b['score'], $b['event_date'], $a['id']] <=> [$a['score'], $a['event_date'], $b['id']];
        });

        $candidates = array_slice($scored, 0, 8);
        $best = $candidates[0] ?? null;

        return [
            'best_match' => ($best && (float) $best['score'] >= $threshold) ? $best : null,
            'candidates' => $candidates,
            'threshold' => $threshold,
        ];
    }

    protected function anniversaryDateDiffDays($selectedDate, $candidateDate): int
    {
        try {
            $candidateAnniversary = $candidateDate->copy()->year((int) $selectedDate->year);
        } catch (\Throwable $e) {
            return 999;
        }

        return (int) abs($candidateAnniversary->diffInDays($selectedDate, false));
    }

    /**
     * @return array<string,mixed>
     */
    protected function marketPlanGroupSummary(int $representativeId, string $eventTitle, string $eventDate, string $normalizedTitle): array
    {
        $rows = MarketPlan::query()
            ->where('status', 'published')
            ->where('normalized_title', $normalizedTitle)
            ->whereDate('event_date', $eventDate)
            ->orderBy('id')
            ->get(['id', 'box_type', 'scent', 'box_count']);

        $fullBoxes = 0;
        $halfBoxes = 0;
        $topShelfBoxes = 0;

        foreach ($rows as $row) {
            $boxType = strtolower(trim((string) $row->box_type));
            $count = max(0, (int) $row->box_count);

            if ($boxType === 'full') {
                $fullBoxes += $count;
            } elseif ($boxType === 'half') {
                $halfBoxes += $count;
            } elseif ($boxType === 'top_shelf') {
                $topShelfBoxes += $count;
            }
        }

        return [
            'id' => $representativeId,
            'event_title' => $eventTitle,
            'event_date' => $eventDate,
            'normalized_title' => $normalizedTitle,
            'rows_count' => $rows->count(),
            'distinct_scents' => $rows->pluck('scent')->filter()->unique(fn ($s) => Str::lower(trim((string) $s)))->count(),
            'full_boxes' => $fullBoxes,
            'half_boxes' => $halfBoxes,
            'top_shelf_boxes' => $topShelfBoxes,
        ];
    }

    public function render()
    {
        $items = $this->plan->items()
            ->where('status', '!=', 'published')
            ->orderBy('source')
            ->get();
        $sizeQuery = trim($this->inventorySizeSearch);
        $marketSourceLabels = [];
        $marketEventsPanel = $this->marketEventsPanelViewModel();

        $scents = Scent::query()
            ->orderBy('name')
            ->get();

        if ($this->queue === 'markets') {
            $draftIds = $items
                ->map(function ($item) {
                    if (preg_match('/^mktpl:(\d+):\d+(?:#split:\d+)?$/', (string) ($item->sku ?? ''), $m)) {
                        return (int) $m[1];
                    }

                    return null;
                })
                ->filter()
                ->unique()
                ->values();

            $draftsById = MarketPourList::query()
                ->with(['event:id,market_id,name,display_name,starts_at', 'event.market:id,name'])
                ->whereIn('id', $draftIds)
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                if (!preg_match('/^mktpl:(\d+):\d+(?:#split:\d+)?$/', (string) ($item->sku ?? ''), $m)) {
                    continue;
                }
                $draft = $draftsById->get((int) $m[1]);
                $event = $draft?->event;
                $marketSourceLabels[$item->id] = $event?->display_name ?: $event?->name ?: $event?->market?->name;
            }

            $items = $items->sortBy(function ($item) use ($draftsById, $scents) {
                $draftId = null;
                if (preg_match('/^mktpl:(\d+):\d+(?:#split:\d+)?$/', (string) ($item->sku ?? ''), $m)) {
                    $draftId = (int) $m[1];
                }

                $draft = $draftId ? $draftsById->get($draftId) : null;
                $event = $draft?->event;
                $eventDate = $event?->starts_at ? (string) $event->starts_at : '9999-12-31';
                $eventName = Str::lower(trim((string) ($event?->name ?? 'zzzz manual market box')));
                $scentName = Str::lower(trim((string) (
                    $scents->firstWhere('id', $item->scent_id)?->display_name
                    ?? $scents->firstWhere('id', $item->scent_id)?->name
                    ?? $item->sku
                    ?? 'zzz'
                )));

                return [$eventDate, $eventName, $scentName, (int) $item->id];
            })->values();
        }

        $sizes = Size::query()
            ->when($sizeQuery !== '', function ($q) use ($sizeQuery) {
                $q->where('code', 'like', '%'.$sizeQuery.'%')
                  ->orWhere('label', 'like', '%'.$sizeQuery.'%');
            })
            ->orderBy('label')
            ->orderBy('code')
            ->limit(30)
            ->get();

        return view('livewire.retail.plan', [
            'items' => $items,
            'scents' => $scents,
            'sizes' => $sizes,
            'quote' => $this->quote,
            'queueMeta' => $this->queueMeta(),
            'marketSourceLabels' => $marketSourceLabels,
            'marketEventsPanel' => $marketEventsPanel,
        ]);
    }

    protected function startMarketsRequestProfile(string $trigger): void
    {
        $queue = $this->normalizeQueue((string) ($this->queue ?: request()->query('queue', 'retail')));
        if ($queue !== 'markets') {
            return;
        }

        $this->marketsRequestProfileStartedAt = microtime(true);
        $this->marketsRequestProfileQueryCount = 0;
        $this->marketsRequestProfileQueryTimeMs = 0.0;
        $this->marketsRequestProfileTrigger = $trigger;
        RequestMetrics::reset();

        if (! ($this->shouldProfileMarketsQueries())) {
            return;
        }

        if ($this->marketsRequestProfileListenerAttached) {
            return;
        }

        $this->marketsRequestProfileListenerAttached = true;

        DB::listen(function (QueryExecuted $query): void {
            if ($this->marketsRequestProfileStartedAt === null) {
                return;
            }

            $this->marketsRequestProfileQueryCount++;
            $this->marketsRequestProfileQueryTimeMs += (float) $query->time;
        });
    }

    protected function finishMarketsRequestProfile(): void
    {
        if ($this->marketsRequestProfileStartedAt === null) {
            return;
        }

        Log::info('Markets queue request profile', [
            'trigger' => $this->marketsRequestProfileTrigger,
            'queue' => $this->queue,
            'plan_id' => $this->plan->id ?? null,
            'livewire_component' => static::class,
            'url' => request()?->fullUrl(),
            'duration_ms' => (int) round((microtime(true) - $this->marketsRequestProfileStartedAt) * 1000),
            'db_query_count' => $this->marketsRequestProfileQueryCount,
            'db_query_time_ms' => (int) round($this->marketsRequestProfileQueryTimeMs),
            'external_http_calls' => RequestMetrics::externalHttpCalls(),
        ]);

        $this->marketsRequestProfileStartedAt = null;
        $this->marketsRequestProfileQueryCount = 0;
        $this->marketsRequestProfileQueryTimeMs = 0.0;
        $this->marketsRequestProfileTrigger = null;
    }

    protected function shouldProfileMarketsQueries(): bool
    {
        return app()->isLocal() || (bool) config('app.debug');
    }

    /**
     * @template TReturn
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    protected function profileMarketsAction(string $action, callable $callback): mixed
    {
        if ($this->queue !== 'markets') {
            return $callback();
        }

        $startedAt = microtime(true);
        $queryCount = 0;
        $queryTimeMs = 0.0;
        RequestMetrics::reset();

        if ($this->shouldProfileMarketsQueries()) {
            DB::listen(function (QueryExecuted $query) use (&$queryCount, &$queryTimeMs): void {
                $queryCount++;
                $queryTimeMs += (float) $query->time;
            });
        }

        try {
            return $callback();
        } finally {
            Log::info('Markets queue action profile', [
                'action' => $action,
                'queue' => $this->queue,
                'plan_id' => $this->plan->id ?? null,
                'selected_upcoming_event_id' => $this->selectedUpcomingEventId,
                'selected_candidate_event_id' => $this->selectedCandidateEventId,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'db_query_count' => $queryCount,
                'db_query_time_ms' => (int) round($queryTimeMs),
                'external_http_calls' => RequestMetrics::externalHttpCalls(),
            ]);
        }
    }

    protected function createDraftPlan(string $name): RetailPlan
    {
        $payload = [
            'name' => $name,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ];

        if ($this->supportsQueueTypeColumn()) {
            $payload['queue_type'] = $this->queue;
        }

        if ($this->supportsRetailPlanEventColumn() && $this->queue === 'markets' && $this->marketSelectedEventId) {
            $payload['event_id'] = $this->marketSelectedEventId;
        }

        return RetailPlan::query()->create($payload);
    }

    protected function supportsQueueTypeColumn(): bool
    {
        static $supports = null;

        if ($supports === null) {
            $supports = Schema::hasColumn('retail_plans', 'queue_type');
        }

        return $supports;
    }

    protected function supportsRetailPlanEventColumn(): bool
    {
        static $supports = null;

        if ($supports === null) {
            $supports = Schema::hasColumn('retail_plans', 'event_id');
        }

        return $supports;
    }

    protected function supportsOrderEventColumn(): bool
    {
        static $supports = null;

        if ($supports === null) {
            $supports = Schema::hasColumn('orders', 'event_id');
        }

        return $supports;
    }

    protected function supportsMarketEventSyncStateTable(): bool
    {
        static $supports = null;

        if ($supports === null) {
            $supports = Schema::hasTable('market_event_sync_states');
        }

        return $supports;
    }

    protected function supportsEventMatchOverridesTable(): bool
    {
        static $supports = null;

        if ($supports === null) {
            $supports = Schema::hasTable('event_match_overrides');
        }

        return $supports;
    }

    protected function normalizeQueue(string $queue): string
    {
        $queue = strtolower(trim($queue));

        return in_array($queue, ['retail', 'wholesale', 'markets'], true)
            ? $queue
            : 'retail';
    }

    protected function orderTypeForQueue(): string
    {
        return match ($this->queue) {
            'wholesale' => 'wholesale',
            'markets' => 'event',
            default => 'retail',
        };
    }

    protected function inventoryOrderNumberPrefix(): string
    {
        return match ($this->queue) {
            'wholesale' => 'WHL-INV-',
            'markets' => 'MKT-INV-',
            default => 'RET-INV-',
        };
    }

    protected function queueDisplayName(): string
    {
        return match ($this->queue) {
            'wholesale' => 'Wholesale',
            'markets' => 'Markets',
            default => 'Retail',
        };
    }

    protected function queueMeta(): array
    {
        $queue = $this->queueDisplayName();
        $prefillLabel = match ($this->queue) {
            'wholesale' => 'Prefill from Wholesale Orders',
            'markets' => 'Prefill from Market Drafts',
            default => 'Prefill from Retail Orders',
        };

        $emptyLabel = match ($this->queue) {
            'markets' => 'No items yet. Prefill from market drafts or add inventory below.',
            'wholesale' => 'No items yet. Prefill from wholesale orders or add inventory below.',
            default => 'No items yet. Prefill from retail orders or add inventory below.',
        };

        return [
            'key' => $this->queue,
            'title' => $queue.'/Pour List',
            'subtitle' => 'Draft list for today. Publish to push to the pouring room.',
            'prefill_label' => $prefillLabel,
            'add_button_label' => 'Add to '.$queue.'/Pour List',
            'empty_label' => $emptyLabel,
            'markets_help' => $this->queue === 'markets',
        ];
    }

    protected function resolveMarketDraftLineMapping(MarketPourListLine $line, MarketPourList $draft): array
    {
        $reason = is_array($line->reason_json) ? $line->reason_json : [];

        $scentId = $line->scent_id ?: $this->resolveScentIdFromText((string) ($reason['scent'] ?? ''));
        $sizeId = $line->size_id ?: $this->resolveSizeIdFromText((string) ($reason['size'] ?? ''));

        $sku = 'mktpl:'.$draft->id.':'.$line->id;

        // Preserve the draft SKU so we can still resolve the source event label in the markets planner.
        // A missing size is normal for market box draft rows, but a missing scent is not renderable yet.
        if (!$scentId) {
            $parts = array_values(array_filter([
                trim((string) ($reason['product_key'] ?? '')),
                trim((string) ($reason['scent'] ?? '')),
                trim((string) ($reason['size'] ?? '')),
            ]));

            if ($parts !== []) {
                $sku = implode(' | ', $parts);
            }
        }

        return [$scentId ?: null, $sizeId ?: null, Str::limit($sku, 255, '')];
    }

    protected function resolveScentIdFromText(string $scent): ?int
    {
        return $this->resolveSingleScentIdFromText($scent);
    }

    /**
     * @return array<int,int>
     */
    protected function resolveSplitScentIdsFromText(string $scent): array
    {
        $scent = trim($scent);
        if ($scent === '' || !str_contains($scent, '/')) {
            return [];
        }

        $parts = array_values(array_filter(array_map('trim', explode('/', $scent))));
        if (count($parts) !== 2) {
            return [];
        }

        $ids = [];
        foreach ($parts as $part) {
            $id = $this->resolveSingleScentIdFromText($part);
            if (!$id) {
                return [];
            }
            $ids[] = (int) $id;
        }

        $ids = array_values(array_unique($ids));

        return count($ids) === 2 ? $ids : [];
    }

    protected function resolveSingleScentIdFromText(string $scent): ?int
    {
        $scent = trim($scent);
        if ($scent === '') {
            return null;
        }

        $normalized = Scent::normalizeName($scent);

        $match = Scent::query()
            ->where('name', $scent)
            ->orWhere('display_name', $scent)
            ->orWhere('abbreviation', $scent)
            ->get(['id', 'name', 'display_name', 'abbreviation'])
            ->first();

        if ($match) {
            return (int) $match->id;
        }

        return Scent::query()
            ->get(['id', 'name', 'display_name', 'abbreviation'])
            ->first(function (Scent $candidate) use ($normalized) {
                $names = array_filter([
                    $candidate->name,
                    $candidate->display_name,
                    $candidate->abbreviation,
                ]);

                foreach ($names as $name) {
                    if (Scent::normalizeName((string) $name) === $normalized) {
                        return true;
                    }
                }

                return false;
            })?->id;
    }

    protected function resolveSizeIdFromText(string $size): ?int
    {
        $size = trim($size);
        if ($size === '') {
            return null;
        }

        return Size::query()
            ->where('code', $size)
            ->orWhere('label', $size)
            ->first()?->id;
    }

    protected function marketBoxSizeIds(): array
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $sizes = Size::query()->get(['id', 'code', 'label']);
        $findByContains = function (array $needles) use ($sizes): ?int {
            foreach ($sizes as $size) {
                $haystack = Str::lower(trim(($size->code ?? '').' '.($size->label ?? '')));
                foreach ($needles as $needle) {
                    if (str_contains($haystack, Str::lower($needle))) {
                        return (int) $size->id;
                    }
                }
            }

            return null;
        };

        $cached = [
            $findByContains(['16oz-cotton', '16 oz cotton', '16oz cotton wick']),
            $findByContains(['8oz-cotton', '8 oz cotton', '8oz cotton wick']),
            $findByContains(['wax-melts', 'wax melts', 'wax melt']),
        ];

        return $cached;
    }

    protected function createMarketBoxOrderLine(int $orderId, int $scentId, ?int $sizeId, int $qty): void
    {
        if (!$sizeId || $qty <= 0) {
            return;
        }

        $size = Size::query()->find($sizeId);

        OrderLine::query()->create([
            'order_id' => $orderId,
            'scent_id' => $scentId,
            'size_id' => $sizeId,
            'size_code' => $size?->code,
            'ordered_qty' => $qty,
            'extra_qty' => 0,
            'quantity' => $qty,
            'wick_type' => str_contains((string) ($size?->code ?? ''), 'cotton') ? 'cotton' : null,
        ]);
    }

    protected function enneagramQuote(): string
    {
        $quotes = [
            'Respect is earned. So are clean pours.',
            'Don’t negotiate with sloppy wicks.',
            'Kindness is a choice. Precision is a requirement.',
            'Move fast. Pour clean.',
            'No excuses. Only outcomes.',
        ];

        return $quotes[array_rand($quotes)];
    }
}
