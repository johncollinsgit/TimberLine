<?php

namespace App\Livewire\Markets;

use App\Models\Event;
use App\Models\MarketPlan;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\Size;
use App\Services\EventMatchingService;
use App\Services\MarketBoxService;
use App\Services\UpcomingMarketEventsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

class MarketPourListBuilder extends Component
{
    public int $step = 1;
    public int $weeksAhead = 6;
    public ?int $selectedEventId = null;

    public ?int $entryScentId = null;
    public string $entryScentName = '';
    public string $entryBoxType = 'full';
    public int $entryBoxCount = 1;
    public int $entryTopShelf16oz = 0;
    public int $entryTopShelf8oz = 0;
    public int $entryTopShelfWaxMelt = 0;

    /** @var array<int,array<string,mixed>> */
    public array $draftEntries = [];

    /** @var array<string,mixed> */
    public array $syncSummary = [];

    /** @var array<string,mixed> */
    public array $matchSummary = [];

    /** @var array<string,mixed> */
    public array $publishSummary = [];

    protected $listeners = [
        'scentSelected' => 'handleScentSelected',
    ];

    public function mount(): void
    {
        $this->weeksAhead = 6;
        $this->resetEntryForm();
    }

    public function handleScentSelected(string $key, ?int $scentId = null, ?string $scentName = null): void
    {
        if ($key !== 'market-plan-wizard') {
            return;
        }

        $this->entryScentId = $scentId;
        $this->entryScentName = $scentName ?? '';
    }

    public function syncUpcomingEvents(): void
    {
        try {
            $result = app(UpcomingMarketEventsService::class)->syncUpcoming($this->weeksAhead);
            $this->syncSummary = $result;

            $this->dispatch('toast', [
                'type' => 'success',
                'message' => "Synced {$result['upserted']} upcoming market event(s) from the Asana/Google Calendar feed.",
            ]);
        } catch (\Throwable $e) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'Calendar sync failed: '.$e->getMessage(),
            ]);
        }
    }

    public function selectEvent(int $eventId): void
    {
        $event = Event::query()->find($eventId);
        if (! $event) {
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Event not found.']);
            return;
        }

        $this->selectedEventId = $event->id;
        $this->step = 2;
        $this->publishSummary = [];

        $this->attemptHistoryPreload($event);
    }

    public function addDraftEntry(): void
    {
        if (! $this->selectedEventId) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select an event first.']);
            return;
        }

        $this->validate($this->entryRules());

        $definition = $this->entryBoxType === 'top_shelf'
            ? $this->currentTopShelfDefinition()
            : null;

        $this->draftEntries[] = [
            'key' => (string) Str::uuid(),
            'scent_id' => (int) $this->entryScentId,
            'scent_name' => $this->resolvedEntryScentName(),
            'box_type' => $this->entryBoxType,
            'box_count' => max(1, (int) $this->entryBoxCount),
            'top_shelf_definition' => $definition,
        ];

        $this->resetEntryForm();

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Box line added.']);
    }

    public function removeDraftEntry(string $key): void
    {
        $before = count($this->draftEntries);
        $this->draftEntries = array_values(array_filter(
            $this->draftEntries,
            fn (array $row) => (string) ($row['key'] ?? '') !== $key
        ));

        if (count($this->draftEntries) !== $before) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Box line removed.']);
        }

        if ($this->step > 2 && empty($this->draftEntries)) {
            $this->step = 2;
        }
    }

    public function incrementDraftEntryCount(string $key): void
    {
        $this->mutateDraftEntry($key, function (array $row): array {
            $row['box_count'] = max(1, (int) ($row['box_count'] ?? 0) + 1);
            return $row;
        });
    }

    public function decrementDraftEntryCount(string $key): void
    {
        $this->mutateDraftEntry($key, function (array $row): array {
            $row['box_count'] = max(1, (int) ($row['box_count'] ?? 0) - 1);
            return $row;
        });
    }

    public function setDraftEntryBoxType(string $key, string $boxType): void
    {
        $boxType = $this->normalizeBoxType($boxType);

        $this->mutateDraftEntry($key, function (array $row) use ($boxType): array {
            $row['box_type'] = $boxType;
            if ($boxType !== 'top_shelf') {
                $row['top_shelf_definition'] = null;
            } elseif (!is_array($row['top_shelf_definition'] ?? null)) {
                $row['top_shelf_definition'] = app(MarketBoxService::class)->emptyTotals();
            }
            return $row;
        });
    }

    public function goToTopShelfStep(): void
    {
        if (! $this->selectedEventId) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select an event first.']);
            return;
        }

        if (empty($this->draftEntries)) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Add at least one box line before continuing.']);
            return;
        }

        $this->step = 3;
    }

    public function continueToReview(): void
    {
        if (! $this->selectedEventId) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select an event first.']);
            return;
        }

        if (empty($this->draftEntries)) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Add at least one box line before reviewing.']);
            return;
        }

        $invalidTopShelf = collect($this->draftEntries)->contains(function (array $row) {
            if (($row['box_type'] ?? '') !== 'top_shelf') {
                return false;
            }

            $normalized = app(MarketBoxService::class)->normalizeTopShelfDefinition((array) ($row['top_shelf_definition'] ?? []));
            return array_sum($normalized) <= 0;
        });

        if ($invalidTopShelf) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'Top Shelf entries need recipe quantities before review.',
            ]);
            $this->step = 3;
            return;
        }

        $this->step = 4;
    }

    public function goToStep(int $step): void
    {
        $step = max(1, min(5, $step));
        if ($step > 1 && ! $this->selectedEventId) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select an event first.']);
            return;
        }

        if ($step > 2 && empty($this->draftEntries)) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Add box lines first.']);
            return;
        }

        $this->step = $step;
    }

    public function publish(): void
    {
        if (! $this->selectedEventId) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select an event first.']);
            return;
        }

        if (empty($this->draftEntries)) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'No box lines to publish.']);
            return;
        }

        $event = Event::query()->find($this->selectedEventId);
        if (! $event) {
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Selected event no longer exists.']);
            return;
        }

        $prepared = $this->preparedEntries();
        $sizeMap = $this->resolveMarketSizeMap();
        $aggregated = $this->aggregateExpandedOrderLines($prepared);

        if (empty($aggregated)) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Expanded totals are empty.']);
            return;
        }

        $order = DB::transaction(function () use ($event, $prepared, $aggregated, $sizeMap) {
            $order = Order::query()->create([
                'order_type' => 'event',
                'order_label' => 'Market Boxes · '.($event->display_name ?: $event->name),
                'order_number' => $this->marketOrderNumber($event),
                'source' => 'internal',
                'status' => 'submitted_to_pouring',
                'ordered_at' => now(),
                'ship_by_at' => $this->shipByForEvent($event),
                'due_at' => $this->dueAtForEvent($event),
                'published_at' => now(),
            ]);

            foreach ($aggregated as $line) {
                $sizeKey = (string) $line['size_key'];
                $size = $sizeMap[$sizeKey] ?? null;
                if (! $size) {
                    continue;
                }

                OrderLine::query()->create([
                    'order_id' => $order->id,
                    'scent_id' => (int) $line['scent_id'],
                    'size_id' => (int) $size['id'],
                    'size_code' => (string) $size['code'],
                    'ordered_qty' => (int) $line['qty'],
                    'extra_qty' => 0,
                    'quantity' => (int) $line['qty'],
                    'wick_type' => $sizeKey === 'wax_melt' ? null : 'cotton',
                ]);
            }

            $matcher = app(EventMatchingService::class);
            $normalizedTitle = $matcher->normalizeTitle($event->display_name ?: $event->name);

            foreach ($prepared as $row) {
                MarketPlan::query()->create([
                    'event_title' => (string) ($event->display_name ?: $event->name),
                    'event_date' => $event->starts_at?->toDateString(),
                    'normalized_title' => $normalizedTitle,
                    'box_type' => (string) $row['box_type'],
                    'scent' => (string) $row['scent_name'],
                    'box_count' => (int) $row['box_count'],
                    'top_shelf_definition_json' => $row['box_type'] === 'top_shelf' ? ($row['top_shelf_definition'] ?? null) : null,
                    'status' => 'published',
                ]);
            }

            return $order;
        }, 3);

        $this->publishSummary = [
            'order_id' => (int) $order->id,
            'order_number' => (string) ($order->order_number ?? ''),
            'event_title' => (string) ($event->display_name ?: $event->name),
            'event_date' => $event->starts_at?->format('M j, Y'),
            'rows' => $this->buildPreviewRows($prepared),
            'by_scent' => $this->buildPreviewByScent($prepared),
            'grand_totals' => $this->buildGrandTotals($prepared),
        ];

        $this->step = 5;
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Published to Pouring Room with expanded counts.']);
    }

    public function render()
    {
        $selectedEvent = $this->selectedEventId
            ? Event::query()->with('market')->find($this->selectedEventId)
            : null;

        $preparedEntries = $this->preparedEntries();

        return view('livewire.markets.builder', [
            'events' => $this->upcomingEvents(),
            'selectedEvent' => $selectedEvent,
            'draftEntryRows' => $this->buildPreviewRows($preparedEntries),
            'previewByScent' => $this->buildPreviewByScent($preparedEntries),
            'grandTotals' => $this->buildGrandTotals($preparedEntries),
            'stepMeta' => $this->stepMeta(),
        ])->layout('layouts.app');
    }

    /**
     * @return array<string,string>
     */
    protected function stepMeta(): array
    {
        return [
            '1' => 'Choose Event',
            '2' => 'Build Boxes',
            '3' => 'Top Shelf Review',
            '4' => 'Confirm Totals',
            '5' => 'Published',
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int,Event>
     */
    protected function upcomingEvents(): Collection
    {
        $cutoff = now()->subDays(7)->toDateString();

        return Event::query()
            ->with('market')
            ->where(function ($q) use ($cutoff) {
                $q->whereDate('starts_at', '>=', $cutoff)
                  ->orWhereNull('starts_at');
            })
            ->orderByRaw("CASE WHEN source = 'asana_calendar' THEN 0 ELSE 1 END")
            ->orderBy('starts_at')
            ->orderBy('display_name')
            ->limit(60)
            ->get();
    }

    protected function attemptHistoryPreload(Event $event): void
    {
        $this->matchSummary = [];

        $historyGroups = MarketPlan::query()
            ->where('status', 'published')
            ->select('event_title', 'normalized_title')
            ->selectRaw('MAX(event_date) as latest_event_date')
            ->groupBy('event_title', 'normalized_title')
            ->get();

        if ($historyGroups->isEmpty()) {
            return;
        }

        $match = app(EventMatchingService::class)->bestMatch(
            (string) ($event->display_name ?: $event->name),
            $historyGroups,
            fn ($row) => (string) $row->event_title
        );

        if (! $match) {
            return;
        }

        $matchedGroup = $match['candidate'];
        $latestDate = $matchedGroup->latest_event_date;
        $normalizedTitle = (string) $matchedGroup->normalized_title;

        $rows = MarketPlan::query()
            ->where('status', 'published')
            ->where('normalized_title', $normalizedTitle)
            ->whereDate('event_date', $latestDate)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $this->draftEntries = $rows->map(function (MarketPlan $row) {
            return [
                'key' => (string) Str::uuid(),
                'scent_id' => $this->resolveScentIdByName((string) $row->scent),
                'scent_name' => (string) $row->scent,
                'box_type' => $this->normalizeBoxType((string) $row->box_type),
                'box_count' => max(1, (int) $row->box_count),
                'top_shelf_definition' => ($row->box_type === 'top_shelf')
                    ? app(MarketBoxService::class)->normalizeTopShelfDefinition((array) ($row->top_shelf_definition_json ?? []))
                    : null,
            ];
        })->all();

        $matchedDate = $rows->first()?->event_date?->format('M j, Y');

        $this->matchSummary = [
            'matched' => true,
            'score_percent' => (int) round(((float) $match['score']) * 100),
            'source_event_title' => (string) $matchedGroup->event_title,
            'source_event_date' => $matchedDate,
            'rows_loaded' => count($this->draftEntries),
        ];

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Preloaded {$this->matchSummary['rows_loaded']} box line(s) from ".$this->matchSummary['source_event_title'].'.',
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function preparedEntries(): array
    {
        return array_values(array_map(function (array $row) {
            $boxType = $this->normalizeBoxType((string) ($row['box_type'] ?? 'full'));
            $boxCount = max(1, (int) ($row['box_count'] ?? 1));
            $scentId = (int) ($row['scent_id'] ?? 0);
            $scentName = trim((string) ($row['scent_name'] ?? ''));
            if ($scentName === '' && $scentId > 0) {
                $scentName = (string) (Scent::query()->find($scentId)?->display_name
                    ?: Scent::query()->find($scentId)?->name
                    ?: '');
            }

            return [
                'key' => (string) ($row['key'] ?? Str::uuid()->toString()),
                'scent_id' => $scentId,
                'scent_name' => $scentName !== '' ? $scentName : 'Unknown scent',
                'box_type' => $boxType,
                'box_count' => $boxCount,
                'top_shelf_definition' => $boxType === 'top_shelf'
                    ? app(MarketBoxService::class)->normalizeTopShelfDefinition((array) ($row['top_shelf_definition'] ?? []))
                    : null,
            ];
        }, $this->draftEntries));
    }

    /**
     * @param  array<int,array<string,mixed>>  $preparedEntries
     * @return array<int,array<string,mixed>>
     */
    protected function buildPreviewRows(array $preparedEntries): array
    {
        $boxService = app(MarketBoxService::class);

        return array_values(array_map(function (array $row) use ($boxService) {
            $expanded = $boxService->expand(
                (string) $row['box_type'],
                (int) $row['box_count'],
                (array) ($row['top_shelf_definition'] ?? [])
            );

            return $row + [
                'expanded' => $expanded,
                'box_type_label' => $this->boxTypeLabel((string) $row['box_type']),
            ];
        }, $preparedEntries));
    }

    /**
     * @param  array<int,array<string,mixed>>  $preparedEntries
     * @return array<int,array<string,mixed>>
     */
    protected function buildPreviewByScent(array $preparedEntries): array
    {
        $rows = $this->buildPreviewRows($preparedEntries);

        $grouped = [];
        foreach ($rows as $row) {
            $key = (string) ($row['scent_id'] ?: $row['scent_name']);
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'scent_id' => (int) ($row['scent_id'] ?? 0),
                    'scent_name' => (string) ($row['scent_name'] ?? 'Unknown scent'),
                    '16oz' => 0,
                    '8oz' => 0,
                    'wax_melt' => 0,
                ];
            }

            $grouped[$key]['16oz'] += (int) ($row['expanded']['16oz'] ?? 0);
            $grouped[$key]['8oz'] += (int) ($row['expanded']['8oz'] ?? 0);
            $grouped[$key]['wax_melt'] += (int) ($row['expanded']['wax_melt'] ?? 0);
        }

        return array_values($grouped);
    }

    /**
     * @param  array<int,array<string,mixed>>  $preparedEntries
     * @return array{16oz:int,8oz:int,wax_melt:int}
     */
    protected function buildGrandTotals(array $preparedEntries): array
    {
        $totals = app(MarketBoxService::class)->emptyTotals();

        foreach ($this->buildPreviewRows($preparedEntries) as $row) {
            $totals = app(MarketBoxService::class)->mergeTotals($totals, (array) $row['expanded']);
        }

        return $totals;
    }

    /**
     * @param  array<int,array<string,mixed>>  $preparedEntries
     * @return array<int,array{scent_id:int,size_key:string,qty:int}>
     */
    protected function aggregateExpandedOrderLines(array $preparedEntries): array
    {
        $rows = $this->buildPreviewRows($preparedEntries);
        $aggregated = [];

        foreach ($rows as $row) {
            $scentId = (int) ($row['scent_id'] ?? 0);
            if ($scentId <= 0) {
                continue;
            }

            foreach (['16oz', '8oz', 'wax_melt'] as $sizeKey) {
                $qty = (int) ($row['expanded'][$sizeKey] ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $aggregateKey = $scentId.'|'.$sizeKey;
                if (! isset($aggregated[$aggregateKey])) {
                    $aggregated[$aggregateKey] = [
                        'scent_id' => $scentId,
                        'size_key' => $sizeKey,
                        'qty' => 0,
                    ];
                }
                $aggregated[$aggregateKey]['qty'] += $qty;
            }
        }

        return array_values($aggregated);
    }

    /**
     * @return array<string,array{id:int,code:string}>
     */
    protected function resolveMarketSizeMap(): array
    {
        $sizes = Size::query()->get(['id', 'code', 'label']);
        $find = function (array $needles) use ($sizes): ?array {
            foreach ($sizes as $size) {
                $haystack = Str::lower(trim(((string) $size->code).' '.((string) $size->label)));
                foreach ($needles as $needle) {
                    if (str_contains($haystack, Str::lower($needle))) {
                        return ['id' => (int) $size->id, 'code' => (string) $size->code];
                    }
                }
            }

            return null;
        };

        $map = [
            '16oz' => $find(['16oz-cotton', '16 oz cotton', '16oz cotton']),
            '8oz' => $find(['8oz-cotton', '8 oz cotton', '8oz cotton']),
            'wax_melt' => $find(['wax-melts', 'wax melts', 'wax melt']),
        ];

        foreach ($map as $sizeKey => $record) {
            if (! $record) {
                throw new \RuntimeException("Missing required size mapping for {$sizeKey}. Expected active sizes for 16oz cotton, 8oz cotton, and wax melts.");
            }
        }

        /** @var array<string,array{id:int,code:string}> $map */
        return $map;
    }

    protected function shipByForEvent(Event $event): \Illuminate\Support\Carbon
    {
        return ($event->ship_date ?: $event->starts_at ?: now())->copy()->startOfDay();
    }

    protected function dueAtForEvent(Event $event): \Illuminate\Support\Carbon
    {
        return ($event->due_date ?: $event->ship_date ?: $event->starts_at ?: now())->copy()->startOfDay();
    }

    protected function marketOrderNumber(Event $event): string
    {
        $date = ($event->starts_at ?: now())->format('Ymd');
        return 'MKT-PLAN-'.$date.'-'.$event->id.'-'.Str::upper(Str::random(4));
    }

    protected function boxTypeLabel(string $boxType): string
    {
        return match ($this->normalizeBoxType($boxType)) {
            'full' => 'Full Box',
            'half' => 'Half Box',
            default => 'Top Shelf Box',
        };
    }

    protected function normalizeBoxType(string $boxType): string
    {
        $boxType = strtolower(trim($boxType));
        return in_array($boxType, ['full', 'half', 'top_shelf'], true) ? $boxType : 'full';
    }

    protected function currentTopShelfDefinition(): array
    {
        return app(MarketBoxService::class)->normalizeTopShelfDefinition([
            '16oz' => $this->entryTopShelf16oz,
            '8oz' => $this->entryTopShelf8oz,
            'wax_melt' => $this->entryTopShelfWaxMelt,
        ]);
    }

    protected function resetEntryForm(): void
    {
        $this->entryScentId = null;
        $this->entryScentName = '';
        $this->entryBoxType = 'full';
        $this->entryBoxCount = 1;
        $this->entryTopShelf16oz = 0;
        $this->entryTopShelf8oz = 0;
        $this->entryTopShelfWaxMelt = 0;
    }

    protected function resolvedEntryScentName(): string
    {
        if ($this->entryScentName !== '') {
            return $this->entryScentName;
        }

        if (! $this->entryScentId) {
            return '';
        }

        $scent = Scent::query()->find($this->entryScentId);
        return (string) ($scent?->display_name ?: $scent?->name ?: '');
    }

    /**
     * @return array<string,string>
     */
    protected function entryRules(): array
    {
        $rules = [
            'entryScentId' => 'required|exists:scents,id',
            'entryBoxType' => 'required|in:full,half,top_shelf',
            'entryBoxCount' => 'required|integer|min:1|max:500',
        ];

        if ($this->entryBoxType === 'top_shelf') {
            $rules['entryTopShelf16oz'] = 'required|integer|min:0|max:500';
            $rules['entryTopShelf8oz'] = 'required|integer|min:0|max:500';
            $rules['entryTopShelfWaxMelt'] = 'required|integer|min:0|max:500';
        }

        return $rules;
    }

    protected function resolveScentIdByName(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $exact = Scent::query()
            ->where('name', $name)
            ->orWhere('display_name', $name)
            ->first(['id']);

        if ($exact) {
            return (int) $exact->id;
        }

        $normalized = Scent::normalizeName($name);

        return Scent::query()
            ->get(['id', 'name', 'display_name'])
            ->first(function (Scent $scent) use ($normalized) {
                foreach ([(string) $scent->name, (string) ($scent->display_name ?? '')] as $candidate) {
                    if ($candidate !== '' && Scent::normalizeName($candidate) === $normalized) {
                        return true;
                    }
                }

                return false;
            })?->id;
    }

    protected function mutateDraftEntry(string $key, callable $mutator): void
    {
        $mutated = false;
        $entries = [];

        foreach ($this->draftEntries as $row) {
            if ((string) ($row['key'] ?? '') === $key) {
                $row = $mutator($row);
                $mutated = true;
            }
            $entries[] = $row;
        }

        $this->draftEntries = $entries;

        if ($mutated) {
            $this->publishSummary = [];
        }
    }
}
