<?php

namespace App\Livewire\Markets;

use App\Models\MarketPourList;
use Livewire\Component;
use Livewire\WithPagination;

class MarketPourLists extends Component
{
    use WithPagination;

    public function render()
    {
        $lists = MarketPourList::query()
            ->withCount('events')
            ->orderByDesc('id')
            ->paginate(10);

        return view('livewire.markets.lists', [
            'lists' => $lists,
        ])->layout('layouts.app');
    }
}
