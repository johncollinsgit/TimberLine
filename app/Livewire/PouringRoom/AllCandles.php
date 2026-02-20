<?php

namespace App\Livewire\PouringRoom;

use App\Services\Pouring\PouringQueueService;
use Livewire\Component;

class AllCandles extends Component
{
    public string $channel = 'all';
    public string $dueWindow = '7'; // 3|7|14|all

    protected $queryString = [
        'channel' => ['except' => 'all'],
        'dueWindow' => ['except' => '7'],
    ];

    public function render()
    {
        $lines = app(PouringQueueService::class)->allCandles([
            'channel' => $this->channel,
            'due_window' => $this->dueWindow,
        ]);

        $lines = $lines->sortBy(function ($row) {
            return $row['earliest_due'] ?? now()->addYears(5);
        });

        return view('livewire.pouring-room.all-candles', [
            'lines' => $lines,
        ])->layout('layouts.app');
    }
}
