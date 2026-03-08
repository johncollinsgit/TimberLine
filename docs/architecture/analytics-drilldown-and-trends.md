# Analytics Drilldown And Trends (Block 11)

Date: 2026-03-08

## Scope
Block 11 adds action-oriented drilldowns and trend visualization on top of the existing analytics widget + timeframe/comparison framework.

## Drilldown Pattern
- All priority widgets now use a shared drilldown interaction:
  - `View details` on widget card
  - opens a single right-side detail panel
  - panel always shows:
    - state context (`forecast` / `current` / `actual` when applicable)
    - primary timeframe label
    - comparison timeframe label (if enabled)
    - action links for next operational step

## Service Layer

### New
- `App\Services\Reporting\AnalyticsDrilldownService`
  - central orchestration for widget detail payloads
  - avoids query logic in Blade/UI

### Extended
- `DemandReportingService`
  - `explodedOilDemandByWindow(...)`
  - `trendSeries(...)`
  - `oilContributorsWithComparison(...)`
- `ScentAnalyticsService`
  - `unmappedExceptionDetails(...)`
  - `unmappedExceptionTrend(...)`

## Widgets With Drilldown

1. Unmapped Exceptions Summary
- unresolved row table
- channel/store context
- trend bars
- action: `Open Scent Intake`

2. Current Oil Reorder Risk
- oil demand/on-hand/projected/threshold/status rows
- top scent drivers per oil
- trend bars
- action: `Open Inventory`

3. Wax Reorder Risk
- wax risk cards/rows
- grams + 45lb box-equivalent display
- trend bars
- action: `Open Inventory`

4. Top Scents by Forecast / Current / Actual
- full scent detail table (not only top snippet)
- delta context preserved from comparison framework
- trend bars
- action: `Open Master Data`

5. Top Oils by Forecast Demand
- full oil detail table
- per-oil contributor breakdown by scent
- trend bars
- actions: `Open Inventory`, `Open Wholesale Custom`

6. Demand State Overview
- explicit forecast/current/actual detail rows
- per-state trend bars
- action: `Open Pouring Queue`

## Trend Visualization
- Block 11 uses compact trend-bar visualizations inside drilldowns.
- Trend data is generated from shared timeframe-aware query services.
- No widget-specific ad hoc date math in Blade.

## Tests Added
- `tests/Feature/Analytics/AnalyticsWidgetDrilldownTest.php`
  - drilldown entry rendering
  - detail content presence
  - state/timeframe labels in detail panel
  - action links for priority widgets
- `tests/Feature/Reporting/AnalyticsTimeComparisonFrameworkTest.php`
  - trend-series query coverage for demand + unmapped exceptions

## Remaining Gap After Block 11
- Drilldowns are operational and actionable, but deeper navigation (entity-level pages with pre-applied filters) is still limited.
- Next phase should focus on richer drill-through routing and larger trend granularity controls, not additional raw metric widgets.
