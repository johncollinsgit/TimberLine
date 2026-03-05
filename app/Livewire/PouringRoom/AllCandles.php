<?php

namespace App\Livewire\PouringRoom;

use App\Services\Pouring\PouringQueueService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Component;

class AllCandles extends Component
{
    public string $channel = 'all';
    public string $dueWindow = '7'; // 3|7|14|all
    public string $sortBy = 'earliest_due'; // earliest_due|most_wax|most_pitchers|most_units|markets_first|retail_first|wholesale_first
    public string $batchMode = 'by_market'; // by_market|all_markets_combined
    public ?string $selectedRowKey = null;

    protected $queryString = [
        'channel' => ['except' => 'all'],
        'dueWindow' => ['except' => '7'],
        'sortBy' => ['except' => 'earliest_due'],
        'batchMode' => ['except' => 'by_market'],
    ];

    public function selectRow(string $rowKey): void
    {
        $this->selectedRowKey = trim($rowKey) !== '' ? $rowKey : null;
    }

    public function render()
    {
        $rows = app(PouringQueueService::class)->allCandles([
            'channel' => $this->channel,
            'due_window' => $this->dueWindow,
            'batch_mode' => $this->batchMode,
        ]);

        $nextQueue = $rows
            ->filter(fn (array $row): bool => (string) ($row['status'] ?? '') !== 'brought_down')
            ->sort(function (array $a, array $b): int {
                $dueA = ($a['earliest_due'] ?? null) instanceof CarbonImmutable
                    ? $a['earliest_due']->timestamp
                    : PHP_INT_MAX;
                $dueB = ($b['earliest_due'] ?? null) instanceof CarbonImmutable
                    ? $b['earliest_due']->timestamp
                    : PHP_INT_MAX;
                if ($dueA !== $dueB) {
                    return $dueA <=> $dueB;
                }

                $waxA = (float) ($a['wax_grams'] ?? 0);
                $waxB = (float) ($b['wax_grams'] ?? 0);
                if ($waxA !== $waxB) {
                    return $waxB <=> $waxA;
                }

                $pitchersA = (int) ($a['pitchers'] ?? 0);
                $pitchersB = (int) ($b['pitchers'] ?? 0);
                if ($pitchersA !== $pitchersB) {
                    return $pitchersB <=> $pitchersA;
                }

                return strcasecmp((string) ($a['scent_label'] ?? ''), (string) ($b['scent_label'] ?? ''));
            })
            ->take(5)
            ->values();

        $rows = $this->sortRows($rows);

        if ($rows->isNotEmpty()) {
            $validKeys = $rows->pluck('key')->filter()->values()->all();
            if (! $this->selectedRowKey || ! in_array($this->selectedRowKey, $validKeys, true)) {
                $this->selectedRowKey = (string) $validKeys[0];
            }
        } else {
            $this->selectedRowKey = null;
        }

        $selectedRow = $this->selectedRowKey
            ? $rows->firstWhere('key', $this->selectedRowKey)
            : null;

        return view('livewire.pouring-room.all-candles', [
            'rows' => $rows,
            'selectedRow' => $selectedRow,
            'nextQueue' => $nextQueue,
        ])->layout('layouts.app');
    }

    protected function sortRows(Collection $rows): Collection
    {
        $sort = $this->sortBy;
        $channelPriority = [
            'markets_first' => ['event' => 0, 'retail' => 1, 'wholesale' => 2],
            'retail_first' => ['retail' => 0, 'event' => 1, 'wholesale' => 2],
            'wholesale_first' => ['wholesale' => 0, 'event' => 1, 'retail' => 2],
        ];

        return $rows->sort(function (array $a, array $b) use ($sort, $channelPriority): int {
            $dueA = ($a['earliest_due'] ?? null) instanceof CarbonImmutable
                ? $a['earliest_due']->timestamp
                : PHP_INT_MAX;
            $dueB = ($b['earliest_due'] ?? null) instanceof CarbonImmutable
                ? $b['earliest_due']->timestamp
                : PHP_INT_MAX;
            $waxA = (float) ($a['wax_grams'] ?? 0);
            $waxB = (float) ($b['wax_grams'] ?? 0);
            $oilA = (float) ($a['oil_grams'] ?? 0);
            $oilB = (float) ($b['oil_grams'] ?? 0);
            $pitchersA = (int) ($a['pitchers'] ?? 0);
            $pitchersB = (int) ($b['pitchers'] ?? 0);
            $unitsA = (int) ($a['units'] ?? 0);
            $unitsB = (int) ($b['units'] ?? 0);
            $channelA = (string) ($a['primary_channel'] ?? 'retail');
            $channelB = (string) ($b['primary_channel'] ?? 'retail');
            $nameA = mb_strtolower((string) ($a['scent_label'] ?? ''));
            $nameB = mb_strtolower((string) ($b['scent_label'] ?? ''));

            if (isset($channelPriority[$sort])) {
                $priority = $channelPriority[$sort];
                $cmp = ($priority[$channelA] ?? 9) <=> ($priority[$channelB] ?? 9);
                if ($cmp !== 0) {
                    return $cmp;
                }
                if ($dueA !== $dueB) {
                    return $dueA <=> $dueB;
                }
                if ($waxA !== $waxB) {
                    return $waxB <=> $waxA;
                }
                return $nameA <=> $nameB;
            }

            if ($sort === 'most_wax') {
                if ($waxA !== $waxB) {
                    return $waxB <=> $waxA;
                }
                if ($oilA !== $oilB) {
                    return $oilB <=> $oilA;
                }
            } elseif ($sort === 'most_pitchers') {
                if ($pitchersA !== $pitchersB) {
                    return $pitchersB <=> $pitchersA;
                }
            } elseif ($sort === 'most_units') {
                if ($unitsA !== $unitsB) {
                    return $unitsB <=> $unitsA;
                }
            } else {
                if ($dueA !== $dueB) {
                    return $dueA <=> $dueB;
                }
            }

            if ($dueA !== $dueB) {
                return $dueA <=> $dueB;
            }
            if ($pitchersA !== $pitchersB) {
                return $pitchersB <=> $pitchersA;
            }

            return $nameA <=> $nameB;
        })->values();
    }
}
