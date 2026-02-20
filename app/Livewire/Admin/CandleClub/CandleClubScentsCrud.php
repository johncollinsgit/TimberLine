<?php

namespace App\Livewire\Admin\CandleClub;

use App\Models\CandleClubScent;
use App\Models\Scent;
use Livewire\Component;
use Livewire\WithPagination;

class CandleClubScentsCrud extends Component
{
    use WithPagination;

    public string $search = '';
    public int $perPage = 25;

    public ?int $month = null;
    public ?int $year = null;
    public string $scentName = '';
    public string $oilName = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        $this->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2000|max:2100',
            'scentName' => 'required|string|max:255',
            'oilName' => 'required|string|max:255',
        ]);

        $monthName = \Carbon\Carbon::create()->month((int) $this->month)->format('F');
        $display = $monthName . ' ' . $this->year . ' Candle Club — ' . trim($this->scentName);

        $normalized = Scent::normalizeName($display);
        $existing = Scent::query()->get()->first(function ($scent) use ($normalized) {
            return Scent::normalizeName($scent->name) === $normalized;
        });

        $scent = $existing ?? Scent::query()->create([
            'name' => $display,
            'display_name' => $display,
            'oil_reference_name' => $this->oilName,
            'is_candle_club' => true,
            'is_active' => true,
        ]);

        CandleClubScent::query()->updateOrCreate(
            ['month' => (int) $this->month, 'year' => (int) $this->year],
            ['scent_id' => $scent->id]
        );

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Candle Club scent saved.',
        ]);

        $this->reset(['month','year','scentName','oilName']);
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
