<?php

namespace App\Livewire\Analytics;

use App\Models\MappingException;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AnalyticsWidgets extends Component
{
    public array $layout = [];
    public bool $showLibrary = true;
    public array $expandedNextShip = [];

    private array $library = [
        ['id' => 'orders_by_type', 'title' => 'Orders by Type', 'size' => '1', 'description' => 'Retail vs wholesale vs market mix.'],
        ['id' => 'orders_by_status', 'title' => 'Orders by Status', 'size' => '1', 'description' => 'Production status breakdown.'],
        ['id' => 'exceptions', 'title' => 'Mapping Exceptions', 'size' => '1', 'description' => 'Unresolved item mappings.'],
        ['id' => 'upcoming_ship', 'title' => 'Upcoming Shipping Deadlines', 'size' => '2', 'description' => 'Next ship-by dates.'],
        ['id' => 'recent_orders', 'title' => 'Recent Orders', 'size' => '2', 'description' => 'Latest activity.'],
    ];

    private array $defaultLayout = [
        'orders_by_type',
        'orders_by_status',
        'exceptions',
        'upcoming_ship',
        'recent_orders',
    ];

    public function mount(): void
    {
        $user = Auth::user();
        $prefs = is_array($user?->ui_preferences) ? $user->ui_preferences : [];
        $saved = $prefs['analytics_layout'] ?? null;
        $this->layout = $this->normalizeLayout($saved, $this->defaultLayout);
    }

    public function saveOrder(array $orderedIds): void
    {
        $ids = $this->filterKnownIds($orderedIds);
        $this->layout = $this->mergeOrder($ids, $this->layout);
        $this->persist();
    }

    public function toggleWidget(string $id): void
    {
        $this->addWidget($id);
    }

    public function addWidget(string $id): void
    {
        if (!collect($this->layout)->contains(fn ($item) => ($item['id'] ?? null) === $id)) {
            $this->layout[] = ['id' => $id, 'size' => $this->defaultSizeFor($id)];
            $this->persist();
        }
    }

    public function removeWidget(string $id): void
    {
        $this->layout = array_values(array_filter($this->layout, fn ($w) => ($w['id'] ?? null) !== $id));
        $this->persist();
    }

    public function setWidgetSize(string $id, string $size): void
    {
        $size = $this->normalizeSize($size);
        $updated = false;
        foreach ($this->layout as &$item) {
            if (($item['id'] ?? null) === $id) {
                $item['size'] = $size;
                $updated = true;
                break;
            }
        }
        unset($item);

        if ($updated) {
            $this->persist();
        }
    }

    public function openLibrary(): void
    {
        $this->showLibrary = true;
    }

    public function closeLibrary(): void
    {
        $this->showLibrary = false;
    }

    public function toggleLibrary(): void
    {
        $this->showLibrary = !$this->showLibrary;
    }

    public function toggleNextShip(int $orderId): void
    {
        $this->expandedNextShip[$orderId] = !($this->expandedNextShip[$orderId] ?? false);
    }

    protected function persist(): void
    {
        $user = Auth::user();
        if (!$user) {
            return;
        }
        $prefs = is_array($user->ui_preferences) ? $user->ui_preferences : [];
        $prefs['analytics_layout'] = $this->layout;
        $user->forceFill(['ui_preferences' => $prefs])->save();
    }

    protected function normalizeLayout($saved, array $defaults): array
    {
        $items = [];
        if (is_array($saved)) {
            foreach ($saved as $item) {
                if (is_array($item)) {
                    $id = $item['id'] ?? null;
                    if ($id) {
                        $items[] = ['id' => $id, 'size' => $this->normalizeSize($item['size'] ?? null, $id)];
                    }
                } else if (is_string($item)) {
                    $items[] = ['id' => $item, 'size' => $this->defaultSizeFor($item)];
                }
            }
        }
        if (empty($items)) {
            $items = array_map(fn ($id) => ['id' => $id, 'size' => $this->defaultSizeFor($id)], $defaults);
        }
        $known = collect($this->library)->pluck('id')->all();
        return array_values(array_filter($items, fn ($item) => in_array($item['id'] ?? null, $known, true)));
    }

    protected function filterKnownIds(array $ids): array
    {
        $known = collect($this->library)->pluck('id')->all();
        return array_values(array_filter($ids, fn ($id) => in_array($id, $known, true)));
    }

    protected function mergeOrder(array $orderedIds, array $current): array
    {
        $sizes = [];
        foreach ($current as $item) {
            if (is_array($item) && isset($item['id'])) {
                $sizes[$item['id']] = $this->normalizeSize($item['size'] ?? null, $item['id']);
            } elseif (is_string($item)) {
                $sizes[$item] = $this->defaultSizeFor($item);
            }
        }

        $merged = [];
        foreach ($orderedIds as $id) {
            if (isset($sizes[$id])) {
                $merged[] = ['id' => $id, 'size' => $sizes[$id]];
            }
        }
        foreach ($sizes as $id => $size) {
            if (!collect($merged)->contains(fn ($item) => ($item['id'] ?? null) === $id)) {
                $merged[] = ['id' => $id, 'size' => $size];
            }
        }
        return $merged;
    }

    public function getVisibleWidgetsProperty(): array
    {
        $map = collect($this->library)->keyBy('id');
        return collect($this->layout)
            ->map(function ($item) use ($map) {
                if (is_string($item)) {
                    $base = $map->get($item);
                    return $base ? array_merge($base, ['size' => $this->defaultSizeFor($item)]) : null;
                }
                $id = $item['id'] ?? null;
                $base = $id ? $map->get($id) : null;
                if (!$base) return null;
                return array_merge($base, [
                    'size' => $this->normalizeSize($item['size'] ?? null, $id),
                ]);
            })
            ->filter()
            ->values()
            ->all();
    }

    public function getWidgetLibraryProperty(): array
    {
        return $this->library;
    }

    private function defaultSizeFor(string $id): string
    {
        $size = collect($this->library)->firstWhere('id', $id)['size'] ?? '2';
        return $this->normalizeSize($size, $id);
    }

    private function normalizeSize(?string $size, ?string $id = null): string
    {
        if ($size === 'full') return '3';
        if ($size === 'half') return '2';
        if ($size === 'third') return '1';

        if (in_array($size, ['1', '2', '3'], true)) {
            return $size;
        }

        if ($id) {
            $fallback = collect($this->library)->firstWhere('id', $id)['size'] ?? '2';
            return $this->normalizeSize($fallback);
        }

        return '2';
    }

    public function getAnalyticsDataProperty(): array
    {
        $statusCounts = Order::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $typeCounts = Order::query()
            ->selectRaw('order_type, COUNT(*) as count')
            ->groupBy('order_type')
            ->pluck('count', 'order_type')
            ->all();

        $recentOrders = Order::query()
            ->orderByDesc('id')
            ->limit(6)
            ->get([
                'order_number',
                'order_label',
                'customer_name',
                'shipping_name',
                'billing_name',
                'shipping_company',
                'shipping_address1',
                'billing_company',
                'billing_address1',
                'order_type',
                'status',
                'ship_by_at',
                'created_at',
                'shopify_name',
            ]);

        $nextShip = Order::query()
            ->whereNotNull('ship_by_at')
            ->orderBy('ship_by_at')
            ->limit(5)
            ->get([
                'id',
                'order_number',
                'order_label',
                'customer_name',
                'shipping_name',
                'billing_name',
                'shipping_company',
                'shipping_address1',
                'billing_company',
                'billing_address1',
                'order_type',
                'status',
                'ship_by_at',
                'shopify_name',
            ]);

        $lineSummaries = $this->lineSummaryForOrders($nextShip->pluck('id')->all());

        $exceptions = MappingException::query()
            ->whereNull('resolved_at')
            ->count();

        return [
            'statusCounts' => $statusCounts,
            'typeCounts' => $typeCounts,
            'recentOrders' => $recentOrders->map(function ($o) {
                $o->display_name = $o->order_label
                    ?: $o->customer_name
                    ?: $o->shipping_name
                    ?: $o->billing_name
                    ?: $o->shipping_company
                    ?: $o->shipping_address1
                    ?: $o->billing_company
                    ?: $o->billing_address1
                    ?: $o->shopify_name;
                return $o;
            }),
            'nextShip' => $nextShip->map(function ($o) use ($lineSummaries) {
                $lines = $lineSummaries[$o->id] ?? [];
                $o->display_name = $o->order_label
                    ?: $o->customer_name
                    ?: $o->shipping_name
                    ?: $o->billing_name
                    ?: $o->shipping_company
                    ?: $o->shipping_address1
                    ?: $o->billing_company
                    ?: $o->billing_address1
                    ?: $o->shopify_name;
                $o->lines = $lines;
                $o->lines_preview = array_slice($lines, 0, 4);
                $o->lines_more = max(0, count($lines) - 4);
                return $o;
            }),
            'exceptions' => $exceptions,
        ];
    }

    public function render()
    {
        return view('livewire.analytics.widgets');
    }

    protected function lineSummaryForOrders(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        $lines = \App\Models\OrderLine::query()
            ->with(['scent', 'size'])
            ->whereIn('order_id', $orderIds)
            ->get();

        return $lines->groupBy('order_id')->map(function ($group) {
            return $group->map(function ($line) {
                $qty = (int) ($line->ordered_qty ?? 0) + (int) ($line->extra_qty ?? 0);
                if ($qty <= 0) {
                    $qty = (int) ($line->quantity ?? 0);
                }
                $scent = $line->scent?->name ?: $line->scent_name ?: $line->raw_title ?: 'Unknown';
                $size = $line->size?->display ?: $line->size_code ?: null;
                $label = $size ? "{$scent} · {$size}" : $scent;
                return trim($label).' ×'.$qty;
            })->filter()->values()->all();
        })->all();
    }
}
