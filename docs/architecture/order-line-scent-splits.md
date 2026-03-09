# Order Line Scent Splits (Production Breakdown Layer)

Date: 2026-03-08

## Why this exists
Imported order lines represent commercial truth (what customer bought). Some custom lines require multiple production scents. We preserve imported lines and store an internal production split for downstream work.

## Data model
Table: `order_line_scent_splits`

Fields:
- `order_line_id` (required)
- `mapping_exception_id` (nullable)
- `scent_id` (nullable)
- `raw_scent_name` (nullable)
- `quantity` (required)
- `allocation_type` (`manual_split` default)
- `notes` (nullable)
- `created_by` (nullable)
- timestamps

Relationships:
- `OrderLine -> scentSplits()`
- `MappingException -> scentSplits()`
- `OrderLineScentSplit -> scent()`

## Workflow
Surface: `Admin > Scent Intake > Resolve Mapping` (`ProgressiveMapper` modal).

- Default path remains single-scent mapping.
- Edge-case toggle: **Split this line into multiple scents**.
- Split rows support scent selection, quantity, optional notes.
- Validation: split quantity total must equal imported line quantity.
- Save writes split rows and resolves exception(s) without fabricating imported lines.

## Downstream precedence
- If `order_line_scent_splits` exist for a line, demand/production uses split rows.
- If no splits exist, legacy single-scent line behavior remains.

Implemented consumers:
- `DemandReportingService` (forecast/current demand rows)
- `PourBatchCalculator` (material totals)
- `Pouring Queue` scent grouping and line preview

## Product-form behavior
Split save preserves product-form-aware size/wick behavior already used by mapping:
- room spray -> room-spray size, clears wick
- wax melt -> wax-melt size, clears wick
- candle/default -> existing behavior

## Backfill/migration considerations
No destructive backfill is required.
- Existing rows without split records continue to work unchanged.
- Splits can be created only where exceptions require manual production breakdown.

## Known follow-ups
1. Add optional helper parsing from note text into prefilled split rows.
2. Improve scent-mode selection behavior in pouring queue for lines with multiple split scents (per-scent selective picking).
3. Add targeted UI badges where split-mapped lines appear outside intake.
