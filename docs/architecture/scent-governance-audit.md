# Scent Governance Audit (Backstage)

Date: 2026-03-07  
Repo: `myapp` (Laravel + Livewire + React grid)

## 1) Why This Audit Exists
This audit maps every current write path for scent/recipe/blend/alias/wholesale mapping data so creation/edit flows can be consolidated without breaking production.

Business objective inferred from current system behavior:
- Normalize incoming product/scent names from retail + wholesale channels.
- Convert normalized demand into shipping/pouring work, oil usage, and inventory signals.
- Provide operational visibility (what to make now) and planning visibility (past trend + present queue + near-future demand).
- Support finance/logistics posture by maintaining reliable canonical references (scents, blends, oils, sizes, mappings).

## 2) Canonical Entities in Scope
Primary tables/models audited:
- `scents` (`App\Models\Scent`)
- `blends` (`App\Models\Blend`)
- `blend_components` (`App\Models\BlendComponent`)
- `base_oils` (`App\Models\BaseOil`)
- `oil_abbreviations` (`App\Models\OilAbbreviation`)
- `scent_aliases` (`App\Models\ScentAlias`)
- `wholesale_custom_scents` (`App\Models\WholesaleCustomScent`)
- `candle_club_scents` (`App\Models\CandleClubScent`)

Related operational tables that feed/consume mappings:
- `orders`, `order_lines`, `mapping_exceptions`, `shopify_import_exceptions`, `import_normalizations`

## 3) Current Data-In Flow (How Data Enters)
### 3.1 Shopify ingestion
- Webhooks:
  - `POST /webhooks/shopify/orders/create`
  - `POST /webhooks/shopify/orders/updated`
  - `POST /webhooks/shopify/orders/cancelled`
  - `POST /webhooks/shopify/refunds/create`
- Entry: `App\Http\Controllers\ShopifyWebhookController`
- Job: `App\Jobs\ShopifyUpsertOrder`
- Service: `App\Services\Shopify\ShopifyOrderIngestor`
- Writes:
  - `orders`, `order_lines`
  - `mapping_exceptions` for unresolved lines
  - `shopify_import_exceptions` for bundle/import parsing failures
  - `import_normalizations` for normalization telemetry
- Important: this pipeline **does not auto-create canonical scents** directly.

### 3.2 Manual/CLI imports
- `shopify:import-orders` (same pipeline as webhook, command-driven replay)
- `master-data:import` (can create/update canonical scents, blends, oils, candle club links, wholesale custom mappings)
- `wholesale-custom:sync-master` (can replace wholesale mappings and auto-create/update canonical scents + blends)
- `markets:import-boxes` / `markets:generate-pour-lists` (operational market/event data)

### 3.3 Admin UI-triggered import tools
From authenticated admin routes (`routes/web.php`):
- `POST /admin/tools/import/retail` -> `shopify:import-orders`
- `POST /admin/tools/import/wholesale` -> `shopify:import-orders`
- `POST /admin/tools/import-market-boxes` -> `markets:import-boxes`
- `POST /admin/tools/clear-orders` -> clears order + exception history

## 4) Governance Matrix: All Scent-Related Write Surfaces

## Legend
- Role:
  - Authoring surface: create/define canonical master records
  - Mapping surface: map incoming labels to existing canonical records
  - Operational surface: execute production workflow with canonical references
  - Maintenance surface: admin housekeeping/import/normalization tasks
- Decision:
  - Keep
  - Restrict
  - Redirect to wizard
  - Deprecate later

| Surface | Route / Command | Entrypoint | Entities Written | Actions | Current Role | Recommended Role | Decision |
|---|---|---|---|---|---|---|---|
| Master Data Grid | `/admin?tab=master-data&resource=*` + `/admin/master/*` APIs | `App\Http\Controllers\AdminMasterDataController` + `resources/js/admin/master-data-grid.tsx` | `scents`, `base_oils`, `blends`, `blend_components`, `wholesale_custom_scents`, `oil_abbreviations`, `sizes`, `wicks`, `scent_aliases` | create/update/delete/bulk update | Maintenance + Authoring | **Primary maintenance surface** | **Keep** |
| Catalog Scents | `/admin?tab=catalog` (`/admin/catalog/scents` direct) | `App\Livewire\Admin\Catalog\ScentsCrud` | `scents`, `blends`, `blend_components`, `scent_aliases` | create/update/delete scent, inline blend creation, canonical/source links, alias sync | Authoring + Mapping | Edit existing only; new canonical creation via wizard | **Restrict + Redirect create to wizard** |
| Scent Intake (exceptions) | `/admin?tab=scent-intake` | `App\Livewire\Admin\MappingExceptions` + modal `App\Livewire\Intake\ProgressiveMapper` | `order_lines`, `mapping_exceptions`, `scent_aliases`, `wholesale_custom_scents`, **sometimes `scents`**, `candle_club_scents` | map/resolve, exclude/restore, plus fallback creation of scent/candle-club rows in `MappingExceptions` | Mapping + accidental authoring | Mapping only | **Restrict (remove inline canonical creation)** |
| Wholesale Custom Scents | `/admin?tab=wholesale-custom` (`/admin/wholesale/custom-scents`) | `App\Livewire\Admin\Wholesale\CustomScentsCrud` | `wholesale_custom_scents` (+ recipe JSON), triggers `wholesale-custom:sync-master` on CSV | create/update/delete mapping rows, full CSV replace/sync | Mapping + Maintenance | Mapping-only surface | **Keep with guardrails** |
| Candle Club | `/admin?tab=candle-club` | `App\Livewire\Admin\CandleClub\CandleClubScentsCrud` | `candle_club_scents`, **sometimes `scents`** | assign month/year -> scent, fallback create scent | Assignment + accidental authoring | Assign existing only | **Restrict + Redirect create to wizard** |
| Oil Blends | `/admin?tab=blends` (`/admin/oils/blends`) | `App\Livewire\Admin\Oils\OilBlendsCrud` | `blends`, `blend_components` | create/update/delete blend + components | Authoring | Keep (or move behind wizard phase 2) | **Keep** |
| Oil Abbreviations | `/admin?tab=oils` (`/admin/oils/abbreviations`) | `App\Livewire\Admin\Oils\OilAbbreviationsCrud` | `oil_abbreviations` | create/update/delete | Maintenance | Keep | **Keep** |
| Shopify webhook ingest | `/webhooks/shopify/*` | `ShopifyWebhookController` -> `ShopifyUpsertOrder` -> `ShopifyOrderIngestor` | `orders`, `order_lines`, `mapping_exceptions`, `shopify_import_exceptions`, `import_normalizations` | upsert operational records, flag mapping exceptions | Operational ingestion | Keep; never create canonical scents | **Keep** |
| Shopify command ingest | `shopify:import-orders` | `App\Console\Commands\ShopifyImportOrders` -> `ShopifyOrderIngestor` | same as webhook path | replay/import | Operational + maintenance | Keep; never create canonical scents | **Keep** |
| Master data batch import | `master-data:import` | `App\Console\Commands\MasterDataImport` | `scents`, `base_oils`, `blends`, `blend_components`, `oil_abbreviations`, `candle_club_scents`, `wholesale_custom_scents` | insert/update from CSV/zip | Maintenance + bulk authoring | Controlled back-office migration tool only | **Restrict** |
| Wholesale master sync | `wholesale-custom:sync-master` | `App\Console\Commands\SyncWholesaleCustomMaster` | `wholesale_custom_scents`, `blends`, `blend_components`, **`scents`** | optional replace + auto-create/update canonical scent/blend | Mapping + accidental authoring | Mapping import only (no canonical auto-create in steady state) | **Restrict** |
| Duplicate cleanup | `catalog:merge-duplicate-scents` | `App\Console\Commands\MergeDuplicateScents` | `scents` + FK rewires | merge/delete duplicates | Maintenance | Keep manual/admin only | **Keep (restricted)** |
| Wholesale cleanup | `catalog:normalize-wholesale-scents` | `App\Console\Commands\NormalizeWholesaleScentNames` | `scents` + FK rewires | rename/delete/merge | Maintenance | Keep manual/admin only | **Keep (restricted)** |
| Raw-name cleanup | `catalog:normalize-wholesale-raw` | `App\Console\Commands\NormalizeWholesaleRawNames` | `order_lines`, `mapping_exceptions` | normalize raw strings | Maintenance | Keep | **Keep** |
| Destructive reset | `catalog:reset` | `App\Console\Commands\CatalogReset` | `scents`, `sizes`, mapping/order links | destructive reset | Maintenance | Local/dev only | **Keep (dev only)** |
| Legacy dormant CRUD | no routed URL currently | `App\Livewire\Admin\Catalog` | `scents`, `sizes` | create/update | Duplicate legacy authoring | Remove from active architecture | **Deprecate later** |

## 5) Duplicate / Conflicting Creation Paths (Critical)
Canonical scent records can currently be created from multiple places:
1. `AdminMasterDataController@store` (`resource=scents`)
2. `ScentsCrud::create()` (Catalog)
3. `MappingExceptions::saveGroup()` fallback (`Scent::firstOrCreate` / `create`)
4. `CandleClubScentsCrud::save()` fallback (`Scent::create`)
5. `MasterDataImport::importScents()` and `importCandleClubScents()`
6. `SyncWholesaleCustomMaster` auto-creates canonical scent if no match

Blend creation also duplicates:
1. `OilBlendsCrud::create()`
2. `ScentsCrud::createInlineBlendFromResolvedRows()`
3. `MasterDataImport::importBlends()`
4. `SyncWholesaleCustomMaster` (`Blend::firstOrNew`) + component rewrite

Alias writes duplicate:
1. Master Data (`resource=scent-aliases`)
2. `ProgressiveMapper::syncAliases()`
3. `MappingExceptions::saveGroup()` alias upsert
4. `ScentsCrud::syncCanonicalAlias()` (scope `catalog`)

Wholesale custom mapping writes duplicate:
1. Master Data (`resource=wholesale-custom-scents`)
2. `CustomScentsCrud::create/save`
3. `ProgressiveMapper::syncWholesaleCustomMappings()`
4. `MappingExceptions::saveGroup()`
5. `MasterDataImport::importWholesaleCustomScents()`
6. `SyncWholesaleCustomMaster`

## 6) Inline vs Shared Service Layer (Current Reality)
Most write logic is duplicated in UI/command classes and not centralized:
- Direct model writes are spread across Livewire components, controller actions, and commands.
- Mapping logic appears in both `MappingExceptions` and `ProgressiveMapper`.
- Blend recipe resolution is partially centralized via `NestedOilRecipeResolver`, but write orchestration is not.

Shared-service gap:
- There is no single `ScentGovernanceService` / `CanonicalScentWriteAction` enforcing one canonical creation policy.
- Validation and uniqueness behavior diverges by surface.

## 7) Validation / Integrity Gaps
1. **`scents` DB constraints are lighter than UI assumptions**
   - DB enforces unique `name` only.
   - `abbreviation` and `display_name` uniqueness are enforced inconsistently at app layer (surface-dependent).
2. **`oil_abbreviations` has no DB unique constraints**
   - Different surfaces validate different uniqueness rules (`name` vs `abbreviation`).
3. **Case/normalization mismatch risks**
   - Several flows do case-insensitive checks in PHP collections, others rely on SQL equality/like.
4. **Mapping surfaces can still author canonical records**
   - Scent Intake + Candle Club can create `scents` rows, violating mapping-only expectation.
5. **CSV/CLI paths can bypass governance intent**
   - `wholesale-custom:sync-master` can create canonical scents and blends during sync.
6. **No single lifecycle/status policy for scent entities**
   - `is_active`, `is_wholesale_custom`, `is_candle_club`, canonical/source links are not consistently enforced by one policy layer.

## 8) Imports/Webhooks That Mutate Scent-Related Master Data
### Mutate operational only (good)
- `ShopifyWebhookController` + `ShopifyOrderIngestor`
- `shopify:import-orders`

### Mutate canonical/master data (high governance impact)
- `master-data:import`
- `wholesale-custom:sync-master`
- `catalog:merge-duplicate-scents`
- `catalog:normalize-wholesale-scents`

### UI-triggered command hooks
- `/admin/tools/import/retail`
- `/admin/tools/import/wholesale`
- `/admin/tools/import-market-boxes`

## 9) Recommended First-Pass Restrictions / Redirects
Aligned to target state:
- Master Data = primary maintenance
- Wizard = only approved new scent creation flow
- Catalog = edit existing; create routes to wizard
- Scent Intake = mapping only
- Wholesale Custom = mapping only
- Candle Club = assign existing only
- Imports/webhooks = never auto-create canonical scents in steady-state ops

Immediate safe policy changes (first pass):
1. **Block canonical scent creation in mapping UIs**
   - Remove fallback creation from `MappingExceptions::saveGroup()`.
   - Keep `ProgressiveMapper` map-to-existing behavior.
2. **Block Candle Club fallback scent creation**
   - Require selecting existing scent; “Create new scent” routes to wizard.
3. **Catalog create button routes to wizard**
   - Keep inline edit for existing rows.
4. **Gate bulk import creators**
   - For `wholesale-custom:sync-master`, add/require explicit flag for canonical creation (`--allow-create-canonical`) and default to false.
5. **Unify uniqueness policy**
   - Add DB constraints where required (or formally document non-unique fields); align all surfaces to same rules.
6. **Deprecate `App\Livewire\Admin\Catalog` legacy component**
   - Keep out of routing and remove once confirmed unused.

## 10) Files Requiring Follow-Up (Implementation Backlog)
### Highest priority (governance correctness)
- `app/Livewire/Admin/MappingExceptions.php`
- `app/Livewire/Intake/ProgressiveMapper.php`
- `app/Livewire/Admin/CandleClub/CandleClubScentsCrud.php`
- `app/Livewire/Admin/Catalog/ScentsCrud.php`
- `app/Http/Controllers/AdminMasterDataController.php`
- `app/Console/Commands/SyncWholesaleCustomMaster.php`

### Validation/schema alignment
- `database/migrations/*scents*`
- `database/migrations/*oil_abbreviations*`
- `database/migrations/*wholesale_custom_scents*`
- `database/migrations/*scent_aliases*`

### Ingestion safeguards
- `app/Services/Shopify/ShopifyOrderIngestor.php`
- `app/Console/Commands/MasterDataImport.php`
- `routes/web.php` (admin import tools)

### Potential deprecation cleanup
- `app/Livewire/Admin/Catalog.php` (legacy duplicate CRUD)

## 11) Best-Guess Product Direction (for planning)
You appear to be moving toward this operating model:
1. One canonical scent/blend/oil graph as source of truth.
2. Fast mapping layer for messy incoming names (retail + wholesale + account-specific variants).
3. Operational pages (shipping/pouring/planning) consume canonical IDs only.
4. Forecasting and cost/logistics math derive from canonical + recipe graph, not ad-hoc text fields.

If that is correct, governance should enforce:
- no silent canonical creation from mapping screens
- one approved creation path (wizard)
- every other surface maps/assigns existing records
- import paths default to non-creating mode unless explicitly authorized

---

## Appendix A: Direct Route Write Endpoints (Scent Governance Relevant)
From `routes/web.php`:
- `POST /admin/master-data/{resource}/bulk-update`
- `POST /admin/master/{resource}`
- `PATCH /admin/master/{resource}/{record}`
- `DELETE /admin/master/{resource}/{record}`
- `POST /webhooks/shopify/orders/create`
- `POST /webhooks/shopify/orders/updated`
- `POST /webhooks/shopify/orders/cancelled`
- `POST /webhooks/shopify/refunds/create`
- `POST /admin/tools/import/retail`
- `POST /admin/tools/import/wholesale`
- `POST /admin/tools/import-market-boxes`

## Appendix B: Notes on Operational/Future-State Outcome
Current architecture already supports a pipeline from demand intake -> mapping -> production queue -> oil consumption. Consolidating scent governance will materially improve:
- logistics confidence (what to pour, when, from what recipe)
- purchasing confidence (true base-oil demand from nested blends)
- financial confidence (costing and forward demand without duplicate scent identity drift)
