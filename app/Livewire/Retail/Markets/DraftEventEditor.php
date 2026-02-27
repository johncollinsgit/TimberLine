<?php

namespace App\Livewire\Retail\Markets;

use App\Models\Event;
use App\Models\RetailPlanItem;
use Livewire\Component;

class DraftEventEditor extends Component
{
    public int $planId;
    public ?int $selectedEventId = null;

    public function mount(int $planId, ?int $selectedEventId = null): void
    {
        $this->planId = $planId;
        $this->selectedEventId = $selectedEventId;
    }

    public function updatedSelectedEventId(mixed $value): void
    {
        $this->selectedEventId = $value ? (int) $value : null;
    }

    public function removeItem(int $itemId): void
    {
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->planId)
            ->findOrFail($itemId);
        $item->delete();
        $this->dispatch('marketsDraftUpdated', eventId: (int) ($this->selectedEventId ?? 0));
        $this->dispatch('toast', ['type' => 'warning', 'message' => 'Item removed from draft.']);
    }

    public function incrementItemQuantity(int $itemId): void
    {
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->planId)
            ->findOrFail($itemId);
        $item->quantity = max(1, (int) $item->quantity + 1);
        $item->save();
        $this->dispatch('marketsDraftUpdated', eventId: (int) ($this->selectedEventId ?? 0));
    }

    public function decrementItemQuantity(int $itemId): void
    {
        $item = RetailPlanItem::query()
            ->where('retail_plan_id', $this->planId)
            ->findOrFail($itemId);
        $item->quantity = max(1, (int) $item->quantity - 1);
        $item->save();
        $this->dispatch('marketsDraftUpdated', eventId: (int) ($this->selectedEventId ?? 0));
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

    public function render()
    {
        $eventId = (int) ($this->selectedEventId ?: 0);
        $event = $eventId > 0
            ? Event::query()->select(['id', 'name', 'display_name', 'starts_at'])->find($eventId)
            : null;

        $items = collect();
        if ($eventId > 0) {
            $items = RetailPlanItem::query()
                ->where('retail_plan_id', $this->planId)
                ->where('status', '!=', 'published')
                ->where('upcoming_event_id', $eventId)
                ->whereIn('source', ['market_box_draft', 'market_box_manual', 'market_box_event_prefill', 'event_prefill', 'market_top_shelf_template'])
                ->with(['scent:id,name,display_name'])
                ->orderBy('source')
                ->orderBy('id')
                ->get();
        }

        $canSubmit = $items->isNotEmpty() && ! $items->contains(
            fn (RetailPlanItem $item) => (int) ($item->scent_id ?? 0) <= 0 || (int) ($item->quantity ?? 0) <= 0
        );

        return view('livewire.retail.markets.draft-event-editor', [
            'event' => $event,
            'items' => $items,
            'canSubmit' => $canSubmit,
        ]);
    }
}
