<?php

namespace App\Livewire\Retail\Markets;

use App\Models\Event;
use App\Models\EventMapping;
use App\Models\MarketPlan;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\RetailPlan;
use App\Models\RetailPlanItem;
use App\Models\Scent;
use App\Models\ScentTemplate;
use App\Models\Size;
use App\Services\EventMatchingService;
use App\Services\MarketEventSyncCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class MarketsPlanner extends Component
{
    public int $planId = 0;
    public RetailPlan $plan;

    public function mount(int $planId): void
    {
        $this->planId = max(0, $planId);
        $this->plan = RetailPlan::query()->findOrFail($this->planId);
    }

    #[On('marketsMappingConfirmed')]
    public function handleMarketsMappingConfirmed(int $upcomingEventId, int $candidateEventId): void
    {
        $upcoming = Event::query()->find($upcomingEventId);
        $candidate = Event::query()->find($candidateEventId);
        if (! $upcoming || ! $candidate) {
            $this->dispatchPrefillStatus(
                'error',
                'Could not load the selected historical event. Pick another match or start fresh.',
                $upcoming,
                $candidate
            );
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Could not confirm mapping because one of the events was not found.']);

            return;
        }

        if (! $this->supportsEventMappingsTable()) {
            $message = 'Mappings table missing. Run migrations: php artisan migrate';
            $this->logMissingMappingsTableWarning((int) $upcoming->id, (int) $candidate->id);
            $this->dispatchPrefillStatus('missing_mappings', $message, $upcoming, $candidate);
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Event mappings table missing. Run migrations first.']);

            return;
        }

        EventMapping::query()->updateOrCreate(
            ['upcoming_event_id' => $upcoming->id],
            [
                'past_event_id' => $candidate->id,
                'created_by' => auth()->id(),
            ]
        );

        $this->rememberSelectedEvent($upcoming);
        app(MarketEventSyncCoordinator::class)->bumpMatchingCacheVersion();
        $rows = $this->marketPlanRowsForCandidateEvent($candidate);

        Log::info('MarketsPlanner historical prefill evaluated', [
            'upcoming_event_id' => (int) $upcoming->id,
            'candidate_event_id' => (int) $candidate->id,
            'has_event_mappings_table' => true,
            'template_row_count' => $rows->count(),
        ]);

        if ($rows->isEmpty()) {
            $message = 'Historical event has no boxes to copy. Start building boxes by adding a scent.';
            $this->syncEventPlanningStatus((int) $upcoming->id, 'mapped');
            $this->dispatch('marketsRefreshRequested', eventId: (int) $upcoming->id);
            $this->dispatchPrefillStatus('no_history_rows', $message, $upcoming, $candidate);
            $this->dispatch('toast', ['type' => 'warning', 'message' => $message]);

            return;
        }

        [$added, $merged, $skipped] = $this->applyMarketPlanRowsPrefill($rows, $upcoming);

        if ($added === 0 && $merged === 0) {
            $message = 'Historical event has no usable boxes to copy. Start building boxes by adding a scent.';
            $this->syncEventPlanningStatus((int) $upcoming->id, 'mapped');
            $this->dispatch('marketsRefreshRequested', eventId: (int) $upcoming->id);
            $this->dispatchPrefillStatus('no_history_rows', $message, $upcoming, $candidate, $rows->count());
            $this->dispatch('toast', ['type' => 'warning', 'message' => $message]);

            return;
        }

        $rowCount = $rows->count();
        $message = "Copied {$rowCount} historical box row".($rowCount === 1 ? '' : 's')
            ." from ".($candidate->display_name ?: $candidate->name).". Added {$added}, merged {$merged}"
            .($skipped ? ", skipped {$skipped}" : '').'.';

        $this->syncEventPlanningStatus((int) $upcoming->id, 'drafted');
        $this->dispatch('marketsRefreshRequested', eventId: (int) $upcoming->id);
        $this->dispatch('marketsDraftUpdated', eventId: (int) $upcoming->id);
        $this->dispatchPrefillStatus('applied', $message, $upcoming, $candidate, $rowCount);
        $this->dispatch('toast', ['type' => 'success', 'message' => $message]);
    }

    #[On('marketsDraftUpdated')]
    public function handleMarketsDraftUpdated(int $eventId): void
    {
        if ($eventId > 0) {
            $this->syncEventPlanningStatus($eventId);
        }
    }

    #[On('marketsAddHalfBoxRequested')]
    public function addMarketHalfBox(?int $scentId = null, ?int $upcomingEventId = null): void
    {
        $this->addMarketBoxUnits(1, $scentId, $upcomingEventId);
    }

    #[On('marketsAddFullBoxRequested')]
    public function addMarketFullBox(?int $scentId = null, ?int $upcomingEventId = null): void
    {
        $this->addMarketBoxUnits(2, $scentId, $upcomingEventId);
    }

    #[On('marketsAddTopShelfRequested')]
    public function addTopShelfTemplate(?int $upcomingEventId = null): void
    {
        $selectedEvent = $this->selectedEventById($upcomingEventId);
        if (! $selectedEvent) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select an event first before adding Top Shelf defaults.']);
            return;
        }

        $template = $this->activeTopShelfTemplate();
        if (! $template || $template->items->isEmpty()) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'No active Top Shelf default template is configured.']);
            return;
        }

        $this->rememberSelectedEvent($selectedEvent);
        $added = 0;
        $merged = 0;

        DB::transaction(function () use ($selectedEvent, $template, &$added, &$merged): void {
            foreach ($template->items as $templateItem) {
                $scentId = (int) ($templateItem->scent_id ?? 0);
                if ($scentId <= 0) {
                    continue;
                }

                $existing = null;
                if ($this->supportsRetailPlanItemUpcomingEventColumn()) {
                    $existing = RetailPlanItem::query()
                        ->where('retail_plan_id', $this->plan->id)
                        ->where('source', 'market_top_shelf_template')
                        ->where('upcoming_event_id', $selectedEvent->id)
                        ->where('scent_id', $scentId)
                        ->where('status', '!=', 'published')
                        ->first();
                }

                if ($existing) {
                    $existing->quantity = max(1, (int) $existing->quantity + 1);
                    $existing->save();
                    $merged++;
                    continue;
                }

                $payload = [
                    'retail_plan_id' => $this->plan->id,
                    'scent_id' => $scentId,
                    'size_id' => null,
                    'quantity' => 1,
                    'source' => 'market_top_shelf_template',
                    'status' => 'draft',
                    'sku' => Str::limit("mkttop:manual:{$selectedEvent->id}:{$scentId}", 255, ''),
                ];
                if ($this->supportsRetailPlanItemUpcomingEventColumn()) {
                    $payload['upcoming_event_id'] = $selectedEvent->id;
                }

                RetailPlanItem::query()->create($payload);
                $added++;
            }
        });

        $this->syncEventPlanningStatus((int) $selectedEvent->id, 'drafted');
        $this->dispatch('marketsRefreshRequested', eventId: (int) $selectedEvent->id);
        $this->dispatch('marketsDraftUpdated', eventId: (int) $selectedEvent->id);
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Top Shelf default template applied. Added {$added}, merged {$merged}.",
        ]);
    }

    #[On('marketsPublishRequested')]
    public function submitSelectedEventToPouringRoom(?int $upcomingEventId = null): void
    {
        $event = $this->selectedEventById($upcomingEventId);
        if (! $event) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select an upcoming event first.']);
            return;
        }

        if (! $this->supportsRetailPlanItemUpcomingEventColumn()) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Retail plan items missing upcoming event support. Run migrations first.']);
            return;
        }

        $this->rememberSelectedEvent($event);

        $marketSources = ['market_box_draft', 'market_box_manual', 'market_box_event_prefill', 'event_prefill', 'market_top_shelf_template'];
        $eventItems = $this->plan->items()
            ->where('status', '!=', 'published')
            ->where('upcoming_event_id', $event->id)
            ->whereIn('source', $marketSources)
            ->get();

        if ($eventItems->isEmpty()) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'No draft boxes found for the selected event.']);
            return;
        }

        $invalid = $eventItems->contains(fn (RetailPlanItem $item) => (int) ($item->scent_id ?? 0) <= 0 || (int) ($item->quantity ?? 0) <= 0);
        if ($invalid) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'All boxes must have a mapped scent and quantity before sending to Pouring Room.']);
            return;
        }

        DB::transaction(function () use ($event, $eventItems): void {
            $today = CarbonImmutable::today();
            $marketOrderPayload = [
                'order_type' => 'event',
                'order_label' => 'Markets Box Plan · '.($event->display_name ?: $event->name),
                'order_number' => 'MKT-BOX-' . $today->format('Ymd') . '-' . $event->id,
                'source' => 'internal',
                'status' => 'submitted_to_pouring',
                'ordered_at' => now(),
                'ship_by_at' => $today,
                'due_at' => $today,
                'published_at' => now(),
            ];
            if ($this->supportsOrderEventColumn()) {
                $marketOrderPayload['event_id'] = $event->id;
            }

            $marketOrder = Order::query()->create($marketOrderPayload);

            [$size16CottonId, $size8CottonId, $sizeWaxMeltId] = $this->marketBoxSizeIds();
            foreach ($eventItems as $item) {
                $qty = max(1, (int) $item->quantity);
                $scentId = (int) $item->scent_id;

                if (($item->source ?? '') === 'market_top_shelf_template') {
                    $this->createMarketBoxOrderLine($marketOrder->id, $scentId, $size16CottonId, $qty);
                } else {
                    $this->createMarketBoxOrderLine($marketOrder->id, $scentId, $size16CottonId, $qty * 2);
                    $this->createMarketBoxOrderLine($marketOrder->id, $scentId, $size8CottonId, $qty * 4);
                    $this->createMarketBoxOrderLine($marketOrder->id, $scentId, $sizeWaxMeltId, $qty * 4);
                }

                $item->order_id = $marketOrder->id;
                $item->status = 'published';
                $item->save();
            }
        }, 3);

        $this->syncEventPlanningStatus((int) $event->id, 'submitted');
        $this->dispatch('marketsRefreshRequested', eventId: (int) $event->id);

        $eventLabel = (string) ($event->display_name ?: $event->name ?: 'Event');
        $eventDate = $event->starts_at?->format('M j, Y') ?? 'Date TBD';

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Sent to Pouring Room: {$eventLabel} ({$eventDate}).",
        ]);
        $this->dispatch('event-submitted', [
            'title' => 'Sent to Pouring Room',
            'subtitle' => "{$eventLabel} · {$eventDate}",
            'pegasus_gif' => asset('images/pegasus.gif'),
        ]);
        $this->dispatch('retail-plan-published', [
            'title' => 'Sent to Pouring Room',
            'subtitle' => "{$eventLabel} · {$eventDate}",
            'pegasus_gif' => asset('images/pegasus.gif'),
        ]);
    }

    protected function addMarketBoxUnits(int $halfBoxUnits, ?int $scentId = null, ?int $upcomingEventId = null): void
    {
        $selectedEvent = $this->selectedEventById($upcomingEventId);
        if (! $selectedEvent) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select an event first before adding market boxes.']);
            return;
        }

        $scentId = $scentId && $scentId > 0 ? (int) $scentId : null;
        if (! $scentId) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select a scent first before adding market boxes.']);
            return;
        }

        $this->rememberSelectedEvent($selectedEvent);

        $payload = [
            'retail_plan_id' => $this->plan->id,
            'scent_id' => $scentId,
            'size_id' => null,
            'quantity' => max(1, $halfBoxUnits),
            'source' => 'market_box_manual',
            'status' => 'draft',
            'sku' => 'market-box',
        ];

        if ($this->supportsRetailPlanItemUpcomingEventColumn()) {
            $payload['upcoming_event_id'] = $selectedEvent->id;
        }

        RetailPlanItem::query()->create($payload);

        $this->syncEventPlanningStatus((int) $selectedEvent->id, 'drafted');
        $this->dispatch('marketsRefreshRequested', eventId: (int) $selectedEvent->id);
        $this->dispatch('marketsDraftUpdated', eventId: (int) $selectedEvent->id);
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Market box scent added to plan.']);
    }

    protected function dispatchPrefillStatus(string $state, string $message, ?Event $upcoming = null, ?Event $candidate = null, int $templateRowCount = 0): void
    {
        $this->dispatch(
            'marketsPrefillStatusChanged',
            upcomingEventId: (int) ($upcoming?->id ?? 0),
            candidateEventId: (int) ($candidate?->id ?? 0),
            state: $state,
            message: $message,
            templateRowCount: max(0, $templateRowCount)
        );
    }

    protected function logMissingMappingsTableWarning(int $upcomingEventId, int $candidateEventId): void
    {
        if (! Cache::add('markets:event-mappings-missing-warning', true, now()->addMinutes(10))) {
            return;
        }

        Log::warning('MarketsPlanner missing event_mappings table', [
            'plan_id' => (int) $this->plan->id,
            'upcoming_event_id' => $upcomingEventId,
            'candidate_event_id' => $candidateEventId,
            'expected_table' => 'event_mappings',
            'action' => 'run php artisan migrate',
        ]);
    }

    /**
     * @return Collection<int,MarketPlan>
     */
    protected function marketPlanRowsForCandidateEvent(Event $candidate): Collection
    {
        if (! $candidate->starts_at) {
            return collect();
        }

        $normalizedTitle = app(EventMatchingService::class)
            ->normalizeTitle((string) ($candidate->display_name ?: $candidate->name));
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
    protected function applyMarketPlanRowsPrefill(Collection $rows, Event $upcomingEvent): array
    {
        $added = 0;
        $merged = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $upcomingEvent, &$added, &$merged, &$skipped): void {
            $this->rememberSelectedEvent($upcomingEvent);

            foreach ($rows as $row) {
                $boxType = strtolower(trim((string) $row->box_type));
                $boxCount = max(0, (int) $row->box_count);

                if ($boxCount <= 0) {
                    $skipped++;
                    continue;
                }

                if ($boxType === 'top_shelf') {
                    $result = $this->mergeTopShelfTemplatePrefillItems($upcomingEvent, (int) $row->id, $boxCount);
                    $added += $result['added'];
                    $merged += $result['merged'];
                    $skipped += $result['skipped'];
                    continue;
                }

                if (! in_array($boxType, ['full', 'half'], true)) {
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
                    $boxType
                );

                $added += $result['added'];
                $merged += $result['merged'];
            }
        }, 3);

        return [$added, $merged, $skipped];
    }

    /**
     * @return array{added:int,merged:int,skipped:int}
     */
    protected function mergeTopShelfTemplatePrefillItems(Event $selectedEvent, int $sourceMarketPlanLineId, int $quantityPerScent): array
    {
        $template = $this->activeTopShelfTemplate();
        if (! $template || $template->items->isEmpty()) {
            return ['added' => 0, 'merged' => 0, 'skipped' => 1];
        }

        $added = 0;
        $merged = 0;

        foreach ($template->items as $templateItem) {
            $scentId = (int) ($templateItem->scent_id ?? 0);
            if ($scentId <= 0) {
                continue;
            }

            $existing = null;
            if ($this->supportsRetailPlanItemUpcomingEventColumn()) {
                $existing = RetailPlanItem::query()
                    ->where('retail_plan_id', $this->plan->id)
                    ->where('source', 'market_top_shelf_template')
                    ->where('upcoming_event_id', $selectedEvent->id)
                    ->where('scent_id', $scentId)
                    ->where('status', '!=', 'published')
                    ->first();
            }

            if ($existing) {
                $existing->quantity = max(1, (int) $existing->quantity + max(1, $quantityPerScent));
                if (($existing->status ?? 'draft') === 'needs_mapping') {
                    $existing->status = 'draft';
                }
                $existing->save();
                $merged++;
                continue;
            }

            $payload = [
                'retail_plan_id' => $this->plan->id,
                'order_id' => null,
                'order_line_id' => null,
                'scent_id' => $scentId,
                'size_id' => null,
                'quantity' => max(1, $quantityPerScent),
                'source' => 'market_top_shelf_template',
                'status' => 'draft',
                'sku' => Str::limit("mkttop:{$selectedEvent->id}:{$sourceMarketPlanLineId}:{$scentId}", 255, ''),
            ];

            if ($this->supportsRetailPlanItemUpcomingEventColumn()) {
                $payload['upcoming_event_id'] = $selectedEvent->id;
            }

            RetailPlanItem::query()->create($payload);
            $added++;
        }

        return ['added' => $added, 'merged' => $merged, 'skipped' => 0];
    }

    /**
     * @return array{added:int,merged:int}
     */
    protected function mergeMarketEventPrefillItem(
        Event $selectedEvent,
        int $sourceMarketPlanLineId,
        ?int $scentId,
        int $halfBoxUnits,
        string $rawScent,
        string $boxType,
        ?int $splitIndex = null
    ): array {
        $halfBoxUnits = max(1, $halfBoxUnits);

        if ($scentId) {
            $existing = RetailPlanItem::query()
                ->where('retail_plan_id', $this->plan->id)
                ->whereNull('size_id')
                ->whereIn('source', ['market_box_manual', 'market_box_draft', 'market_box_event_prefill', 'event_prefill'])
                ->when(
                    $this->supportsRetailPlanItemUpcomingEventColumn(),
                    fn ($query) => $query->where('upcoming_event_id', $selectedEvent->id)
                )
                ->where('scent_id', $scentId)
                ->where('status', '!=', 'published')
                ->first();

            if ($existing) {
                $existing->quantity = max(1, (int) $existing->quantity + $halfBoxUnits);
                if (($existing->status ?? 'draft') === 'needs_mapping') {
                    $existing->status = 'draft';
                }
                if ($this->supportsRetailPlanItemUpcomingEventColumn()) {
                    $existing->upcoming_event_id = $selectedEvent->id;
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

        if ($this->supportsRetailPlanItemUpcomingEventColumn()) {
            $payload['upcoming_event_id'] = $selectedEvent->id;
        }

        RetailPlanItem::query()->create($payload);

        return ['added' => 1, 'merged' => 0];
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
        if ($scent === '' || ! str_contains($scent, '/')) {
            return [];
        }

        $parts = array_values(array_filter(array_map('trim', explode('/', $scent))));
        if (count($parts) !== 2) {
            return [];
        }

        $ids = [];
        foreach ($parts as $part) {
            $id = $this->resolveSingleScentIdFromText($part);
            if (! $id) {
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
                foreach (array_filter([$candidate->name, $candidate->display_name, $candidate->abbreviation]) as $name) {
                    if (Scent::normalizeName((string) $name) === $normalized) {
                        return true;
                    }
                }

                return false;
            })?->id;
    }

    protected function selectedEventById(?int $upcomingEventId = null): ?Event
    {
        $eventId = (int) ($upcomingEventId ?: 0);
        if ($eventId <= 0) {
            return null;
        }

        return Event::query()->select(['id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state', 'status'])->find($eventId);
    }

    protected function rememberSelectedEvent(Event $event): void
    {
        if ($this->supportsRetailPlanEventColumn() && (int) ($this->plan->event_id ?? 0) !== (int) $event->id) {
            $this->plan->event_id = $event->id;
            $this->plan->save();
        }
    }

    protected function syncEventPlanningStatus(int $eventId, ?string $forceStatus = null): void
    {
        $event = Event::query()->find($eventId);
        if (! $event) {
            return;
        }

        if ($forceStatus !== null && in_array($forceStatus, ['needs_mapping', 'mapped', 'drafted', 'submitted'], true)) {
            $next = $forceStatus;
        } else {
            $hasDraftRows = $this->supportsRetailPlanItemUpcomingEventColumn()
                ? RetailPlanItem::query()
                    ->where('retail_plan_id', $this->plan->id)
                    ->where('status', '!=', 'published')
                    ->where('upcoming_event_id', $eventId)
                    ->exists()
                : false;
            $hasMapping = $this->supportsEventMappingsTable()
                ? EventMapping::query()->where('upcoming_event_id', $eventId)->exists()
                : false;

            $next = $this->planningStateForEvent((string) ($event->status ?? ''), $hasMapping, $hasDraftRows);
        }

        if ((string) $event->status !== $next) {
            $event->status = $next;
            $event->save();
        }
    }

    protected function planningStateForEvent(string $status, bool $hasMapping, bool $hasDraftRows): string
    {
        $status = strtolower(trim($status));
        if ($status === 'submitted') {
            return 'submitted';
        }

        if ($hasDraftRows || $status === 'drafted') {
            return 'drafted';
        }

        if ($hasMapping || $status === 'mapped') {
            return 'mapped';
        }

        return 'needs_mapping';
    }

    protected function activeTopShelfTemplate(): ?ScentTemplate
    {
        return ScentTemplate::query()
            ->with(['items.scent:id,name,display_name'])
            ->where('type', 'top_shelf')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
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
        if (! $sizeId || $qty <= 0) {
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

    protected function supportsRetailPlanItemUpcomingEventColumn(): bool
    {
        static $supports = null;

        if ($supports === null) {
            $supports = Schema::hasColumn('retail_plan_items', 'upcoming_event_id');
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

    protected function supportsEventMappingsTable(): bool
    {
        return Schema::hasTable('event_mappings');
    }

    public function render()
    {
        return view('livewire.retail.markets.markets-planner');
    }
}
