<?php

namespace App\Livewire\Markets;

use App\Models\Event;
use App\Models\Market;
use Livewire\Component;
use Livewire\WithPagination;

class DirectoryIndex extends Component
{
    use WithPagination;

    public string $search = '';
    public string $year = '';
    public string $state = '';

    protected $queryString = [
        'search' => ['except' => ''],
        'year' => ['except' => ''],
        'state' => ['except' => ''],
        'page' => ['except' => 1],
    ];

    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingYear(): void { $this->resetPage(); }
    public function updatingState(): void { $this->resetPage(); }

    public function render()
    {
        $search = trim($this->search);
        $year = ctype_digit($this->year) ? (int) $this->year : null;
        $state = strtoupper(trim($this->state));

        $markets = Market::query()
            ->with(['events' => function ($q) use ($year, $state) {
                $q->orderByDesc('year')->orderByDesc('starts_at');
                if ($year) {
                    $q->where('year', $year);
                }
                if ($state !== '') {
                    $q->where('state', $state);
                }
            }])
            ->when($search !== '', function ($q) use ($search) {
                $s = '%'.$search.'%';
                $q->where(function ($qq) use ($s) {
                    $qq->where('name', 'like', $s)
                        ->orWhereHas('events', function ($qe) use ($s) {
                            $qe->where('name', 'like', $s)
                                ->orWhere('display_name', 'like', $s)
                                ->orWhere('city', 'like', $s)
                                ->orWhere('state', 'like', $s)
                                ->orWhere('venue', 'like', $s);
                        });
                });
            })
            ->orderBy('name')
            ->paginate(20);

        $today = now()->startOfDay();
        $rows = $markets->getCollection()->map(function (Market $market) use ($today) {
            $events = $market->events;
            $nextUpcoming = $events
                ->filter(fn (Event $e) => $e->starts_at && $e->starts_at->startOfDay()->gte($today))
                ->sortBy('starts_at')
                ->first()
                ?? $events->sortByDesc('starts_at')->first();

            return [
                'market' => $market,
                'occurrences_count' => $events->count(),
                'next' => $nextUpcoming,
            ];
        });
        $markets->setCollection($rows);

        $years = Event::query()
            ->whereNotNull('year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($y) => (int) $y)
            ->all();

        $states = Event::query()
            ->whereNotNull('state')
            ->where('state', '!=', '')
            ->distinct()
            ->orderBy('state')
            ->pluck('state')
            ->map(fn ($s) => strtoupper((string) $s))
            ->all();

        return view('livewire.markets.directory-index', [
            'rows' => $markets,
            'years' => $years,
            'states' => $states,
        ])->layout('layouts.app');
    }
}

