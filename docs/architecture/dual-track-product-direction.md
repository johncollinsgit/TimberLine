# Dual-Track Product Direction (Guardrail)

Date: 2026-03-25  
Status: Active direction for future implementation planning

## Purpose
Keep product execution aligned to two simultaneous goals without breaking the currently working Shopify proof-of-concept.

This document is directional. It does not claim planned modules are already implemented.

## Non-Negotiable Preservation Rules
- Do not break or weaken working Shopify storefront proxy behavior.
- Do not break or weaken working embedded Shopify admin flows.
- Do not replace or bypass canonical identity (`marketing_profiles` + link tables + sync pipeline).
- Do not build a parallel rewards/reviews/wishlist identity path.
- Do not claim tenant safety where enforcement is not implemented.

Reference runtime surfaces to preserve:
- `routes/web.php` (`/apps/forestry/*`, `/shopify/marketing/v1/*`, `/shopify/app/*`, `/shopify/embedded/*`)
- `App\Http\Controllers\Marketing\MarketingShopifyIntegrationController`
- `App\Http\Controllers\ShopifyEmbeddedRewardsController`
- `App\Http\Controllers\ShopifyEmbeddedCustomersController`
- `App\Http\Controllers\ShopifyEmbeddedSettingsController`

## Current State vs Desired Direction

Current implemented reality:
- Shopify storefront widgets + proxy contracts are working and first-party.
- Embedded admin has live dashboard/customers/settings/rewards flows.
- Candle Cash, birthdays, reviews, and wishlist are implemented as first-party systems.
- Backstage/internal ops remains deeply coupled to current Shopify and operational workflows.
- Tenant scaffolding exists but core domain isolation is incomplete.
- Several embedded tabs are placeholder/thin (`referrals`, `vip`, `notifications`, `activity`, `questions`).
- Storefront runtime is largely delivered via separate theme sidecar plus backend endpoints.

Desired direction:
- Preserve Shopify Product Track as the flagship commercial wedge.
- Expand into broader business systems as a second track, not a rewrite.
- Introduce entitlement-aware module boundaries (plan/add-ons/setup state) before heavy feature expansion.
- Reuse canonical backend models/services for both tracks.

## Current Release Checkpoint (Implemented)

The following surfaces are now implemented in this branch:
- Embedded product shell:
  - `/shopify/app` (overview/dashboard)
  - `/shopify/app/start`
  - `/shopify/app/plans`
  - `/shopify/app/integrations`
- Public product surfaces:
  - `/platform/promo`
  - `/platform/contact`
- Diagnostics/operator surfaces:
  - customer email timeline provider-context filters + CSV export parity
  - birthday analytics/reporting/export/comparison
  - campaign delivery diagnostics/provider-context summaries

Integrations checkpoint:
- Placeholder-first integrations cards are live in embedded UI.
- Each card has setup detail drawer content.
- Card/drawer state is entitlement-aware and includes read-only status-registry metadata.
- This is intentionally non-operational for live connectors:
  - no OAuth/connect flows
  - no external sync jobs/webhooks
  - no outbound connector writes

Commercialization checkpoint:
- Product shell + module-state visibility + informational upgrade paths are implemented.
- Billing/checkout activation remains intentionally out of scope in this release.

Recommended next step after push:
- deploy and verify shell navigation + diagnostics filter/export parity + integrations drawer/status messaging in production before adding new capability scope.

## Track Definitions

### Track A: Shopify Product Track (Flagship)
Target:
- Strong Shopify merchant value and App Store viability.

Must remain first-class:
- app-proxy storefront contracts
- embedded admin operations and reporting
- identity/rewards/reviews/wishlist lifecycle tied to canonical profile system

### Track B: Broader Business Systems Track (Expansion)
Target:
- Direct onboarding for non-Shopify businesses over time.
- Connector-based data intake (or manual import fallback).
- Tiered access and purchasable add-ons.

Constraint:
- Reuse shared core primitives where justified by real use cases.
- Do not erase Shopify-specific value to force generic abstractions.

## Feature Classification Rules (Required)
Every new major feature must declare exactly one primary classification:
- Shopify-only
- Shared core
- Integration layer
- Purchasable add-on
- Internal/admin only

Required metadata for each major feature proposal:
1. Classification
2. Tenant scope (tenant-scoped / global-by-design / mixed with bridge rules)
3. Entitlement/access level (plan tier, add-on dependency, default state)
4. Canonical models/services reused
5. Shopify hooks affected (proxy routes, embedded pages, webhooks, theme runtime)
6. Setup/onboarding implications
7. Shopify behavior preservation notes
8. Non-Shopify applicability target (now / later / never)

## Shared Core vs Integration vs Internal Admin Boundaries

### Shared core (canonical platform)
Examples:
- `marketing_profiles` identity
- `customer_external_profiles`, `marketing_profile_links`
- customer records + canonical profile lifecycle
- reviews and wishlist canonical records
- events/activity foundations
- uploads/import foundations
- entitlement-aware module state layer

### Integration layer
Examples:
- Shopify
- QuickBooks
- Wix
- Square
- Email provider integrations
- SMS provider integrations
- future mobile app connection

Rules:
- Integrations must feed canonical core; they do not become identity truth.
- Manual/import fallback is required when direct sync is missing.

### Internal/admin-only
Examples:
- inventory
- pouring workflows
- markets planning
- wiki/internal operations tools

Rule:
- Internal tooling can stay operations-specific when not part of merchant-facing product promises.

## Entitlement-First Product Direction
Future modules should be designed around:
- plan levels
- add-ons
- module enabled/disabled state
- setup-complete/setup-incomplete state
- upgrade prompts
- tenant-aware access checks

Current expectation:
- Define architecture and guardrails now.
- Do not require full billing implementation before introducing entitlement-aware structure.

## Admin Navigation and Module Direction
Recommended top-level navigation for embedded/admin product surfaces:

1. Overview
- Status: `REAL`
- Today: dashboard entrypoints exist (`/shopify/app`, embedded dashboard data services)

2. Customers
- Status: `REAL (tenant hardening pending)`
- Today: embedded customers + detail/action flows exist

3. Rewards
- Status: `REAL + PARTIAL`
- Today: Candle Cash and birthday rewards management exists
- Gap: referral/vip/notification sections are mostly placeholder

4. Reviews
- Status: `PARTIAL`
- Today: storefront review flows exist; internal review moderation exists in Backstage
- Gap: embedded review moderation/reporting parity is incomplete

5. Wishlist
- Status: `PARTIAL`
- Today: storefront + backend wishlist mechanics exist
- Gap: embedded admin reporting/configuration parity is incomplete

6. Birthdays / Lifecycle
- Status: `REAL + PARTIAL`
- Today: birthday reward and analytics flows exist
- Gap: full tenant isolation and unified lifecycle admin clarity remain in progress

7. Campaigns
- Status: `PARTIAL`
- Today: campaign domain exists in backend
- Gap: embedded-first UX and entitlement-aware packaging are incomplete

8. Automations
- Status: `FUTURE`
- Direction: promote lifecycle/campaign automations as explicit module once governance is defined

9. Reporting
- Status: `PARTIAL`
- Today: multiple reporting surfaces exist
- Gap: unified module-based reporting parity across rewards/reviews/wishlist/referrals

10. Integrations
- Status: `FUTURE (placeholder-first)`
- Direction: safe connection cards + status + manual fallback links

11. Uploads / Imports
- Status: `PARTIAL`
- Today: specific import commands exist
- Gap: tenant-facing guided UI and reusable ingestion framework

12. Plans & Add-ons
- Status: `FUTURE`
- Direction: entitlement visibility, module lock state, upgrade paths

13. Onboarding / Setup Guide
- Status: `FUTURE`
- Direction: post-login checklist and setup status

14. Settings
- Status: `REAL + PARTIAL`
- Today: embedded settings routes for email/provider management exist
- Gap: broader module settings parity

15. Future AI / Intelligence
- Status: `FUTURE`
- Direction: premium intelligence layer on structured data foundations

## Planned Product Surfaces (Forward-Looking, Honest)

### 1) Promo / Marketing Page
Goal:
- Explain value proposition, modules, and tiered plans.

Requirements:
- Headline + value story
- feature explanations by module
- pricing section with configurable values (not hardcoded copy constants)
- CTA paths:
  - Shopify install
  - demo request
  - contact/sales

Implementation constraint:
- keep isolated from existing Shopify proof-of-concept runtime paths.

### 2) Post-Login Setup Guide
Goal:
- first-run guidance for activation and operator clarity.

Should show:
- setup checklist
- current plan/access level
- module status by enablement/setup state
- connector prompts
- import/upload prompts
- recommended next actions
- upgrade prompts for locked modules

### 3) Integrations Area (Placeholder-First)
Connection cards to include:
- Shopify
- QuickBooks
- Wix
- Square
- Email
- SMS
- Future mobile app connection

Placeholder states must be explicit:
- connected
- not connected
- setup required
- planned/not available yet

Fallback expectation:
- manual upload/import path for not-yet-connected providers.

### 4) Non-Shopify Onboarding
Direction:
- tenant creation
- plan selection
- setup wizard
- upload/import starter flows
- connector prompts
- canonical profile pipeline usage from day one

### 5) Future Mobile Connection
Direction:
- treat mobile as another integration surface into shared core, not a second backend.

### 6) Future AI Premium Tier
Direction:
- AI is a high-tier intelligence layer on top of structured data and workflows.
- AI is not a substitute for clean identity, entitlement, reporting, and workflow foundations.

## Documentation Truthfulness Requirements
- If code and docs disagree, document the disagreement and treat code as current reality.
- Mark stale architecture assumptions explicitly (for example, historical extension-directory assumptions not present in this repo).
- Never present placeholders as completed modules.

## Phased Implementation Roadmap (Direction)

### Phase 1: Docs and guardrails
Goal:
- stop product drift and overclaims.
Core outputs:
- update repo guardrail docs
- record current reality warnings
- define classification + entitlement metadata requirements

### Phase 2: Entitlement/access architecture
Goal:
- introduce module gating primitives without forcing full billing implementation.
Core outputs:
- entitlement state model and access checks
- setup state model for module readiness
- implementation reference: `docs/architecture/tenant-entitlements-foundation.md`

### Phase 3: Promo page planning
Goal:
- define externally legible product narrative and conversion paths.
Core outputs:
- page IA/content contract
- configurable pricing source design

Current implemented reference (Phase 3 foundation):
- Public promo + contact surfaces:
  - `/platform/promo`
  - `/platform/contact`
- Embedded commercialization/onboarding surfaces:
  - `/shopify/app/start`
  - `/shopify/app/plans`
- Centralized copy/pricing/CTA content source:
  - `config/product_surfaces.php`
- Payload composition service:
  - `App\Services\Tenancy\TenantCommercialExperienceService`
- Module-state UI reuse requirement:
  - `App\Support\Tenancy\TenantModuleUi`
  - `resources/views/components/tenancy/*`

### Phase 4: Post-login guide planning
Goal:
- reduce setup friction and clarify module readiness.
Core outputs:
- setup checklist contract
- upgrade prompt behavior spec

### Phase 5: Integrations placeholder planning
Goal:
- establish safe, honest integration surface.
Core outputs:
- integration card model
- placeholder state rules
- manual/import fallback contract

Current implemented reference (placeholder-first):
- Embedded route: `/shopify/app/integrations`
- Payload source: `App\Services\Tenancy\TenantCommercialExperienceService::integrationsPayload`
- Content source: `config/product_surfaces.php` -> `integrations`
- State model exposed in UI:
  - `connected`
  - `setup_needed`
  - `locked`
  - `coming_soon`
- Safety boundary:
  - no real connector sync implementation
  - no external writes/jobs/webhooks from integrations surface

### Phase 6: Admin/navigation direction
Goal:
- align embedded + future direct-admin module structure.
Core outputs:
- navigation taxonomy
- module status map (real/partial/future)
- parity gap inventory

### Phase 7: Non-Shopify onboarding direction
Goal:
- define onboarding path for businesses without existing ecommerce stack.
Core outputs:
- tenant creation + plan selection + setup wizard direction
- canonical model reuse rules

### Phase 8: Future connectors / mobile / AI
Goal:
- sequence advanced expansion after foundations are stable.
Core outputs:
- connector roadmap
- mobile connection boundary
- AI premium-layer readiness criteria
