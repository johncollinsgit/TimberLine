<?php

namespace App\Livewire\Retail\Markets;

use App\Models\Event;
use App\Models\EventBoxPlan;
use App\Models\EventInstance;
use App\Models\EventMapping;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\RetailPlan;
use App\Models\RetailPlanItem;
use App\Models\Scent;
use App\Models\ScentTemplate;
use App\Models\Size;
use App\Services\MarketDurationTemplateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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
        $candidate = EventInstance::query()->with(['boxPlans' => fn ($query) => $query->orderBy('id')])->find($candidateEventId);
        if (! $upcoming || ! $candidate) {
            $this->dispatchPrefillStatus(
                'error',
                'Could not load the selected historical template. Pick another match or start fresh.',
                $upcoming?->id,
                $candidate?->id
            );
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Could not confirm the template because one of the records was not found.']);

            return;
        }

        $this->rememberSelectedEvent($upcoming);
        $rows = $candidate->boxPlans;

        Log::info('MarketsPlanner historical prefill evaluated', [
            'upcoming_event_id' => (int) $upcoming->id,
            'candidate_event_instance_id' => (int) $candidate->id,
            'template_row_count' => $rows->count(),
        ]);

        if ($rows->isEmpty()) {
            $message = 'Historical event has no boxes to copy. Start building boxes by adding a scent.';
            $this->syncEventPlanningStatus((int) $upcoming->id, 'mapped');
            $this->dispatch('marketsRefreshRequested', eventId: (int) $upcoming->id);
            $this->dispatchPrefillStatus('no_history_rows', $message, $upcoming->id, $candidate->id);
            $this->dispatch('toast', ['type' => 'warning', 'message' => $message]);

            return;
        }

        $deleted = 0;
        [$added, $merged, $skipped] = DB::transaction(function () use ($upcoming, $rows, &$deleted): array {
            $this->lockUpcomingEventScope($upcoming->id);
            $deleted = $this->clearEventDraftRows($upcoming->id);

            return $this->applyEventBoxPlanRowsPrefill($rows, $upcoming, wrapTransaction: false);
        }, 3);

        if ($added === 0 && $merged === 0) {
            $message = 'Historical event has no usable boxes to copy. Start building boxes by adding a scent.';
            $this->syncEventPlanningStatus((int) $upcoming->id, 'mapped');
            $this->dispatch('marketsRefreshRequested', eventId: (int) $upcoming->id);
            $this->dispatchPrefillStatus('no_history_rows', $message, $upcoming->id, $candidate->id, $rows->count());
            $this->dispatch('toast', ['type' => 'warning', 'message' => $message]);

            return;
        }

        $rowCount = $rows->count();
        $message = "Copied {$rowCount} historical box row".($rowCount === 1 ? '' : 's')
            ." from ".$candidate->title.". Replaced {$deleted} existing row".($deleted === 1 ? '' : 's')
            .". Added {$added}, merged {$merged}"
            .($skipped ? ", skipped {$skipped}" : '').'.';

        $this->syncEventPlanningStatus((int) $upcoming->id, 'drafted');
        $this->dispatch('marketsRefreshRequested', eventId: (int) $upcoming->id);
        $this->dispatch('marketsDraftUpdated', eventId: (int) $upcoming->id);
        $this->dispatchPrefillStatus('applied', $message, $upcoming->id, $candidate->id, $rowCount);
        $this->dispatch('toast', ['type' => 'success', 'message' => $message]);
    }

    #[On('marketsDraftUpdated')]
    public function handleMarketsDraftUpdated(int $eventId): void
    {
        if ($eventId > 0) {
            $this->syncEventPlanningStatus($eventId);
        }
    }

    #[On('marketsDurationTemplateRequested')]
    public function handleMarketsDurationTemplateRequested(int $upcomingEventId, int $durationDays): void
    {
        $upcoming = Event::query()->find($upcomingEventId);
        if (! $upcoming) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select an event first before applying a starter template.']);

            return;
        }

        $durationDays = max(1, min(3, $durationDays));
        $template = app(MarketDurationTemplateService::class)->templateForDays($durationDays);
        $lines = collect($template['lines'] ?? []);

        if ($lines->isEmpty()) {
            $message = "No {$durationDays}-day starter template is available yet.";
            $this->dispatchPrefillStatus('no_history_rows', $message, $upcoming->id, 0, 0);
            $this->dispatch('toast', ['type' => 'warning', 'message' => $message]);

            return;
        }

        $this->rememberSelectedEvent($upcoming);
        $deleted = 0;
        [$added, $merged] = DB::transaction(function () use ($upcoming, $lines, &$deleted): array {
            $this->lockUpcomingEventScope($upcoming->id);
            $deleted = $this->clearEventDraftRows($upcoming->id);

            return $this->applyDurationStarterTemplate($upcoming, $lines, wrapTransaction: false);
        }, 3);
        $rowCount = $lines->count();
        $avgBoxes = (float) ($template['average_boxes'] ?? 0);

        $message = "Applied {$durationDays}-day starter template. {$rowCount} starter rows"
            ." using ".rtrim(rtrim(number_format($avgBoxes, 1), '0'), '.')." average boxes."
            ." Replaced {$deleted} existing row".($deleted === 1 ? '' : 's')."."
            ." Added {$added}, merged {$merged}.";

        $this->syncEventPlanningStatus((int) $upcoming->id, 'drafted');
        $this->dispatch('marketsRefreshRequested', eventId: (int) $upcoming->id);
        $this->dispatch('marketsDraftCreated', upcomingEventId: (int) $upcoming->id, durationDays: $durationDays, templateRowCount: $rowCount);
        $this->dispatchPrefillStatus('applied', $message, $upcoming->id, 0, $rowCount);
        $this->dispatch('toast', ['type' => 'success', 'message' => $message]);
    }

    #[On('marketsResetDraftRequested')]
    public function handleMarketsResetDraftRequested(?int $upcomingEventId = null): void
    {
        $event = $this->selectedEventById($upcomingEventId);
        if (! $event) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select an event first before resetting the draft.']);

            return;
        }

        if (! $this->supportsRetailPlanItemUpcomingEventColumn()) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Retail plan items missing upcoming event support. Run migrations first.']);

            return;
        }

        $deleted = DB::transaction(function () use ($event): int {
            $this->lockUpcomingEventScope($event->id);

            return $this->clearEventDraftRows($event->id);
        }, 3);

        $this->syncEventPlanningStatus((int) $event->id);
        $this->dispatch('marketsRefreshRequested', eventId: (int) $event->id);
        $this->dispatch('marketsDraftUpdated', eventId: (int) $event->id);
        $this->dispatch('marketsDraftReset', eventId: (int) $event->id, deletedRows: $deleted);
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Draft reset. Removed {$deleted} row".($deleted === 1 ? '' : 's').'.',
        ]);
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
        $configuration = $this->topShelfDefaultConfiguration();
        if ($configuration === []) {
            $seedScentId = RetailPlanItem::query()
                ->where('retail_plan_id', $this->plan->id)
                ->where('upcoming_event_id', $selectedEvent->id)
                ->whereIn('source', RetailPlanItem::marketDraftSources())
                ->whereNotNull('scent_id')
                ->value('scent_id');

            if (! $seedScentId) {
                $seedScentId = Scent::query()
                    ->where('is_active', true)
                    ->orderByRaw('COALESCE(display_name, name)')
                    ->value('id');
            }

            if ($seedScentId) {
                $seedScentId = (int) $seedScentId;
                $configuration = RetailPlanItem::normalizeTopShelfConfiguration([
                    'preset' => 'same_12',
                    'size_mode' => '16oz',
                    'slots' => [$seedScentId],
                ], $seedScentId);
            }
        }

        if ($configuration === []) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'No scents are available yet for a Top Shelf line.']);
            Log::info('Top shelf template add skipped: no template and no seed scent available', [
                'upcoming_event_id' => (int) $selectedEvent->id,
                'has_template' => (bool) $template,
            ]);

            return;
        }

        $this->rememberSelectedEvent($selectedEvent);
        $result = ['added' => 0, 'merged' => 0, 'item_id' => 0];

        DB::transaction(function () use ($selectedEvent, $configuration, &$result): void {
            $result = $this->mergeTopShelfBoxItem(
                $selectedEvent,
                0,
                1,
                'market_top_shelf_template',
                $configuration
            );
        });

        $this->syncEventPlanningStatus((int) $selectedEvent->id, 'drafted');
        $this->dispatch('marketsRefreshRequested', eventId: (int) $selectedEvent->id);
        $this->dispatch('marketsDraftUpdated', eventId: (int) $selectedEvent->id);
        $this->dispatch(
            'marketsTopShelfAdded',
            eventId: (int) $selectedEvent->id,
            itemId: (int) ($result['item_id'] ?? 0)
        );
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Top Shelf default template applied. Added {$result['added']}, merged {$result['merged']}.",
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

        $marketSources = RetailPlanItem::marketDraftSources();
        $eventItems = $this->plan->items()
            ->where('status', '!=', 'published')
            ->where('upcoming_event_id', $event->id)
            ->whereIn('source', $marketSources)
            ->get();

        if ($eventItems->isEmpty()) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'No draft boxes found for the selected event.']);
            return;
        }

        $invalid = $eventItems->contains(function (RetailPlanItem $item): bool {
            if ((int) ($item->quantity ?? 0) <= 0) {
                return true;
            }

            $boxTier = $this->supportsRetailPlanItemBoxTierColumn()
                ? strtolower(trim((string) ($item->box_tier ?? 'standard')))
                : (($item->source ?? '') === 'market_top_shelf_template' ? 'top_shelf' : 'standard');

            if ($boxTier !== 'top_shelf') {
                return (int) ($item->scent_id ?? 0) <= 0;
            }

            return ! RetailPlanItem::topShelfConfigurationIsComplete(
                RetailPlanItem::decodeTopShelfConfiguration(
                    $this->supportsRetailPlanItemNotesColumn() ? $item->notes : null,
                    $item->scent_id ? (int) $item->scent_id : null
                )
            );
        });
        if ($invalid) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'All boxes must have a valid scent setup and quantity before sending to Pouring Room.']);
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
                $boxTier = $this->supportsRetailPlanItemBoxTierColumn()
                    ? strtolower(trim((string) ($item->box_tier ?? 'standard')))
                    : (($item->source ?? '') === 'market_top_shelf_template' ? 'top_shelf' : 'standard');

                if ($boxTier === 'top_shelf') {
                    $this->publishTopShelfRow(
                        $marketOrder->id,
                        $item,
                        $qty,
                        $size16CottonId,
                        $size8CottonId,
                        $sizeWaxMeltId
                    );
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
        if ($this->supportsRetailPlanItemBoxTierColumn()) {
            $payload['box_tier'] = 'standard';
        }
        if ($this->supportsRetailPlanItemNotesColumn()) {
            $payload['notes'] = null;
        }

        RetailPlanItem::query()->create($payload);

        $this->syncEventPlanningStatus((int) $selectedEvent->id, 'drafted');
        $this->dispatch('marketsRefreshRequested', eventId: (int) $selectedEvent->id);
        $this->dispatch('marketsDraftUpdated', eventId: (int) $selectedEvent->id);
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Market box scent added to plan.']);
    }

    protected function dispatchPrefillStatus(string $state, string $message, ?int $upcomingEventId = null, ?int $candidateEventId = null, int $templateRowCount = 0): void
    {
        $this->dispatch(
            'marketsPrefillStatusChanged',
            upcomingEventId: (int) ($upcomingEventId ?? 0),
            candidateEventId: (int) ($candidateEventId ?? 0),
            state: $state,
            message: $message,
            templateRowCount: max(0, $templateRowCount)
        );
    }

    /**
     * @param  Collection<int,EventBoxPlan>  $rows
     * @return array{0:int,1:int,2:int}
     */
    protected function applyEventBoxPlanRowsPrefill(Collection $rows, Event $upcomingEvent, bool $wrapTransaction = true): array
    {
        $added = 0;
        $merged = 0;
        $skipped = 0;

        $apply = function () use ($rows, $upcomingEvent, &$added, &$merged, &$skipped): void {
            $this->rememberSelectedEvent($upcomingEvent);

            foreach ($rows as $row) {
                $sentBoxes = max(0, (float) ($row->box_count_sent ?? 0));

                if ($sentBoxes <= 0) {
                    $skipped++;
                    continue;
                }

                $rawScent = trim((string) $row->scent_raw);
                if (RetailPlanItem::isTopShelfLabel($rawScent)) {
                    $result = $this->mergeTopShelfBoxItem(
                        $upcomingEvent,
                        (int) $row->id,
                        max(1, (int) round($sentBoxes)),
                        'event_prefill'
                    );

                    $added += $result['added'];
                    $merged += $result['merged'];
                    continue;
                }

                $splitScentIds = $this->resolveSplitScentIdsFromText($rawScent);
                if ((bool) $row->is_split_box || count($splitScentIds) === 2) {
                    if (count($splitScentIds) !== 2) {
                        $skipped++;
                        continue;
                    }

                    $halfBoxUnitsPerScent = max(1, (int) round($sentBoxes));

                    foreach ($splitScentIds as $index => $splitScentId) {
                        $result = $this->mergeMarketEventPrefillItem(
                            $upcomingEvent,
                            (int) $row->id,
                            (int) $splitScentId,
                            $halfBoxUnitsPerScent,
                            $rawScent,
                            'split',
                            $index + 1
                        );

                        $added += $result['added'];
                        $merged += $result['merged'];
                    }

                    continue;
                }

                $scentId = $this->resolveScentIdFromText($rawScent);
                if (! $scentId) {
                    Log::info('MarketsPlanner could not resolve scent from historical row', [
                        'upcoming_event_id' => (int) $upcomingEvent->id,
                        'source_market_plan_row_id' => (int) $row->id,
                        'raw_scent' => $rawScent,
                        'sent_boxes' => $sentBoxes,
                    ]);
                }
                $halfBoxUnits = $this->normalizedStandardHalfBoxUnits($sentBoxes);
                $result = $this->mergeMarketEventPrefillItem(
                    $upcomingEvent,
                    (int) $row->id,
                    $scentId,
                    $halfBoxUnits,
                    $rawScent,
                    'full'
                );

                $added += $result['added'];
                $merged += $result['merged'];
            }
        };

        if ($wrapTransaction) {
            DB::transaction($apply, 3);
        } else {
            $apply();
        }

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

        $result = $this->mergeTopShelfBoxItem(
            $selectedEvent,
            $sourceMarketPlanLineId,
            max(1, $quantityPerScent),
            'market_top_shelf_template',
            $this->topShelfDefaultConfiguration()
        );

        return [
            'added' => $result['added'],
            'merged' => $result['merged'],
            'skipped' => 0,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $configuration
     * @return array{added:int,merged:int,item_id:int}
     */
    protected function mergeTopShelfBoxItem(
        Event $selectedEvent,
        int $sourceMarketPlanLineId,
        int $boxCount,
        string $source = 'event_prefill',
        ?array $configuration = null
    ): array {
        $boxCount = max(1, $boxCount);
        $defaultPrimaryScentId = $this->topShelfDefaultPrimaryScentId();
        $rawConfiguration = $configuration ?? [];

        if ($rawConfiguration === [] && ! $defaultPrimaryScentId) {
            Log::info('Top shelf merge skipped: no default template configuration available', [
                'upcoming_event_id' => (int) $selectedEvent->id,
                'source_market_plan_line_id' => $sourceMarketPlanLineId,
                'source' => $source,
            ]);

            return ['added' => 0, 'merged' => 0, 'item_id' => 0];
        }

        $configuration = RetailPlanItem::normalizeTopShelfConfiguration(
            $rawConfiguration,
            $defaultPrimaryScentId
        );
        $primaryScentId = $this->topShelfPrimaryScentId($configuration);

        if (! $primaryScentId) {
            Log::info('Top shelf merge skipped: configuration has no primary scent', [
                'upcoming_event_id' => (int) $selectedEvent->id,
                'source_market_plan_line_id' => $sourceMarketPlanLineId,
                'source' => $source,
            ]);

            return ['added' => 0, 'merged' => 0, 'item_id' => 0];
        }

        $notes = $this->supportsRetailPlanItemNotesColumn()
            ? RetailPlanItem::encodeTopShelfConfiguration($configuration, $primaryScentId)
            : null;

        $existing = RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->whereNull('size_id')
            ->where('source', $source)
            ->when(
                $this->supportsRetailPlanItemUpcomingEventColumn(),
                fn ($query) => $query->where('upcoming_event_id', $selectedEvent->id)
            )
            ->where('status', '!=', 'published')
            ->when(
                $this->supportsRetailPlanItemBoxTierColumn(),
                fn ($query) => $query->where('box_tier', 'top_shelf')
            )
            ->when(
                $this->supportsRetailPlanItemNotesColumn(),
                fn ($query) => $query->where('notes', $notes)
            )
            ->first();

        if ($existing) {
            $existing->quantity = max(1, (int) $existing->quantity + $boxCount);
            $existing->scent_id = $primaryScentId;
            $existing->status = RetailPlanItem::topShelfConfigurationIsComplete($configuration) ? 'draft' : 'needs_mapping';
            if ($this->supportsRetailPlanItemUpcomingEventColumn()) {
                $existing->upcoming_event_id = $selectedEvent->id;
            }
            if ($this->supportsRetailPlanItemBoxTierColumn()) {
                $existing->box_tier = 'top_shelf';
            }
            if ($this->supportsRetailPlanItemNotesColumn()) {
                $existing->notes = $notes;
            }
            $existing->save();

            return ['added' => 0, 'merged' => 1, 'item_id' => (int) $existing->id];
        }

        $payload = [
            'retail_plan_id' => $this->plan->id,
            'order_id' => null,
            'order_line_id' => null,
            'scent_id' => $primaryScentId,
            'size_id' => null,
            'quantity' => $boxCount,
            'source' => $source,
            'status' => RetailPlanItem::topShelfConfigurationIsComplete($configuration) ? 'draft' : 'needs_mapping',
            'sku' => Str::limit(
                'mkttop:'.$selectedEvent->id.':'.$sourceMarketPlanLineId.':'.($sourceMarketPlanLineId > 0 ? $sourceMarketPlanLineId : 'manual'),
                255,
                ''
            ),
        ];

        if ($this->supportsRetailPlanItemUpcomingEventColumn()) {
            $payload['upcoming_event_id'] = $selectedEvent->id;
        }
        if ($this->supportsRetailPlanItemBoxTierColumn()) {
            $payload['box_tier'] = 'top_shelf';
        }
        if ($this->supportsRetailPlanItemNotesColumn()) {
            $payload['notes'] = $notes;
        }

        $created = RetailPlanItem::query()->create($payload);

        return ['added' => 1, 'merged' => 0, 'item_id' => (int) $created->id];
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
                ->whereIn('source', RetailPlanItem::marketMergeableSources())
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
                if ($this->supportsRetailPlanItemBoxTierColumn()) {
                    $existing->box_tier = $boxType === 'top_shelf' ? 'top_shelf' : 'standard';
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
        if ($this->supportsRetailPlanItemBoxTierColumn()) {
            $payload['box_tier'] = $boxType === 'top_shelf' ? 'top_shelf' : 'standard';
        }
        if ($this->supportsRetailPlanItemNotesColumn()) {
            $payload['notes'] = null;
        }

        RetailPlanItem::query()->create($payload);

        return ['added' => 1, 'merged' => 0];
    }

    protected function resolveScentIdFromText(string $scent): ?int
    {
        return $this->resolveSingleScentIdFromText($scent);
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $lines
     * @return array{0:int,1:int}
     */
    protected function applyDurationStarterTemplate(Event $upcomingEvent, Collection $lines, bool $wrapTransaction = true): array
    {
        $added = 0;
        $merged = 0;

        $apply = function () use ($upcomingEvent, $lines, &$added, &$merged): void {
            foreach ($lines as $index => $line) {
                $scentRaw = trim((string) ($line['scent_raw'] ?? ''));
                $halfBoxUnits = max(1, (int) ($line['half_box_units'] ?? 1));
                $scentId = $this->resolveScentIdFromText($scentRaw);
                $result = $this->mergeDurationTemplateItem(
                    $upcomingEvent,
                    $index + 1,
                    $scentId,
                    $halfBoxUnits,
                    $scentRaw,
                    (string) ($line['box_tier'] ?? 'standard')
                );

                $added += $result['added'];
                $merged += $result['merged'];
            }
        };

        if ($wrapTransaction) {
            DB::transaction($apply, 3);
        } else {
            $apply();
        }

        return [$added, $merged];
    }

    /**
     * @return array{added:int,merged:int}
     */
    protected function mergeDurationTemplateItem(
        Event $selectedEvent,
        int $templateIndex,
        ?int $scentId,
        int $halfBoxUnits,
        string $rawScent,
        string $boxTier = 'standard'
    ): array {
        $halfBoxUnits = max(1, $halfBoxUnits);
        $boxTier = strtolower(trim($boxTier)) === 'top_shelf' ? 'top_shelf' : 'standard';

        if ($scentId) {
            $existing = RetailPlanItem::query()
                ->where('retail_plan_id', $this->plan->id)
                ->whereNull('size_id')
                ->whereIn('source', RetailPlanItem::marketMergeableSources())
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
                if ($this->supportsRetailPlanItemBoxTierColumn()) {
                    $existing->box_tier = $boxTier;
                }
                $existing->save();

                return ['added' => 0, 'merged' => 1];
            }
        }

        $payload = [
            'retail_plan_id' => $this->plan->id,
            'order_id' => null,
            'order_line_id' => null,
            'scent_id' => $scentId,
            'size_id' => null,
            'quantity' => $halfBoxUnits,
            'source' => 'market_duration_template',
            'status' => $scentId ? 'draft' : 'needs_mapping',
            'sku' => Str::limit('mkttpl:'.$selectedEvent->id.':'.$templateIndex.':'.(Str::slug($rawScent) ?: 'scent'), 255, ''),
        ];

        if ($this->supportsRetailPlanItemUpcomingEventColumn()) {
            $payload['upcoming_event_id'] = $selectedEvent->id;
        }
        if ($this->supportsRetailPlanItemBoxTierColumn()) {
            $payload['box_tier'] = $boxTier;
        }
        if ($this->supportsRetailPlanItemNotesColumn()) {
            $payload['notes'] = null;
        }

        RetailPlanItem::query()->create($payload);

        return ['added' => 1, 'merged' => 0];
    }

    /**
     * @return array<int,int>
     */
    protected function resolveSplitScentIdsFromText(string $scent): array
    {
        $scent = trim($scent);
        if ($scent === '' || (! str_contains($scent, '/') && ! str_contains($scent, '+'))) {
            return [];
        }

        $parts = array_values(array_filter(array_map(
            'trim',
            preg_split('/\s*(?:\/|\+)\s*/', $scent) ?: []
        )));
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

        $variants = $this->scentResolutionVariants($scent);
        $normalizedVariants = array_values(array_unique(array_filter(array_map(
            fn (string $value): string => Scent::normalizeName($value),
            $variants
        ))));
        $searchKeys = array_values(array_unique(array_filter(array_map(
            fn (string $value): string => $this->scentSearchKey($value),
            $variants
        ))));

        $match = Scent::query()
            ->where(function ($query) use ($variants): void {
                foreach ($variants as $value) {
                    $query->orWhere('name', $value)
                        ->orWhere('display_name', $value)
                        ->orWhere('abbreviation', $value);
                }
            })
            ->get(['id', 'name', 'display_name', 'abbreviation'])
            ->first();

        if ($match) {
            return (int) $match->id;
        }

        $candidates = $this->scentResolutionCandidates();

        foreach ($normalizedVariants as $normalized) {
            $exact = $candidates->first(function (array $candidate) use ($normalized): bool {
                return in_array($normalized, $candidate['normalized'], true);
            });
            if ($exact) {
                return (int) $exact['id'];
            }
        }

        foreach ($searchKeys as $key) {
            $exactKey = $candidates->first(function (array $candidate) use ($key): bool {
                return in_array($key, $candidate['keys'], true);
            });
            if ($exactKey) {
                return (int) $exactKey['id'];
            }
        }

        $bestId = null;
        $bestScore = 0.0;
        $secondBestScore = 0.0;

        foreach ($searchKeys as $needle) {
            foreach ($candidates as $candidate) {
                foreach ($candidate['keys'] as $key) {
                    $score = $this->scentSimilarityScore($needle, $key);
                    if ($score > $bestScore) {
                        $secondBestScore = $bestScore;
                        $bestScore = $score;
                        $bestId = (int) $candidate['id'];
                    } elseif ($score > $secondBestScore) {
                        $secondBestScore = $score;
                    }
                }
            }
        }

        if ($bestId === null) {
            return null;
        }

        $gap = $bestScore - $secondBestScore;
        if ($bestScore >= 0.90 || ($bestScore >= 0.74 && $gap >= 0.06)) {
            return $bestId;
        }

        return null;
    }

    protected function normalizedStandardHalfBoxUnits(float $sentBoxes): int
    {
        $halfBoxUnits = max(1, (int) round($sentBoxes * 2));

        // Keep half-box granularity for 0.5 rows, but snap larger odd totals
        // to full boxes so we don't flood drafts with 1.5/2.5 unless explicit.
        if ($halfBoxUnits > 1 && $halfBoxUnits % 2 !== 0) {
            $halfBoxUnits = max(2, (int) round($halfBoxUnits / 2) * 2);
        }

        return $halfBoxUnits;
    }

    /**
     * @return array<int,string>
     */
    protected function scentResolutionVariants(string $scent): array
    {
        $raw = trim($scent);
        if ($raw === '') {
            return [];
        }

        $variants = [$raw];

        foreach (['/', '+', ','] as $delimiter) {
            if (! str_contains($raw, $delimiter)) {
                continue;
            }

            foreach (explode($delimiter, $raw) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $variants[] = $part;
                }
            }
        }

        return array_values(array_unique($variants));
    }

    protected function scentSearchKey(string $value): string
    {
        $value = Scent::normalizeName($value);
        $value = Str::lower($value);
        $value = preg_replace('/\([^)]*\)/', ' ', $value) ?? $value;
        $value = preg_replace('/\b(wholesale|candle|candles|cotton|wick|wicks|box|boxes|jar|jars|16oz|8oz|oz|wax|melt|melts)\b/', ' ', $value) ?? $value;
        $value = preg_replace('/\b\d+(?:\.\d+)?\b/', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? $value;

        return trim($value);
    }

    protected function scentSimilarityScore(string $needle, string $candidate): float
    {
        if ($needle === '' || $candidate === '') {
            return 0.0;
        }

        if ($needle === $candidate) {
            return 1.0;
        }

        $needleCompact = str_replace(' ', '', $needle);
        $candidateCompact = str_replace(' ', '', $candidate);

        if ($needleCompact !== '' && $needleCompact === $candidateCompact) {
            return 0.97;
        }

        if (strlen($needle) >= 4 && (str_contains($candidate, $needle) || str_contains($needle, $candidate))) {
            return 0.92;
        }

        similar_text($needle, $candidate, $similarityPercent);
        $similarity = $similarityPercent / 100;

        $needleTokens = array_values(array_filter(explode(' ', $needle)));
        $candidateTokens = array_values(array_filter(explode(' ', $candidate)));
        $tokenOverlap = 0.0;

        if ($needleTokens !== [] && $candidateTokens !== []) {
            $common = array_intersect($needleTokens, $candidateTokens);
            $tokenOverlap = count($common) / max(count($needleTokens), count($candidateTokens));
        }

        return ($similarity * 0.75) + ($tokenOverlap * 0.25);
    }

    /**
     * @return Collection<int,array{id:int,normalized:array<int,string>,keys:array<int,string>}>
     */
    protected function scentResolutionCandidates(): Collection
    {
        $rows = Scent::query()->get(['id', 'name', 'display_name', 'abbreviation']);
        
        return $rows
            ->map(function (Scent $candidate): array {
                $labels = array_values(array_unique(array_filter(array_map(
                    fn ($value): string => trim((string) $value),
                    [$candidate->name, $candidate->display_name, $candidate->abbreviation]
                ))));

                $normalized = array_values(array_unique(array_filter(array_map(
                    fn (string $value): string => Scent::normalizeName($value),
                    $labels
                ))));

                $keys = array_values(array_unique(array_filter(array_map(
                    fn (string $value): string => $this->scentSearchKey($value),
                    $labels
                ))));

                return [
                    'id' => (int) $candidate->id,
                    'normalized' => $normalized,
                    'keys' => $keys,
                ];
            })
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    protected function topShelfDefaultConfiguration(): array
    {
        $template = $this->activeTopShelfTemplate();
        if (! $template) {
            return [];
        }

        $slots = $template
            ->items
            ->pluck('scent_id')
            ->filter(fn ($id): bool => (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->take(12)
            ->values()
            ->all();

        if ($slots === []) {
            return [];
        }

        $preset = match (count($slots)) {
            2 => 'split_6_6',
            3 => 'split_4_4_4',
            4 => 'split_3_3_3_3',
            default => count($slots) >= 5 ? 'different_12' : 'same_12',
        };

        return RetailPlanItem::normalizeTopShelfConfiguration([
            'preset' => $preset,
            'size_mode' => '16oz',
            'slots' => $slots,
        ], $slots[0] ?? null);
    }

    protected function topShelfDefaultPrimaryScentId(): ?int
    {
        $configuration = $this->topShelfDefaultConfiguration();
        if ($configuration === []) {
            return null;
        }

        return $this->topShelfPrimaryScentId($configuration);
    }

    /**
     * @param  array<string,mixed>  $configuration
     */
    protected function topShelfPrimaryScentId(array $configuration): ?int
    {
        foreach ((array) ($configuration['composition'] ?? []) as $slot) {
            $scentId = (int) ($slot['scent_id'] ?? 0);
            if ($scentId > 0) {
                return $scentId;
            }
        }

        return null;
    }

    protected function publishTopShelfRow(
        int $orderId,
        RetailPlanItem $item,
        int $boxCount,
        ?int $size16CottonId,
        ?int $size8CottonId,
        ?int $sizeWaxMeltId
    ): void {
        $configuration = RetailPlanItem::decodeTopShelfConfiguration(
            $this->supportsRetailPlanItemNotesColumn() ? $item->notes : null,
            $item->scent_id ? (int) $item->scent_id : null
        );
        $sizeMode = (string) ($configuration['size_mode'] ?? '16oz');
        $sizeId = match ($sizeMode) {
            '8oz' => $size8CottonId,
            'wax_melt' => $sizeWaxMeltId,
            default => $size16CottonId,
        };

        foreach ((array) ($configuration['composition'] ?? []) as $slot) {
            $scentId = (int) ($slot['scent_id'] ?? 0);
            $unitsPerBox = max(0, (int) ($slot['units_per_box'] ?? 0));

            if ($scentId <= 0 || $unitsPerBox <= 0) {
                continue;
            }

            $this->createMarketBoxOrderLine($orderId, $scentId, $sizeId, $boxCount * $unitsPerBox);
        }
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
        static $loggedMissingTables = false;

        $templatesTableExists = Schema::hasTable('scent_templates');
        $templateItemsTableExists = Schema::hasTable('scent_template_items');

        if (! $templatesTableExists || ! $templateItemsTableExists) {
            if (! $loggedMissingTables) {
                Log::warning('Top shelf templates disabled: template tables missing', [
                    'scent_templates_exists' => $templatesTableExists,
                    'scent_template_items_exists' => $templateItemsTableExists,
                ]);
                $loggedMissingTables = true;
            }

            return null;
        }

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

    protected function lockUpcomingEventScope(int $eventId): void
    {
        Event::query()
            ->whereKey($eventId)
            ->lockForUpdate()
            ->first();
    }

    protected function clearEventDraftRows(int $eventId): int
    {
        if (! $this->supportsRetailPlanItemUpcomingEventColumn()) {
            return 0;
        }

        return RetailPlanItem::query()
            ->where('retail_plan_id', $this->plan->id)
            ->where('status', '!=', 'published')
            ->where('upcoming_event_id', $eventId)
            ->whereIn('source', RetailPlanItem::marketDraftSources())
            ->delete();
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

    protected function supportsRetailPlanItemBoxTierColumn(): bool
    {
        static $supports = null;

        if ($supports === null) {
            $supports = Schema::hasColumn('retail_plan_items', 'box_tier');
        }

        return $supports;
    }

    protected function supportsRetailPlanItemNotesColumn(): bool
    {
        static $supports = null;

        if ($supports === null) {
            $supports = Schema::hasColumn('retail_plan_items', 'notes');
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
