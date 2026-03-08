# Scent Wizard Flow (Block 4)

Date: 2026-03-07

## Purpose
`/admin/scent-wizard` is now the governed front door for new scent authoring and intake-driven scent resolution.

It is intentionally **match-first**:
- map to existing scent whenever possible
- only create new canonical scent when no governed match exists

## Steps Implemented

## Step 1 — Identify
Inputs:
- intent (`map_existing`, `new_scent`, `customer_alias`, `blend_template_placeholder`)
- search term (prefilled from incoming raw name when available)

Behavior:
- calls `ResolveScentMatchService::resolveCandidates()`
- shows likely existing matches with score/type/why
- requires selecting an existing scent for mapping intents

## Step 2 — Scent Identity (new scent path)
Fields:
- `name`, `display_name`, `abbreviation`, `oil_reference_name`, `notes`
- lifecycle status (`draft`, `active`, `inactive`, `archived`)
- blend metadata (`is_blend`, `oil_blend_id`, `blend_oil_count`)
- availability flags (`retail`, `wholesale`, `candle_club`, `room_spray`, `wax_melt`)
- channel flags (`is_wholesale_custom`, `is_candle_club`)

Behavior:
- validates and warns/blocks when exact duplicate exists
- defaults lifecycle to `draft`

## Step 3 — Alias / Mapping
Options:
- global alias
- customer-scoped alias
- save incoming unresolved raw name as alias

Behavior:
- uses `CreateScentAliasAction`
- enforces scope uniqueness via action upsert behavior

## Step 4 — Review
Shows:
- whether flow maps existing vs creates new
- scent identity payload (for new path)
- aliases to create
- account/channel context
- governance warnings (near matches, placeholder mode)

## Step 5 — Complete
If mapping to existing:
- creates aliases/mapping artifacts only

If creating new:
- creates scent through `CreateScentAction`
- applies lifecycle via `ScentLifecycleService` (through action)
- creates aliases via `CreateScentAliasAction`

Both paths:
- can upsert wholesale custom mapping when account + raw name context exists
- show completion summary and return-to-source action

## Prefill + Launch Context
Wizard now accepts and uses:
- `raw`, `variant`, `account`, `store`
- `source_context`
- `channel_hint`
- `product_form_hint`
- `return_to`

Launch points currently passing governed context:
- Master Data header CTA and grid add-row (`source_context=master-data`)
- Catalog wizard entry (`source_context=catalog`)
- Scent Intake mapper/modal (`source_context=scent-intake`, plus raw/account/store/channel/product hints)
- Candle Club (`source_context=candle-club`, `channel_hint=candle_club`)
- Wholesale Custom (`source_context=wholesale-custom`, `channel_hint=wholesale`, `store=wholesale`)

## Return-path UX
- Wizard completion uses `finish()` to redirect to `return_to`
- Success message is flashed to session as toast payload and rendered on next page load

## Services Used by Wizard
- `App\Services\ScentGovernance\ResolveScentMatchService`
- `App\Actions\ScentGovernance\CreateScentAction`
- `App\Actions\ScentGovernance\CreateScentAliasAction`
- `App\Services\ScentGovernance\ScentLifecycleService`

## Out of Scope (Block 5+)
Not fully implemented in this block:
- deep recipe versioning / blend-template schema
- recursive recipe composition graph authoring in wizard
- full structured source-context persistence for alias provenance beyond scope naming

These remain planned for recipe/blend-template blocks.
