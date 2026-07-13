# Everbranch Module App Store Readiness

Status: PR 25 blueprint-driven display alignment complete.

## QuickBooks, Documents, And Estimator Readiness (2026-07-13)

- `quickbooks` is a shared beta Branch. OAuth, reports, settings, manual refresh, encrypted run history, hourly opt-in synchronization, and weekly reconciliation are owner/admin-only and tenant-scoped. It is read-only; no payments, accounting writes, CDC, or webhooks are enabled.
- `documents` is a shared beta Branch with private authenticated downloads, team/owner visibility, searchable tags and extracted text for text/CSV files, multi-job links, audit events, QuickBooks attachment copies, and an iOS multi-photo picker. Temporary Intuit URLs and iCloud shared albums are never canonical storage.
- `estimator` is a default-disabled internal beta dependent on Field Service. Candidate generation requires repeated recent invoice descriptions and owner review. Approved items and draft line snapshots are persistent; QuickBooks write-back is absent.
- Financial mobile sections are role-filtered server-side. Managers and team members can see operational Reporting and upcoming jobs but cannot receive receivables, P&L, labor, owner compensation, price-book administration, or owner-only files.
- Collins is the first rollout tenant. Do not enable another tenant's QuickBooks schedule from onboarding interest, plan defaults, or a catalog request.

## Mission

Make the module system understandable, safe, and productizable before adding new modules.

## Current State

- `config/module_catalog.php` is the canonical source of truth for modules, plans, capabilities, visibility, billing mode, and CTA routing.
- `TenantModuleCatalogService` filters tenant/public discovery payloads.
- `TenantModuleAccessResolver` resolves entitlement and access states.
- App Store visibility fails closed through explicit app store visibility, market state, and live/beta status checks.
- Tenant module access requests exist for known catalog modules.
- PR 7 added display-only product metadata helpers to `TenantModuleCatalogService` and renders safe module context on tenant, Shopify embedded, and landlord commercial module surfaces.
- PR 25 added `TenantBlueprintModuleRecommendationService` so tenant blueprints can drive display-only recommended/requested/planned/future/not-active-yet module guidance.
- Tenant Module Store, landlord tenant detail, and tenant `/start` now use blueprint-aware module recommendation copy without changing module entitlements or activation behavior.
- Pricing labels are display-only until billing activation work happens. Checkout is not active from module cards.

## Module Definition

A module is a catalog entry with:
- Stable key.
- Display metadata.
- Lifecycle/status.
- Visibility flags.
- Capabilities.
- Plan/add-on relationships.
- Tenant entitlement/access behavior.
- CTA/request routing.

## Product Metadata Standard

`config/module_catalog.php` remains the canonical source of truth. Product-grade module displays should use catalog fields directly when present and service-derived defaults when absent.

Display-only metadata supported by `TenantModuleCatalogService`:
- `category` / `category_label`: product grouping such as customer operations, integrations, Shopify growth, growth add-on, operator tools, analytics, customer retention, or future mobile companion.
- `short_description`: concise card copy. Falls back to `description`.
- `long_description`: detail-page copy. Falls back to `description`.
- `lifecycle` / `lifecycle_label`: normalized as `draft`, `internal`, `beta`, `safe_to_market`, `live`, or `deprecated`.
- `setup_effort` / `setup_effort_label`: examples include no setup, light setup, standard setup, Everbranch-assisted setup, and custom setup review.
- `required_integrations` / `required_integrations_label`: Shopify, Square, CSV/manual import, or no required integration.
- `mobile_relevance` / `mobile_relevance_label`: not mobile-specific, mobile-ready when entitled, future mobile companion candidate, or mobile operator candidate.
- `pricing_impact_label`: included, add-on label only, custom pricing discussion, or no pricing action.
- `entitlement_requirement_label`: included plan, add-on/access request, or Everbranch review.
- `tenant_visibility_label`: tenant-visible only when the fail-closed surface checks pass.
- `blueprint_display_state` / `blueprint_display_state_label`: display-only tenant blueprint context such as active, available, recommended, requested, planned, future, requires setup, unavailable, or not active yet.
- `blueprint_recommendation_reason`: explanation of why a module or module family appears for the tenant blueprint.

The service may derive defaults from existing `classification`, `channels`, `billing_mode`, `included_in_plans`, `status`, `market_state`, and `visibility` fields. This avoids a large database migration while keeping tenant-facing copy consistent.

Blueprint module recommendation payloads may also include module families that are not canonical `module_catalog` entries yet, such as jobs, tasks, assignments, photos, materials, documents, job costing, or mobile field capture. These rows are not modules, are not installable, and exist only to make setup intent coherent for landlords and tenants.

## Surface Rules

- Tenant App Store and Shopify embedded App Store show only modules that pass `isSafeForSurface()`: explicit surface visibility, `SAFE_TO_MARKET`, and `live` or `beta`.
- Landlord/commercial views may show internal, hidden, beta, roadmap, and unsafe modules for operator context.
- Module cards may show pricing impact labels, but those labels do not activate checkout or paid module purchasing.
- Module interests captured during setup are separate from installed modules, entitlements, and module access requests.
- Blueprint template recommendations and work-management intent are separate from installed modules, entitlements, and module access requests.
- Non-Shopify tenants should not receive Shopify-only module assumptions unless their blueprint/operating mode asks for Shopify.
- Custom module requests are separate from known-module access requests. They capture discovery/intake for possible future work and do not create modules, install modules, or change entitlements.
- Modules can eventually have web, Shopify, landlord, tenant, and future mobile surfaces, but every surface must remain entitlement-checked and tenant-scoped.
- Future mobile modules must be API-driven and entitlement-checked. The existing Modern Forestry mobile catalog API remains tenant-specific.
- Planned job/photo/quote/invoice/team communication/client communication capabilities are future module candidates, not live PR 7 features.

## Proposed Lifecycle

- `draft`: not visible, not installable.
- `internal`: operator-only, never public.
- `beta`: visible only when explicitly safe and allowed for the surface.
- `safe_to_market`: ready for App Store discovery if visibility allows it.
- `live`: available for enabled/productized tenants.
- `deprecated`: hidden from new discovery, retained for existing tenants where needed.

## Visibility And Entitlement Matrix

| Module state | Public site | Tenant App Store | Tenant usage | Landlord |
| --- | --- | --- | --- | --- |
| draft | hidden | hidden | blocked | visible |
| internal | hidden | hidden | blocked unless operator | visible |
| roadmap | hidden | hidden | blocked | visible |
| beta + safe | optional | visible if app_store true | entitlement required | visible |
| live + safe | visible if public_site true | visible if app_store true | entitlement required | visible |
| deprecated | hidden | hidden for new installs | existing only | visible |

## Gaps

- Module detail pages are limited.
- Setup complexity, required integrations, pricing impact, and mobile relevance now have service-level display helpers, but many individual catalog entries still rely on derived defaults rather than hand-authored product copy.
- Custom module requests now have a separate intake/triage workflow, but conversion into reusable modules remains manual and future.
- Blueprint-driven recommendation families are display-only; real implementation of jobs, tasks, assignments, photos, materials, invoices, or messaging modules remains future work.
- Landlord module management exposes read-only visibility context, but richer management/reporting remains future work.

## Pass Criteria

- App Store hides internal, roadmap, disabled, placeholder, and unsafe modules.
- Module access checks use canonical services.
- Tenant install/request actions are tenant-scoped server-side.
- New module metadata is added to the catalog or derived through `TenantModuleCatalogService`, not scattered ad hoc.
- Blueprint-driven recommendation logic is centralized in `TenantBlueprintModuleRecommendationService`, not scattered through Blade views.
- Pricing and mobile labels are explicitly display-only until billing/mobile activation work is approved.

## Fail Criteria

- Roadmap or internal modules appear in public or tenant App Store payloads.
- UI visibility is treated as entitlement.
- Blueprint recommendation rows are treated as real module installs, entitlements, billing events, import execution, upload flows, messaging, notifications, or mobile APIs.

## Recommended Next PR

Add landlord summary/filtering for tenants with future-module demand only after operators have real review volume; do not build the future modules until a separate implementation PR is approved.
