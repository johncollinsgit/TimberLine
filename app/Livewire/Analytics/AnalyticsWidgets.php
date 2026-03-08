<?php

namespace App\Livewire\Analytics;

use App\Services\Inventory\InventoryService;
use App\Services\Reporting\AnalyticsTimeframeService;
use App\Services\Reporting\DemandReportingService;
use App\Services\Reporting\InventoryReportingService;
use App\Services\Reporting\ScentAnalyticsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AnalyticsWidgets extends Component
{
    public array $layout = [];

    public bool $showLibrary = true;

    public string $timeMode = 'rolling';

    public string $preset = 'last_30_days';

    public ?string $customStartDate = null;

    public ?string $customEndDate = null;

    public string $comparisonMode = 'none';

    public string $channel = 'all';

    private array $library = [
        ['id' => 'unmapped_exceptions', 'title' => 'Unmapped Exceptions Summary', 'size' => '1', 'description' => 'Open unresolved mappings by source/channel.'],
        ['id' => 'inventory_snapshot', 'title' => 'Inventory Snapshot', 'size' => '1', 'description' => 'Current low/reorder counts and wax coverage.'],
        ['id' => 'demand_state_overview', 'title' => 'Demand State Overview', 'size' => '1', 'description' => 'Forecast vs current vs actual totals.'],
        ['id' => 'top_scents_forecast', 'title' => 'Top Scents by Forecast Demand', 'size' => '2', 'description' => 'Upcoming scent demand (forecast state).'],
        ['id' => 'top_scents_current', 'title' => 'Top Scents by Current/Open Demand', 'size' => '2', 'description' => 'Committed queue scent demand (current state).'],
        ['id' => 'top_scents_actual', 'title' => 'Top Scents by Actual Usage', 'size' => '2', 'description' => 'Completed usage scent demand (actual state).'],
        ['id' => 'top_oils_forecast', 'title' => 'Top Oils by Forecast Demand', 'size' => '2', 'description' => 'Flattened oil demand for forecast window.'],
        ['id' => 'oil_reorder_risk', 'title' => 'Current Oil Reorder Risk', 'size' => '2', 'description' => 'Projected oil risk after current demand.'],
        ['id' => 'wax_reorder_risk', 'title' => 'Wax Reorder Risk', 'size' => '1', 'description' => 'Projected wax risk after current demand.'],
    ];

    private array $defaultLayout = [
        'unmapped_exceptions',
        'inventory_snapshot',
        'demand_state_overview',
        'top_scents_forecast',
        'top_scents_current',
        'top_scents_actual',
        'top_oils_forecast',
        'oil_reorder_risk',
        'wax_reorder_risk',
    ];

    public function mount(): void
    {
        $user = Auth::user();
        $prefs = is_array($user?->ui_preferences) ? $user->ui_preferences : [];

        $savedLayout = $prefs['analytics_layout'] ?? null;
        $this->layout = $this->normalizeLayout($savedLayout, $this->defaultLayout);

        $filters = is_array($prefs['analytics_filters'] ?? null) ? $prefs['analytics_filters'] : [];
        $legacyWeeks = (int) ($filters['window_weeks'] ?? 0);

        $this->timeMode = $this->normalizeTimeMode($filters['time_mode'] ?? 'rolling');
        $this->preset = $this->normalizePreset($filters['preset'] ?? ($legacyWeeks >= 8 ? 'last_90_days' : 'last_30_days'));
        $this->customStartDate = $this->normalizeDateString($filters['custom_start_date'] ?? null);
        $this->customEndDate = $this->normalizeDateString($filters['custom_end_date'] ?? null);
        $this->comparisonMode = $this->normalizeComparisonMode($filters['comparison_mode'] ?? 'none');
        $this->channel = $this->normalizeChannel($filters['channel'] ?? 'all');
    }

    public function applyFilters(): void
    {
        $this->timeMode = $this->normalizeTimeMode($this->timeMode);
        $this->preset = $this->normalizePreset($this->preset);
        $this->comparisonMode = $this->normalizeComparisonMode($this->comparisonMode);
        $this->channel = $this->normalizeChannel($this->channel);
        $this->customStartDate = $this->normalizeDateString($this->customStartDate);
        $this->customEndDate = $this->normalizeDateString($this->customEndDate);

        if ($this->preset !== 'custom') {
            $this->customStartDate = null;
            $this->customEndDate = null;
        }

        $this->persist();
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
        if (! collect($this->layout)->contains(fn ($item) => ($item['id'] ?? null) === $id)) {
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
        $this->showLibrary = ! $this->showLibrary;
    }

    protected function persist(): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $prefs = is_array($user->ui_preferences) ? $user->ui_preferences : [];
        $prefs['analytics_layout'] = $this->layout;
        $prefs['analytics_filters'] = [
            'time_mode' => $this->timeMode,
            'preset' => $this->preset,
            'custom_start_date' => $this->customStartDate,
            'custom_end_date' => $this->customEndDate,
            'comparison_mode' => $this->comparisonMode,
            'channel' => $this->channel,
        ];

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
                } elseif (is_string($item)) {
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
            if (! collect($merged)->contains(fn ($item) => ($item['id'] ?? null) === $id)) {
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
                if (! $base) {
                    return null;
                }

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

    /**
     * @return array<int,array<string,string>>
     */
    public function getTimeModeOptionsProperty(): array
    {
        return app(AnalyticsTimeframeService::class)->modeOptions();
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function getPresetOptionsProperty(): array
    {
        return app(AnalyticsTimeframeService::class)->presetOptions();
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function getComparisonOptionsProperty(): array
    {
        return app(AnalyticsTimeframeService::class)->comparisonOptions();
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function getChannelOptionsProperty(): array
    {
        return [
            ['value' => 'all', 'label' => 'All channels'],
            ['value' => 'retail', 'label' => 'Retail'],
            ['value' => 'wholesale', 'label' => 'Wholesale'],
            ['value' => 'event', 'label' => 'Markets'],
        ];
    }

    public function getAnalyticsDataProperty(): array
    {
        $channelFilter = $this->channel === 'all' ? null : $this->channel;

        $timeframe = app(AnalyticsTimeframeService::class)->resolve([
            'time_mode' => $this->timeMode,
            'preset' => $this->preset,
            'custom_start_date' => $this->customStartDate,
            'custom_end_date' => $this->customEndDate,
            'comparison_mode' => $this->comparisonMode,
        ]);

        $demand = app(DemandReportingService::class);
        $inventoryReporting = app(InventoryReportingService::class);
        $scentAnalytics = app(ScentAnalyticsService::class);
        $inventory = app(InventoryService::class);

        $forecastBundle = $demand->scentDemandWithComparison('forecast', $timeframe, $channelFilter);
        $currentBundle = $demand->scentDemandWithComparison('current', $timeframe, $channelFilter);
        $actualBundle = $demand->scentDemandWithComparison('actual', $timeframe, $channelFilter);

        $oilForecastBundle = $demand->explodedOilDemandWithComparison('forecast', $timeframe, $channelFilter);
        $reorderRiskBundle = $inventoryReporting->reorderRiskWithComparison($timeframe, 'current', $channelFilter);
        $exceptions = $scentAnalytics->unmappedExceptionSummary(8, $channelFilter);

        $oilInventoryRows = $inventory->oilRows(limit: 500)->all();
        $waxInventoryRows = $inventory->waxRows(limit: 50)->all();

        $inventorySnapshot = [
            'oil_total_items' => count($oilInventoryRows),
            'oil_low_count' => collect($oilInventoryRows)->filter(fn (array $row) => data_get($row, 'state.status') === 'low')->count(),
            'oil_reorder_count' => collect($oilInventoryRows)->filter(fn (array $row) => data_get($row, 'state.status') === 'reorder')->count(),
            'wax_total_items' => count($waxInventoryRows),
            'wax_low_count' => collect($waxInventoryRows)->filter(fn (array $row) => data_get($row, 'state.status') === 'low')->count(),
            'wax_reorder_count' => collect($waxInventoryRows)->filter(fn (array $row) => data_get($row, 'state.status') === 'reorder')->count(),
            'wax_on_hand_grams' => round((float) collect($waxInventoryRows)->sum('on_hand_grams'), 2),
            'wax_on_hand_boxes' => round((float) collect($waxInventoryRows)->sum('on_hand_boxes'), 3),
        ];

        return [
            'filters' => [
                'time_mode' => $this->timeMode,
                'preset' => $this->preset,
                'custom_start_date' => $this->customStartDate,
                'custom_end_date' => $this->customEndDate,
                'comparison_mode' => $this->comparisonMode,
                'channel' => $this->channel,
            ],
            'timeframe' => $timeframe,
            'urls' => [
                'mapping_exceptions' => route('admin.scent-intake'),
                'inventory' => route('inventory.index'),
            ],
            'forecast' => [
                'bundle' => $forecastBundle,
                'top_scents' => $this->topScentRows($forecastBundle),
            ],
            'current' => [
                'bundle' => $currentBundle,
                'top_scents' => $this->topScentRows($currentBundle),
            ],
            'actual' => [
                'bundle' => $actualBundle,
                'top_scents' => $this->topScentRows($actualBundle),
            ],
            'state_totals' => [
                $this->stateOverviewRow('forecast', $forecastBundle),
                $this->stateOverviewRow('current', $currentBundle),
                $this->stateOverviewRow('actual', $actualBundle),
            ],
            'top_oils_forecast' => collect(data_get($oilForecastBundle, 'primary.rows', []))->take(10)->values()->all(),
            'top_oils_forecast_bundle' => $oilForecastBundle,
            'reorder_risk_bundle' => $reorderRiskBundle,
            'exceptions' => $exceptions,
            'inventory_snapshot' => $inventorySnapshot,
        ];
    }

    public function render()
    {
        return view('livewire.analytics.widgets');
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

    private function normalizeTimeMode(mixed $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return in_array($mode, ['rolling', 'fixed'], true) ? $mode : 'rolling';
    }

    private function normalizePreset(mixed $preset): string
    {
        $preset = strtolower(trim((string) $preset));

        $allowed = collect($this->presetOptions)->pluck('value')->all();

        return in_array($preset, $allowed, true) ? $preset : 'last_30_days';
    }

    private function normalizeComparisonMode(mixed $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        $allowed = collect($this->comparisonOptions)->pluck('value')->all();

        return in_array($mode, $allowed, true) ? $mode : 'none';
    }

    private function normalizeChannel(mixed $channel): string
    {
        $channel = strtolower(trim((string) $channel));

        return in_array($channel, ['all', 'retail', 'wholesale', 'event'], true) ? $channel : 'all';
    }

    private function normalizeDateString(mixed $date): ?string
    {
        $value = trim((string) $date);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    /**
     * @param  array<string,mixed>  $bundle
     * @return array<int,array<string,mixed>>
     */
    private function topScentRows(array $bundle, int $limit = 8): array
    {
        return collect(data_get($bundle, 'primary.rows', []))
            ->sortByDesc('units')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $bundle
     * @return array<string,mixed>
     */
    private function stateOverviewRow(string $state, array $bundle): array
    {
        return [
            'state' => $state,
            'units' => (int) data_get($bundle, 'primary.totals.units', 0),
            'wax_grams' => round((float) data_get($bundle, 'primary.totals.wax_grams', 0), 2),
            'oil_grams' => round((float) data_get($bundle, 'primary.totals.oil_grams', 0), 2),
            'row_count' => (int) data_get($bundle, 'primary.totals.row_count', 0),
            'delta' => data_get($bundle, 'delta.metrics.units'),
        ];
    }
}
