# SYSTEM SNAPSHOT

## Project Overview
- Fire Forge Tech is the platform owner.
- The Forestry Studio is the flagship tenant, not the architectural center of the platform.
- Flagship customization is allowed, but platform architecture must remain reusable for future tenants.
- This is a Laravel + Shopify integration project
- Laravel backend is the canonical system of truth
- Shopify storefront communicates with Laravel primarily through signed app-proxy endpoints
- Theme repo is separate from backend repo
- Architecture is in single-tenant-to-multi-tenant transition; tenant scaffolding exists in-code and conversion is in progress
- Dual-track product direction is documented in:
  - `docs/architecture/dual-track-product-direction.md`
  - `docs/architecture/operational-multi-tenant-direction.md`
  - `docs/architecture/tenant-entitlements-foundation.md`

## Current Release State (2026-03-27)

Quick-scan summary for future agents:
- Product shell/commercialization surfaces are implemented:
  - embedded: `/shopify/app`, `/shopify/app/start`, `/shopify/app/plans`, `/shopify/app/integrations`
  - public: `/platform/promo`, `/platform/contact`
  - landlord commercial: `/landlord/commercial`
- Diagnostics/operator surfaces are implemented and actively used:
  - customer email timeline provider-context filters + CSV export parity
  - birthday analytics/reporting/export/comparison
  - campaign delivery diagnostics/provider-context summaries
- Integrations surface is intentionally placeholder-first:
  - per-connector setup drawer
  - read-only deterministic status registry metadata
  - entitlement-aware card states (`connected`, `setup_needed`, `locked`, `coming_soon`)
  - no real connector sync/OAuth/jobs/webhooks/API writes from this surface
- Commercialization direction is active with three guarded landlord-only Stripe actions:
  - customer-reference sync
  - subscription-prep metadata sync
  - live subscription reference create/sync (explicit landlord trigger; disabled-by-default config flag)
  - guarded Stripe preflight requires HTTPS for remote `services.stripe.api_base` endpoints (HTTP is loopback-only for local testing on `localhost`/`127.0.0.1`/`::1`)
- Staging validation support for those guarded actions is explicit:
  - required run order + pass/fail matrix: `docs/operations/staging-commercial-uat-runbook.md`
  - required evidence template: `docs/operations/staging-commercial-uat-evidence-template.md`
- Latest repo-side validation status (2026-03-28):
  - real staging operator evidence is not attached by this pass
  - blocked-run record: `docs/operations/staging-commercial-uat-blocked-run-2026-03-28.md`
  - staging Stripe sandbox pricing follow-up (2026-03-28): runtime Stripe auth succeeds and all required recurring lookup-key prices are now present/verified (`tier_starter_monthly`, `tier_growth_monthly`, `tier_pro_monthly`, `addon_referrals_monthly`, `addon_sms_monthly`, `addon_additional_channels_monthly`, `addon_bulk_email_marketing_monthly`, `addon_future_niche_modules_monthly`); staging is runtime-ready for the guarded operator evidence run, but real evidence is still blocked until an authenticated landlord operator session executes the 3-step sequence
  - follow-up commit `9c2502c` (CI assertion alignment after dotenv bootstrap fix) is pushed to `main`
  - local CI-equivalent rerun for this pass:
    - `php -d memory_limit=512M ./vendor/bin/pest` => `845 passed`, `0 failed`
  - GitHub Actions results for commit `9c2502c`:
    - `linter`: `success`
    - `tests`: `success` (`ci (8.4)` and `ci (8.5)` passed)
    - `Deploy Production`: initial `failure` on push, then `success` on rerun `23687500356` after deploy-ops unblock
  - Deploy-ops unblock completed in GitHub `production` environment:
    - configured `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT`, `DEPLOY_PATH`, `DEPLOY_SSH_KEY`
    - corrected server checkout branch at `DEPLOY_PATH` to `main` so workflow `git checkout main` succeeds
  - latest known production rollout for commit `dbf0762` was manual before deploy automation was restored:
    - `ssh forge@129.212.138.111 'bash /home/forge/deploy_backstage.sh'`
    - `curl -sS https://backstage.theforestrystudio.com/up` => `Application up.`
  - manual SSH deploy remains available as fallback, but is no longer the primary required path while deploy secrets stay configured
- Checkout and broad subscription lifecycle mutation flows remain intentionally disabled.
- Public commercial model has been normalized:
  - tiers: `Starter`, `Growth`, `Pro`
  - add-ons: `referrals`, `sms`, `additional_channels`, `bulk_email_marketing`, `future_niche_modules`
  - template library: `Candle`, `Law`, `Landscaping`, `Apparel`, `Generic`
- Tenant-aware direction is established in shell + diagnostics, while full domain tenant isolation remains in progress.
- Landlord/admin Phase 1 host foundation is implemented:
  - global pre-auth host context middleware resolves landlord/tenant/none host mode
  - landlord host target in production is `app.forestrybackstage.com` (configurable)
  - tenant host target in production is `<slug>.forestrybackstage.com`
  - unknown hosts resolve safely to `none` (no first-tenant fallback)
  - landlord routes are host-locked: `/landlord`, `/landlord/commercial`, `/landlord/tenants`, `/landlord/tenants/{tenant}`
  - landlord directory remains read-only while commercial writes are constrained to safe configuration scope
  - landlord route auth uses dedicated `landlord.operator` middleware (default `admin` role; optional email allowlist) instead of tenant-facing role groups
- Production DNS/TLS verification completed on 2026-03-27:
  - wildcard cert for `*.forestrybackstage.com` is active
  - ACME `_acme-challenge` delegation is CNAME-based and must stay Cloudflare `DNS only`
  - wildcard tenant DNS (`*`) resolves and tenant HTTPS reaches Laravel (`/login` redirects observed)
- Immediate next step is deploy + verify this release, not broad new feature expansion.
- Staging operator UAT runbook for commercialization assignment hardening:
  - `docs/operations/staging-commercial-uat-runbook.md`
- Multi-tenant completion estimate: `45%`.

## Strict Near-Term Execution Order (As of 2026-03-27)
1. Candle Cash verified live and trustworthy for Modern Forestry.
2. Email reliability fixed for launch-critical reward/customer workflows.
3. Only then broader platform expansion.

Do not start yet:
- broad multi-tenant refactors
- Shopify App Store packaging
- speculative AI automation work

## Product Architecture References (2026-03-27 Pass)
- `docs/architecture/business-concept-and-product-architecture.md`
- `docs/architecture/multi-tenant-inventory-2026-03-27.md`
- `docs/architecture/tenant-entitlements-foundation.md`

## Current Shopify Proof-of-Concept Reality
- Working now (proof-of-concept surfaces that must not be weakened):
  - Storefront signed proxy contracts (`/apps/forestry/*`, `/shopify/marketing/v1/*`) via `routes/web.php` + `MarketingShopifyIntegrationController`
  - Embedded Shopify app flows for dashboard/customers/settings/rewards via:
    - `ShopifyEmbeddedAppController`
    - `ShopifyEmbeddedCustomersController`
    - `ShopifyEmbeddedRewardsController`
    - `ShopifyEmbeddedSettingsController`
  - First-party feature systems in active use:
    - Candle Cash
    - birthdays
    - native reviews/comments
    - wishlist
    - canonical marketing identity linking

- Partial/incomplete reality (do not overclaim):
  - Tenant safety remains incomplete across core domains and reporting.
  - Embedded dashboard/customers still require hard tenant-scope auditing in query/service paths.
  - Candle Cash and birthday data models still rely heavily on `marketing_profile_id` joins rather than direct tenant isolation on all domain tables.
  - Multiple embedded tabs are currently placeholder/thin surfaces (`referrals`, `vip`, `notifications`, `activity`, `questions`) and should not be documented as complete modules.

- Documentation truthfulness rule:
  - If documentation claims conflict with implemented routes/controllers/services, trust implementation reality and mark docs as stale.
  - Historical references to packaged Shopify extension-directory architecture should be treated as stale when contradicted by code.
  - Current storefront runtime behavior is heavily delivered through the separate Shopify theme repo sidecar (`modernforestry-live-theme`) plus backend proxy contracts.

## Local Repo Locations
### Backend repo
- Local path: `/Users/johncollins/Code/myapp`
- GitHub remote: `git@github.com:johncollinsgit/TimberLine.git`

### Theme repo
- Local path: `/Users/johncollins/projects/modernforestry-live-theme`

## Deployment Reality
- Production deploy path is GitHub Actions on push to `main`
- Deploy workflow also supports manual `workflow_dispatch`
- Deploy job fail-fast checks `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT`, `DEPLOY_PATH`, and `DEPLOY_SSH_KEY`; missing values will block deploy.
- Current state: those secrets are configured and deploy automation is passing (verified on rerun `23687500356`).
- Manual SSH deploy remains available fallback if Actions deploy is unavailable.
- Server-side deploy pulls from GitHub, not from local branches
- If code is not committed and pushed to `main`, it is not live
- Practical rule: local changes on feature/agent branches are not deployed until merged/pushed to `main`

## Git / Branching Reality
- Codex may be working in dirty trees with unrelated modified/untracked files
- Before making changes:
  1. audit git status
  2. avoid touching unrelated files
  3. make tightly scoped edits
- Always distinguish:
  - local working tree state
  - current branch
  - origin/main deployed state

## Environment Model
- Document env variable names and purpose only
- Never store secret values in this file

### Important backend env concepts
- `APP_ENV` -> environment name
- `APP_URL` -> canonical backend URL
- Shopify app/proxy-related envs may control signed storefront behavior
- Marketing/Growave envs may still exist historically, but Growave API should not be assumed active
- Deployment/runtime verification should always inspect actual server env before assuming behavior

## Authentication / Access Model
### Browser session / login-based access
Use browser-authenticated flows when the user is interacting with:
- Shopify storefront customer session behavior
- Backstage/admin UI
- any flow that depends on cookies, customer login, or admin session state

### Signed app-proxy access
Use signed app-proxy flows for storefront-native integrations such as:
- `/apps/forestry/...`
- `/shopify/marketing/v1/...`
These must pass the existing storefront verification middleware and should reuse the established request-signing pattern.

### OAuth / token-based integrations
OAuth/token-based integrations are only for systems that explicitly require them.
Do not assume OAuth is the right solution for internal/native features.
Historically, third-party systems like Growave used API/OAuth-style access, but current first-party replacements should prefer canonical local data when available.

## Identity Model
- All customer identity flows through `marketing_profiles`
- External/customer identity linkage uses:
  - `customer_external_profiles`
  - `marketing_profile_links`
- No parallel identity systems are allowed
- New features must reuse this identity model, not invent a second one

## Canonical First-Party Systems
### Reviews
- Native review system is canonical
- Core service: `ProductReviewService`
- Storefront/controller integration flows through `MarketingShopifyIntegrationController`

### Rewards
- Native rewards system is Candle Cash
- Do not build parallel loyalty ledgers

### Wishlist
- Canonical table: `marketing_profile_wishlist_items`
- Core service: `MarketingWishlistService`
- CSV importer command: `marketing:import-wishlist-csv`
- Wishlist data is now first-party and canonical

### Analytics / segmentation
- Analytics: `MarketingProfileAnalyticsService`
- Segments: `MarketingSegmentEvaluator`

### Email delivery reporting context
- Canonical delivery table: `marketing_email_deliveries`.
- Provider resolution/readiness context is stamped into delivery metadata and now surfaced directly in:
  - birthday analytics + exports
  - campaign delivery diagnostics
  - customer email timeline diagnostics (`marketing.customers.show`)
- Canonical derivation service:
  - `app/Services/Marketing/MarketingEmailDeliveryProviderContext.php`
- Customer timeline rows include operator-facing provider context labels (tenant-configured/fallback/unsupported/incomplete/legacy) and provider-context summary counts.
- Customer timeline supports optional provider-context filters:
  - `provider_resolution_source` (`tenant`, `fallback`, `none`, `unknown`)
  - `provider_readiness_status` (`ready`, `unsupported`, `incomplete`, `error`, `not_configured`, `unknown`)
- Customer timeline CSV export route:
  - `marketing.customers.email-deliveries.export`
  - `/marketing/customers/{marketingProfile}/email-deliveries/export`
  - export uses the same active provider-context filters as the timeline view
- Legacy rows without provider-context metadata remain visible as `unknown`/legacy context (no fabricated mapping).
- Reference: `docs/architecture/birthday-provider-context-reporting.md`.

### Operational Domain Boundary Guidance
- Cross-domain boundary guidance for future implementation runs lives in:
  - `docs/architecture/operational-multi-tenant-direction.md`
- Use it to decide whether a requested change belongs to:
  - tenant/platform infrastructure
  - reusable operational workflow infrastructure
  - candle-specific domain logic
- Current documented stance:
  - email + birthday/lifecycle messaging are established tenant-aware directions
  - customer domain is tenant-scoped and should remain reusable beyond campaign-specific flows
  - inventory/order ops currently include reusable primitives but remain partially candle-shaped
  - avoid premature generalization of candle manufacturing assumptions

## Storefront Architecture
- Storefront uses signed endpoints, not random ad hoc AJAX contracts
- Primary controller: `MarketingShopifyIntegrationController`
- Verification middleware must remain in the request path
- Reuse the existing signed storefront pattern for new customer-facing features

## Theme Architecture
- Theme repo is separate from backend repo
- Native review and wishlist UX were built in the theme repo with flag-based cutover
- Theme runtime should suppress duplicate Growave UI when native mode is enabled
- Any storefront widget replacement work must check both:
  - backend contract availability
  - theme runtime/rendering path

### Rewards Sidecar Snapshot
- `/pages/rewards` storefront UX currently runs through theme-side runtime files:
  - `/Users/johncollins/projects/modernforestry-live-theme/assets/forestry-rewards.js`
  - `/Users/johncollins/projects/modernforestry-live-theme/assets/forestry-rewards.css`
- Current sidecar status:
  - theme selector moved behind compact top-right toggle/panel
  - top summary/status clutter removed
  - birthday reward + birthday intake combined into one expandable card
  - reward opportunity cards collapsed by default with accessible toggles
  - task/reward history sections removed from the page UI
  - responsive hierarchy/spacing polish for mobile + desktop

## Data Principles
- Canonical tables only
- No shadow systems
- No parallel identity stacks
- Preserve provenance where relevant:
  - provider
  - integration
  - source
  - source_surface
  - source_ref
  - raw_payload
- Prefer idempotent writes and upsert-style behavior
- Prefer using local canonical data over third-party API calls when data already exists in the system

## Wishlist Import Reality
- CSV import path exists for wishlist continuity
- Import command: `marketing:import-wishlist-csv`
- A verified import run produced:
  - total_rows=233
  - imported=110
  - skipped_guest_rows=123
  - missing_profile=0
  - missing_product=0
  - errors=0
- Verified outputs included:
  - customer admin visibility
  - storefront wishlist status correctness
  - analytics/segment availability

## Growave Replacement Status
### Replaced
- Reviews
- Rewards
- Wishlist
- Birthday-related native marketing replacement

### Verify before touching
Potential remaining Growave-adjacent areas may include:
- referral behavior
- VIP tier behavior
- social login
- any lingering theme embed/app block behavior

Do not assume Growave is fully gone just because core review/wishlist/reward flows were replaced.
Always audit actual runtime/theme/backend usage before removal.

## Operational Guidance for Future Agents
Before building anything:
1. audit existing services, models, routes, migrations, commands, and views
2. reuse existing architecture
3. check whether the behavior belongs in backend repo, theme repo, or both
4. confirm deploy path to determine what is actually live
5. avoid creating parallel systems

## Feature Packaging Doctrine

- Prefer domain-neutral platform nouns, config keys, service names, and data models unless the requirement is truly tenant-specific.
- Shared business logic should remain in canonical backend services/contracts.
- Tenant-specific structure, labels, ordering, visibility, and workflow composition should be configurable where practical.
- Purchasable add-ons must be tenant-scoped, billing-aware, and configurable without per-tenant forks.

## Required Before Implementation (Hard Gate)

Do not implement a feature until all of the following are explicitly written:
- Classification: core platform capability / tenant configuration option / purchasable add-on / temporary tenant-specific override
- Tenant scope: what varies per tenant
- Entitlement/billing model: if applicable
- Canonical services/contracts reused
- Whether it works for a non-Forestry tenant without code changes

If this is not defined, stop and define it before coding.

## Forestry Bias Warning

- The Forestry Studio is the flagship tenant, but it is not the architectural center of the platform.
- Do not assume Forestry structure, naming, or UX is globally correct.
- Do not encode Forestry-specific language into platform models, services, config keys, or data models.
- Do not skip tenant abstraction "just for now."
- If a feature works for Forestry but not for a generic tenant, redesign the abstraction before implementing.

## Override -> Platform Graduation Rule

- Evaluate every tenant-specific override for promotion into:
  1. a tenant configuration option, or
  2. a shared add-on module.
- Do not let overrides accumulate as permanent parallel logic.
- Document the expected graduation path when creating an override.

## Storefront Sidecar Boundary (Strict)

- Theme-side JS/CSS may render UI, trigger actions, and consume backend responses.
- Theme-side JS/CSS must not implement business logic.
- Theme-side JS/CSS must not perform validation that diverges from backend rules.
- Theme-side JS/CSS must not create parallel state systems.
- Backend remains the single source of truth.

## Add-On Module Requirements

Every add-on must explicitly define:
- Canonical data ownership
- Tenant scope boundary
- Entitlement/billing check
- Admin configuration surface
- Storefront interaction surface
- Integration points (`API`, app proxy, theme, etc.)
- Canonical services/contracts reused

Add-ons are attachable capabilities, not isolated systems or feature flags.

Add-on implementation rules:
- Reuse canonical identity (`marketing_profiles`, `customer_external_profiles`, `marketing_profile_links`) and existing sync pipelines.
- Reuse existing signed storefront/app-proxy contracts before adding new surfaces.
- Gate availability by tenant-scoped feature/billing state, not by hardcoded tenant assumptions.
- Prefer one shared module architecture with tenant-level configuration over per-tenant forks.

## Productization Principle

- Evaluate every feature built for The Forestry Studio as a future product for other tenants.
- Build for Forestry, but name, structure, and scope features so they can be reused or sold without architectural rework.

## Customization Ladder (Implementation Order)

Use this order for feature work:
1. Tenant content/config only
2. Tenant UI/theme composition
3. Shared module option
4. Shared module extension
5. Tenant-specific override
6. New bespoke code path (last resort only)

Do not skip upward on this ladder without documenting why the simpler level was insufficient.

## Current Priority TODOs
### Do Now — Candle Cash Launch-Critical Verification
- [ ] Verify Candle Cash is visibly working on the live storefront and in the Laravel admin/backstage system (no date assumptions)
- [ ] Confirm the full customer-facing reward loop is functioning end to end:
  - earn behavior occurs
  - reward state updates correctly
  - customer-visible storefront state is accurate
  - admin/backstage visibility reflects the same truth
- [ ] Keep Growave-replacement parity in place for the launch-critical features already being replaced, so the program can be observed working in a real customer flow

### Current execution priority
- [ ] Finish/verify the launch-critical Candle Cash flow before expanding scope
- [ ] Prioritize operational visibility over new feature invention
- [ ] Prefer concrete verification of live behavior over additional architecture changes

### Do Next — Email Reliability Immediately After Candle Cash Launch
- [ ] Get email working correctly
- [ ] Audit the current email flow end to end:
  - what sends
  - what triggers sends
  - what provider/config is in use
  - where failures or gaps exist
- [ ] Make email operational enough to support the customer/reward workflow after launch

### Do Later — Platform Expansion (Important, Not Current Focus)
- [ ] Expand beyond Shopify into a general small-business operating system
- [ ] Support in-person onboarding for non-Shopify clients
- [ ] Allow flexible data models per business type (example: lawn care company storing customer, property photos, service history, plants installed/materials used)
- [ ] Ensure the system can adapt to different verticals without creating separate systems per industry
- [ ] Keep Laravel backend as the canonical system of truth across all verticals

## Scope Discipline
### Do Not Start Yet
- [ ] Do not start broad multi-tenant refactors yet
- [ ] Do not start Shopify App Store packaging yet
- [ ] Do not expand into speculative AI automation work yet

### Working rules
- [ ] Stay inside the current priority scope unless explicitly told otherwise
- [ ] Reuse existing Candle Cash / marketing / identity architecture before creating anything new

### Operating principle
- [ ] The immediate business goal is not abstract architecture progress
- [ ] The immediate goal is a working, visible, revenue-adjacent customer system across:
  - storefront
  - admin/backstage
  - email follow-through

## Multi-Tenant Transition Map
### 1. Tenant-aware foundations already present
- Tenant schema and membership scaffolding:
  - `database/migrations/2026_02_04_031802_create_tenants_table.php`
  - `database/migrations/2026_03_27_090000_create_tenant_user_table.php`
- Tenant scope added to Shopify + identity surfaces:
  - `database/migrations/2026_03_26_090000_add_tenant_scope_to_shopify_and_marketing_identity_tables.php`
  - Adds `tenant_id`/FK/indexes to `shopify_stores`, `marketing_profiles`, `marketing_profile_links`, `customer_external_profiles`, `marketing_consent_requests`, `marketing_consent_events`, `marketing_storefront_events`
- Tenant-scoped model helper:
  - `app/Models/Concerns/HasTenantScope.php` (`forTenantId` / `forTenant`)
- Core tenant-aware models already using `HasTenantScope` include:
  - `MarketingProfile`, `MarketingProfileLink`, `CustomerExternalProfile`, `MarketingProfileWishlistItem`, `ShopifyStore`, `MarketingConsentRequest`, `MarketingConsentEvent`, `MarketingStorefrontEvent`
- Tenant context services:
  - `app/Services/Tenancy/TenantResolver.php`
  - `app/Services/Tenancy/AuthenticatedTenantContextResolver.php`
- Tenant middleware + registration:
  - `app/Http/Middleware/EnsureTenantAccess.php`
  - `bootstrap/app.php` middleware aliases for `tenant.access` and `marketing.storefront.verify`
- Route-level tenant gates are present in key marketing areas:
  - `routes/web.php` (`tenant.access` groups and signed storefront route middleware)

### 2. Canonical systems that must become tenant-safe
- `marketing_profiles` + linked identity tables:
  - Tenant columns/scopes exist, but any raw `DB::table(...)` paths must still be explicitly tenant-filtered
- Wishlist:
  - Table includes `tenant_id` (`marketing_profile_wishlist_items` migration)
  - `MarketingWishlistService`, `MarketingImportWishlistCsv`, and `GrowaveWishlistBackfillService` pass/use tenant context
- Reviews:
  - `marketing_review_histories` / `marketing_review_summaries` do not have a direct `tenant_id` column
  - Isolation currently relies on `marketing_profile_id` and/or `store_key` paths in review flows
- Rewards / Candle Cash:
  - Core Candle Cash tables are keyed by `marketing_profile_id`; no direct tenant column on reward/task/ledger tables
  - Reward/task config is stored in shared `marketing_settings` keys
- Imports/exports:
  - Some importers are tenant-aware (`marketing:import-wishlist-csv`, Growave wishlist backfill path)
  - Other sync/import paths should be audited per command for explicit tenant boundary controls (`marketing:sync-profiles`, replacement review import)
- Segmentation / analytics:
  - `MarketingSegmentEvaluator` has no explicit tenant logic
  - `MarketingProfileAnalyticsService` is mostly profile-scoped; tenant filtering is explicit for wishlist metrics only
- Storefront signed endpoints:
  - Signed routes are guarded by `marketing.storefront.verify`
  - Tenant/store context resolution runs in `MarketingShopifyIntegrationController` via `TenantResolver`
- Admin/backstage views and queries:
  - Only some marketing routes are behind `tenant.access`; many marketing/birthday/admin pages require explicit query-level tenant audit
- Shopify store context mapping:
  - Tenant mapping is driven by `shopify_stores.store_key -> tenant_id` through `TenantResolver`
  - Webhook and embedded controllers depend on this mapping

### 3. Known risk categories
- Queries missing tenant filters (especially raw query-builder and report-style aggregations)
- Jobs/queues missing tenant context propagation (constructor payload + downstream query scope)
- Webhook handling without tenant resolution before dispatch
- Cache keys lacking tenant namespace (global keys can mix tenant-visible results)
- Imports/backfills that can cross tenant boundaries when source filters are broad
- Admin reports/pages that read global data accidentally
- Theme/storefront requests resolving to the wrong store/tenant context

### 4. Files and classes to inspect first for tenant work
- `app/Services/Tenancy/TenantResolver.php`
- `app/Services/Tenancy/AuthenticatedTenantContextResolver.php`
- `app/Http/Middleware/EnsureTenantAccess.php`
- `app/Models/Concerns/HasTenantScope.php`
- `database/migrations/2026_03_26_090000_add_tenant_scope_to_shopify_and_marketing_identity_tables.php`
- `routes/web.php`
- `app/Http/Controllers/Marketing/MarketingShopifyIntegrationController.php`
- `app/Http/Controllers/ShopifyWebhookController.php`
- `app/Services/Marketing/MarketingProfileSyncService.php`
- `app/Services/Marketing/MarketingWishlistService.php`
- `app/Services/Marketing/ProductReviewService.php`
- `app/Services/Marketing/CandleCashService.php`
- `app/Services/Shopify/ShopifyEmbeddedCustomersGridService.php`

### 5. Practical rules for future agents
- Do not add new global tables when the domain is tenant-scoped in current architecture
- Do not assume `store_key` alone is sufficient isolation; resolve/verify tenant context explicitly
- Do not add jobs, events, or importers without explicit tenant context in payload + query paths
- Prefer model scopes (`forTenantId`) or equivalent tenant predicates for every data path touching shared tables
- Verify both backend and theme/storefront implications for customer-facing endpoints and tenant/store resolution
- Treat cache keys, reporting queries, and backfills as tenant-boundary-sensitive by default

## Development Philosophy
1. Audit before building
2. Reuse before inventing
3. Keep canonical tables canonical
4. Minimize blast radius
5. Prefer structural guarantees over conventions
6. Distinguish local state from deployed state
7. Never assume OAuth/browser/app-proxy are interchangeable

## Repo-Backed Precision Notes
- Deploy path includes both `push` to `main` and manual `workflow_dispatch` in `.github/workflows/deploy.yml`
- Multi-tenant conversion is already in-progress in code (`tenants` tables/services, tenant middleware/routes, tenant-scoped identity fields)

Keep this file updated as the architecture evolves.

## Customer Operating System (Revenue Engine Model)

This system is not just a Shopify integration. It is a customer operating system designed to:
- track behavior
- influence decisions
- convert intent into purchases over time

Think of this system as a "robot with arms":
- the backend = brain (data + decisions)
- each capability = an arm (input, influence, conversion, communication)

### System Brain (Canonical Core)
- `marketing_profiles` (identity)
- `MarketingProfileAnalyticsService` (behavior analysis)
- `MarketingSegmentEvaluator` (targeting logic)
- Candle Cash system (reward ledger + incentives)
- marketing events tables (behavior tracking)

This layer stores memory and enables decision-making.

---

### Arms: System Capabilities

#### 1. Capture Arms (Data Intake)
- Orders (Shopify integration)
- Reviews (native review system)
- Wishlist (native system via `marketing_profile_wishlist_items`)
- Customer identity (marketing_profiles)

Partial / missing:
- Email engagement tracking
- SMS engagement tracking
- Browsing/session behavior tracking

These determine how intelligent the system can become.

---

#### 2. Influence Arms (Behavior Shaping)
- Candle Cash rewards
- Review incentives
- Wishlist tracking (foundation for intent-based offers)
- Birthday rewards

Missing / not yet automated:
- Wishlist-triggered offers
- Abandoned intent nudges
- Re-engagement campaigns

This layer turns behavior into motivation.

---

#### 3. Communication Arms (Outbound Reach)
Current state:
- Manual and approval-driven email/SMS sending exists (campaigns/direct messaging paths)
- No fully integrated behavioral email automation system
- No fully integrated behavioral SMS automation system
- No trigger-based outreach tied directly to backend events as a complete production loop

This is a critical missing layer.

Without communication, the system observes but does not act.

---

#### 4. Operator Control Arms (Admin Power)
- Backstage customer detail view
- Ability to assign Candle Cash
- Visibility into wishlist and activity

Missing:
- One-click campaign triggers from customer data
- Segment-based outreach tools
- Full customer timeline view

This layer determines how effectively operators can use the system.

---

#### 5. Conversion Arms (Revenue Generation)
Currently working:
- Rewards -> purchase loop (Candle Cash)

Not yet built:
- Wishlist -> discount -> purchase loop
- Segment -> targeted offer -> conversion
- Automated behavior-driven campaigns

This is the primary monetization layer.

---

## Executive Completion Checklist

### Core System
- [x] Canonical identity (`marketing_profiles`)
- [x] Native reviews (Growave replaced)
- [x] Candle Cash rewards system
- [x] Wishlist system (CSV import + canonical table)
- [x] Shopify app-proxy integration
- [x] Customer admin visibility

### Growave Replacement
- [x] Reviews replaced
- [x] Rewards replaced
- [x] Wishlist replaced
- [x] Birthday functionality replaced

Needs verification:
- [ ] Referral system
- [ ] VIP tier system
- [ ] Social login
- [ ] Remaining theme/app embed remnants

### Backend Control
- [x] View customers
- [x] Assign rewards
- [x] View wishlist data

Missing:
- [ ] Trigger actions from data
- [ ] Segment-based tools

### Communication Layer
- [ ] Email automation (behavior-based)
- [ ] SMS automation
- [ ] Triggered outreach system

### AI Layer
- [ ] Decision engine (who to target)
- [ ] Message generation
- [ ] Campaign execution
- [ ] Feedback loop (sale -> behavior update)

---

## Monetization Model (How This Makes Money)

The system should operate as a loop:

1. Detect intent
   - wishlist add
   - repeat product views
   - inactivity

2. Decide action
   - offer discount
   - send reminder
   - wait

3. Execute communication
   - email
   - SMS
   - on-site messaging

4. Observe outcome
   - purchase
   - ignore
   - partial engagement

5. Adapt future behavior

Currently, steps 1 and 4 are partially implemented.
Steps 2 and 3 are largely missing.

---

## AI Decision Layer (Future Direction)

AI sits on top of existing systems and does NOT replace them.

Responsibilities:
- Identify high-intent users
- Choose appropriate actions
- Generate messaging
- Stop or redirect when a sale occurs

Example flow:
- User adds product to wishlist
- AI selects segment
- AI triggers offer
- User purchases
- System stops promotion and shifts to upsell/retention

---

## Strategic Direction

The system is:
- strong in data collection and canonical architecture
- partially complete in incentives (rewards, wishlist)
- weak in communication and automation

Primary next step:
Build the first full conversion loop:
wishlist -> outreach -> purchase -> stop condition

All future work should reinforce:
- automation
- decision-making
- revenue generation

Not just feature expansion.

## Historical Productization Notes (Not Active Scope)

The following App Store/productization notes are historical strategy context only.
They are not the active execution sequence for this release.
Current order remains: Candle Cash verification -> email reliability -> then broader expansion.

This system is evolving from a single-store internal tool into a multi-tenant SaaS application distributed via the Shopify App Store.

---

### Tenant Intake / Onboarding (Future Requirement)

The system must support a fully self-serve onboarding flow for new Shopify stores:

- OAuth-based install via Shopify App Store
- Automatic tenant creation on install
- Store-to-tenant mapping via `shopify_stores.store_key -> tenant_id`
- Initial setup wizard (GUI) to:
  - explain core concepts (rewards, wishlist, reviews)
  - enable/disable features
  - configure incentives (e.g., Candle Cash rules)
- Default configurations for fast activation

Goal:
A random Shopify merchant should be able to:
- install the app
- understand its value quickly
- activate core features without developer involvement

---

### Feature Packaging / Monetization

The system will support tiered pricing based on enabled capabilities.

Example feature tiers:

Core (entry tier):
- Basic rewards (Candle Cash)
- Basic customer tracking
- Limited analytics

Growth:
- Wishlist tracking
- Review incentives
- Segmentation

Pro:
- Advanced segmentation
- Automated campaigns (email/SMS)
- AI-driven targeting and messaging

Enterprise:
- Custom workflows
- advanced analytics
- priority processing / support

Requirements:
- Feature flags must be tenant-scoped
- Billing state must control feature access
- No hardcoded assumptions of feature availability

---

### App Distribution Model

Current:
- Custom/private app tied to a single store

Target:
- Public Shopify App Store app

Implications:
- Must support OAuth install flow
- Must handle multiple independent stores securely
- Must isolate tenant data strictly
- Must support uninstall/reinstall lifecycle
- Must handle webhook registration per store

---

### Security and IP Protection

The system must be treated as proprietary infrastructure.

Requirements:

- Strict tenant data isolation (no cross-tenant leakage)
- All sensitive routes behind authentication or signed verification
- Avoid exposing internal logic in storefront JS beyond what is required
- Validate all inbound requests (Shopify webhooks, app proxy, admin calls)
- Rate-limit sensitive endpoints where applicable
- Audit for:
  - injection vulnerabilities
  - improper authorization checks
  - unsafe raw queries

Code/IP considerations:
- Do not expose proprietary business logic in public endpoints unnecessarily
- Avoid leaking internal scoring/decision logic via API responses
- Treat AI decision logic as protected intellectual property

---

### Strategic Goal

Build a system where:

- multiple Shopify stores can onboard themselves
- each store becomes a tenant
- each tenant can activate features based on pricing tier
- the system drives revenue through:
  - behavior tracking
  - automated incentives
  - AI-driven outreach

This transforms the system into:
A multi-tenant customer operating system distributed as a Shopify app.

---

## Platform Operating Model
### 1. Product vs Custom Work
- Operate in two layers:
  - shared product/platform baseline used across tenants
  - paid, scoped customization layered on top
- Do not treat every client request as a new bespoke system.
- New custom requests should be evaluated for:
  - configuration in existing architecture first
  - reusable module potential second
  - one-off build only when justified and explicitly scoped/priced

### 2. Core Platform Shell
Every tenant should eventually share a common baseline shell with:
- contacts/customers
- identity/profile history
- notes/activity timeline
- messaging/email/text hooks
- rewards/incentives/offers
- uploads/files/images
- tags/segments
- dashboards/admin visibility
- tenant settings/access

This shell is the canonical reusable substrate; vertical modules should attach to it.

### 3. Optional Module Arms
Optional modules should attach to the shared shell per tenant instead of forking architecture.

Examples:
- Shopify customer marketing / rewards / reviews / wishlist
- appointment or service history
- property/job/photo tracking for local service businesses
- intake workflows
- follow-up automation
- reporting/analytics

Rule:
- Different verticals should reuse core identity, timeline, messaging hooks, and admin visibility wherever possible.

Add-on packaging rule (future purchasable apps):
- Treat add-ons as tenant-scoped capabilities/modules, not separate sidecar data systems.
- Add-ons must reuse canonical identity (`marketing_profiles` + existing link tables/services).
- Add-ons should integrate through existing signed storefront/app-proxy patterns when possible.
- Access to add-ons should be controlled by tenant-scoped feature/billing state.
- Avoid per-tenant forks; one module implementation, tenant-level configuration.

### 4. Tenant Onboarding Flow
Intended sales/delivery operating flow:
1. Demo
2. Requirements/discovery
3. Create tenant from baseline shell
4. Configure baseline features before custom coding
5. Add only scoped custom work where needed
6. Review/revise with tenant
7. Set feature/support tier
8. Ongoing monitoring/support via tenant admin analytics

### 5. Customization Rules
- Configuration first, code second
- Reuse canonical architecture (identity/marketing/rewards/storefront patterns)
- Avoid one-off systems unless they can evolve into reusable modules
- Scope and price custom work separately from recurring platform access
- Do not let each client become a fresh software project from scratch

### 6. Support and Pricing Model
Commercial structure (no final price table defined here):
- recurring platform fee
- optional setup/onboarding fee
- optional scoped custom build fee
- optional support tier

Pricing alignment rule:
- Prefer pricing parity between Shopify App Store customers and direct local clients unless a deliberate exception is chosen later.

### 7. Near-Term vs Long-Term Execution Order
Near term (do now):
- Candle Cash launch visibility
- email reliability immediately after launch
- visible storefront/admin truth alignment

Medium term (after launch-critical stabilization):
- define reusable tenant shell boundaries clearly
- prepare demo presence / SaaS positioning
- create at least one non-candle demo use case

Long term:
- multi-tenant hardening
- App Store packaging
- broader vertical support
- AI/operator automation

Scope guard:
- This section does not override existing `Current Priority TODOs` or `Scope Discipline`; it contextualizes them.

### 8. Strategic Expansion Beyond Shopify
- Platform direction should include non-Shopify businesses over time.
- Example target use case: lawn/property service business storing:
  - customer
  - property photos
  - service history
  - installed plants/materials
- Laravel backend remains the canonical system of truth across verticals.
- Shopify remains an important arm, not the only future arm.
