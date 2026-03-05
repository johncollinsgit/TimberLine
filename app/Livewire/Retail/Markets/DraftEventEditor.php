<?php

namespace App\Livewire\Retail\Markets;

use App\Models\Event;
use App\Models\RetailPlanItem;
use App\Models\Scent;
use App\Models\Size;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\On;
use Livewire\Component;

class DraftEventEditor extends Component
{
    public int $planId;
    public ?int $selectedEventId = null;
    public int $selfHealAttempts = 0;
    public int $maxSelfHealAttempts = 2;

    /** @var array<int,array<string,mixed>> */
    public array $draftRows = [];

    /** @var array<int,array{type:string,message:string}> */
    public array $rowStatus = [];
    /** @var array<int,bool> */
    public array $openNotes = [];
    /** @var array<int,bool> */
    public array $openDetails = [];
    public ?int $activeTopShelfRowId = null;

    public function mount(int $planId, ?int $selectedEventId = null): void
    {
        $this->planId = $planId;
        $this->selectedEventId = $selectedEventId;
        $this->selfHealAttempts = 0;
        $this->loadDraftRows(true);
    }

    public function updatedSelectedEventId(mixed $value): void
    {
        $this->selectedEventId = $value ? (int) $value : null;
        $this->selfHealAttempts = 0;
        $this->loadDraftRows(true);
    }

    public function updatedDraftRows(mixed $value, ?string $name = null): void
    {
        unset($value);

        if ($name === null || $name === '') {
            return;
        }

        if (! preg_match('/^(\d+)\.(.+)$/', $name, $matches)) {
            return;
        }

        $itemId = (int) ($matches[1] ?? 0);
        $field = (string) ($matches[2] ?? '');
        $row = $this->draftRows[$itemId] ?? null;

        if (! is_array($row)) {
            return;
        }

        if ($field === 'box_tier') {
            $boxTier = $this->normalizeBoxTier((string) ($row['box_tier'] ?? 'standard'));
            $this->draftRows[$itemId]['box_tier'] = $boxTier;
            $quantity = max(1, (int) ($row['quantity'] ?? 1));
            $this->draftRows[$itemId]['quantity'] = $quantity;
            $this->draftRows[$itemId]['box_count'] = $this->boxCountFromStoredQuantity($quantity, $boxTier);

            if ($boxTier === 'top_shelf') {
                $fallbackScentId = $this->normalizeNullableId($row['scent_id'] ?? null);
                $this->draftRows[$itemId]['top_shelf'] = $this->normalizeTopShelfConfiguration(
                    $row['top_shelf'] ?? [],
                    $fallbackScentId
                );
                $this->activeTopShelfRowId = $itemId;
            } elseif ($this->activeTopShelfRowId === $itemId) {
                $this->activeTopShelfRowId = null;
            }

            return;
        }

        if ($field === 'box_count') {
            $boxTier = $this->normalizeBoxTier((string) ($row['box_tier'] ?? 'standard'));
            $quantity = $this->normalizeQuantity($row['box_count'] ?? 1, $boxTier);
            $this->draftRows[$itemId]['quantity'] = $quantity;
            $this->draftRows[$itemId]['box_count'] = $this->boxCountFromStoredQuantity($quantity, $boxTier);

            return;
        }

        if ($field === 'quantity') {
            $boxTier = $this->normalizeBoxTier((string) ($row['box_tier'] ?? 'standard'));
            $quantity = max(1, (int) ($row['quantity'] ?? 1));
            $this->draftRows[$itemId]['quantity'] = $quantity;
            $this->draftRows[$itemId]['box_count'] = $this->boxCountFromStoredQuantity($quantity, $boxTier);

            return;
        }

        if (
            str_starts_with($field, 'top_shelf')
            || ($field === 'scent_id' && $this->normalizeBoxTier((string) ($row['box_tier'] ?? 'standard')) === 'top_shelf')
        ) {
            $fallbackScentId = $this->normalizeNullableId($row['scent_id'] ?? null);
            $topShelf = is_array($row['top_shelf'] ?? null) ? $row['top_shelf'] : [];

            if ($field === 'scent_id' && $fallbackScentId) {
                $slots = is_array($topShelf['slots'] ?? null) ? array_values($topShelf['slots']) : [];
                $slots[0] = $fallbackScentId;
                $topShelf['slots'] = $slots;
            }

            $this->draftRows[$itemId]['top_shelf'] = $this->normalizeTopShelfConfiguration(
                $topShelf,
                $fallbackScentId
            );
        }
    }

    #[On('marketsDraftUpdated')]
    public function handleDraftUpdated(int $eventId): void
    {
        $selectedEventId = (int) ($this->selectedEventId ?: 0);
        if ($selectedEventId > 0 && $eventId > 0 && $selectedEventId !== $eventId) {
            return;
        }

        $this->loadDraftRows();
    }

    public function removeItem(int $itemId): void
    {
        $item = $this->itemQuery()->find($itemId);
        if ($item) {
            $item->delete();
        }

        unset($this->draftRows[$itemId], $this->rowStatus[$itemId], $this->openNotes[$itemId], $this->openDetails[$itemId]);
        if ($this->activeTopShelfRowId === $itemId) {
            $this->activeTopShelfRowId = null;
        }

        $this->loadDraftRows();

        $this->dispatch('marketsDraftUpdated', eventId: (int) ($this->selectedEventId ?? 0));
        $this->dispatch('toast', [
            'type' => $item ? 'warning' : 'info',
            'message' => $item ? 'Item removed from draft.' : 'Row was already removed.',
        ]);
    }

    public function saveItem(int $itemId): void
    {
        $this->saveItemInternal($itemId, true);
    }

    public function saveAllRows(): void
    {
        if (empty($this->draftRows)) {
            return;
        }

        $saved = 0;
        $failed = 0;

        foreach (array_keys($this->draftRows) as $itemId) {
            $ok = $this->saveItemInternal((int) $itemId, false);
            if ($ok) {
                $saved++;
            } else {
                $failed++;
            }
        }

        $this->dispatch('marketsDraftUpdated', eventId: (int) ($this->selectedEventId ?? 0));

        if ($failed > 0) {
            $message = $saved > 0
                ? "Saved {$saved} row".($saved === 1 ? '' : 's')."; {$failed} still need attention."
                : "No rows saved; {$failed} row".($failed === 1 ? '' : 's')." need attention.";

            $this->dispatch('toast', ['type' => 'warning', 'message' => $message]);
            return;
        }

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => "Saved {$saved} draft row".($saved === 1 ? '' : 's').'.',
        ]);
    }

    protected function saveItemInternal(int $itemId, bool $emitPerRowEvents = true): bool
    {
        $row = $this->draftRows[$itemId] ?? null;
        if (! is_array($row)) {
            return false;
        }

        $boxTier = $this->normalizeBoxTier((string) ($row['box_tier'] ?? 'standard'));
        $quantityInput = array_key_exists('box_count', $row) ? ($row['box_count'] ?? null) : ($row['quantity'] ?? 1);
        $quantity = $this->normalizeQuantity($quantityInput, $boxTier);
        $scentId = $this->normalizeNullableId($row['scent_id'] ?? null);
        $sizeId = $this->normalizeNullableId($row['size_id'] ?? null);

        if ($sizeId && ! Size::query()->whereKey($sizeId)->exists()) {
            $this->setRowStatus($itemId, 'error', 'Selected size no longer exists.');

            return false;
        }

        $topShelfConfiguration = null;
        $notes = null;

        if ($boxTier === 'top_shelf') {
            $topShelfConfiguration = $this->normalizeTopShelfConfiguration($row['top_shelf'] ?? [], $scentId);
            $invalidSlotIds = $this->invalidTopShelfSlotIds($topShelfConfiguration);

            if ($invalidSlotIds !== []) {
                $this->setRowStatus($itemId, 'error', 'One or more Top Shelf scents no longer exist.');

                return false;
            }

            $notes = RetailPlanItem::encodeTopShelfConfiguration($topShelfConfiguration, $scentId);
            $scentId = $this->primaryScentIdForTopShelfConfiguration($topShelfConfiguration) ?: $scentId;
        } else {
            if ($scentId && ! Scent::query()->whereKey($scentId)->exists()) {
                $this->setRowStatus($itemId, 'error', 'Selected scent no longer exists.');

                return false;
            }

            $notes = $this->normalizeNotes($row['notes_text'] ?? $row['notes'] ?? null);
        }

        $item = $this->itemQuery()->find($itemId);
        if (! $item) {
            unset($this->draftRows[$itemId], $this->rowStatus[$itemId], $this->openNotes[$itemId], $this->openDetails[$itemId]);
            if ($this->activeTopShelfRowId === $itemId) {
                $this->activeTopShelfRowId = null;
            }
            $this->dispatch('marketsDraftUpdated', eventId: (int) ($this->selectedEventId ?? 0));
            $this->setRowStatus($itemId, 'error', 'Row no longer exists.');

            return false;
        }
        $item->quantity = $quantity;
        $item->scent_id = $scentId;
        $item->size_id = $sizeId;

        if ($this->supportsRetailPlanItemBoxTierColumn()) {
            $item->box_tier = $boxTier;
        }

        if ($this->supportsRetailPlanItemNotesColumn()) {
            $item->notes = $notes;
        }

        $item->status = $boxTier === 'top_shelf'
            ? ($topShelfConfiguration !== null && RetailPlanItem::topShelfConfigurationIsComplete($topShelfConfiguration) ? 'draft' : 'needs_mapping')
            : ($scentId ? 'draft' : 'needs_mapping');
        $item->save();

        $this->draftRows[$itemId]['quantity'] = $quantity;
        $this->draftRows[$itemId]['box_count'] = $this->boxCountFromStoredQuantity($quantity, $boxTier);
        $this->draftRows[$itemId]['scent_id'] = $scentId;
        $this->draftRows[$itemId]['size_id'] = $sizeId;
        $this->draftRows[$itemId]['box_tier'] = $boxTier;
        $this->draftRows[$itemId]['notes'] = $notes ?? '';
        $this->draftRows[$itemId]['notes_text'] = $boxTier === 'top_shelf' ? '' : ($notes ?? '');
        $this->draftRows[$itemId]['status'] = (string) $item->status;

        if ($boxTier === 'top_shelf' && $topShelfConfiguration !== null) {
            $this->draftRows[$itemId]['top_shelf'] = $topShelfConfiguration;
        }

        $this->setRowStatus($itemId, 'success', 'Saved.');

        if ($emitPerRowEvents) {
            $this->dispatch('marketsDraftUpdated', eventId: (int) ($this->selectedEventId ?? 0));
            $this->dispatch('toast', ['type' => 'success', 'message' => 'Draft row updated.']);
        }

        return true;
    }

    public function marketBoxLabelFromUnits(int $units): string
    {
        $units = max(1, $units);
        $boxes = $units / 2;

        if (fmod($boxes, 1.0) === 0.0) {
            $value = (string) (int) $boxes;
        } else {
            $value = number_format($boxes, 1);
        }

        return $value.' '.(((float) $boxes) === 1.0 ? 'box' : 'boxes');
    }

    public function quantityLabelForRow(array $row): string
    {
        $boxTier = $this->normalizeBoxTier((string) ($row['box_tier'] ?? 'standard'));
        $quantity = $this->normalizeQuantity($row['box_count'] ?? ($row['quantity'] ?? 1), $boxTier);

        if ($boxTier === 'top_shelf') {
            return $quantity.' '.($quantity === 1 ? 'Top Shelf box' : 'Top Shelf boxes');
        }

        return $this->marketBoxLabelFromUnits($quantity);
    }

    public function topShelfDescription(array $row): string
    {
        $configuration = $this->normalizeTopShelfConfiguration(
            $row['top_shelf'] ?? [],
            $this->normalizeNullableId($row['scent_id'] ?? null)
        );

        $preset = RetailPlanItem::TOP_SHELF_PRESETS[(string) ($configuration['preset'] ?? 'same_12')] ?? '12 Same';
        $sizeMode = RetailPlanItem::TOP_SHELF_SIZE_MODES[(string) ($configuration['size_mode'] ?? '16oz')] ?? '16oz';

        if ((string) ($configuration['size_mode'] ?? '') === 'wax_melt') {
            $sizeMode .= ' · '.(int) ($configuration['wax_melt_capacity'] ?? 12).' per box';
        }

        return $preset.' · '.$sizeMode;
    }

    public function topShelfCompositionPreview(array $row, array $scentLookup = []): string
    {
        $configuration = $this->normalizeTopShelfConfiguration(
            $row['top_shelf'] ?? [],
            $this->normalizeNullableId($row['scent_id'] ?? null)
        );

        $parts = [];

        foreach ((array) ($configuration['composition'] ?? []) as $slot) {
            $scentId = (int) ($slot['scent_id'] ?? 0);
            $units = max(1, (int) ($slot['units_per_box'] ?? 0));
            $label = $scentId > 0
                ? (string) ($scentLookup[$scentId] ?? "Scent #{$scentId}")
                : 'Choose scent';

            $parts[] = $units.'x '.$label;
        }

        return implode(' | ', $parts);
    }

    public function toggleNotes(int $itemId): void
    {
        if (! isset($this->draftRows[$itemId])) {
            return;
        }

        $this->openNotes[$itemId] = ! (bool) ($this->openNotes[$itemId] ?? false);
    }

    public function toggleDetails(int $itemId): void
    {
        if (! isset($this->draftRows[$itemId])) {
            return;
        }

        if ($this->normalizeBoxTier((string) ($this->draftRows[$itemId]['box_tier'] ?? 'standard')) === 'top_shelf') {
            $this->activeTopShelfRowId = $itemId;
            return;
        }

        $this->openDetails[$itemId] = ! (bool) ($this->openDetails[$itemId] ?? false);
    }

    public function openTopShelfConfigurator(int $itemId): void
    {
        if (! isset($this->draftRows[$itemId])) {
            return;
        }

        $row = $this->draftRows[$itemId];
        if ($this->normalizeBoxTier((string) ($row['box_tier'] ?? 'standard')) !== 'top_shelf') {
            return;
        }

        $fallbackScentId = $this->normalizeNullableId($row['scent_id'] ?? null);
        $this->draftRows[$itemId]['top_shelf'] = $this->normalizeTopShelfConfiguration(
            $row['top_shelf'] ?? [],
            $fallbackScentId
        );
        $this->activeTopShelfRowId = $itemId;
    }

    public function closeTopShelfConfigurator(): void
    {
        $this->activeTopShelfRowId = null;
    }

    public function render()
    {
        $eventId = (int) ($this->selectedEventId ?: 0);
        $event = $eventId > 0
            ? Event::query()->select(['id', 'name', 'display_name', 'starts_at'])->find($eventId)
            : null;

        if (
            $eventId > 0
            && empty($this->draftRows)
            && $this->supportsRetailPlanItemUpcomingEventColumn()
            && $this->selfHealAttempts < $this->maxSelfHealAttempts
        ) {
            $this->selfHealAttempts++;
            $this->loadDraftRows();
        }

        $canSubmit = ! empty($this->draftRows) && ! collect($this->draftRows)->contains(
            fn (array $row): bool => ! $this->rowCanSubmit($row)
        );

        $selectedScentIds = collect($this->draftRows)
            ->pluck('scent_id')
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $scentOptions = Scent::query()
            ->when($selectedScentIds !== [], function ($query) use ($selectedScentIds): void {
                $query->where(function ($inner) use ($selectedScentIds): void {
                    $inner->where('is_active', true)
                        ->orWhereIn('id', $selectedScentIds);
                });
            }, fn ($query) => $query->where('is_active', true))
            ->orderByRaw('COALESCE(display_name, name)')
            ->get(['id', 'name', 'display_name']);

        return view('livewire.retail.markets.draft-event-editor', [
            'event' => $event,
            'rows' => collect($this->draftRows)->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ]),
            'canSubmit' => $canSubmit,
            'scentOptions' => $scentOptions,
            'scentLookup' => $scentOptions
                ->mapWithKeys(fn (Scent $scent): array => [
                    (int) $scent->id => (string) ($scent->display_name ?: $scent->name),
                ])
                ->all(),
            'sizeOptions' => Size::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get(['id', 'code', 'label']),
            'topShelfPresetOptions' => RetailPlanItem::TOP_SHELF_PRESETS,
            'topShelfSizeModes' => RetailPlanItem::TOP_SHELF_SIZE_MODES,
        ]);
    }

    protected function loadDraftRows(bool $resetStatuses = false): void
    {
        $existingStatuses = $resetStatuses ? [] : $this->rowStatus;
        $existingOpenNotes = $this->openNotes;
        $existingOpenDetails = $this->openDetails;
        $previousTopShelfRowId = $this->activeTopShelfRowId;
        $this->draftRows = [];
        $this->rowStatus = [];
        $this->openNotes = [];
        $this->openDetails = [];
        $this->activeTopShelfRowId = null;

        $eventId = (int) ($this->selectedEventId ?: 0);
        if ($eventId <= 0 || ! $this->supportsRetailPlanItemUpcomingEventColumn()) {
            return;
        }

        $items = $this->itemQuery()
            ->with(['scent:id,name,display_name', 'size:id,code,label'])
            ->orderBy('source')
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $boxTier = $this->supportsRetailPlanItemBoxTierColumn()
                ? $this->normalizeBoxTier((string) ($item->box_tier ?? 'standard'))
                : $this->legacyTierForItem($item);
            $topShelf = $boxTier === 'top_shelf'
                ? RetailPlanItem::decodeTopShelfConfiguration(
                    $this->supportsRetailPlanItemNotesColumn() ? $item->notes : null,
                    $item->scent_id ? (int) $item->scent_id : null
                )
                : RetailPlanItem::defaultTopShelfConfiguration($item->scent_id ? (int) $item->scent_id : null);

            $this->draftRows[(int) $item->id] = [
                'id' => (int) $item->id,
                'quantity' => max(1, (int) ($item->quantity ?? 1)),
                'box_count' => $this->boxCountFromStoredQuantity(max(1, (int) ($item->quantity ?? 1)), $boxTier),
                'scent_id' => $item->scent_id ? (int) $item->scent_id : null,
                'size_id' => $item->size_id ? (int) $item->size_id : null,
                'box_tier' => $boxTier,
                'notes' => $this->supportsRetailPlanItemNotesColumn()
                    ? (string) ($item->notes ?? '')
                    : '',
                'notes_text' => $boxTier === 'top_shelf'
                    ? ''
                    : ($this->supportsRetailPlanItemNotesColumn() ? (string) ($item->notes ?? '') : ''),
                'top_shelf' => $topShelf,
                'source' => (string) ($item->source ?? ''),
                'status' => (string) ($item->status ?? 'draft'),
                'scent_label' => (string) ($item->scent?->display_name ?: $item->scent?->name ?: ''),
                'size_label' => (string) ($item->size?->label ?: $item->size?->code ?: ''),
                'sort_order' => $this->sortOrderForTier($boxTier),
            ];
        }

        foreach (array_keys($this->draftRows) as $itemId) {
            if (isset($existingStatuses[$itemId])) {
                $this->rowStatus[$itemId] = $existingStatuses[$itemId];
            }

            $this->openNotes[$itemId] = (bool) ($existingOpenNotes[$itemId] ?? false);
            $this->openDetails[$itemId] = (bool) ($existingOpenDetails[$itemId] ?? false);
            if ($previousTopShelfRowId === $itemId) {
                $this->activeTopShelfRowId = $itemId;
            }
        }

        if (! empty($this->draftRows)) {
            $this->selfHealAttempts = 0;
        }
    }

    protected function itemQuery()
    {
        return RetailPlanItem::query()
            ->where('retail_plan_id', $this->planId)
            ->where('status', '!=', 'published')
            ->where('upcoming_event_id', (int) ($this->selectedEventId ?: 0))
            ->whereIn('source', RetailPlanItem::marketDraftSources());
    }

    protected function rowCanSubmit(array $row): bool
    {
        $boxTier = $this->normalizeBoxTier((string) ($row['box_tier'] ?? 'standard'));
        $quantity = $this->normalizeQuantity($row['box_count'] ?? ($row['quantity'] ?? 0), $boxTier);

        if ($quantity <= 0) {
            return false;
        }

        if ($boxTier === 'top_shelf') {
            return RetailPlanItem::topShelfConfigurationIsComplete(
                $this->normalizeTopShelfConfiguration(
                    $row['top_shelf'] ?? [],
                    $this->normalizeNullableId($row['scent_id'] ?? null)
                )
            );
        }

        return (int) ($row['scent_id'] ?? 0) > 0;
    }

    protected function normalizeNullableId(mixed $value): ?int
    {
        $value = is_string($value) ? trim($value) : $value;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    protected function normalizeQuantity(mixed $value, string $boxTier): int
    {
        if ($this->normalizeBoxTier($boxTier) === 'top_shelf') {
            return max(1, (int) round(is_numeric($value) ? (float) $value : 1));
        }

        $boxes = is_numeric($value) ? (float) $value : 0.5;
        $halfBoxUnits = (int) round($boxes * 2);

        return max(1, $halfBoxUnits);
    }

    protected function boxCountFromStoredQuantity(int $quantity, string $boxTier): float|int
    {
        $quantity = max(1, $quantity);

        if ($this->normalizeBoxTier($boxTier) === 'top_shelf') {
            return $quantity;
        }

        return max(0.5, $quantity / 2);
    }

    protected function normalizeBoxTier(string $value): string
    {
        $value = trim($value);

        return in_array($value, ['standard', 'top_shelf'], true) ? $value : 'standard';
    }

    protected function normalizeNotes(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, 1000) : null;
    }

    /**
     * @param  array<string,mixed>  $configuration
     * @return array<int,int>
     */
    protected function invalidTopShelfSlotIds(array $configuration): array
    {
        $slotIds = collect((array) ($configuration['composition'] ?? []))
            ->pluck('scent_id')
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($slotIds->isEmpty()) {
            return [];
        }

        $existing = Scent::query()
            ->whereIn('id', $slotIds->all())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_diff($slotIds->all(), $existing));
    }

    /**
     * @param  array<string,mixed>|mixed  $value
     * @return array<string,mixed>
     */
    protected function normalizeTopShelfConfiguration(mixed $value, ?int $fallbackScentId = null): array
    {
        return RetailPlanItem::normalizeTopShelfConfiguration(
            is_array($value) ? $value : [],
            $fallbackScentId
        );
    }

    protected function primaryScentIdForTopShelfConfiguration(array $configuration): ?int
    {
        foreach ((array) ($configuration['composition'] ?? []) as $slot) {
            $scentId = $this->normalizeNullableId($slot['scent_id'] ?? null);
            if ($scentId) {
                return $scentId;
            }
        }

        return null;
    }

    protected function setRowStatus(int $itemId, string $type, string $message): void
    {
        $this->rowStatus[$itemId] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    protected function legacyTierForItem(RetailPlanItem $item): string
    {
        return ($item->source ?? '') === 'market_top_shelf_template' ? 'top_shelf' : 'standard';
    }

    protected function sortOrderForTier(string $boxTier): int
    {
        return $boxTier === 'top_shelf' ? 1 : 0;
    }

    protected function supportsRetailPlanItemUpcomingEventColumn(): bool
    {
        static $supports = null;

        if ($supports === null) {
            $supports = Schema::hasColumn('retail_plan_items', 'upcoming_event_id');
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
}
