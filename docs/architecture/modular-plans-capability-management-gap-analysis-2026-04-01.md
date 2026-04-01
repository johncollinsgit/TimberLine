# Modular Plans And Capability Management Gap Analysis

Date: 2026-04-01
Status: Repo-aware implementation note for modular plans, tenant entitlements, App Store flows, and landlord capability controls

## Purpose

Translate the modular plans mockup/PRD into the current repository reality by answering:

1. What commercialization and entitlement primitives already exist?
2. Which marketed plans/modules are actually supported today?
3. Where are the main gaps for website, tenant App Store, locked-capability UX, and landlord controls?
4. What is the minimum schema and service work needed to close those gaps without creating a parallel commercial system?

## Sources Reviewed

- `README_FOR_AGENTS.md`
- `SYSTEM_SNAPSHOT.md`
- `docs/architecture/tenant-entitlements-foundation.md`
- `docs/architecture/operational-multi-tenant-direction.md`
- `config/entitlements.php`
- `config/commercial.php`
- `config/product_surfaces.php`
- `routes/web.php`
- `app/Services/Tenancy/TenantModuleAccessResolver.php`
- `app/Services/Tenancy/TenantCommercialExperienceService.php`
- `app/Services/Tenancy/LandlordCommercialConfigService.php`
- `app/Http/Controllers/Landlord/LandlordCommercialConfigurationController.php`
- `app/Http/Controllers/PlatformProductPagesController.php`
- `app/Http/Controllers/ShopifyEmbeddedAppController.php`
- `resources/views/platform/promo.blade.php`
- `resources/views/shopify/plans-addons.blade.php`
- `resources/views/landlord/commercial/index.blade.php`
- `database/migrations/2026_03_29_090000_create_tenant_access_entitlement_tables.php`
- `database/migrations/2026_03_30_090000_create_landlord_commercial_configuration_tables.php`
- `database/migrations/2026_04_07_090000_create_landlord_operator_actions_table.php`
- commercialization and entitlement tests under `tests/Feature/Tenancy` and `tests/Feature/ShopifyCommercializationPagesTest.php`

## Current Implementation Snapshot

### What already exists

- A tenant entitlement foundation is implemented and centered on:
  - `tenant_access_profiles`
  - `tenant_access_addons`
  - `tenant_module_states`
- There is already a centralized resolver:
  - `App\Services\Tenancy\TenantModuleAccessResolver`
  - current inputs: plan + enabled add-ons + tenant module overrides
  - current outputs: `has_access`, `access_sources`, `setup_status`, `coming_soon`, `ui_state`, `upgrade_prompt_eligible`
- Commercial catalog/config foundations already exist in:
  - `config/commercial.php`
  - `landlord_catalog_entries`
  - `tenant_commercial_overrides`
  - `tenant_usage_counters`
- A landlord commercial console already exists at `/landlord/commercial` and can:
  - assign plans
  - enable/disable add-ons
  - set module overrides
  - store pricing/display/billing metadata overrides
  - run guarded Stripe customer/subscription-prep/live-sync actions
- Shopify embedded commercialization surfaces already exist:
  - `/shopify/app/start`
  - `/shopify/app/plans`
  - `/shopify/app/integrations`
- Locked/coming-soon UI states already exist in soft form for embedded/admin surfaces through:
  - `TenantModuleUi`
  - shared module-state Blade components
  - embedded navigation metadata

### What does not exist yet

- No tenant-facing App Store or marketplace routes, detail pages, or purchase/request flows
- No first-class capability catalog separate from module labels/config
- No hard shared entitlement decision service that returns reason codes across website + app + landlord
- No landlord drag-and-drop entitlement control
- No auditable commercial change log for plan/add-on/module override mutations
- No first-class billing status per tenant-module relationship
- No public pricing page that actually matches the modular plans mockup/PRD
- No runtime tenant-aware public website experience; public promo is generic/config-driven, not tenant-aware

## Canonical Entities Recommended

These should be the platform nouns going forward:

- `Plan`
  - a commercial tier that grants a default set of modules
- `Module`
  - a marketable product bundle shown on the public site, in the App Store, and in landlord tools
- `Capability`
  - a granular permission or feature inside a module
- `Entitlement`
  - the effective tenant access result after plan defaults, module purchases, overrides, rollout flags, and billing state are combined
- `Billing status`
  - the charging treatment for an enabled or visible module

Recommended billing status vocabulary:

- `included_in_plan`
- `add_on_paid`
- `add_on_comped`
- `custom_contract`
- `trial`
- `pending_billing`
- `unavailable`

## Repo-Reality Canonical Module List

### Safe to market as live or partially live now

- `reviews`
- `rewards`
- `birthdays`
- `wishlist`
- `lead_capture`
- `customers`
- `reporting`
- `campaigns` with caution
- `integrations` as placeholder-first, not as a live connector backend
- `uploads`
- `settings`

### Commercial add-ons already modeled in config

- `referrals`
- `sms`
- `additional_channels`
- `bulk_email_marketing`
- `future_niche_modules`

### Supporting platform surfaces, not real market modules

- `dashboard`
- `onboarding`
- `shopify`
- `email`
- `square`

### Roadmap or placeholder-only modules that should not be marketed as live

- `activity`
- `questions`
- `vip`
- `notifications`
- `quickbooks`
- `wix`
- `mobile_connection`
- `ai`

## Mapping: Current Flags/Structures To Marketed Modules

| Marketed concept | Current implementation reality |
| --- | --- |
| Starter / Growth / Pro | `tenant_access_profiles.plan_key` resolved by `TenantModuleAccessResolver` |
| Reviews | `reviews` module in `config/entitlements.php` |
| Rewards | `rewards` module; tenant labels can rename it via `TenantCommercialOverride` and template defaults |
| Birthdays | `birthdays` module |
| Wishlist | `wishlist` module |
| Referrals | `referrals` add-on and module key exist, but module is still `coming_soon` |
| SMS | `sms` add-on exists; entitlement maps to `sms` module |
| Bulk Email Marketing | commercial add-on `bulk_email_marketing`; entitlement currently maps only to generic `email` |
| Additional Channels | commercial add-on exists; entitlement currently maps to `shopify`, not a quantity-aware channel object |
| Future Niche Modules | commercial add-on exists; entitlement mapping conflicts between `config/commercial.php` and `config/entitlements.php` |
| Tenant template/vertical branding | `tenant_commercial_overrides.template_key` + `display_labels` + template catalog entries |
| Usage limits | `tenant_usage_counters` and config-defined metrics |
| Billing mapping | `tenant_commercial_overrides.billing_mapping` |

## Gap Analysis

### 1. Public marketing website

Current state:

- Public marketing entry is `PlatformProductPagesController::promo()`
- `TenantCommercialExperienceService::promoPayload()` only returns generic promo copy plus plan cards
- `resources/views/platform/promo.blade.php` still markets a broader operations platform with production/shipping/wholesale emphasis, not the modular plans experience

Gap:

- The live public site does not reflect the plans mockup or the modular product story
- No module comparison rows, add-on cards, App Store handoff, or locked-capability CTA system exists on the public site
- Public site is not tenant-aware at runtime and has no audience/segment rules beyond config content

Recommendation:

- Replace the current promo page with a modular pricing/catalog experience driven by the catalog service
- Keep runtime public-site behavior segment-aware by default
- Only make it tenant-aware when an authenticated tenant or signed handoff token is present

### 2. Tenant-facing App Store

Current state:

- No App Store routes, controllers, or views exist
- `/shopify/app/plans` is informational only
- `/shopify/app/integrations` is placeholder-first and read-only

Gap:

- No module list, module detail, eligibility reason, request-access flow, self-serve enablement flow, or contact-sales flow exists
- No app-store-specific status model exists beyond generic `ui_state`

Recommendation:

- Introduce a dedicated tenant App Store surface instead of stretching `/shopify/app/plans`
- Use the existing entitlement resolver as the starting point, but expand it to return eligibility reasons and CTA types

### 3. Locked capability UX

Current state:

- Embedded surfaces already expose `locked` and `coming_soon` soft states
- Upgrade prompts are supported for some modules through `upgrade_prompt_eligible`
- Rewards/customer/settings surfaces already respect some module lock behavior

Gap:

- There is no shared reason-code model such as:
  - `not_in_plan`
  - `addon_not_purchased`
  - `shopify_required`
  - `rollout_pending`
  - `approval_required`
- CTA routing is still mostly informational and page-specific
- Website, Backstage admin, Shopify embedded, and landlord surfaces do not all consume one shared locked-state contract

Recommendation:

- Expand resolver output to include:
  - `visibility_status`
  - `lock_reason`
  - `cta_type`
  - `cta_href`
  - `eligible_for_self_serve`
  - `channel_support`

### 4. Tenant-aware rendering

Current state:

- Tenant resolution is strongest in Shopify/storefront contexts via `TenantResolver`
- Embedded navigation and commercialization pages already consume tenant module states
- Public site has no tenant runtime context

Gap:

- Shared entitlement logic does not yet include:
  - billing status
  - channel support
  - dependency rules
  - region/rollout restrictions
  - environment visibility
- Entitlement checks are soft presentation checks in many places, not a universal decision service

Recommendation:

- Keep `TenantModuleAccessResolver` as the compatibility layer
- Introduce a richer `TenantEntitlementDecisionService` as the real source of truth for all surfaces

### 5. Shopify and non-Shopify support

Current state:

- `tenant_access_profiles.operating_mode` supports `shopify` and `direct`
- tests confirm `direct_starter` aliases resolve correctly
- real runtime tenant discovery still heavily depends on Shopify store context

Gap:

- Direct/non-Shopify mode is a stored commercial attribute, not a complete runtime experience
- No non-Shopify App Store acquisition or activation path exists
- Channel support is not first-class catalog metadata; it is inferred weakly from module classification or operating mode

Recommendation:

- Add explicit `channel_support` metadata per module:
  - `shopify_only`
  - `backstage_only`
  - `both`
- Treat direct mode as supported in entitlement resolution and landlord tooling first
- Add non-Shopify tenant entry points before claiming end-to-end direct support in marketing

### 6. Landlord admin capability management

Current state:

- Landlord commercial page uses forms to assign plans, set add-ons, and toggle module overrides
- there is a separate append-only `landlord_operator_actions` table, but commercial mutations are not using it
- landlord auth is still an interim `admin`-role/email-allowlist middleware

Gap:

- No drag/drop UI
- No audit record for commercial mutations
- No notes field per change
- No effective date support
- No per-module billing state control
- No capability-level override surface

Recommendation:

- Reuse `landlord_operator_actions` for all commercial mutations instead of adding a second audit table
- Add before/after snapshots and operator notes for plan/add-on/module changes
- Add effective dating only after the v1 entitlement/billing contract is stable

### 7. Billing model

Current state:

- Billing is intentionally guarded-first and configuration-only
- Stripe customer sync, subscription prep, and narrow live subscription sync exist
- commercial data stores provider mapping in `tenant_commercial_overrides.billing_mapping`
- there is no first-class per-module billing state row

Gap:

- Billing is stored as a tenant-level JSON mapping, not as module-level state
- Cannot cleanly represent:
  - enabled but comped
  - enabled with custom price
  - pending billing
  - trial window
  - source of entitlement
  - source of price

Recommendation:

- Keep Stripe guarded flow as-is for now
- Move billing treatment to a first-class tenant-module record before building self-serve App Store flows

## Mismatches And Risks

### Product truth mismatches already in repo

1. `config/commercial.php` and `config/entitlements.php` are not perfectly aligned.
   - `square` is included in entitlement plans but omitted from commercial plan module lists.
   - `future_niche_modules` maps to an empty module list in `config/commercial.php` but grants `ai` in `config/entitlements.php`.
   - `bulk_email_marketing` is a commercial add-on, but entitlement logic only unlocks the generic `email` module.
   - `additional_channels` is modeled as an add-on but currently just maps to `shopify`, which does not support quantity or channel-specific entitlement logic.

2. Public website copy is still broader operations messaging, not modular plans messaging.
   - Current promo page leads with production/shipping/wholesale, which is materially different from the plans mockup.

3. Several modules in the entitlement catalog are placeholders but could be over-marketed if copied directly into a public catalog.
   - `activity`, `questions`, `referrals`, `vip`, `notifications`, `quickbooks`, `wix`, `mobile_connection`, `ai`

4. Non-Shopify support is partial.
   - The data model acknowledges direct mode.
   - The runtime acquisition and activation experience does not.

5. Commercial changes are not yet audited through the existing append-only landlord action system.
   - This is a risk for landlord-side enable/disable/comp workflows.

### Architectural risks

- Overloading `tenant_commercial_overrides.billing_mapping` further will make App Store and landlord billing state harder to reason about
- Using `module_key` as both a UX label and a billing unit will break down for cases like `additional_channels`
- Building App Store logic directly into `TenantCommercialExperienceService` would mix public marketing, onboarding UX, integrations placeholder logic, and commercial activation rules too tightly
- Claiming self-serve add-ons before module-level billing state exists will create support debt immediately

## Minimum Schema Changes Recommended

### Reuse existing tables where possible

- Keep:
  - `tenant_access_profiles`
  - `tenant_access_addons`
  - `tenant_module_states`
  - `landlord_catalog_entries`
  - `tenant_commercial_overrides`
  - `tenant_usage_counters`
  - `landlord_operator_actions`

### Add first-class catalog records for modules and capabilities

Preferred minimum extension:

1. Extend `landlord_catalog_entries.entry_type` to support:
   - `module`
   - `capability`

2. Add normalized relationship tables:
   - `catalog_plan_modules`
   - `catalog_module_capabilities`
   - `catalog_module_dependencies`

This is cleaner than burying all relationships in JSON payloads and keeps search/filter/App Store queries straightforward.

### Add a tenant-module commercial state table

New table: `tenant_module_entitlements`

Suggested fields:

- `tenant_id`
- `module_key`
- `availability_status`
- `enabled_status`
- `billing_status`
- `price_override_cents`
- `currency`
- `entitlement_source`
- `price_source`
- `starts_at`
- `ends_at`
- `notes`
- `metadata`
- `created_by`
- `updated_by`

Purpose:

- separate access from billing treatment
- support comp/custom/trial/pending states
- give the App Store and landlord UI a stable record to mutate

### Add capability override support

New table: `tenant_capability_overrides`

Suggested fields:

- `tenant_id`
- `capability_key`
- `enabled_override`
- `source`
- `starts_at`
- `ends_at`
- `notes`
- `metadata`

Purpose:

- satisfy the PRD requirement for module-level and capability-level control without replacing module-level entitlements

### Audit strategy

- Do not add a new audit table
- Reuse `landlord_operator_actions` for all commercial changes

## Minimum Service Changes Recommended

### 1. Catalog service

Add `App\Services\Tenancy\CatalogService` or split from `LandlordCommercialConfigService`.

Responsibilities:

- resolve plans/modules/capabilities
- resolve dependencies and channel support
- provide public-site and App Store catalog payloads
- use config as fallback/bootstrap until DB ownership is complete

### 2. Entitlement decision service

Add `App\Services\Tenancy\TenantEntitlementDecisionService`.

Responsibilities:

- compute effective module visibility and access
- merge:
  - plan defaults
  - add-ons
  - tenant-module commercial state
  - capability overrides
  - module state/setup status
  - rollout and channel rules
- return explicit reason codes and CTA routing

`TenantModuleAccessResolver` can remain as a compatibility adapter over this richer service.

### 3. App Store service

Add `App\Services\Tenancy\TenantAppStoreService`.

Responsibilities:

- list visible modules for a tenant
- produce active/available/locked/unavailable/request-access/contact-sales CTA states
- build module detail payloads
- power website-to-app handoff

### 4. Commercial mutation audit service

Extend the existing landlord audit path so commercial actions record:

- actor
- tenant
- action type
- note
- before state
- after state
- result

### 5. Locked-state presenter

Extend `TenantModuleUi` or add `LockedCapabilityPresenter`.

Responsibilities:

- turn entitlement decisions into shared website/app/admin locked-state cards
- guarantee consistent CTA language and lock reasons

## File-Level Implementation Suggestions

### Catalog and entitlement core

- `config/entitlements.php`
  - keep only as fallback/bootstrap, not as the long-term primary catalog
- `config/commercial.php`
  - reconcile plan/add-on/module mappings with entitlement reality
- `app/Services/Tenancy/TenantModuleAccessResolver.php`
  - refactor into adapter over new entitlement decision service
- `app/Services/Tenancy/LandlordCommercialConfigService.php`
  - split catalog management, billing readiness, and tenant commercial mutations into smaller services

### Public pricing site

- `app/Http/Controllers/PlatformProductPagesController.php`
  - switch from generic promo payload to catalog-backed pricing/app-store CTA payload
- `resources/views/platform/promo.blade.php`
  - replace operations-heavy promo with modular plans and add-on experience
- `config/product_surfaces.php`
  - move website copy toward plan/module marketing blocks and CTA rules

### Tenant App Store

- `routes/web.php`
  - add routes such as:
    - `/shopify/app/store`
    - `/shopify/app/store/{module}`
    - `/backstage/store`
    - `/backstage/store/{module}`
- `app/Http/Controllers/ShopifyEmbeddedAppController.php`
  - add App Store endpoints or extract to dedicated controller
- new views:
  - `resources/views/shopify/app-store/index.blade.php`
  - `resources/views/shopify/app-store/show.blade.php`

### Locked capability UX

- `resources/views/components/tenancy/module-upgrade-prompt.blade.php`
  - expand from generic upgrade prompt to next-step CTA surface
- `resources/views/components/shopify/customers-layout.blade.php`
- `resources/views/shopify/rewards-layout.blade.php`
- `resources/views/shopify/settings.blade.php`
  - switch to reason-code-driven locked states

### Landlord controls

- `app/Http/Controllers/Landlord/LandlordCommercialConfigurationController.php`
  - add notes/effective date handling
  - record audit entries for all commercial mutations
- `resources/views/landlord/commercial/index.blade.php`
  - introduce module library + tenant entitlement assignment UX
  - later add drag/drop affordances on top of auditable POST endpoints
- new or extended service:
  - `App\Services\Tenancy\LandlordCommercialMutationService`

### Tests

- extend:
  - `tests/Feature/Tenancy/TenantModuleAccessResolverTest.php`
  - `tests/Feature/ShopifyCommercializationPagesTest.php`
  - `tests/Feature/Tenancy/LandlordCommercialConfigurationTest.php`
- add:
  - App Store eligibility and CTA tests
  - landlord commercial audit tests
  - non-Shopify module activation path tests

## Recommended Phased Sequence

### Phase 1. Reconcile product truth

- Reconcile `config/entitlements.php`, `config/commercial.php`, and `config/product_surfaces.php`
- Mark each module as:
  - live
  - partial
  - placeholder
  - roadmap
- Decide what the public website is allowed to market now

### Phase 2. Normalize catalog and entitlement contracts

- add first-class module/capability catalog records
- add `tenant_module_entitlements`
- add `tenant_capability_overrides`
- introduce entitlement decision service with reason codes

### Phase 3. Audit-safe landlord commercial mutations

- route all plan/add-on/module billing changes through append-only audit logging
- add notes and before/after snapshots
- keep billing lifecycle guarded and manual

### Phase 4. Public pricing page

- replace generic promo page with modular pricing and add-on page
- source content from catalog/config
- wire CTA targets into contact/App Store paths

### Phase 5. Tenant App Store

- module list
- module detail
- self-serve/request/contact-sales CTA handling
- website-to-app handoff

### Phase 6. Cross-surface locked-state and gating

- enforce one entitlement decision contract across:
  - public site
  - Backstage admin
  - Shopify embedded app
  - landlord tools

## Practical Recommendation

The fastest safe path is not to invent a brand-new commerce system. The repo already has the beginnings of one:

- plan/add-on catalogs
- tenant access profiles
- module state resolver
- landlord commercial config
- guarded Stripe preparation

The right move is to harden those into:

1. a real catalog layer
2. a richer entitlement decision service
3. auditable landlord mutations
4. a tenant App Store built on top of that shared source of truth

Do not ship the public modular pricing site before reconciling the catalog mismatches above, or marketing will promise modules the runtime cannot truthfully deliver.
