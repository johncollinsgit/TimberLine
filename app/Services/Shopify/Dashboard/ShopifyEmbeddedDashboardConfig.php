<?php

namespace App\Services\Shopify\Dashboard;

class ShopifyEmbeddedDashboardConfig
{
    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return [
            'defaultTimeframe' => 'last_30_days',
            'defaultComparison' => 'previous_period',
            'chartDefaultMetric' => 'rewards_sales',
            'locationGroupingPreference' => 'state',
            'timeframeOptions' => [
                ['label' => 'Today', 'value' => 'today'],
                ['label' => 'Yesterday', 'value' => 'yesterday'],
                ['label' => 'Last 7 days', 'value' => 'last_7_days'],
                ['label' => 'Last 30 days', 'value' => 'last_30_days'],
                ['label' => 'Month to date', 'value' => 'month_to_date'],
                ['label' => 'Quarter to date', 'value' => 'quarter_to_date'],
                ['label' => 'Year to date', 'value' => 'year_to_date'],
                ['label' => 'Full year', 'value' => 'full_year'],
                ['label' => 'Custom range', 'value' => 'custom'],
            ],
            'comparisonOptions' => [
                ['label' => 'Previous period', 'value' => 'previous_period'],
                ['label' => 'Previous year', 'value' => 'previous_year'],
                ['label' => 'No comparison', 'value' => 'none'],
            ],
            'locationGroupingOptions' => [
                ['label' => 'Country', 'value' => 'country'],
                ['label' => 'State', 'value' => 'state'],
                ['label' => 'City', 'value' => 'city'],
            ],
            'visibleWidgets' => [
                'metricCards' => true,
                'performanceChart' => true,
                'locationOrigins' => true,
                'attribution' => true,
                'financialSummary' => true,
            ],
            'visibleAttributionSources' => [
                'text',
                'email',
                'instagram',
                'facebook',
                'google',
                'other',
                'direct',
                'unknown',
            ],
            'widgetRegistry' => [
                'metricCards' => ['title' => 'Top metrics'],
                'performanceChart' => ['title' => 'Performance chart'],
                'locationOrigins' => ['title' => 'Location origins'],
                'attribution' => ['title' => 'Attribution'],
                'financialSummary' => ['title' => 'Financial summary'],
            ],
        ];
    }
}
