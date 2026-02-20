<?php

namespace App\Livewire\PouringRoom;

use App\Services\Pouring\PouringQueueService;
use Livewire\Component;

class Stacks extends Component
{
    public function render()
    {
        $summary = app(PouringQueueService::class)->stackSummary();
        return view('livewire.pouring-room.stacks', [
            'summary' => $summary,
        ])->layout('layouts.app');
    }
}
