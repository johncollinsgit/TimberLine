# Scent Governance Phase 1 (Implemented)

Date: 2026-03-07

## Scope
Phase 1 applies soft-governance restrictions to reduce duplicate scent creation paths while keeping production workflows stable and reversible.

## What Was Restricted

### 1) New canonical scent creation now routes to wizard
- Added dedicated route/page: `GET /admin/scent-wizard` (`admin.scent-wizard`)
- Added `App\Livewire\Admin\ScentWizard` + `resources/views/livewire/admin/scent-wizard.blade.php`
- Wizard supports:
  - canonical scent creation
  - optional alias creation from incoming raw label
  - optional wholesale custom mapping seed when account context exists
  - context-prefill (`raw`, `variant`, `account`, `store`, `return_to`)

### 2) Master Data `scents` create path restricted
- `AdminMasterDataController@store` now blocks `resource=scents` with explicit message:
  - “New scents must be created through the New Scent Wizard.”
- Master Data grid “Add Row” behavior for `scents` now opens wizard instead of POST create.
- Other resources still retain normal row creation.

### 3) Catalog create path redirected
- `App\Livewire\Admin\Catalog\ScentsCrud::openCreate()` now redirects to wizard.
- `App\Livewire\Admin\Catalog\ScentsCrud::create()` now redirects to wizard.
- Catalog view top action now links to wizard.
- Existing inline edit behavior remains available for existing scent rows.

### 4) Scent Intake mapping-only enforcement
- `ProgressiveMapper` now exposes direct “Launch New Scent Wizard” path with context prefill.
- `MappingExceptions::saveGroup()` no longer auto-creates canonical scents:
  - if no mapped scent exists, it redirects to wizard.
  - Candle Club fallback in this path also now requires existing scent or wizard handoff.
- Alias + wholesale custom mapping enrichment remains intact after successful map-to-existing.

### 5) Candle Club assign-existing only
- `CandleClubScentsCrud` now requires `scent_id` (`exists:scents,id`) and no longer creates scents inline.
- Candle Club UI now uses scent picker and wizard fallback link.

### 6) Wholesale custom sync canonical-creation guardrail
- `wholesale-custom:sync-master` now supports:
  - `--allow-create-canonical`
- Default behavior (`false`) no longer invents canonical scent rows when unmatched.
- Command summary now reports `rows_without_canonical_match`.
- UI-triggered CSV sync now stays in governed mode (no canonical auto-create by default).

## UI Role Copy Added
- Master Data: “Power-user maintenance… use wizard to create new scents.”
- Catalog: “New scents should be created through the wizard.”
- Scent Intake: mapping-only + wizard fallback guidance.
- Wholesale Custom: customer-specific mapping-only guidance + wizard link.
- Blends: positioned as reusable blend-template maintenance.
- Candle Club: assign-existing guidance + wizard link.

## Files Changed (Phase 1)
- `routes/web.php`
- `app/Livewire/Admin/ScentWizard.php` (new)
- `resources/views/livewire/admin/scent-wizard.blade.php` (new)
- `app/Http/Controllers/AdminMasterDataController.php`
- `resources/views/livewire/admin/admin-home.blade.php`
- `resources/js/admin/master-data-grid.tsx`
- `app/Livewire/Admin/Catalog/ScentsCrud.php`
- `resources/views/livewire/admin/catalog/scents.blade.php`
- `app/Livewire/Admin/MappingExceptions.php`
- `resources/views/livewire/admin/mapping-exceptions.blade.php`
- `app/Livewire/Intake/ProgressiveMapper.php`
- `resources/views/livewire/intake/progressive-mapper.blade.php`
- `app/Livewire/Admin/CandleClub/CandleClubScentsCrud.php`
- `resources/views/livewire/admin/candleclub/scents.blade.php`
- `resources/views/livewire/admin/wholesale/custom-scents.blade.php`
- `resources/views/livewire/admin/oils/blends.blade.php`
- `app/Console/Commands/SyncWholesaleCustomMaster.php`
- `app/Livewire/Admin/Wholesale/CustomScentsCrud.php`

## What Remains for Phase 2
- Consolidate all scent/blend/alias writes behind shared service/action layer.
- Remove dormant legacy write paths after usage confirmation.
- Add stricter DB-level uniqueness/index alignment for abbreviated fields/aliases where needed.
- Expand wizard to support richer nested blend authoring in one flow (if desired).
- Add policy/permissions layer explicitly enforcing “mapping-only” vs “authoring” capabilities per surface.

