# Reporting Query Foundation (Block 8)

Date: 2026-03-08  
Status: Implemented (service/query foundation)

## Purpose
Block 8 establishes reusable reporting/query contracts for widget and analytics surfaces without coupling logic to UI components.

The services separate demand into three distinct states:
- `forecast`: future demand not yet published to pouring
- `current`: open/committed demand currently in queue
- `actual`: completed usage from pour batch records

This prevents forecast, open queue, and completed usage from being blended into a single misleading metric.

## Source-of-Truth Precedence
For scent-driven material math:
1. `scent.current_scent_recipe_id` / active scent recipe components
2. Formula flattening via `FlattenFormulaService` (through `ScentRecipeService`)
3. Legacy scent fields only when explicitly allowed by flattening fallback paths

Operational rows used by state:
- Forecast/Current: `orders` + `order_lines`
- Actual: `pour_batches` + `pour_batch_lines`
- Unmapped work blockers: `mapping_exceptions` (unresolved, non-excluded)
- Inventory risk inputs: `base_oils`, `wax_inventories`

## Service Contracts

## `App\Services\Reporting\DemandReportingService`
Primary methods:
- `forecastedScentDemand(int $weeks = 4, ?string $channel = null): array`
- `currentScentDemand(int $weeks = 4, ?string $channel = null): array`
- `actualScentDemand(int $weeks = 4, ?string $channel = null): array`
- `scentDemandByState(string $state, int $weeks = 4, ?string $channel = null): array`
- `explodedOilDemand(string $state, int $weeks = 4, ?string $channel = null): array`
- `waxDemand(string $state, int $weeks = 4, ?string $channel = null): array`
- `blendTemplateDemand(string $state, int $weeks = 4, ?string $channel = null): array`

Common output envelope:
```php
[
  'state' => 'forecast|current|actual',
  'window' => ['weeks' => 4, 'from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD'],
  'channel' => 'retail|wholesale|event|all',
  'rows' => [...],
  'totals' => [...],
]
```

Exploded oil rows:
```php
[
  'base_oil_id' => 12,
  'base_oil_name' => 'Patchouli',
  'grams' => 1834.5,
  'percent_of_total' => 23.18,
  'state' => 'current',
  'channel' => 'all',
]
```

## `App\Services\Reporting\ScentAnalyticsService`
Primary method:
- `unmappedExceptionSummary(int $limit = 10, ?string $channel = null): array`

Output:
```php
[
  'open_count' => 42,
  'channel_filter' => null,
  'by_store' => [['store_key' => 'wholesale-main', 'channel' => 'wholesale', 'open_count' => 18]],
  'by_channel' => [['channel' => 'wholesale', 'open_count' => 18]],
  'top_raw_names' => [['raw_name' => 'Custom Scent', 'open_count' => 9]],
]
```

## `App\Services\Reporting\InventoryReportingService`
Primary method:
- `reorderRiskInputs(string $state = 'current', int $weeks = 4, ?string $channel = null): array`

Output:
```php
[
  'state' => 'current',
  'window' => [...],
  'channel' => 'all',
  'oil' => [
    'rows' => [
      [
        'base_oil_id' => 12,
        'demand_grams' => 450,
        'on_hand_grams' => 320,
        'projected_on_hand_grams' => 0,
        'state_after_demand' => ['status' => 'reorder', ...],
        'risk_level' => 'reorder',
      ],
    ],
    'summary' => ['ok_count' => 3, 'low_count' => 2, 'reorder_count' => 1, 'row_count' => 6],
    'demand_totals' => [...],
  ],
  'wax' => [
    'rows' => [...],
    'summary' => [...],
    'demand_totals' => ['wax_grams' => 12345.67],
  ],
]
```

## Current Limitations (Intentional)
- Blend-template demand is a first-pass seam based on top-level scent recipe components. It is suitable for queue sizing but not yet a full recursive attribution report.
- Forecast/current windowing is due-date based and intentionally simple for now.
- Channel inference for exception summaries is derived from `store_key` naming conventions.

## What Block 9 Can Build Directly
Block 9 can consume these contracts for widget assembly without re-implementing query math:
- top scents by forecast/current/actual
- top oils by exploded demand
- oil forecast (2/4/8 weeks)
- open queue material demand
- reorder risk list (oil + wax)
- unmapped exception cards and drill-down entry points

## Test Coverage Added
- Forecast/current/actual separation
- Exploded oil demand correctness from recipe truth
- Blend-template demand seam output
- Wax demand output
- Unmapped exception summary filtering
- Reorder-risk query support (inventory + demand)
