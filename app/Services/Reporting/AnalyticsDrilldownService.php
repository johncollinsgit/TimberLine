<?php

namespace App\Services\Reporting;

class AnalyticsDrilldownService
{
    public function __construct(
        protected DemandReportingService $demandReporting,
        protected InventoryReportingService $inventoryReporting,
        protected ScentAnalyticsService $scentAnalytics,
    ) {}

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    public function build(
        string $widgetId,
        array $timeframe,
        ?string $channel = null,
        ?string $state = null,
        ?int $focusId = null
    ): array {
        return match ($widgetId) {
            'unmapped_exceptions' => $this->unmappedExceptionsDetail($timeframe, $channel),
            'oil_reorder_risk' => $this->oilReorderRiskDetail($timeframe, $channel, $focusId),
            'wax_reorder_risk' => $this->waxReorderRiskDetail($timeframe, $channel),
            'top_scents_forecast' => $this->topScentsDetail('forecast', $timeframe, $channel),
            'top_scents_current' => $this->topScentsDetail('current', $timeframe, $channel),
            'top_scents_actual' => $this->topScentsDetail('actual', $timeframe, $channel),
            'top_oils_forecast' => $this->topOilsDetail($timeframe, $channel, $focusId),
            'demand_state_overview' => $this->demandOverviewDetail($timeframe, $channel),
            default => $this->genericFallbackDetail($widgetId, $state, $timeframe),
        };
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    protected function unmappedExceptionsDetail(array $timeframe, ?string $channel): array
    {
        $context = $this->analyticsContextParams($timeframe, $channel, 'unmapped_exceptions', 'current');
        $summary = $this->scentAnalytics->unmappedExceptionSummary(limit: 20, channel: $channel);
        $details = $this->scentAnalytics->unmappedExceptionDetails(limit: 200, channel: $channel);
        $details['rows'] = collect($details['rows'] ?? [])->map(function (array $row) use ($context): array {
            $search = trim(implode(' ', array_filter([
                $row['raw_name'] ?? null,
                $row['account_name'] ?? null,
                $row['store_key'] ?? null,
            ])));

            $channel = (string) ($row['channel'] ?? 'all');

            $row['handoff_url'] = route('admin.scent-intake', array_filter(array_merge($context, [
                'filter' => in_array($channel, ['retail', 'wholesale'], true) ? $channel : 'all',
                'raw' => (string) ($row['raw_name'] ?? ''),
                'store' => (string) ($row['store_key'] ?? ''),
                'account' => (string) ($row['account_name'] ?? ''),
                'search' => $search,
            ]), fn ($value) => $value !== null && $value !== ''));

            return $row;
        })->all();

        return [
            'widget' => 'unmapped_exceptions',
            'title' => 'Unmapped Exceptions Detail',
            'state' => 'current',
            'labels' => $this->labelsFromTimeframe($timeframe),
            'summary' => $summary,
            'details' => $details,
            'trend' => $this->scentAnalytics->unmappedExceptionTrend($timeframe, 8, $channel),
            'actions' => [
                [
                    'label' => 'Open Scent Intake',
                    'url' => route('admin.scent-intake', $context),
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    protected function oilReorderRiskDetail(array $timeframe, ?string $channel, ?int $focusOilId): array
    {
        $context = $this->analyticsContextParams($timeframe, $channel, 'oil_reorder_risk', 'current');
        $bundle = $this->inventoryReporting->reorderRiskWithComparison($timeframe, 'current', $channel);
        $oilRows = collect(data_get($bundle, 'primary.oil.rows', []))
            ->map(function (array $row) use ($context): array {
                $row['handoff_url'] = route('inventory.index', array_filter(array_merge($context, [
                    'oil' => (string) ($row['base_oil_id'] ?? ''),
                    'materialSearch' => (string) ($row['name'] ?? ''),
                    'source_widget' => 'oil_reorder_risk',
                ]), fn ($value) => $value !== null && $value !== ''));

                return $row;
            })
            ->all();

        data_set($bundle, 'primary.oil.rows', $oilRows);

        return [
            'widget' => 'oil_reorder_risk',
            'title' => 'Current Oil Reorder Risk Detail',
            'state' => 'current',
            'labels' => $this->labelsFromTimeframe($timeframe),
            'bundle' => $bundle,
            'contributors' => $this->demandReporting->oilContributorsWithComparison(
                'current',
                $timeframe,
                $channel,
                $focusOilId,
                3
            ),
            'trend' => $this->demandReporting->trendSeries('current', $timeframe, $channel, 'oil_grams', 8),
            'actions' => [
                [
                    'label' => 'Open Inventory',
                    'url' => route('inventory.index', $context),
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    protected function waxReorderRiskDetail(array $timeframe, ?string $channel): array
    {
        $context = $this->analyticsContextParams($timeframe, $channel, 'wax_reorder_risk', 'current');
        $bundle = $this->inventoryReporting->reorderRiskWithComparison($timeframe, 'current', $channel);
        $waxRows = collect(data_get($bundle, 'primary.wax.rows', []))
            ->map(function (array $row) use ($context): array {
                $row['handoff_url'] = route('inventory.index', array_filter(array_merge($context, [
                    'materialSearch' => (string) ($row['name'] ?? 'Wax'),
                    'source_widget' => 'wax_reorder_risk',
                ]), fn ($value) => $value !== null && $value !== ''));

                return $row;
            })
            ->all();
        data_set($bundle, 'primary.wax.rows', $waxRows);

        return [
            'widget' => 'wax_reorder_risk',
            'title' => 'Wax Reorder Risk Detail',
            'state' => 'current',
            'labels' => $this->labelsFromTimeframe($timeframe),
            'bundle' => $bundle,
            'trend' => $this->demandReporting->trendSeries('current', $timeframe, $channel, 'wax_grams', 8),
            'actions' => [
                [
                    'label' => 'Open Inventory',
                    'url' => route('inventory.index', $context),
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    protected function topScentsDetail(string $state, array $timeframe, ?string $channel): array
    {
        $context = $this->analyticsContextParams($timeframe, $channel, 'top_scents_'.$state, $state);
        $bundle = $this->demandReporting->scentDemandWithComparison($state, $timeframe, $channel);
        $rows = collect(data_get($bundle, 'primary.rows', []))
            ->map(function (array $row) use ($context): array {
                $scentName = (string) ($row['scent_name'] ?? '');
                $row['handoff_url'] = route('admin.catalog.scents', array_filter(array_merge($context, [
                    'scent' => $scentName,
                    'search' => $scentName,
                    'source_widget' => 'top_scents',
                ]), fn ($value) => $value !== null && $value !== ''));

                return $row;
            })
            ->all();
        data_set($bundle, 'primary.rows', $rows);

        return [
            'widget' => 'top_scents_'.$state,
            'title' => 'Top Scents ('.ucfirst($state).')',
            'state' => $state,
            'labels' => $this->labelsFromTimeframe($timeframe),
            'bundle' => $bundle,
            'trend' => $this->demandReporting->trendSeries($state, $timeframe, $channel, 'units', 8),
            'actions' => [
                [
                    'label' => 'Open Catalog (Prefiltered)',
                    'url' => route('admin.catalog.scents', $context),
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    protected function topOilsDetail(array $timeframe, ?string $channel, ?int $focusOilId): array
    {
        $context = $this->analyticsContextParams($timeframe, $channel, 'top_oils_forecast', 'forecast');
        $bundle = $this->demandReporting->explodedOilDemandWithComparison('forecast', $timeframe, $channel);
        $bundleRows = collect(data_get($bundle, 'primary.rows', []))
            ->map(function (array $row) use ($context): array {
                $row['handoff_url'] = route('inventory.index', array_filter(array_merge($context, [
                    'oil' => (string) ($row['base_oil_id'] ?? ''),
                    'materialSearch' => (string) ($row['base_oil_name'] ?? ''),
                    'source_widget' => 'top_oils_forecast',
                ]), fn ($value) => $value !== null && $value !== ''));

                return $row;
            })
            ->all();
        data_set($bundle, 'primary.rows', $bundleRows);

        return [
            'widget' => 'top_oils_forecast',
            'title' => 'Top Oils by Forecast Demand Detail',
            'state' => 'forecast',
            'labels' => $this->labelsFromTimeframe($timeframe),
            'bundle' => $bundle,
            'contributors' => $this->demandReporting->oilContributorsWithComparison(
                'forecast',
                $timeframe,
                $channel,
                $focusOilId,
                5
            ),
            'trend' => $this->demandReporting->trendSeries('forecast', $timeframe, $channel, 'oil_grams', 8),
            'actions' => [
                [
                    'label' => 'Open Inventory',
                    'url' => route('inventory.index', $context),
                ],
                [
                    'label' => 'Open Wholesale Custom',
                    'url' => route('admin.index', array_merge($context, ['tab' => 'wholesale-custom'])),
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    protected function demandOverviewDetail(array $timeframe, ?string $channel): array
    {
        $context = $this->analyticsContextParams($timeframe, $channel, 'demand_state_overview', 'mixed');
        $forecast = $this->demandReporting->scentDemandWithComparison('forecast', $timeframe, $channel);
        $current = $this->demandReporting->scentDemandWithComparison('current', $timeframe, $channel);
        $actual = $this->demandReporting->scentDemandWithComparison('actual', $timeframe, $channel);

        $stateHandoffs = [
            'forecast' => $this->forecastQueueHandoff($channel, $context),
            'current' => $this->queueHandoff($channel, 'current', $context),
            'actual' => $this->queueHandoff($channel, 'actual', $context),
        ];

        return [
            'widget' => 'demand_state_overview',
            'title' => 'Demand State Overview Detail',
            'state' => 'mixed',
            'labels' => $this->labelsFromTimeframe($timeframe),
            'states' => compact('forecast', 'current', 'actual'),
            'state_handoffs' => $stateHandoffs,
            'trend' => [
                'forecast' => $this->demandReporting->trendSeries('forecast', $timeframe, $channel, 'units', 8),
                'current' => $this->demandReporting->trendSeries('current', $timeframe, $channel, 'units', 8),
                'actual' => $this->demandReporting->trendSeries('actual', $timeframe, $channel, 'units', 8),
            ],
            'actions' => [
                [
                    'label' => 'Open Pouring Queue',
                    'url' => $this->queueHandoff($channel, 'current', $context),
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    protected function genericFallbackDetail(string $widgetId, ?string $state, array $timeframe): array
    {
        return [
            'widget' => $widgetId,
            'title' => 'Widget Detail',
            'state' => $state ?: 'current',
            'labels' => $this->labelsFromTimeframe($timeframe),
            'actions' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,mixed>
     */
    protected function labelsFromTimeframe(array $timeframe): array
    {
        return [
            'primary' => (string) data_get($timeframe, 'labels.primary', ''),
            'comparison' => data_get($timeframe, 'labels.comparison'),
        ];
    }

    /**
     * @param  array<string,mixed>  $timeframe
     * @return array<string,string>
     */
    protected function analyticsContextParams(array $timeframe, ?string $channel, string $widget, string $state): array
    {
        return array_filter([
            'analytics_mode' => (string) data_get($timeframe, 'time_mode', 'rolling'),
            'analytics_preset' => (string) data_get($timeframe, 'preset', 'last_30_days'),
            'analytics_compare' => (string) data_get($timeframe, 'comparison_mode', 'none'),
            'analytics_start' => (string) data_get($timeframe, 'primary.from_date', ''),
            'analytics_end' => (string) data_get($timeframe, 'primary.to_date', ''),
            'channel' => $channel ?: 'all',
            'analytics_state' => $state,
            'source_widget' => $widget,
            'return_to' => route('analytics.index'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string,string>  $context
     */
    protected function queueHandoff(?string $channel, string $state, array $context): string
    {
        $channelKey = $this->normalizeChannel($channel);

        if ($channelKey === 'all') {
            return route('pouring.all-candles', array_merge($context, [
                'channel' => 'all',
                'state' => $state,
            ]));
        }

        return route('pouring.stack', ['channel' => $channelKey]).'?'.http_build_query(array_merge($context, [
            'state' => $state,
        ]));
    }

    /**
     * @param  array<string,string>  $context
     */
    protected function forecastQueueHandoff(?string $channel, array $context): string
    {
        $queue = match ($this->normalizeChannel($channel)) {
            'wholesale' => 'wholesale',
            'event' => 'markets',
            default => 'retail',
        };

        return route('retail.plan', array_merge($context, ['queue' => $queue]));
    }

    protected function normalizeChannel(?string $channel): string
    {
        $normalized = strtolower(trim((string) $channel));
        if (! in_array($normalized, ['retail', 'wholesale', 'event'], true)) {
            return 'all';
        }

        return $normalized;
    }
}
