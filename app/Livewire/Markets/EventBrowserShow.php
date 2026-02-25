<?php

namespace App\Livewire\Markets;

use App\Models\Event;
use App\Models\MarketBoxShipment;
use Illuminate\Support\Str;
use Livewire\Component;

class EventBrowserShow extends Component
{
    public Event $event;
    public bool $editing = false;

    public string $market_name = '';
    public string $event_name = '';
    public string $display_name = '';
    public string $year = '';
    public string $starts_at = '';
    public string $ends_at = '';
    public string $city = '';
    public string $state = '';
    public string $venue = '';
    public string $status = 'planned';
    public string $notes = '';
    public bool $needs_review = false;

    public function mount(Event $event): void
    {
        $this->event = $event;
        $this->fillFromEvent();
    }

    public function startEdit(): void
    {
        abort_unless((auth()->user()?->role ?? null) === 'admin', 403);
        $this->fillFromEvent();
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->fillFromEvent();
        $this->resetValidation();
    }

    public function saveEvent(): void
    {
        abort_unless((auth()->user()?->role ?? null) === 'admin', 403);

        $validated = $this->validate([
            'market_name' => ['nullable', 'string', 'max:255'],
            'event_name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'between:2020,2100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'size:2'],
            'venue' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'needs_review' => ['boolean'],
        ]);

        if (!empty($validated['ends_at']) && !empty($validated['starts_at']) && $validated['ends_at'] < $validated['starts_at']) {
            $this->addError('ends_at', 'End date cannot be before start date.');
            return;
        }

        $event = $this->event->fresh(['market']) ?? $this->event;

        $event->fill([
            'name' => trim((string) $validated['event_name']),
            'display_name' => trim((string) ($validated['display_name'] ?? '')) ?: null,
            'year' => $validated['year'] !== '' ? (int) $validated['year'] : null,
            'starts_at' => $validated['starts_at'] ?: null,
            'ends_at' => $validated['ends_at'] ?: null,
            'city' => trim((string) ($validated['city'] ?? '')) ?: null,
            'state' => strtoupper(trim((string) ($validated['state'] ?? ''))) ?: null,
            'venue' => trim((string) ($validated['venue'] ?? '')) ?: null,
            'status' => trim((string) ($validated['status'] ?? 'planned')) ?: 'planned',
            'notes' => $validated['notes'] ?? null,
            'needs_review' => (bool) ($validated['needs_review'] ?? false),
        ])->save();

        if ($event->market && trim((string) ($validated['market_name'] ?? '')) !== '') {
            $event->market->update([
                'name' => trim((string) $validated['market_name']),
                'default_location_city' => $event->city ?: $event->market->default_location_city,
                'default_location_state' => $event->state ?: $event->market->default_location_state,
            ]);
        }

        $this->event = $event->fresh();
        $this->editing = false;
        $this->fillFromEvent();
        session()->flash('status', 'Market event updated.');
    }

    public function render()
    {
        $event = $this->event->load([
            'market',
            'boxShipments',
            'marketPourList.lines.scent',
            'marketPourList.lines.size',
        ]);

        $boxLines = $event->boxShipments->reject(fn (MarketBoxShipment $line) => $this->isSummaryBoxLine($line))->values();

        return view('livewire.markets.event-browser-show', [
            'event' => $event,
            'boxLines' => $boxLines,
            'draftList' => $event->marketPourList,
        ])->layout('layouts.app');
    }

    private function fillFromEvent(): void
    {
        $event = $this->event->loadMissing('market');
        $this->market_name = (string) ($event->market?->name ?? '');
        $this->event_name = (string) ($event->name ?? '');
        $this->display_name = (string) ($event->display_name ?? '');
        $this->year = $event->year ? (string) $event->year : '';
        $this->starts_at = $event->starts_at?->format('Y-m-d') ?? '';
        $this->ends_at = $event->ends_at?->format('Y-m-d') ?? '';
        $this->city = (string) ($event->city ?? '');
        $this->state = (string) ($event->state ?? '');
        $this->venue = (string) ($event->venue ?? '');
        $this->status = (string) ($event->status ?: 'planned');
        $this->notes = (string) ($event->notes ?? '');
        $this->needs_review = (bool) $event->needs_review;
    }

    private function isSummaryBoxLine(MarketBoxShipment $line): bool
    {
        $candidates = [
            (string) ($line->scent ?? ''),
            (string) ($line->product_key ?? ''),
            (string) ($line->item_type ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $value = Str::lower(trim($candidate));
            if (in_array($value, ['total', 'grand total', 'subtotal', 'totals'], true)) {
                return true;
            }
        }

        return false;
    }
}
