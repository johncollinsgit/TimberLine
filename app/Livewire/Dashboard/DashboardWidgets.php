<?php

namespace App\Livewire\Dashboard;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;

class DashboardWidgets extends Component
{
    public array $widgets = [];

    // default widget layout (ids must be unique + stable)
private array $defaultLayout = [
    ['id' => 'status_pie',   'title' => 'Orders by Status',  'size' => 'half'],
    ['id' => 'channel_pie',  'title' => 'Orders by Channel', 'size' => 'half'],

    ['id' => 'next_due',     'title' => 'Next 5 Due',        'size' => 'half'],
    ['id' => 'orders_table', 'title' => 'Orders',            'size' => 'half'],
];


    public function mount(): void
    {
        $user = Auth::user();
        $saved = is_array($user?->dashboard_layout) ? $user->dashboard_layout : null;

        $this->widgets = $this->mergeLayouts($saved, $this->defaultLayout);
    }

    public function saveOrder(array $orderedIds): void
    {
        $map = collect($this->widgets)->keyBy('id');

        $this->widgets = collect($orderedIds)
            ->map(fn ($id) => $map->get($id))
            ->filter()
            ->values()
            ->all();

        Auth::user()->forceFill([
            'dashboard_layout' => $this->widgets,
        ])->save();
    }

    private function mergeLayouts(?array $saved, array $defaults): array
    {
        if (!is_array($saved) || empty($saved)) return $defaults;

        $defaultMap = collect($defaults)->keyBy('id');
        $savedIds = collect($saved)->pluck('id')->all();

        $merged = collect($saved)
            ->map(function ($w) use ($defaultMap) {
                $id = $w['id'] ?? null;
                if (!$id) return null;
                return $defaultMap->get($id, $w);
            })
            ->filter()
            ->values();

        $newOnes = collect($defaults)->filter(fn ($d) => !in_array($d['id'], $savedIds, true));

        return $merged->concat($newOnes)->values()->all();
    }

    public function getDashboardDataProperty(): array
    {
        // STUB DATA until you have an Order model/table.
        return [
            'statusCounts' => [
                'new' => 12,
                'pouring' => 7,
                'verified' => 4,
                'complete' => 19,
            ],
            'channelCounts' => [
                'wholesale' => 14,
                'retail' => 21,
                'market' => 7,
            ],
            'nextDue' => [
                ['number' => '10021', 'customer' => 'Urban Digs', 'channel' => 'wholesale', 'status' => 'pouring', 'due' => '2026-02-11'],
                ['number' => '10022', 'customer' => 'Website', 'channel' => 'retail', 'status' => 'new', 'due' => '2026-02-12'],
                ['number' => '10023', 'customer' => 'Strawberry Fest', 'channel' => 'market', 'status' => 'reviewed', 'due' => '2026-02-13'],
                ['number' => '10024', 'customer' => 'Wholesale', 'channel' => 'wholesale', 'status' => 'new', 'due' => '2026-02-14'],
                ['number' => '10025', 'customer' => 'Retail', 'channel' => 'retail', 'status' => 'verified', 'due' => '2026-02-15'],
            ],
            'orders' => [
                ['number' => '10025', 'customer' => 'Retail', 'channel' => 'retail', 'status' => 'verified', 'due' => '2026-02-15', 'created' => '2026-02-09'],
                ['number' => '10024', 'customer' => 'Wholesale', 'channel' => 'wholesale', 'status' => 'new', 'due' => '2026-02-14', 'created' => '2026-02-09'],
                ['number' => '10023', 'customer' => 'Strawberry Fest', 'channel' => 'market', 'status' => 'reviewed', 'due' => '2026-02-13', 'created' => '2026-02-09'],
            ],
        ];
    }

    public function render()
    {
        return view('livewire.dashboard.dashboard-widgets');
    }
}
