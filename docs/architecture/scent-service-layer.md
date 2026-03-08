# Scent Service Layer (Block 3)

Date: 2026-03-07

## Purpose
Block 3 centralizes scent governance rules into shared actions/services so active admin flows do not keep duplicating create/match/alias logic.

This is a production-safe consolidation pass:
- no broad schema rewrite
- no rollback of phase-1 governance restrictions
- no reintroduction of alternate canonical scent creation paths

## New Shared Classes

### Actions
- `App\Actions\ScentGovernance\CreateScentAction`
  - Canonical scent creation orchestration
  - Normalizes name/fields
  - Enforces duplicate name + abbreviation checks
  - Applies lifecycle intent via `ScentLifecycleService`

- `App\Actions\ScentGovernance\UpdateScentAction`
  - Shared metadata update rules for existing scents
  - Reuses duplicate protection + lifecycle mapping
  - Handles blend-field normalization

- `App\Actions\ScentGovernance\CreateScentAliasAction`
  - Scoped alias upsert orchestration
  - Normalizes alias labels
  - Enforces uniqueness by `(alias, scope)` behavior with `updateOrCreate`
  - Supports bulk sync across scopes

### Services
- `App\Services\ScentGovernance\ResolveScentMatchService`
  - Shared scent match candidate resolution across:
    - canonical scent fields
    - wholesale custom mappings
    - scoped aliases
  - Provides:
    - candidate list ranking
    - exact/best existing scent lookup
    - single-candidate ID resolution for mapping flows

- `App\Services\ScentGovernance\ScentLifecycleService`
  - Lifecycle policy seam for `draft|active|inactive|archived`
  - Current schema bridge: maps lifecycle intent to `is_active`
  - Documents current-vs-target lifecycle gap without schema risk

## Active Flows Now Using Shared Layer

- `App\Livewire\Admin\ScentWizard`
  - Uses `CreateScentAction` for canonical create
  - Uses `CreateScentAliasAction` for optional scoped alias sync

- `App\Livewire\Intake\ProgressiveMapper`
  - Uses `ResolveScentMatchService` for search candidate results
  - Uses `ResolveScentMatchService` for existing scent fallback lookup
  - Uses `CreateScentAliasAction` for scoped alias sync after mapping

- `App\Livewire\Admin\MappingExceptions`
  - Uses `ResolveScentMatchService` for modal search fallback lookup
  - Uses `CreateScentAliasAction` for scoped markets alias sync

- `App\Livewire\Admin\Wholesale\CustomScentsCrud`
  - Uses `ResolveScentMatchService` to prefer existing canonical scent matches when canonical is not manually selected

- `App\Livewire\Admin\Catalog\ScentsCrud`
  - Uses `UpdateScentAction` for shared scent metadata updates
  - Uses `CreateScentAction` only as safe fallback for null-create path
  - Uses `CreateScentAliasAction` for catalog alias sync

## What Still Remains Outside the Shared Layer (Intentional for Block 3)

- Generic Master Data controller bulk-update path (`AdminMasterDataController`)
  - Uses dynamic resource rules; not yet routed through scent-specific actions.

- Controlled migration/import commands
  - `master-data:import`
  - `wholesale-custom:sync-master` (still guarded by `--allow-create-canonical`)

- Some operations-oriented scent matching in markets/pouring flows
  - Separate operational matching helpers remain and should be evaluated in later blocks.

## Block 4 Readiness

Block 4 can now use these services as wizard backbone:
- create/update rules live in dedicated actions
- matching logic is reusable across intake and mapping surfaces
- alias policies are centralized
- lifecycle intent seam exists for schema-backed lifecycle expansion later

