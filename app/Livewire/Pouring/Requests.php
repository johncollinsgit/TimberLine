<?php

namespace App\Livewire\Pouring;

use App\Models\PourRequest;
use App\Models\PourRequestLine;
use Livewire\Component;

class Requests extends Component
{
    public function markProduced(int $lineId, int $qty): void
    {
        PourRequestLine::query()->where('id', $lineId)->update([
            'produced_qty' => max(0, $qty),
        ]);
    }

    public function closeRequest(int $requestId): void
    {
        PourRequest::query()->where('id', $requestId)->update(['status' => 'closed']);
    }

    public function render()
    {
        $requests = PourRequest::query()
            ->with(['lines.scent', 'lines.size'])
            ->orderByDesc('id')
            ->get();

        return view('livewire.pouring.requests', [
            'requests' => $requests,
        ])->layout('layouts.app');
    }
}
