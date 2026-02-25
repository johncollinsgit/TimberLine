<?php

namespace App\Livewire\Markets;

use App\Models\Market;
use Livewire\Component;

class MarketHistoryShow extends Component
{
    public Market $market;
    public string $displayTitle = '';
    public ?string $rawTitleKey = null;

    public function mount(Market $market): void
    {
        $this->market = $market;
    }

    public function render()
    {
        $events = $this->market->events()
            ->with(['boxShipments', 'marketPourList'])
            ->orderByDesc('year')
            ->orderByDesc('starts_at')
            ->get();

        $grouped = $events->groupBy(fn ($e) => (string) ($e->year ?? optional($e->starts_at)->format('Y') ?? 'Unknown'));

        [$this->displayTitle, $this->rawTitleKey] = $this->resolveHeaderTitle($events);

        return view('livewire.markets.market-history-show', [
            'groupedEvents' => $grouped,
        ])->layout('layouts.app');
    }

    private function resolveHeaderTitle($events): array
    {
        $raw = trim((string) $this->market->name);
        if (!$this->looksLikeDateKey($raw)) {
            return [$raw, null];
        }

        $fallback = $events->pluck('name')
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '' && !$this->looksLikeDateKey($v))
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        return [$fallback ?: ($raw !== '' ? $raw : 'Market History'), $raw ?: null];
    }

    private function looksLikeDateKey(?string $value): bool
    {
        $value = trim((string) $value);
        return $value === '' || (bool) preg_match('/^\d{1,2}(?:\.\d{1,2}){0,2}$/', $value);
    }
}
