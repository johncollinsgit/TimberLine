<?php

namespace App\Livewire\PouringRoom;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Services\Pouring\MeasurementResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class OrderDetail extends Component
{
    public Order $order;
    public bool $showCompleted = false;
    public ?string $returnTo = null;
    /** @var array<string,bool> */
    public array $expandedScents = [];
    /** @var array<string,string> */
    public array $scentStatuses = [];
    /** @var array<string,string> */
    public array $persistedScentStatuses = [];

    protected array $statusOptions = [
        'queued' => 'Queued',
        'laid_out' => 'Laid Out',
        'first_pour' => 'First Pour',
        'second_pour' => 'Second Pour',
        'waiting_on_oil' => 'Waiting on Oil (Paused)',
        'brought_down' => 'Brought Down',
    ];

    protected $queryString = [
        'showCompleted' => ['except' => false],
    ];

    public function mount(Order $order): void
    {
        $this->order = $order->load(['lines.scent.oilBlend.components.baseOil', 'lines.size']);
        $this->returnTo = $this->sanitizeReturnTo((string) request()->query('return_to', ''));
        $this->syncScentStateFromRows($this->buildScentSummaries(), true);
    }

    public function start(): void
    {
        if ((string) ($this->order->status ?? '') === 'brought_down') {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Order is already brought down.']);

            return;
        }

        if ((string) ($this->order->status ?? '') !== 'pouring') {
            $this->order->status = 'pouring';
            $this->order->save();
            $this->dispatch('toast', ['type' => 'success', 'message' => 'Order started.']);

            return;
        }

        $this->dispatch('toast', ['type' => 'info', 'message' => 'Order is already in process.']);
    }

    public function complete(): void
    {
        $rows = $this->buildScentSummaries();
        $this->syncScentStateFromRows($rows);

        $pendingChanges = $this->pendingStatusChangeCount($rows);
        if ($pendingChanges > 0) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'Save scent status changes before completing this order.',
            ]);

            return;
        }

        if (! $this->allScentsBroughtDown($rows)) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'Cannot complete order until every scent is marked Brought Down.',
            ]);

            return;
        }

        $this->order->status = 'brought_down';
        $this->order->save();

        $fallback = route('pouring.stack', $this->order->channel ?? $this->order->order_type ?? 'retail');
        $destination = $this->returnTo ?: $fallback;
        $this->redirect($this->appendCelebrateFlag($destination), navigate: true);
    }

    public function toggleCompleted(): void
    {
        $this->showCompleted = !$this->showCompleted;
    }

    public function toggleScent(string $scentKey): void
    {
        if ($scentKey === '') {
            return;
        }

        $this->expandedScents[$scentKey] = ! (bool) ($this->expandedScents[$scentKey] ?? false);
    }

    public function saveScentStatuses(): void
    {
        $rows = $this->buildScentSummaries();
        $this->syncScentStateFromRows($rows);

        $changes = [];

        foreach ($rows as $row) {
            $scentKey = (string) ($row['key'] ?? '');
            $lookup = is_array($row['scent_lookup'] ?? null) ? $row['scent_lookup'] : [];
            $lookupType = (string) ($lookup['type'] ?? '');
            $lookupId = (int) ($lookup['id'] ?? 0);
            $lookupName = trim((string) ($lookup['name'] ?? ''));

            if ($scentKey === '' || ($lookupType !== 'id' && $lookupType !== 'name')) {
                continue;
            }

            $persisted = $this->normalizeLineStatus(
                (string) ($this->persistedScentStatuses[$scentKey] ?? $row['status'] ?? 'queued')
            );
            $next = $this->normalizeLineStatus(
                (string) ($this->scentStatuses[$scentKey] ?? $persisted)
            );

            if (! array_key_exists($next, $this->statusOptions) || $next === $persisted) {
                continue;
            }

            if ($lookupType === 'id' && $lookupId <= 0) {
                continue;
            }

            if ($lookupType === 'name' && $lookupName === '') {
                continue;
            }

            $changes[] = [
                'status' => $next,
                'scent_key' => $scentKey,
                'lookup_type' => $lookupType,
                'lookup_id' => $lookupId,
                'lookup_name' => $lookupName,
            ];
        }

        if ($changes === []) {
            $this->dispatch('toast', ['type' => 'info', 'message' => 'No scent status changes to save.']);

            return;
        }

        $updatedScentCount = 0;
        $enteredActiveFlow = false;

        DB::transaction(function () use ($changes, &$updatedScentCount, &$enteredActiveFlow): void {
            foreach ($changes as $change) {
                $status = (string) ($change['status'] ?? 'queued');
                $updates = ['pour_status' => $status];

                if ($status === 'brought_down') {
                    $updates['brought_down_at'] = now();
                } else {
                    $updates['brought_down_at'] = null;
                }

                $query = $this->order->lines();

                if ((string) ($change['lookup_type'] ?? '') === 'id') {
                    $query->where('scent_id', (int) ($change['lookup_id'] ?? 0));
                } else {
                    $lookupName = mb_strtolower(trim((string) ($change['lookup_name'] ?? '')));
                    $query->whereRaw('lower(trim(coalesce(scent_name, \'\'))) = ?', [$lookupName]);
                }

                $affected = $query->update($updates);

                if ($affected > 0) {
                    $updatedScentCount++;
                    if ($status !== 'queued') {
                        $enteredActiveFlow = true;
                    }
                }
            }
        }, 3);

        if ($updatedScentCount <= 0) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'No scent status updates were applied.',
            ]);

            return;
        }

        if ($enteredActiveFlow) {
            $this->markOrderInProcessIfNeeded();
        }

        $this->order->refresh()->load(['lines.scent.oilBlend.components.baseOil', 'lines.size']);
        $this->syncScentStateFromRows($this->buildScentSummaries(), true);

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Saved {$updatedScentCount} scent status".($updatedScentCount === 1 ? '' : 'es').'.',
        ]);
    }

    public function setGroupStatus(string $groupKey, string $status): void
    {
        if (! array_key_exists($status, $this->statusOptions)) {
            return;
        }

        [$scentId, $sizeId, $wickType] = array_pad(explode(':', $groupKey, 3), 3, '');

        if (!$scentId || !$sizeId) {
            return;
        }

        $query = $this->order->lines()
            ->where('scent_id', (int) $scentId)
            ->where('size_id', (int) $sizeId);

        if ($wickType === '') {
            $query->where(function ($q) {
                $q->whereNull('wick_type')->orWhere('wick_type', '');
            });
        } else {
            $query->where('wick_type', $wickType);
        }

        $updates = ['pour_status' => $status];
        if ($status === 'brought_down') {
            $updates['brought_down_at'] = now();
        } else {
            $updates['brought_down_at'] = null;
        }

        $query->update($updates);
        if ($status !== 'queued') {
            $this->markOrderInProcessIfNeeded();
        }

        $this->order->refresh()->load(['lines.scent.oilBlend.components.baseOil', 'lines.size']);
        $this->syncScentStateFromRows($this->buildScentSummaries(), true);

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Status set to ' . ($this->statusOptions[$status] ?? $status) . '.',
        ]);
    }

    public function render()
    {
        $allRows = $this->buildScentSummaries();
        $this->syncScentStateFromRows($allRows);

        $completedCount = $allRows
            ->filter(function (array $row): bool {
                $key = (string) ($row['key'] ?? '');
                $persisted = $key !== ''
                    ? (string) ($this->persistedScentStatuses[$key] ?? ($row['status'] ?? 'queued'))
                    : (string) ($row['status'] ?? 'queued');

                return $persisted === 'brought_down';
            })
            ->count();

        $rows = $this->showCompleted
            ? $allRows
            : $allRows->reject(function (array $row): bool {
                $key = (string) ($row['key'] ?? '');
                $persisted = $key !== ''
                    ? (string) ($this->persistedScentStatuses[$key] ?? ($row['status'] ?? 'queued'))
                    : (string) ($row['status'] ?? 'queued');

                return $persisted === 'brought_down';
            })->values();

        $pendingStatusChanges = $this->pendingStatusChangeCount($allRows);
        $allBroughtDown = $this->allScentsBroughtDown($allRows);
        $canComplete = $allBroughtDown && $pendingStatusChanges === 0;
        $completeBlockedReason = null;

        if ($allRows->isEmpty()) {
            $completeBlockedReason = 'No scent lines found for this order.';
        } elseif ($pendingStatusChanges > 0) {
            $completeBlockedReason = 'Save scent status changes before marking this order complete.';
        } elseif (! $allBroughtDown) {
            $completeBlockedReason = 'Every scent must be marked Brought Down before completing.';
        }

        return view('livewire.pouring-room.order-detail', [
            'scentRows' => $rows,
            'statusOptions' => $this->statusOptions,
            'completedCount' => $completedCount,
            'allScentCount' => $allRows->count(),
            'pendingStatusChanges' => $pendingStatusChanges,
            'canComplete' => $canComplete,
            'completeBlockedReason' => $completeBlockedReason,
        ])->layout('layouts.app');
    }

    protected function buildScentSummaries(): Collection
    {
        $measurement = app(MeasurementResolver::class);

        return $this->order->lines
            ->groupBy(function (OrderLine $line): string {
                $scentId = (int) ($line->scent_id ?? 0);
                if ($scentId > 0) {
                    return 'id:'.$scentId;
                }

                $scentName = mb_strtolower(trim((string) ($line->scent_name ?? '')));
                if ($scentName !== '') {
                    return 'name:'.$scentName;
                }

                return 'line:'.$line->id;
            })
            ->map(function (Collection $scentLines, string $groupKey) use ($measurement): array {
                /** @var OrderLine|null $first */
                $first = $scentLines->first();
                $scentId = (int) ($first?->scent_id ?? 0);
                $hasScentId = $scentId > 0;
                $scent = $first?->scent;
                $scentLabel = (string) ($scent?->display_name ?: $scent?->name ?: $first?->scent_name ?: 'Unmapped scent');
                $oilName = trim((string) ($scent?->oil_reference_name ?: $scent?->oilBlend?->name ?: ''));
                $lookupName = trim((string) ($scent?->name ?: $first?->scent_name ?: ''));
                $rowKey = $hasScentId
                    ? 'scent_'.$scentId
                    : 'scent_name_'.md5($lookupName !== '' ? mb_strtolower($lookupName) : $groupKey);

                $statusCounts = $scentLines
                    ->groupBy(fn (OrderLine $line): string => $this->normalizeLineStatus((string) ($line->pour_status ?? 'queued')))
                    ->map(fn (Collection $group): int => $group->count())
                    ->all();

                $status = count($statusCounts) === 1
                    ? (string) array_key_first($statusCounts)
                    : 'mixed';

                $details = $scentLines
                    ->groupBy(fn (OrderLine $line): string => ((int) ($line->size_id ?? 0)).':'.trim((string) ($line->wick_type ?? '')))
                    ->map(function (Collection $sizeLines) use ($measurement, $scent): array {
                        /** @var OrderLine|null $detailFirst */
                        $detailFirst = $sizeLines->first();
                        $size = $detailFirst?->size;
                        $qty = (int) $sizeLines->sum(fn (OrderLine $line): int => $this->lineQuantity($line));
                        $sizeCode = (string) ($size?->code ?: $detailFirst?->size_code ?: $size?->label ?: '');
                        $sizeLabel = trim((string) ($size?->label ?: $size?->code ?: $detailFirst?->size_code ?: 'Unknown size'));
                        $sizeShort = $this->sizeShortLabel($sizeLabel, $sizeCode);
                        $ingredients = $measurement->resolveLineIngredients($sizeCode, $qty);
                        $pitchers = $this->splitPitchers($ingredients);
                        $detailStatusCounts = $sizeLines
                            ->groupBy(fn (OrderLine $line): string => $this->normalizeLineStatus((string) ($line->pour_status ?? 'queued')))
                            ->map(fn (Collection $group): int => $group->count())
                            ->all();
                        $detailStatus = count($detailStatusCounts) === 1
                            ? (string) array_key_first($detailStatusCounts)
                            : 'mixed';
                        $missingRecipe = ! $scent?->oilBlend || ! $ingredients;

                        return [
                            'size_label' => $sizeLabel,
                            'size_short' => $sizeShort,
                            'size_sort' => $this->sizeSortOrder($sizeCode, $sizeLabel),
                            'wick' => trim((string) ($detailFirst?->wick_type ?? '')),
                            'qty' => $qty,
                            'ingredients' => $ingredients,
                            'pitcher_count' => count($pitchers),
                            'pitchers' => $pitchers,
                            'wax_grams' => (float) ($ingredients['wax_grams'] ?? 0),
                            'oil_grams' => (float) ($ingredients['oil_grams'] ?? 0),
                            'recipe_name' => (string) ($scent?->oilBlend?->name ?? ''),
                            'missing_recipe' => $missingRecipe,
                            'status' => $detailStatus,
                            'status_counts' => $detailStatusCounts,
                        ];
                    })
                    ->sortBy([
                        ['size_sort', 'asc'],
                        ['size_label', 'asc'],
                    ])
                    ->values()
                    ->all();

                $sizeBreakdown = collect($details)
                    ->map(fn (array $detail): array => [
                        'label' => (string) ($detail['size_short'] ?? $detail['size_label'] ?? 'Size'),
                        'qty' => (int) ($detail['qty'] ?? 0),
                    ])
                    ->all();

                return [
                    'key' => $rowKey,
                    'scent_id' => $hasScentId ? $scentId : null,
                    'scent_lookup' => [
                        'type' => $hasScentId ? 'id' : 'name',
                        'id' => $hasScentId ? $scentId : null,
                        'name' => $lookupName,
                    ],
                    'scent' => $scent,
                    'scent_label' => $scentLabel,
                    'status' => $status,
                    'status_counts' => $statusCounts,
                    'size_breakdown' => $sizeBreakdown,
                    'inferred_boxes' => $this->inferBoxesFromDetails($details),
                    'pitcher_count' => (int) collect($details)->sum(fn (array $detail): int => (int) ($detail['pitcher_count'] ?? 0)),
                    'wax_grams' => (float) collect($details)->sum(fn (array $detail): float => (float) ($detail['wax_grams'] ?? 0)),
                    'oil_grams' => (float) collect($details)->sum(fn (array $detail): float => (float) ($detail['oil_grams'] ?? 0)),
                    'oil_name' => $oilName !== '' ? $oilName : '—',
                    'missing_recipe' => collect($details)->contains(fn (array $detail): bool => (bool) ($detail['missing_recipe'] ?? false)),
                    'recipe_name' => (string) ($scent?->oilBlend?->name ?? ''),
                    'recipe_components' => $this->recipeComponentsForScent($scent),
                    'details' => $details,
                ];
            })
            ->sortBy(fn (array $row): string => mb_strtolower((string) ($row['scent_label'] ?? '')))
            ->values();
    }

    protected function syncScentStateFromRows(Collection $rows, bool $reset = false): void
    {
        $keys = $rows
            ->pluck('key')
            ->filter(fn ($key): bool => is_string($key) && $key !== '')
            ->values()
            ->all();

        if ($reset) {
            $this->scentStatuses = [];
            $this->persistedScentStatuses = [];
        }

        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $status = $this->normalizeLineStatus((string) ($row['status'] ?? 'queued'));
            if ($reset || ! array_key_exists($key, $this->persistedScentStatuses)) {
                $this->persistedScentStatuses[$key] = $status;
            }

            if ($reset || ! array_key_exists($key, $this->scentStatuses)) {
                $this->scentStatuses[$key] = $this->persistedScentStatuses[$key];
            }

            if (! array_key_exists($key, $this->expandedScents)) {
                $this->expandedScents[$key] = false;
            }
        }

        $valid = array_flip($keys);
        $this->scentStatuses = array_filter(
            $this->scentStatuses,
            fn (string $key): bool => isset($valid[$key]),
            ARRAY_FILTER_USE_KEY
        );
        $this->persistedScentStatuses = array_filter(
            $this->persistedScentStatuses,
            fn (string $key): bool => isset($valid[$key]),
            ARRAY_FILTER_USE_KEY
        );
        $this->expandedScents = array_filter(
            $this->expandedScents,
            fn (string $key): bool => isset($valid[$key]),
            ARRAY_FILTER_USE_KEY
        );
    }

    protected function pendingStatusChangeCount(Collection $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $persisted = $this->normalizeLineStatus(
                (string) ($this->persistedScentStatuses[$key] ?? $row['status'] ?? 'queued')
            );
            $selected = $this->normalizeLineStatus(
                (string) ($this->scentStatuses[$key] ?? $persisted)
            );

            if ($selected !== $persisted) {
                $count++;
            }
        }

        return $count;
    }

    protected function allScentsBroughtDown(Collection $rows): bool
    {
        if ($rows->isEmpty()) {
            return false;
        }

        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            $persisted = $key !== ''
                ? $this->normalizeLineStatus((string) ($this->persistedScentStatuses[$key] ?? $row['status'] ?? 'queued'))
                : $this->normalizeLineStatus((string) ($row['status'] ?? 'queued'));

            if ($persisted !== 'brought_down') {
                return false;
            }
        }

        return true;
    }

    protected function markOrderInProcessIfNeeded(): void
    {
        if ((string) ($this->order->status ?? '') === 'brought_down') {
            return;
        }

        if ((string) ($this->order->status ?? '') !== 'pouring') {
            $this->order->status = 'pouring';
            $this->order->save();
        }
    }

    protected function lineQuantity(OrderLine $line): int
    {
        return max(0, (int) (($line->ordered_qty ?? $line->quantity ?? 0) + ($line->extra_qty ?? 0)));
    }

    protected function normalizeLineStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'mixed') {
            return 'mixed';
        }

        return array_key_exists($status, $this->statusOptions) ? $status : 'queued';
    }

    protected function sizeShortLabel(string $sizeLabel, string $sizeCode): string
    {
        $haystack = strtolower(trim($sizeCode !== '' ? $sizeCode : $sizeLabel));

        if (str_contains($haystack, 'wax') || str_contains($haystack, 'melt')) {
            return 'WM';
        }

        if (str_contains($haystack, '16')) {
            return '16oz';
        }

        if (str_contains($haystack, '8')) {
            return '8oz';
        }

        return trim($sizeLabel) !== '' ? $sizeLabel : 'Size';
    }

    protected function sizeSortOrder(string $sizeCode, string $sizeLabel): int
    {
        $haystack = strtolower(trim($sizeCode !== '' ? $sizeCode : $sizeLabel));

        if (str_contains($haystack, '16')) {
            return 1;
        }

        if (str_contains($haystack, '8')) {
            return 2;
        }

        if (str_contains($haystack, 'wax') || str_contains($haystack, 'melt')) {
            return 3;
        }

        return 9;
    }

    /**
     * @param  array<int,array<string,mixed>>  $details
     */
    protected function inferBoxesFromDetails(array $details): ?float
    {
        $count16 = 0;
        $count8 = 0;
        $countWaxMelt = 0;

        foreach ($details as $detail) {
            $qty = (int) ($detail['qty'] ?? 0);
            $label = strtolower((string) ($detail['size_short'] ?? ''));

            if ($qty <= 0) {
                continue;
            }

            if (str_contains($label, '16')) {
                $count16 += $qty;
                continue;
            }

            if (str_contains($label, '8')) {
                $count8 += $qty;
                continue;
            }

            if (str_contains($label, 'wm') || str_contains($label, 'wax')) {
                $countWaxMelt += $qty;
            }
        }

        $candidates = [];
        if ($count16 > 0) {
            $candidates[] = $count16 / 2;
        }
        if ($count8 > 0) {
            $candidates[] = $count8 / 4;
        }
        if ($countWaxMelt > 0) {
            $candidates[] = $countWaxMelt / 4;
        }

        if ($candidates === []) {
            return null;
        }

        return round(max($candidates), 2);
    }

    /**
     * @return array<int,array{oil:string,ratio:mixed}>
     */
    protected function recipeComponentsForScent(?Scent $scent): array
    {
        if (! $scent?->oilBlend) {
            return [];
        }

        return $scent->oilBlend->components
            ->map(fn ($component): array => [
                'oil' => (string) ($component->baseOil?->name ?? 'Oil'),
                'ratio' => $component->ratio_weight,
            ])
            ->values()
            ->all();
    }

    protected function sanitizeReturnTo(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        $returnHost = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($returnHost && $appHost && strtolower((string) $returnHost) === strtolower((string) $appHost)) {
            return $url;
        }

        return null;
    }

    protected function appendCelebrateFlag(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['celebrate'] = 1;

        $path = ($parts['scheme'] ?? null) ? ($parts['scheme'] . '://') : '';
        if (!empty($parts['host'])) {
            $path .= $parts['host'];
        }
        if (!empty($parts['port'])) {
            $path .= ':' . $parts['port'];
        }
        $path .= $parts['path'] ?? '';

        $queryString = http_build_query($query);
        if ($queryString !== '') {
            $path .= '?' . $queryString;
        }
        if (!empty($parts['fragment'])) {
            $path .= '#' . $parts['fragment'];
        }

        return $path;
    }

    /**
     * @param  array{wax_grams?:float,oil_grams?:float,pitcher_grams?:float,total_grams?:float}|null  $ingredients
     * @return array<int, array{index:int,wax_grams:float,oil_grams:float,total_grams:float}>
     */
    protected function splitPitchers(?array $ingredients, float $max = 2280): array
    {
        if (! $ingredients) {
            return [];
        }

        $waxTotal = (float) ($ingredients['wax_grams'] ?? 0);
        $oilTotal = (float) ($ingredients['oil_grams'] ?? 0);
        $total = (float) ($ingredients['pitcher_grams'] ?? ($waxTotal + $oilTotal));

        if ($total <= 0 || $max <= 0) {
            return [];
        }

        $ratioWax = $total > 0 ? $waxTotal / $total : 0.0;
        $ratioOil = $total > 0 ? $oilTotal / $total : 0.0;

        $rows = [];
        $remaining = $total;
        $index = 1;

        while ($remaining > 0.0001) {
            $pitcherTotal = min($max, $remaining);
            $rows[] = [
                'index' => $index,
                'wax_grams' => round($pitcherTotal * $ratioWax, 2),
                'oil_grams' => round($pitcherTotal * $ratioOil, 2),
                'total_grams' => round($pitcherTotal, 2),
            ];
            $remaining -= $pitcherTotal;
            $index++;
        }

        return $rows;
    }
}
