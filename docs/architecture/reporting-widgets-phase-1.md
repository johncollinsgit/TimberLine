# Reporting Widgets Phase 1 (Block 9)

Date: 2026-03-08

## Scope
Block 9 assembles widget UI on top of reporting/query services created in Block 8. It does not redesign backend reporting contracts.

## Page Location
- Route: `/analytics`
- View composition:
  - [resources/views/analytics/index.blade.php](/Users/johncollins/Code/myapp/resources/views/analytics/index.blade.php)
  - [app/Livewire/Analytics/AnalyticsWidgets.php](/Users/johncollins/Code/myapp/app/Livewire/Analytics/AnalyticsWidgets.php)
  - [resources/views/livewire/analytics/widgets.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/analytics/widgets.blade.php)

## Widgets Added
1. `Unmapped Exceptions Summary`
2. `Top Scents by Forecast Demand`
3. `Top Scents by Current/Open Demand`
4. `Top Scents by Actual Usage`
5. `Top Oils by Forecast Demand`
6. `Current Oil Reorder Risk`
7. `Wax Reorder Risk`
8. `Inventory Snapshot`
9. `Demand State Overview`

## Service Wiring
- `DemandReportingService`
  - Forecast/current/actual scent demand
  - Forecast exploded oil demand
  - Wax demand totals
- `InventoryReportingService`
  - Current reorder-risk inputs (oil + wax)
- `ScentAnalyticsService`
  - Unmapped exception summaries
- `InventoryService`
  - Inventory snapshot rollups

## State Separation
Widgets explicitly label and separate:
- `forecast`
- `current`
- `actual`

No widget blends those states into a single unlabeled metric.

## Filters and Persistence
Analytics widgets now support:
- Time mode and preset filters (expanded in Block 10)
- Channel filter: all/retail/wholesale/event

Persisted in user `ui_preferences` under:
- `analytics_layout`
- `analytics_filters`

## What Remains for Iteration
- Optional chart visualizations (current phase is table/card-first)
- Drill-down navigation from each widget row
- More advanced trend windows and variance deltas
- Alerting thresholds tuned per business policy
