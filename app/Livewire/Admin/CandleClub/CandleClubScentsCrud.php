<?php

namespace App\Livewire\Admin\CandleClub;

use App\Models\CandleClubScent;
use Livewire\Component;
use Livewire\WithPagination;

class CandleClubScentsCrud extends Component
{
    use WithPagination;

    protected $listeners = [
        'scentSelected' => 'handleScentSelected',
    ];

    public string $search = '';
    public int $perPage = 25;

    public ?int $month = null;
    public ?int $year = null;
    public ?int $scentId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        $this->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000|max:2100',
            'scentId' => 'required|integer|exists:scents,id',
        ]);

        CandleClubScent::query()->updateOrCreate(
            ['month' => (int) $this->month, 'year' => (int) $this->year],
            ['scent_id' => (int) $this->scentId]
        );

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Candle Club assignment saved.',
        ]);

        $this->reset(['month','year','scentId']);
    }

    public function handleScentSelected(string $key, ?int $scentId = null): void
    {
        if ($key !== 'candle-club-scent') {
            return;
        }

        $this->scentId = $scentId;
    }

    public function render()
    {
        $query = CandleClubScent::query()
            ->with('scent')
            ->orderByDesc('year')
            ->orderByDesc('month');

        if ($this->search !== '') {
            $s = '%' . $this->search . '%';
            $query->whereHas('scent', function ($q) use ($s) {
                $q->where('name', 'like', $s)
                  ->orWhere('display_name', 'like', $s)
                  ->orWhere('oil_reference_name', 'like', $s);
            });
        }

        return view('livewire.admin.candleclub.scents', [
            'records' => $query->paginate($this->perPage),
        ]);
    }
}
