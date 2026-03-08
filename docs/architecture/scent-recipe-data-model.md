# Scent Recipe Data Model Foundation (Block 5)

Date: 2026-03-07

## Strategy Chosen
We used a **hybrid compatibility strategy**:

1. Introduce explicit relational recipe truth tables:
- `scent_recipes`
- `scent_recipe_components`

2. Reuse existing blend storage as blend-template foundation (no destructive rename):
- existing `blends` table acts as `blend_templates`
- existing `blend_components` table extended for typed nested components

This avoids breaking active production flows that already depend on `Blend` while creating a clean seam for versioned scent recipe truth.

## Why This Strategy
- Existing app already relies heavily on `Blend` and `blend_components` across admin/UI/import flows.
- Hard replacement would introduce high risk and large migration blast radius.
- The chosen approach adds normalized recipe truth now, while keeping compatibility bridges in place.

## New / Updated Structure

## Scent identity
- `scents` remains customer-facing identity.
- Added:
  - `scents.lifecycle_status` (status intent)
  - `scents.current_scent_recipe_id` (pointer to active recipe truth)

## Recipe truth
- `scent_recipes`
  - `scent_id`, `version`, `status`, `is_active`, metadata
  - unique per `(scent_id, version)`
  - indexed for active recipe lookup

- `scent_recipe_components`
  - belongs to `scent_recipe`
  - typed components:
    - `component_type = oil` with `base_oil_id`
    - `component_type = blend_template` with `blend_template_id`
  - supports both `parts` and `percentage`

## Blend templates
- `blends` extended with lifecycle fields (`lifecycle_status`, `is_active`)
- `blend_components` extended with typed nested-component support:
  - `component_type`
  - `blend_template_id` (nullable self-reference)
  - `percentage`, `sort_order`

This enables nested template graph storage now without forcing immediate UI/editor rewrite.

## Backfill / Compatibility Bridge
Migration includes a safe backfill:
- For existing scents without active recipe:
  - create version `1` recipe row
  - mark active
  - set `scents.current_scent_recipe_id`
  - bridge from legacy fields:
    - `oil_blend_id` -> `blend_template` component
    - otherwise `oil_reference_name` exact match -> `oil` component

This gives current scents a stable active-recipe pointer without requiring full manual migration.

## Service-Layer Integration
`CreateScentAction` and `UpdateScentAction` now call:
- `App\Services\ScentGovernance\ScentRecipeService`

`ScentRecipeService`:
- creates/versions active recipes
- keeps one active recipe at a time per scent in application logic
- uses explicit components when provided
- otherwise bridges from current legacy scent blend/oil fields

## New Models
- `App\Models\ScentRecipe`
- `App\Models\ScentRecipeComponent`
- `App\Models\BlendTemplate` (table alias of `blends`)
- `App\Models\BlendTemplateComponent` (table alias of `blend_components`)

Updated models:
- `Scent`
- `Blend`
- `BlendComponent`
- `BaseOil`

## What Is Live After Block 5
- Scent rows can now point to an explicit active recipe row.
- Recipe versions are persisted relationally.
- Recipe components are typed and ready for flattening.
- Blend templates can represent nested template references in schema.

## Compatibility Shims Still Present
- Legacy scent fields (`oil_blend_id`, `oil_reference_name`, `recipe_components_json`) remain for compatibility.
- Existing `Blend`/`BlendComponent` CRUD still works and now also serves as blend-template maintenance.
- No full recipe authoring UI rewrite yet.

## Block 6 Ready Scope
Block 6 can now safely implement flattening math on top of relational truth:
- recursive expansion from `scent_recipe_components`
- recursive expansion from `blend_components` typed rows
- cycle detection + deterministic flatten output
- usage-ready aggregate oil totals from active recipe graph

