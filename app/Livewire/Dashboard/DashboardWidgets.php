<?php

namespace App\Livewire\Dashboard;

use App\Services\Dashboard\DashboardMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DashboardWidgets extends Component
{
    public array $widgets = [];
    public array $layout = [];
    public bool $showLibrary = false;
    public array $expandedDueWindow = [];
    public string $range = '7';
    public string $channel = 'all';

    private array $library = [
        ['id' => 'today_glance', 'title' => 'Today at a Glance', 'size' => '3', 'description' => 'High-signal daily KPIs.', 'section' => 'A', 'audience' => 'all'],
        ['id' => 'due_window', 'title' => 'Due Today / Next 3 Days', 'size' => '2', 'description' => 'Upcoming due orders by channel.', 'section' => 'B', 'audience' => 'ops'],
        ['id' => 'unpublished_queue', 'title' => 'Unpublished by Channel + Status', 'size' => '2', 'description' => 'Publishing backlog by channel/status.', 'section' => 'C', 'audience' => 'ops'],
        ['id' => 'shipping_queue', 'title' => 'Shipping Queue Health', 'size' => '1', 'description' => 'Ready, blocked, and open queue age.', 'section' => 'B', 'audience' => 'ops'],
        ['id' => 'production_load', 'title' => 'Production Load', 'size' => '1', 'description' => 'Open line item workload by product type.', 'section' => 'B', 'audience' => 'ops'],
        ['id' => 'import_health', 'title' => 'Import Health', 'size' => '1', 'description' => 'Import recency and exception counts.', 'section' => 'C', 'audience' => 'ops'],
        ['id' => 'exceptions', 'title' => 'Exceptions', 'size' => '1', 'description' => 'Unresolved mapping issues.', 'section' => 'C', 'audience' => 'ops'],
        ['id' => 'revenue_snapshot', 'title' => 'Revenue Snapshot', 'size' => '2', 'description' => 'Last 7/30-day sales estimate by channel.', 'section' => 'D', 'audience' => 'all'],
        ['id' => 'top_scents', 'title' => 'Top Scents', 'size' => '2', 'description' => 'Top scents by channel for selected date range.', 'section' => 'E', 'audience' => 'all'],
        ['id' => 'status_pie', 'title' => 'Orders by Status', 'size' => '1', 'description' => 'Current status distribution.', 'section' => 'B', 'audience' => 'ops'],
        ['id' => 'channel_pie', 'title' => 'Orders by Channel', 'size' => '1', 'description' => 'Retail vs wholesale mix.', 'section' => 'E', 'audience' => 'all'],
        ['id' => 'recent_orders', 'title' => 'Recent Orders', 'size' => '2', 'description' => 'Latest inbound order activity.', 'section' => 'B', 'audience' => 'ops'],
        ['id' => 'capacity_staffing', 'title' => 'Capacity / Staffing', 'size' => '1', 'description' => 'Team capacity and staffing signal.', 'section' => 'F', 'audience' => 'all'],
        ['id' => 'cash_runway', 'title' => 'Cash / Runway', 'size' => '1', 'description' => 'Cash health quick view.', 'section' => 'D', 'audience' => 'all'],
        ['id' => 'inventory_alerts', 'title' => 'Inventory Alerts', 'size' => '1', 'description' => 'Inventory warnings and risk states.', 'section' => 'C', 'audience' => 'ops'],
        ['id' => 'notes_reminders', 'title' => 'Notes / Reminders', 'size' => '1', 'description' => 'Internal dashboard notes queue.', 'section' => 'F', 'audience' => 'all'],
    ];

    private array $defaultLayout = [
        'today_glance',
        'due_window',
        'shipping_queue',
        'unpublished_queue',
        'production_load',
        'import_health',
        'exceptions',
        'revenue_snapshot',
        'top_scents',
        'recent_orders',
    ];

    public function mount(): void
    {
        $user = Auth::user();
        $savedLayout = is_array($user?->dashboard_layout) ? $user->dashboard_layout : null;

        $prefs = is_array($user?->ui_preferences) ? $user->ui_preferences : [];
        $savedFilters = is_array($prefs['dashboard_filters'] ?? null) ? $prefs['dashboard_filters'] : [];
        $savedRange = (string) ($savedFilters['range'] ?? '7');
        $savedChannel = (string) ($savedFilters['channel'] ?? 'all');
        $this->range = in_array($savedRange, ['1', '7', '30'], true)
            ? $savedRange
            : '7';
        $this->channel = in_array($savedChannel, ['all', 'retail', 'wholesale'], true)
            ? $savedChannel
            : 'all';

        $this->layout = $this->normalizeLayout($savedLayout, $this->defaultLayoutForUser());
        $this->widgets = $this->visibleWidgets;
    }

    public function updatedRange(string $value): void
    {
        if (!in_array($value, ['1', '7', '30'], true)) {
            $this->range = '7';
        }

        $this->persistFilters();
    }

    public function updatedChannel(string $value): void
    {
        if (!in_array($value, ['all', 'retail', 'wholesale'], true)) {
            $this->channel = 'all';
        }

        $this->persistFilters();
    }

    public function saveOrder(array $orderedIds): void
    {
        $ids = $this->filterKnownIds($orderedIds);
        $this->layout = $this->mergeOrder($ids, $this->layout);
        $this->persist();
        $this->widgets = $this->visibleWidgets;
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

    public function toggleWidget(string $id): void
    {
        $this->addWidget($id);
    }

    public function addWidget(string $id): void
    {
        $id = $this->canonicalWidgetId($id);

        if (!collect($this->layout)->contains(fn ($item) => ($item['id'] ?? null) === $id)) {
            $this->layout[] = ['id' => $id, 'size' => $this->defaultSizeFor($id)];
            $this->persist();
            $this->widgets = $this->visibleWidgets;
        }
    }

    public function removeWidget(string $id): void
    {
        $id = $this->canonicalWidgetId($id);
        $this->layout = array_values(array_filter($this->layout, fn ($w) => ($w['id'] ?? null) !== $id));
        $this->persist();
        $this->widgets = $this->visibleWidgets;
    }

    public function toggleDueWindow(int $orderId): void
    {
        $this->expandedDueWindow[$orderId] = !($this->expandedDueWindow[$orderId] ?? false);
    }

    public function setWidgetSize(string $id, string $size): void
    {
        $id = $this->canonicalWidgetId($id);
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
            $this->widgets = $this->visibleWidgets;
        }
    }

    protected function persist(): void
    {
        $user = Auth::user();
        if (!$user) {
            return;
        }

        $user->forceFill([
            'dashboard_layout' => $this->layout,
        ])->save();
    }

    protected function persistFilters(): void
    {
        $user = Auth::user();
        if (!$user) {
            return;
        }

        $prefs = is_array($user->ui_preferences) ? $user->ui_preferences : [];
        $prefs['dashboard_filters'] = [
            'range' => $this->range,
            'channel' => $this->channel,
        ];

        $user->forceFill([
            'ui_preferences' => $prefs,
        ])->save();
    }

    protected function normalizeLayout($saved, array $defaults): array
    {
        $items = [];

        if (is_array($saved)) {
            foreach ($saved as $item) {
                if (is_array($item)) {
                    $id = $this->canonicalWidgetId((string) ($item['id'] ?? ''));
                    if ($id !== '') {
                        $items[] = ['id' => $id, 'size' => $this->normalizeSize($item['size'] ?? null, $id)];
                    }
                } elseif (is_string($item)) {
                    $id = $this->canonicalWidgetId($item);
                    if ($id !== '') {
                        $items[] = ['id' => $id, 'size' => $this->defaultSizeFor($id)];
                    }
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

        return array_values(array_filter(array_map(fn ($id) => $this->canonicalWidgetId((string) $id), $ids), fn ($id) => in_array($id, $known, true)));
    }

    protected function mergeOrder(array $orderedIds, array $current): array
    {
        $sizes = [];

        foreach ($current as $item) {
            if (is_array($item) && isset($item['id'])) {
                $id = $this->canonicalWidgetId((string) $item['id']);
                $sizes[$id] = $this->normalizeSize($item['size'] ?? null, $id);
            } elseif (is_string($item)) {
                $id = $this->canonicalWidgetId($item);
                $sizes[$id] = $this->defaultSizeFor($id);
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
        $opsOnly = $this->isOpsOnlyUser();

        return collect($this->layout)
            ->map(function ($item) use ($map) {
                if (is_string($item)) {
                    $id = $this->canonicalWidgetId($item);
                    $base = $map->get($id);

                    return $base ? array_merge($base, ['size' => $this->defaultSizeFor($id)]) : null;
                }

                $id = $this->canonicalWidgetId((string) ($item['id'] ?? ''));
                $base = $id ? $map->get($id) : null;
                if (!$base) {
                    return null;
                }

                return array_merge($base, [
                    'size' => $this->normalizeSize($item['size'] ?? null, $id),
                ]);
            })
            ->filter()
            ->filter(function (array $widget) use ($opsOnly): bool {
                if (!$opsOnly) {
                    return true;
                }

                return ($widget['audience'] ?? 'all') === 'ops';
            })
            ->values()
            ->all();
    }

    public function getWidgetLibraryProperty(): array
    {
        $opsOnly = $this->isOpsOnlyUser();

        return collect($this->library)
            ->filter(function (array $widget) use ($opsOnly): bool {
                if (!$opsOnly) {
                    return true;
                }

                return ($widget['audience'] ?? 'all') === 'ops';
            })
            ->values()
            ->all();
    }

    private function defaultSizeFor(string $id): string
    {
        $size = collect($this->library)->firstWhere('id', $id)['size'] ?? '2';

        return $this->normalizeSize($size, $id);
    }

    private function normalizeSize(?string $size, ?string $id = null): string
    {
        if ($size === 'full') {
            return '3';
        }
        if ($size === 'half') {
            return '2';
        }
        if ($size === 'third') {
            return '1';
        }

        if (in_array($size, ['1', '2', '3'], true)) {
            return $size;
        }

        if ($id) {
            $fallback = collect($this->library)->firstWhere('id', $id)['size'] ?? '2';

            return $this->normalizeSize($fallback);
        }

        return '2';
    }

    private function canonicalWidgetId(string $id): string
    {
        $legacyMap = [
            'kpi_strip' => 'today_glance',
            'next_due' => 'due_window',
            'orders_table' => 'recent_orders',
        ];

        return $legacyMap[$id] ?? $id;
    }

    private function defaultLayoutForUser(): array
    {
        if ($this->isOpsOnlyUser()) {
            return [
                'today_glance',
                'due_window',
                'shipping_queue',
                'unpublished_queue',
                'production_load',
                'import_health',
                'exceptions',
                'recent_orders',
                'status_pie',
            ];
        }

        return $this->defaultLayout;
    }

    private function isOpsOnlyUser(): bool
    {
        return Auth::user()?->isPouring() ?? false;
    }

    public function getGreetingProperty(): string
    {
        $name = Auth::user()?->name ?? 'there';

        return "Welcome back, {$name}.";
    }

    public function getQuoteOfDayProperty(): array
    {
        $quotes = [
            ['quote' => "I'm not arguing, I'm just explaining why I'm right.", 'author' => 'Bill Burr'],
            ['quote' => "I love deadlines. I like the whooshing sound they make as they fly by.", 'author' => 'Douglas Adams'],
            ['quote' => 'Behind every great man is a woman rolling her eyes.', 'author' => 'Jim Carrey'],
            ['quote' => "I'm on a whiskey diet. I've lost three days already.", 'author' => 'Tommy Cooper'],
            ['quote' => 'I refuse to join any club that would have me as a member.', 'author' => 'Groucho Marx'],
        ];

        $userId = Auth::id() ?? 0;
        $dayKey = CarbonImmutable::now()->format('Y-m-d');
        $index = crc32($dayKey.'|'.$userId) % count($quotes);

        return $quotes[$index];
    }

    public function getDashboardDataProperty(): array
    {
        return app(DashboardMetrics::class)->snapshot((int) $this->range, $this->channel);
    }

    public function render()
    {
        return view('livewire.dashboard.dashboard-widgets');
    }
}
