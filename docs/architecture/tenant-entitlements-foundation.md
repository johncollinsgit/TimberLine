# Tenant Entitlements Foundation (Phase 2)

Date: 2026-03-25  
Status: Implemented foundation (billing/provider coupling intentionally deferred)

## Purpose
Provide a centralized capability model for tenant access so the platform can support:
- plan tiers
- add-ons
- module enabled/disabled states
- setup completion states
- upgrade prompts
- placeholder/coming-soon states

This foundation is billing-agnostic by design. Billing systems can map to these keys later.

## Concepts (Canonical Meanings)
- Plan:
  - tenant baseline capability bundle (`plan_key`)
- Add-on:
  - optional capability grant layered on top of plan
- Entitlement/Capability:
  - whether tenant can access a module (`has_access`)
- Module enabled/disabled:
  - effective access result after plan + add-on + optional tenant override
- Setup state:
  - implementation/config readiness independent from access
- Coming soon / placeholder:
  - module can be visible but non-active (`ui_state = coming_soon`)

## Schema (Implemented)

### `tenant_access_profiles`
- one row per tenant
- stores:
  - `plan_key`
  - `operating_mode` (`shopify`, `direct`, etc.)
  - `source`, `metadata`

### `tenant_access_addons`
- many rows per tenant
- stores:
  - `addon_key`
  - `enabled`
  - effective window (`starts_at`, `ends_at`)
  - `source`, `metadata`

### `tenant_module_states`
- many rows per tenant
- stores per module:
  - `enabled_override` (nullable)
  - `setup_status`
  - `coming_soon_override` (nullable)
  - `upgrade_prompt_override` (nullable)
  - `setup_completed_at`, `metadata`

Migration:
- `database/migrations/2026_03_29_090000_create_tenant_access_entitlement_tables.php`

## Registry (Implemented)
Config file:
- `config/entitlements.php`

Contains:
- module catalog
- plan grants
- add-on grants
- default plan/mode
- allowed setup statuses

Current default plan now maps to:
- `starter`

Legacy compatibility aliases remain accepted for existing rows:
- `shopify_proof_of_concept` -> `starter`
- `shopify_growth` -> `growth`
- `direct_starter` -> `starter`

## Resolver (Implemented)
Service:
- `App\Services\Tenancy\TenantModuleAccessResolver`

Primary outputs:
- `has_access`
- `access_sources`
- `setup_status`
- `coming_soon`
- `ui_state` (`active`, `setup_needed`, `locked`, `coming_soon`)
- `upgrade_prompt_eligible`

Resolution order:
1. module registry defaults
2. plan grants
3. enabled add-ons
4. tenant module overrides
5. UI state derivation

Convenience methods:
- `resolveForTenant($tenantId, $moduleKeys = null)`
- `resolveForStoreContext($storeContext, $moduleKeys = null)`
- `module($tenantId, $moduleKey)`
- `canAccess($tenantId, $moduleKey)`

## Safe Defaults And Backward Compatibility
- Existing Shopify proof-of-concept modules remain accessible by default.
- Unknown/missing tenant profile falls back to configured default plan.
- No existing signed proxy, storefront runtime, or embedded route behavior is removed.
- This phase adds capability metadata; it does not enforce hard route locks yet.

## Safe UI Integration Points (Implemented)

### Embedded navigation metadata
Trait:
- `App\Http\Controllers\HandlesShopifyEmbeddedNavigation`

Adds module state metadata into:
- `appNavigation.items[*].module_state`
- `appNavigation.items[*].children[*].module_state`
- `appNavigation.moduleStates` bootstrap map

### Shell bootstrap payload
Blade component:
- `resources/views/components/shopify-embedded-shell.blade.php`

Includes:
- `<script id="tenant-module-access-bootstrap" type="application/json">...`

This enables gradual UI adoption for:
- access checks
- setup-needed indicators
- upgrade prompt decisions
- placeholder state rendering

## Module-State Experience Layer (Phase 2.5)

This phase makes entitlement state visible in embedded/admin UX without introducing billing coupling or route-hard locks.

### Shared presentation primitives (implemented)
- Presenter:
  - `App\Support\Tenancy\TenantModuleUi`
  - Canonical UI mapping for:
    - `active`
    - `setup_needed`
    - `locked`
    - `coming_soon`
  - Provides:
    - normalized state labels/tone
    - upgrade-prompt visibility
    - setup checklist grouping (`setup`, `locked`, `coming_soon`, `active`)

- Blade components:
  - `resources/views/components/tenancy/module-state-badge.blade.php`
  - `resources/views/components/tenancy/module-state-card.blade.php`
  - `resources/views/components/tenancy/module-upgrade-prompt.blade.php`
  - `resources/views/components/tenancy/module-setup-checklist.blade.php`

### Current UI integration scope (implemented)
- Embedded navigation + page header indicators:
  - `resources/views/components/app-sidebar.blade.php`
  - `resources/views/components/app-topbar.blade.php`
- Dashboard/home module-state shell + checklist:
  - `resources/views/shopify/embedded-app.blade.php`
- Soft locked/coming-soon rendering for module layouts:
  - `resources/views/shopify/rewards-layout.blade.php`
  - `resources/views/components/shopify/customers-layout.blade.php`
- Settings module-state visibility:
  - `resources/views/shopify/settings.blade.php`

### Bootstrap payload contract (expanded)
`tenant-module-access-bootstrap` now includes:
- `tenant_id`
- `modules`
- `checklist`

This keeps the resolver payload as UI source of truth while allowing JS surfaces to consume pre-grouped checklist metadata.

### How to add a new module to module-state UI
1. Register module in `config/entitlements.php`.
2. Ensure it is resolved in the relevant navigation/module map.
3. Use shared components:
   - badge for nav/header context
   - card for module overview context
   - upgrade prompt for locked/coming-soon context
   - setup checklist for onboarding/setup shell context
4. Avoid per-view custom state logic; use `TenantModuleUi`.
5. Add/extend tests for:
   - presenter/checklist behavior
   - embedded rendering coverage where integrated.

### Scope boundary reminder
- This UX layer is entitlement + setup-state visibility only.
- It is not billing orchestration.
- It does not claim tenant isolation is complete.
- It does not harden all routes yet; soft panels are used for safe incremental rollout.

## Commercialization + Onboarding Layer (Phase 3)

This phase adds first user-facing commercialization surfaces on top of the entitlement and module-state foundation, while intentionally avoiding billing/provider coupling.

### Implemented surfaces
- Public promo page:
  - route: `/platform/promo`
  - view: `resources/views/platform/promo.blade.php`
- Public contact/demo placeholder:
  - route: `/platform/contact`
  - view: `resources/views/platform/contact.blade.php`
- Embedded post-login onboarding/start-here page:
  - route: `/shopify/app/start`
  - view: `resources/views/shopify/start-here.blade.php`
- Embedded plans + add-ons informational page:
  - route: `/shopify/app/plans`
  - view: `resources/views/shopify/plans-addons.blade.php`
- Landlord commercial configuration page:
  - route: `/landlord/commercial`
  - view: `resources/views/landlord/commercial/index.blade.php`

### Centralized content/config source
- File: `config/product_surfaces.php`
- Owns:
  - promo hero + explainer copy
  - plan card names/pricing/summary/CTA copy
  - add-on card copy
  - onboarding orientation + next-action copy
  - placeholder contact CTA targets

Rule:
- Keep promo/onboarding/plans copy in this config file so templates are not scattered with duplicated hardcoded pricing/copy text.
- Tier/add-on/template defaults now also live in `config/commercial.php` for canonical product architecture metadata.

### Resolver + payload composition
- Service: `App\Services\Tenancy\TenantCommercialExperienceService`
- Uses:
  - `TenantModuleAccessResolver` for module access/setup state
  - `TenantModuleUi` for normalized state presentation/checklist grouping
  - entitlement config (`config/entitlements.php`) for plan/add-on/module catalogs

### UI consumption rules for developers
When adding new commercialization/onboarding surfaces:
1. Add copy/CTA/pricing content in `config/product_surfaces.php`.
2. Reuse `TenantCommercialExperienceService` payload methods.
3. Reuse module-state components:
   - `x-tenancy.module-setup-checklist`
   - `x-tenancy.module-state-card`
   - `x-tenancy.module-upgrade-prompt`
4. Keep upgrade actions informational unless billing coupling is explicitly in-scope.
5. Keep roadmap modules labeled as `coming_soon`; do not present them as live.

## Integrations Placeholder Surface (Phase 3.5)

This phase introduces an entitlement-aware, placeholder-first integrations page without implementing real connector sync behavior.

### Implemented surface
- Embedded integrations page:
  - route: `/shopify/app/integrations`
  - view: `resources/views/shopify/integrations.blade.php`

### Config source
- `config/product_surfaces.php` -> `integrations`
- Card definitions include:
  - `key`
  - `module_key`
  - `title`
  - `description`
  - `category`
  - `availability` (`available`, `locked`, `coming_soon`)
  - `fallback_mode` (`manual_import`, `csv_upload`, `none`)
  - `plan_requirement`
  - CTA labels

### State derivation rules
- Service: `App\Services\Tenancy\TenantCommercialExperienceService::integrationsPayload`
- State is derived from:
  1. config availability
  2. resolver/module state (`TenantModuleAccessResolver` + `TenantModuleUi`)
  3. deterministic connection flag (`mock_connected`, default false)
- Supported integration card states:
  - `connected`
  - `setup_needed`
  - `locked`
  - `coming_soon`

### Safety guardrail
- No external sync calls, jobs, or write side-effects are executed from this page.
- Locked integrations route users to informational upgrade/contact paths.
- Fallback-first UX remains available where configured.

### Setup drawer + status registry (implemented)
- Integrations cards expose per-connector setup drawer content from `config/product_surfaces.php`.
- Drawer content includes:
  - setup steps
  - required fields
  - fallback options
  - notes
  - state-aware CTA text
- The integrations payload now includes deterministic, read-only status-registry metadata per card, including:
  - `key`
  - `state`
  - `status_label`
  - `source_label`
  - `last_checked_at` (nullable)
  - `setup_mode` (`manual`, `csv`, `direct`, `placeholder`)
  - `notes`
  - `can_configure`
  - `is_mocked`
  - `configured_in_app`
  - `using_fallback`
  - `summary`

Status-registry derivation constraints:
- Derive from:
  - entitlement/module state
  - config definition
  - safe local provider context where already available
- Do not derive from live external connector verification.
- Do not persist connector connection state from external systems in this phase.
- Keep all card/drawer status messaging explicit about placeholder/read-only behavior.

### Current release boundary reminder
- This integrations surface is an operator clarity layer, not a connector backend.
- Not implemented in this phase:
  - connector OAuth handshakes
  - external API sync writes
  - webhook-driven connector pipelines
  - billing checkout/subscription mutation writes (Stripe checkout session creation + webhook confirmation/reference recording are allowed; entitlement mutation remains deferred)

## Landlord Commercial Configuration Layer (Phase 4)

This phase extends host-locked landlord controls into safe configuration writes without activating billing checkout lifecycle actions.

Implemented additions:
- Route: `/landlord/commercial`
- Controller: `App\Http\Controllers\Landlord\LandlordCommercialConfigurationController`
- Service: `App\Services\Tenancy\LandlordCommercialConfigService`
- Tables:
  - `landlord_catalog_entries`
  - `tenant_commercial_overrides`
  - `tenant_usage_counters`

Configuration scope (allowed writes):
- plan/add-on/setup/template catalog metadata and pricing
- template duplicate/activate/deactivate/archive actions
- tenant plan assignment (via `tenant_access_profiles`)
- tenant module enable/disable overrides (via `tenant_module_states`)
- tenant add-on enable/disable (via `tenant_access_addons`)
- tenant pricing/usage/display-label/billing-mapping overrides

Safety boundary:
- no checkout/subscription mutation
- no connector live-write activation from integrations page
- no parallel identity, loyalty, or billing profile systems

Neutral module terminology support:
- Canonical module keys remain stable (`rewards`, etc.).
- Template defaults and tenant overrides can provide display labels.
- Candle-specific wording (for example `Candle Cash`) is treated as template/default presentation, not a hard architecture requirement.

### Staging UAT Hardening Addendum (2026-03-27)
- Landlord commercial console now emphasizes operator-safe assignment verification:
  - prefilled override JSON values
  - visible effective module/add-on states
  - included-usage display alongside observed usage counters
- Commercialization payloads for `/shopify/app/start`, `/shopify/app/plans`, and `/shopify/app/integrations` now expose template/label-source context so operators can validate assignment propagation quickly.
- Config-driven onboarding copy now supports label tokens (for example `{{rewards_label}}`) so tenant/template display labels propagate into high-traffic commercialization surfaces.
- Deferred by design in this pass:
  - deep legacy Candle Cash/admin/storefront copy outside commercialization UAT surfaces remains unchanged and should be handled in a dedicated legacy-label cleanup pass.

## How To Add A New Entitlement-Aware Module
1. Add module key to `config/entitlements.php` under `modules`.
2. Classify module (`shopify-only`, `shared-core`, `integration-layer`, `add-on`, `internal-admin`).
3. Declare default setup/placeholder behavior.
4. Add module to one or more plan/add-on grant lists.
5. Use `TenantModuleAccessResolver` in controllers/services instead of hardcoded checks.
6. If UI needs state, consume `appNavigation.moduleStates` or call resolver directly.
7. Add tests for:
   - default plan behavior
   - add-on unlock behavior
   - locked behavior
   - setup-state behavior

## Guardrails
- Do not create parallel identity systems.
- Do not tie core access checks directly to billing provider APIs.
- Do not hardcode store/email-based feature access checks in controllers/views.
- Do not mark placeholders as complete modules.

## Related Files
- `config/entitlements.php`
- `app/Services/Tenancy/TenantModuleAccessResolver.php`
- `app/Models/TenantAccessProfile.php`
- `app/Models/TenantAccessAddon.php`
- `app/Models/TenantModuleState.php`
- `app/Http/Controllers/HandlesShopifyEmbeddedNavigation.php`
- `resources/views/components/shopify-embedded-shell.blade.php`
- `tests/Feature/Tenancy/TenantModuleAccessResolverTest.php`
