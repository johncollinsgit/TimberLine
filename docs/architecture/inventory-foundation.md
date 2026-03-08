# Inventory Foundation (Block 7)

Date: 2026-03-07

## Goal
Provide deterministic inventory truth for oils + wax with signed adjustment history and reusable reorder evaluation logic.

## Canonical Storage
- **Oil on-hand grams**: `base_oils.grams_on_hand`
- **Oil reorder threshold grams**: `base_oils.reorder_threshold`
- **Wax on-hand grams**: `wax_inventories.on_hand_grams`
- **Wax reorder threshold grams**: `wax_inventories.reorder_threshold_grams`

Grams are canonical truth.

## Inventory Structure Chosen
Hybrid, production-safe:
1. Reuse existing `base_oils` stock fields.
2. Add `wax_inventories` for wax stock + threshold.
3. Add shared `inventory_adjustments` ledger for both oils and wax.

This avoided risky rewrites while creating one adjustment/audit trail model.

## Adjustment Ledger
Table: `inventory_adjustments`

Tracks:
- `item_type` (`oil` | `wax`)
- linked record (`base_oil_id` or `wax_inventory_id`)
- signed `grams_delta`
- `before_grams` and `after_grams`
- `reason`, `notes`
- `performed_by`
- optional `source_type` / `source_id`
- timestamps

Reasons currently supported:
- `spill`
- `damage`
- `recount`
- `manual_correction`
- `received`
- `other`
- `consumed` (system usage, e.g., pouring consumption)

## Services / Actions

### `AdjustInventoryAction`
- Applies signed gram deltas or explicit set-on-hand operations.
- Updates canonical on-hand values.
- Writes ledger rows to `inventory_adjustments`.
- Rejects invalid reason codes.

### `InventoryService`
- Returns normalized oil/wax inventory rows for admin maintenance surfaces.
- Computes reorder state.
- Provides recent adjustment history.
- Provides a demand-evaluation seam (`evaluateDemandAgainstOilInventory`) for future reporting.

### `WaxConversionService`
- `grams <-> pounds`
- `grams <-> 45 lb box equivalents`
- default wax reorder threshold helper (360 lb = 8 boxes)

## Reorder Evaluation Contract
`InventoryService::evaluateReorderState(onHandGrams, thresholdGrams)` returns:
- `status` (`ok` | `low` | `reorder`)
- `label`
- `gap_grams`
- `ratio`

Current thresholds:
- `reorder` when on-hand <= 50% of threshold
- `low` when on-hand < threshold
- `ok` otherwise

## Admin Maintenance Surface
Updated `Livewire\Inventory\Index` (`/inventory`) now includes:
- Oil on-hand + threshold maintenance
- Wax on-hand + threshold maintenance
- Signed adjustments (+/- grams) with reason codes
- Recent adjustment ledger feed

Legacy “unclaimed candle inventory counts” section remains available and unchanged in purpose.

## Integration With Formula Flattening
This block does not build reporting dashboards.
It provides the service seam needed for Block 8:
- Flattened oil demand (Block 6) + inventory on-hand/thresholds can be combined via `InventoryService::evaluateDemandAgainstOilInventory`.

## Notes for Block 8
Build query/report layer using:
1. Flattened demand output as oil requirement truth.
2. Inventory on-hand + thresholds as stock truth.
3. Adjustment ledger as audit trail for manual + operational corrections.
