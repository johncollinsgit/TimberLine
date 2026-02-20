<?php

namespace App\Livewire\PouringRoom;

use App\Models\Order;
use App\Services\Pouring\MeasurementResolver;
use App\Services\Pouring\PouringQueueService;
use Livewire\Component;

class OrderDetail extends Component
{
    public Order $order;
    public bool $showCompleted = false;
    public ?string $returnTo = null;

    protected array $statusOptions = [
        'laid_out' => 'Laid Out',
        'first_pour' => 'First Pour',
        'second_pour' => 'Second Pour',
        'waiting_on_oil' => 'Waiting on Oil (Paused)',
        'brought_down' => 'Brought Down',
    ];

    protected $queryString = [
        'showCompleted' => ['except' => false],
    ];

    public function mount(Order $order): void
    {
        $this->order = $order->load(['lines.scent.oilBlend.components.baseOil', 'lines.size']);
        $this->returnTo = $this->sanitizeReturnTo((string) request()->query('return_to', ''));
    }

    public function start(): void
    {
        $this->order->status = 'pouring';
        $this->order->save();
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Order started.']);
    }

    public function complete(): void
    {
        $this->order->status = 'brought_down';
        $this->order->save();

        $fallback = route('pouring.stack', $this->order->channel ?? $this->order->order_type ?? 'retail');
        $destination = $this->returnTo ?: $fallback;
        $this->redirect($this->appendCelebrateFlag($destination), navigate: true);
    }

    public function toggleCompleted(): void
    {
        $this->showCompleted = !$this->showCompleted;
    }

    public function setGroupStatus(string $groupKey, string $status): void
    {
        if (!array_key_exists($status, $this->statusOptions)) {
            return;
        }

        [$scentId, $sizeId, $wickType] = array_pad(explode(':', $groupKey, 3), 3, '');

        if (!$scentId || !$sizeId) {
            return;
        }

        $query = $this->order->lines()
            ->where('scent_id', (int) $scentId)
            ->where('size_id', (int) $sizeId);

        if ($wickType === '') {
            $query->where(function ($q) {
                $q->whereNull('wick_type')->orWhere('wick_type', '');
            });
        } else {
            $query->where('wick_type', $wickType);
        }

        $updates = ['pour_status' => $status];
        if ($status === 'brought_down') {
            $updates['brought_down_at'] = now();
        }

        $query->update($updates);
        $this->order->load(['lines.scent.oilBlend.components.baseOil', 'lines.size']);

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Status set to ' . ($this->statusOptions[$status] ?? $status) . '.',
        ]);
    }

    public function render()
    {
        $service = app(PouringQueueService::class);
        $measurement = app(MeasurementResolver::class);
        $groups = $service->orderLinesGrouped($this->order)->map(function ($group) use ($measurement) {
            $sizeCode = $group['size']?->code ?? $group['size']?->label ?? '';
            $ingredients = $measurement->resolveLineIngredients($sizeCode, (int) $group['qty']);
            $group['ingredients'] = $ingredients;
            $group['pitchers'] = $this->splitPitchers($ingredients);
            return $group;
        });
        $completedCount = $groups->where('status', 'brought_down')->count();
        $groups = $this->showCompleted
            ? $groups
            : $groups->reject(fn ($group) => ($group['status'] ?? null) === 'brought_down')->values();

        return view('livewire.pouring-room.order-detail', [
            'groups' => $groups,
            'statusOptions' => $this->statusOptions,
            'completedCount' => $completedCount,
        ])->layout('layouts.app');
    }

    protected function sanitizeReturnTo(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        $returnHost = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($returnHost && $appHost && strtolower((string) $returnHost) === strtolower((string) $appHost)) {
            return $url;
        }

        return null;
    }

    protected function appendCelebrateFlag(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['celebrate'] = 1;

        $path = ($parts['scheme'] ?? null) ? ($parts['scheme'] . '://') : '';
        if (!empty($parts['host'])) {
            $path .= $parts['host'];
        }
        if (!empty($parts['port'])) {
            $path .= ':' . $parts['port'];
        }
        $path .= $parts['path'] ?? '';

        $queryString = http_build_query($query);
        if ($queryString !== '') {
            $path .= '?' . $queryString;
        }
        if (!empty($parts['fragment'])) {
            $path .= '#' . $parts['fragment'];
        }

        return $path;
    }

    /**
     * @param  array{wax_grams?:float,oil_grams?:float,pitcher_grams?:float,total_grams?:float}|null  $ingredients
     * @return array<int, array{index:int,wax_grams:float,oil_grams:float,total_grams:float}>
     */
    protected function splitPitchers(?array $ingredients, float $max = 2280): array
    {
        if (!$ingredients) {
            return [];
        }

        $waxTotal = (float) ($ingredients['wax_grams'] ?? 0);
        $oilTotal = (float) ($ingredients['oil_grams'] ?? 0);
        $total = (float) ($ingredients['pitcher_grams'] ?? ($waxTotal + $oilTotal));

        if ($total <= 0 || $max <= 0) {
            return [];
        }

        $ratioWax = $total > 0 ? $waxTotal / $total : 0.0;
        $ratioOil = $total > 0 ? $oilTotal / $total : 0.0;

        $rows = [];
        $remaining = $total;
        $index = 1;

        while ($remaining > 0.0001) {
            $pitcherTotal = min($max, $remaining);
            $rows[] = [
                'index' => $index,
                'wax_grams' => round($pitcherTotal * $ratioWax, 2),
                'oil_grams' => round($pitcherTotal * $ratioOil, 2),
                'total_grams' => round($pitcherTotal, 2),
            ];
            $remaining -= $pitcherTotal;
            $index++;
        }

        return $rows;
    }
}
