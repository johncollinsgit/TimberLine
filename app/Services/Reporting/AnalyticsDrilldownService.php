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
        return [
            'widget' => 'unmapped_exceptions',
            'title' => 'Unmapped Exceptions Detail',
            'state' => 'current',
            'labels' => $this->labelsFromTimeframe($timeframe),
            'summary' => $this->scentAnalytics->unmappedExceptionSummary(limit: 20, channel: $channel),
            'details' => $this->scentAnalytics->unmappedExceptionDetails(limit: 200, channel: $channel),
            'trend' => $this->scentAnalytics->unmappedExceptionTrend($timeframe, 8, $channel),
            'actions' => [
                [
                    'label' => 'Open Scent Intake',
                    'url' => route('admin.scent-intake'),
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
        return [
            'widget' => 'oil_reorder_risk',
            'title' => 'Current Oil Reorder Risk Detail',
            'state' => 'current',
            'labels' => $this->labelsFromTimeframe($timeframe),
            'bundle' => $this->inventoryReporting->reorderRiskWithComparison($timeframe, 'current', $channel),
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
                    'url' => route('inventory.index'),
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
        return [
            'widget' => 'wax_reorder_risk',
            'title' => 'Wax Reorder Risk Detail',
            'state' => 'current',
            'labels' => $this->labelsFromTimeframe($timeframe),
            'bundle' => $this->inventoryReporting->reorderRiskWithComparison($timeframe, 'current', $channel),
            'trend' => $this->demandReporting->trendSeries('current', $timeframe, $channel, 'wax_grams', 8),
            'actions' => [
                [
                    'label' => 'Open Inventory',
                    'url' => route('inventory.index'),
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
        return [
            'widget' => 'top_scents_'.$state,
            'title' => 'Top Scents ('.ucfirst($state).')',
            'state' => $state,
            'labels' => $this->labelsFromTimeframe($timeframe),
            'bundle' => $this->demandReporting->scentDemandWithComparison($state, $timeframe, $channel),
            'trend' => $this->demandReporting->trendSeries($state, $timeframe, $channel, 'units', 8),
            'actions' => [
                [
                    'label' => 'Open Master Data',
                    'url' => route('admin.index', ['tab' => 'master-data', 'resource' => 'scents']),
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
        return [
            'widget' => 'top_oils_forecast',
            'title' => 'Top Oils by Forecast Demand Detail',
            'state' => 'forecast',
            'labels' => $this->labelsFromTimeframe($timeframe),
            'bundle' => $this->demandReporting->explodedOilDemandWithComparison('forecast', $timeframe, $channel),
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
                    'url' => route('inventory.index'),
                ],
                [
                    'label' => 'Open Wholesale Custom',
                    'url' => route('admin.index', ['tab' => 'wholesale-custom']),
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
        $forecast = $this->demandReporting->scentDemandWithComparison('forecast', $timeframe, $channel);
        $current = $this->demandReporting->scentDemandWithComparison('current', $timeframe, $channel);
        $actual = $this->demandReporting->scentDemandWithComparison('actual', $timeframe, $channel);

        return [
            'widget' => 'demand_state_overview',
            'title' => 'Demand State Overview Detail',
            'state' => 'mixed',
            'labels' => $this->labelsFromTimeframe($timeframe),
            'states' => compact('forecast', 'current', 'actual'),
            'trend' => [
                'forecast' => $this->demandReporting->trendSeries('forecast', $timeframe, $channel, 'units', 8),
                'current' => $this->demandReporting->trendSeries('current', $timeframe, $channel, 'units', 8),
                'actual' => $this->demandReporting->trendSeries('actual', $timeframe, $channel, 'units', 8),
            ],
            'actions' => [
                [
                    'label' => 'Open Pouring Queue',
                    'url' => route('pouring.queue'),
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
}
