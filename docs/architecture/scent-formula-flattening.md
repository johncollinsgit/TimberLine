# Scent Formula Flattening (Block 6)

Date: 2026-03-07

## Purpose
`FlattenFormulaService` is the deterministic math layer that expands recipe truth into base-oil allocations.

This is the foundation for:
- oil-demand reporting
- forecast math
- consumption/inventory adjustments
- reorder logic (future blocks)

## Source of Truth Precedence
1. `scents.current_scent_recipe_id` (or active recipe row)
2. `scent_recipe_components` (`oil` + `blend_template` typed rows)
3. Legacy fallback (`oil_blend_id` / `oil_reference_name`) only when explicitly requested (`allowLegacyFallback=true`)

Legacy fields do **not** silently override an active recipe.

## Service + Entry Points
Primary class:
- `App\Services\Recipes\FlattenFormulaService`

Integration seam for governance layer:
- `App\Services\ScentGovernance\ScentRecipeService::flattenForScent()`
- `App\Services\ScentGovernance\ScentRecipeService::flattenForRecipe()`

## Supported Structures
The flattening engine supports:
- single oil
- multi-oil recipes
- blend template + oil
- blend template + blend template
- nested blend templates
- parts-based inputs
- percentage-based inputs
- equal split when neither parts nor percentages are defined

## Normalization Rules
At each component level:
1. If percentages are present:
   - those weights are honored first
   - remaining weight (up to 100) is distributed to parts rows
   - if no parts, remainder is distributed equally to unspecified rows
2. If no percentages but parts are present:
   - normalize by parts
   - unspecified rows get weight `1` for deterministic fallback
3. If neither percentages nor parts are present:
   - equal split across rows

Output percentages are normalized and deterministic.

## Output Contract
Every flatten call returns:

```php
[
  'source' => [
    'kind' => 'scent'|'scent_recipe'|'blend_template',
    // ids + metadata for that source
  ],
  'total_grams' => ?float,
  'percent_total' => float,
  'components' => [
    [
      'base_oil_id' => int,
      'base_oil_name' => string,
      'percentage' => float,
      'grams' => ?float,
    ],
  ],
  'by_oil_id' => [
    '123' => [same row shape as components item],
  ],
  'unresolved' => [
    [
      'reason' => string,
      'component_type' => string,
      'reference_id' => ?int,
      'path' => array<int,string>,
    ],
  ],
  'tree' => [
    // optional expansion/debug tree for nested formula inspection
  ],
]
```

## Cycle Handling
Nested template recursion uses hard-fail cycle detection:
- exception: `App\Services\Recipes\Exceptions\FormulaCycleDetectedException`
- thrown when a blend-template path loops back to a previously visited template

This is intentional: cycle errors should fail loudly, never produce silent bad oil math.

## Remaining Compatibility Notes
- Legacy fallback is still available for transition cases where scents do not yet have active recipe rows.
- Fallback must be explicitly opted into and is documented as transitional behavior.

## What Block 7 Should Build On
Block 7 (inventory/reorder foundation) should call `FlattenFormulaService` via `ScentRecipeService` and treat returned `components` as canonical oil allocation math.
