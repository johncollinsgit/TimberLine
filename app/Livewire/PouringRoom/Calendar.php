<?php

namespace App\Livewire\PouringRoom;

use App\Services\Pouring\PouringQueueService;
use Carbon\CarbonImmutable;
use Livewire\Component;

class Calendar extends Component
{
    public string $month; // YYYY-MM

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function prevMonth(): void
    {
        $this->month = CarbonImmutable::parse($this->month . '-01')->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = CarbonImmutable::parse($this->month . '-01')->addMonth()->format('Y-m');
    }

    public function render()
    {
        $orders = app(PouringQueueService::class)->openOrders();
        $monthStart = CarbonImmutable::parse($this->month . '-01')->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $start = $monthStart->startOfWeek(CarbonImmutable::SUNDAY);
        $end = $monthEnd->endOfWeek(CarbonImmutable::SATURDAY);

        $days = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $dayOrders = $orders->filter(function ($o) use ($d) {
                return $o->due_at && CarbonImmutable::parse($o->due_at)->isSameDay($d);
            });
            $days[] = [
                'date' => $d,
                'in_month' => $d->month === $monthStart->month,
                'orders' => $dayOrders,
            ];
        }

        return view('livewire.pouring-room.calendar', [
            'days' => $days,
            'monthStart' => $monthStart,
        ])->layout('layouts.app');
    }
}
