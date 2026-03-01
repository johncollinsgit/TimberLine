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
        $item = $this->itemQuery()->findOrFail($itemId);
        $item->delete();

        unset($this->draftRows[$itemId], $this->rowStatus[$itemId]);

        $this->dispatch('marketsDraftUpdated', eventId: (int) ($this->selectedEventId ?? 0));
        $this->dispatch('toast', ['type' => 'warning', 'message' => 'Item removed from draft.']);
    }

    public function saveItem(int $itemId): void
    {
        $row = $this->draftRows[$itemId] ?? null;
        if (! is_array($row)) {
            return;
        }

        $quantity = max(1, (int) ($row['quantity'] ?? 1));
        $scentId = $this->normalizeNullableId($row['scent_id'] ?? null);
        $sizeId = $this->normalizeNullableId($row['size_id'] ?? null);
        $boxTier = $this->normalizeBoxTier((string) ($row['box_tier'] ?? 'standard'));
        $notes = $this->normalizeNotes($row['notes'] ?? null);

        if ($scentId && ! Scent::query()->whereKey($scentId)->exists()) {
            $this->setRowStatus($itemId, 'error', 'Selected scent no longer exists.');

            return;
        }

        if ($sizeId && ! Size::query()->whereKey($sizeId)->exists()) {
            $this->setRowStatus($itemId, 'error', 'Selected size no longer exists.');

            return;
        }

        $item = $this->itemQuery()->findOrFail($itemId);
        $item->quantity = $quantity;
        $item->scent_id = $scentId;
        $item->size_id = $sizeId;

        if ($this->supportsRetailPlanItemBoxTierColumn()) {
            $item->box_tier = $boxTier;
        }

        if ($this->supportsRetailPlanItemNotesColumn()) {
            $item->notes = $notes;
        }

        $item->status = $scentId ? 'draft' : 'needs_mapping';
        $item->save();

        $this->draftRows[$itemId]['quantity'] = $quantity;
        $this->draftRows[$itemId]['scent_id'] = $scentId;
        $this->draftRows[$itemId]['size_id'] = $sizeId;
        $this->draftRows[$itemId]['box_tier'] = $boxTier;
        $this->draftRows[$itemId]['notes'] = $notes;
        $this->setRowStatus($itemId, 'success', 'Saved.');

        $this->dispatch('marketsDraftUpdated', eventId: (int) ($this->selectedEventId ?? 0));
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Draft row updated.']);
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

        $canSubmit = ! empty($this->draftRows) && ! collect($this->draftRows)->contains(function (array $row): bool {
            return (int) ($row['quantity'] ?? 0) <= 0 || (int) ($row['scent_id'] ?? 0) <= 0;
        });

        return view('livewire.retail.markets.draft-event-editor', [
            'event' => $event,
            'rows' => collect($this->draftRows)->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ]),
            'canSubmit' => $canSubmit,
            'scentOptions' => Scent::query()
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereNull('is_wholesale_custom')
                        ->orWhere('is_wholesale_custom', false);
                })
                ->orderByRaw('COALESCE(display_name, name)')
                ->get(['id', 'name', 'display_name']),
            'sizeOptions' => Size::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get(['id', 'code', 'label']),
        ]);
    }

    protected function loadDraftRows(bool $resetStatuses = false): void
    {
        $existingStatuses = $resetStatuses ? [] : $this->rowStatus;
        $this->draftRows = [];
        $this->rowStatus = [];

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
            $this->draftRows[(int) $item->id] = [
                'id' => (int) $item->id,
                'quantity' => max(1, (int) ($item->quantity ?? 1)),
                'scent_id' => $item->scent_id ? (int) $item->scent_id : null,
                'size_id' => $item->size_id ? (int) $item->size_id : null,
                'box_tier' => $this->supportsRetailPlanItemBoxTierColumn()
                    ? $this->normalizeBoxTier((string) ($item->box_tier ?? 'standard'))
                    : $this->legacyTierForItem($item),
                'notes' => $this->supportsRetailPlanItemNotesColumn()
                    ? (string) ($item->notes ?? '')
                    : '',
                'source' => (string) ($item->source ?? ''),
                'status' => (string) ($item->status ?? 'draft'),
                'scent_label' => (string) ($item->scent?->display_name ?: $item->scent?->name ?: ''),
                'size_label' => (string) ($item->size?->label ?: $item->size?->code ?: ''),
                'sort_order' => $this->sortOrderForTier(
                    $this->supportsRetailPlanItemBoxTierColumn()
                        ? $this->normalizeBoxTier((string) ($item->box_tier ?? 'standard'))
                        : $this->legacyTierForItem($item)
                ),
            ];
        }

        foreach (array_keys($this->draftRows) as $itemId) {
            if (isset($existingStatuses[$itemId])) {
                $this->rowStatus[$itemId] = $existingStatuses[$itemId];
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

    protected function normalizeNullableId(mixed $value): ?int
    {
        $value = is_string($value) ? trim($value) : $value;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
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
