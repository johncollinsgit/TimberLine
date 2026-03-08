# Analytics Time + Comparison Framework (Block 10)

Date: 2026-03-08

## Scope
Block 10 adds a shared timeframe/comparison layer that all analytics widgets can consume consistently. It avoids widget-specific date math and keeps forecast/current/actual states explicitly separated.

## Shared Services

1. `AnalyticsTimeframeService`
- Resolves primary + comparison windows from UI filters.
- Supports fixed and rolling modes.
- Returns serialized date windows and human labels for widget display.

2. `AnalyticsComparisonService`
- Computes per-metric delta metadata between primary and comparison totals:
  - `primary`
  - `comparison`
  - `delta`
  - `delta_pct`
  - `trend` (`up`, `down`, `flat`)

3. Reporting integrations
- `DemandReportingService`
  - `scentDemandWithComparison()`
  - `explodedOilDemandWithComparison()`
  - `waxDemandWithComparison()`
- `InventoryReportingService`
  - `reorderRiskWithComparison()`

## Supported Time Modes
- `rolling`
- `fixed`

## Supported Presets
- `today`
- `yesterday`
- `last_7_days`
- `last_30_days`
- `last_90_days`
- `last_365_days`
- `last_12_months`
- `this_week`
- `this_month`
- `this_quarter`
- `this_year`
- `last_week`
- `last_month`
- `last_quarter`
- `last_year`
- `custom`

## Supported Comparison Modes
- `none`
- `previous_period`
- `previous_week`
- `previous_month`
- `previous_quarter`
- `previous_year`
- `same_period_last_year`
- `year_over_year`

## Widget Filter Persistence
Analytics filters are persisted to `users.ui_preferences.analytics_filters` with:
- `time_mode`
- `preset`
- `custom_start_date`
- `custom_end_date`
- `comparison_mode`
- `channel`

Widget layout continues to persist at `users.ui_preferences.analytics_layout`.

## Widget Integration (Phase 1)
The following widgets now read bundle outputs that include timeframe + comparison metadata:
- Top Scents by Forecast Demand
- Top Scents by Current/Open Demand
- Top Scents by Actual Usage
- Top Oils by Forecast Demand
- Current Oil Reorder Risk
- Wax Reorder Risk
- Demand State Overview
- Unmapped Exceptions Summary
- Inventory Snapshot

## Output Contract (Bundle Pattern)
Reporting bundles now follow:
- `timeframe` (primary/comparison windows + labels)
- `primary` (rows + totals)
- `comparison` (rows + totals, nullable)
- `delta` (metric deltas from `AnalyticsComparisonService`)

## Behavior Notes
- Forecast/current/actual states remain separate; comparisons happen within each state.
- Comparison windows are deterministic and generated once centrally.
- Widget templates only display deltas; they do not calculate deltas.

## Next Block Guidance
Block 11 should focus on drilldown flows and richer trend presentation built on this shared bundle contract, not new date math in UI components.
