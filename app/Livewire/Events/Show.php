<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\EventShipment;
use App\Models\MarketPourList;
use App\Models\Scent;
use App\Models\Size;
use App\Services\MarketRecommender;
use Livewire\Component;

class Show extends Component
{
    public Event $event;
    public string $tab = 'overview';

    public string $name = '';
    public ?string $venue = null;
    public ?string $city = null;
    public ?string $state = null;
    public ?string $starts_at = null;
    public ?string $ends_at = null;
    public ?string $due_date = null;
    public ?string $ship_date = null;
    public string $status = 'planned';
    public ?string $notes = null;

    public ?int $shipmentScentId = null;
    public ?int $shipmentSizeId = null;
    public int $shipmentQty = 0;

    public function mount(Event $event): void
    {
        $this->event = $event;
        $this->name = $event->name;
        $this->venue = $event->venue;
        $this->city = $event->city;
        $this->state = $event->state;
        $this->starts_at = optional($event->starts_at)->toDateString();
        $this->ends_at = optional($event->ends_at)->toDateString();
        $this->due_date = optional($event->due_date)->toDateString();
        $this->ship_date = optional($event->ship_date)->toDateString();
        $this->status = $event->status ?? 'planned';
        $this->notes = $event->notes;
    }

    public function saveEvent(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
            'due_date' => 'nullable|date',
            'ship_date' => 'nullable|date',
            'status' => 'required|string|max:50',
        ]);

        $this->event->update([
            'name' => $this->name,
            'venue' => $this->venue,
            'city' => $this->city,
            'state' => $this->state,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'due_date' => $this->due_date,
            'ship_date' => $this->ship_date,
            'status' => $this->status,
            'notes' => $this->notes,
        ]);

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Event updated.']);
    }

    public function addShipment(): void
    {
        EventShipment::query()->create([
            'event_id' => $this->event->id,
            'scent_id' => $this->shipmentScentId,
            'size_id' => $this->shipmentSizeId,
            'planned_qty' => $this->shipmentQty,
        ]);
        $this->shipmentScentId = null;
        $this->shipmentSizeId = null;
        $this->shipmentQty = 0;
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Shipment line added.']);
    }

    public function updateSent(int $id, int $qty): void
    {
        EventShipment::query()->where('id', $id)->update(['sent_qty' => max(0, $qty)]);
    }

    public function updateReturned(int $id, int $qty): void
    {
        EventShipment::query()->where('id', $id)->update(['returned_qty' => max(0, $qty)]);
    }

    public function createMarketPourList(): void
    {
        $list = MarketPourList::query()->create([
            'title' => $this->event->name . ' Market Pour List',
            'status' => 'draft',
            'generated_by_user_id' => auth()->id(),
        ]);

        $list->events()->sync([$this->event->id]);
        redirect()->route('markets.lists.show', $list);
    }

    public function render()
    {
        $shipments = $this->event->shipments()->with(['scent', 'size'])->get();
        $scents = Scent::query()->orderBy('name')->get();
        $sizes = Size::query()->orderBy('label')->get();
        $scentMap = $scents->keyBy('id');
        $sizeMap = $sizes->keyBy('id');

        $reco = collect(app(MarketRecommender::class)->recommendForEvent($this->event))
            ->map(function ($row) use ($scentMap, $sizeMap) {
                $row['scent_name'] = $row['scent_id'] ? ($scentMap[$row['scent_id']]->name ?? null) : null;
                $row['size_label'] = $row['size_id'] ? ($sizeMap[$row['size_id']]->label ?? $sizeMap[$row['size_id']]->code ?? null) : null;
                return $row;
            })
            ->all();

        return view('livewire.events.show', [
            'shipments' => $shipments,
            'scents' => $scents,
            'sizes' => $sizes,
            'recommendations' => $reco,
        ])->layout('layouts.app');
    }
}
