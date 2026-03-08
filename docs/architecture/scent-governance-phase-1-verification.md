# Scent Governance Phase 1 Verification

Date: 2026-03-07  
Verified commit: `228f557`  
Method: code-path verification + route/command inspection (no browser runtime in this environment)

## Summary Status
- `PASS`: 1, 2, 3, 4, 5, 6, 7
- `PARTIAL`: 8, 9
- `READY`: Phase 1 is safe to treat as baseline, with known controlled loopholes documented below.

## 1) Wizard routing
Status: `PASS`

Confirmed wizard route:
- `GET /admin/scent-wizard` in [routes/web.php](/Users/johncollins/Code/myapp/routes/web.php)

Confirmed launch points now routing to wizard:
- Master Data panel CTA in [resources/views/livewire/admin/admin-home.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/admin/admin-home.blade.php)
- Master Data Grid “Add Row” when active resource is `scents` in [resources/js/admin/master-data-grid.tsx](/Users/johncollins/Code/myapp/resources/js/admin/master-data-grid.tsx)
- Catalog “New Scent Wizard” button in [resources/views/livewire/admin/catalog/scents.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/admin/catalog/scents.blade.php)
- Catalog create actions redirect in [app/Livewire/Admin/Catalog/ScentsCrud.php](/Users/johncollins/Code/myapp/app/Livewire/Admin/Catalog/ScentsCrud.php)
- Scent Intake modal “Launch New Scent Wizard” in [resources/views/livewire/intake/progressive-mapper.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/intake/progressive-mapper.blade.php)
- Candle Club wizard link in [resources/views/livewire/admin/candleclub/scents.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/admin/candleclub/scents.blade.php)
- Wholesale Custom wizard link in [resources/views/livewire/admin/wholesale/custom-scents.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/admin/wholesale/custom-scents.blade.php)
- MappingExceptions fallback redirects to wizard in [app/Livewire/Admin/MappingExceptions.php](/Users/johncollins/Code/myapp/app/Livewire/Admin/MappingExceptions.php)

## 2) Master Data behavior
Status: `PASS`

- `scents` inline/direct create blocked server-side in `AdminMasterDataController@store`:
  - returns 422 with explicit wizard message
  - file: [app/Http/Controllers/AdminMasterDataController.php](/Users/johncollins/Code/myapp/app/Http/Controllers/AdminMasterDataController.php)
- Existing row edits remain via `bulkUpdate`/`update` endpoints (unchanged).
- Non-scent resources still use normal add-row flow in grid (only `activeResource === "scents"` branches to wizard):
  - file: [resources/js/admin/master-data-grid.tsx](/Users/johncollins/Code/myapp/resources/js/admin/master-data-grid.tsx)

## 3) Catalog behavior
Status: `PASS`

- Existing scent inline editing remains in `ScentsCrud` and blade table.
- Independent create flow removed from primary UX:
  - `openCreate()` redirects to wizard
  - `create()` redirects to wizard
  - file: [app/Livewire/Admin/Catalog/ScentsCrud.php](/Users/johncollins/Code/myapp/app/Livewire/Admin/Catalog/ScentsCrud.php)
- “Create” affordance now points to wizard:
  - file: [resources/views/livewire/admin/catalog/scents.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/admin/catalog/scents.blade.php)

## 4) Scent Intake behavior
Status: `PASS`

- Map-to-existing remains in `ProgressiveMapper::save()`.
- Alias sync remains in `ProgressiveMapper::syncAliases()`.
- Fallback canonical scent creation removed from `MappingExceptions::saveGroup()`.
- Unresolved flows now redirect to wizard with prefill context (`raw`, `variant`, `account`, `store`, `return_to`):
  - files:
    - [app/Livewire/Admin/MappingExceptions.php](/Users/johncollins/Code/myapp/app/Livewire/Admin/MappingExceptions.php)
    - [app/Livewire/Intake/ProgressiveMapper.php](/Users/johncollins/Code/myapp/app/Livewire/Intake/ProgressiveMapper.php)
    - [resources/views/livewire/intake/progressive-mapper.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/intake/progressive-mapper.blade.php)

## 5) Candle Club behavior
Status: `PASS`

- Now requires existing scent selection (`scentId` exists validation).
- Fallback `Scent::create()` removed.
- “Need new scent” wizard route exposed in UI.
- files:
  - [app/Livewire/Admin/CandleClub/CandleClubScentsCrud.php](/Users/johncollins/Code/myapp/app/Livewire/Admin/CandleClub/CandleClubScentsCrud.php)
  - [resources/views/livewire/admin/candleclub/scents.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/admin/candleclub/scents.blade.php)

## 6) Wholesale custom behavior
Status: `PASS`

- Customer-scoped mapping CRUD still works in `CustomScentsCrud`.
- No direct canonical scent creation in wholesale UI flow.
- `wholesale-custom:sync-master` now has explicit guard:
  - `--allow-create-canonical`
  - default behavior does **not** create canonical scents when unmatched
  - help output includes option
  - file: [app/Console/Commands/SyncWholesaleCustomMaster.php](/Users/johncollins/Code/myapp/app/Console/Commands/SyncWholesaleCustomMaster.php)

## 7) UI copy checks
Status: `PASS`

Confirmed governance/role copy present on:
- Master Data: [resources/views/livewire/admin/admin-home.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/admin/admin-home.blade.php)
- Catalog: [resources/views/livewire/admin/catalog/scents.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/admin/catalog/scents.blade.php)
- Scent Intake: [resources/views/livewire/admin/mapping-exceptions.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/admin/mapping-exceptions.blade.php)
- Wholesale Custom: [resources/views/livewire/admin/wholesale/custom-scents.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/admin/wholesale/custom-scents.blade.php)
- Blends: [resources/views/livewire/admin/oils/blends.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/admin/oils/blends.blade.php)
- Candle Club: [resources/views/livewire/admin/candleclub/scents.blade.php](/Users/johncollins/Code/myapp/resources/views/livewire/admin/candleclub/scents.blade.php)

## 8) Remaining loopholes (repo search)
Status: `PARTIAL`

Remaining scent creation paths still present:
1. Intended canonical creation path:
   - [app/Livewire/Admin/ScentWizard.php](/Users/johncollins/Code/myapp/app/Livewire/Admin/ScentWizard.php)
2. Controlled import path still creates canonical records:
   - [app/Console/Commands/MasterDataImport.php](/Users/johncollins/Code/myapp/app/Console/Commands/MasterDataImport.php)
3. Wholesale sync can create canonical with explicit opt-in flag:
   - [app/Console/Commands/SyncWholesaleCustomMaster.php](/Users/johncollins/Code/myapp/app/Console/Commands/SyncWholesaleCustomMaster.php)
4. Legacy dormant component still contains direct scent create:
   - [app/Livewire/Admin/Catalog.php](/Users/johncollins/Code/myapp/app/Livewire/Admin/Catalog.php)
   - currently not in active admin route surface (`/admin` uses `AdminHome`)

Assessment:
- No remaining **active** admin UI path found that silently creates canonical scents outside wizard.
- CLI/import creators are still present by design and should be governed operationally.

## 9) Regression / UX risk notes
Status: `PARTIAL`

Likely awkward spots introduced by Phase 1:
- Catalog component still has legacy create modal code blocks in blade/class; user now gets redirected instead. Works but noisy.
- Master Data grid scent “Add Row” now hard navigates to wizard; users may expect same-screen entry.
- Intake wizard handoff depends on redirect from modal context; if user expects modal persistence, this is a behavior change.
- Candle Club now requires scent picker; users used to free-typing must switch habits.

No hard dead-end found:
- Each restricted surface now has a visible wizard route or redirect fallback.

## 10) Recommended cleanup before Block 3
1. Remove/trim dead create-form blocks from Catalog Scents blade/class (keep edit-only).
2. Add small “returned from wizard” toast context on intake/catalog/candle-club return paths.
3. Explicitly mark `MasterDataImport` as migration-only in command description/help and runbook.
4. Deprecate or remove dormant [app/Livewire/Admin/Catalog.php](/Users/johncollins/Code/myapp/app/Livewire/Admin/Catalog.php) to eliminate accidental future reactivation.

## Baseline Decision
Phase 1 is safe to treat as the new baseline.
- Governance intent is enforced on active admin surfaces.
- Remaining scent creators are controlled/explicit (wizard + imports + guarded sync flag) and documented.

