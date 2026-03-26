# START HERE

Read `SYSTEM_SNAPSHOT.md` before making changes.

## Current Release State (Scan First)

Current implemented shell/diagnostics checkpoint:
- Embedded product shell is live and navigable:
  - `/shopify/app` (overview/dashboard)
  - `/shopify/app/start`
  - `/shopify/app/plans`
  - `/shopify/app/integrations`
- Public product surfaces are implemented:
  - `/platform/promo`
  - `/platform/contact`
- Diagnostics/operator surfaces are implemented and test-covered:
  - customer email timeline provider-context filters + CSV export parity
  - birthday analytics/reporting/export/comparison
  - campaign delivery diagnostics/provider-context visibility
- Integrations is placeholder-first:
  - setup drawer exists
  - read-only deterministic status registry exists
  - entitlement-aware states (`connected`, `setup_needed`, `locked`, `coming_soon`) exist
  - no real connector sync/OAuth/jobs/webhooks/API writes exist
- Billing/checkout activation is intentionally not implemented yet.
- Landlord/admin Phase 1 host foundation is now in place:
  - pre-auth host tenant context is globally resolved via middleware
  - landlord host: `app.fireforgetech.com`
  - tenant host pattern: `<slug>.fireforgetech.com`
  - landlord routes (host-locked): `/landlord`, `/landlord/tenants`, `/landlord/tenants/{tenant}`
  - landlord directory pages are read-only in this phase
  - unknown hosts do not silently fallback to first tenant

Current execution priority:
- deploy/verify/stabilize this shell and diagnostics release
- avoid new feature sprawl unless a concrete regression requires it

## Landlord Host Foundation (2026-03-26)

Reference implementation paths:
- `app/Services/Tenancy/PreAuthTenantContextResolver.php`
- `app/Support/Tenancy/HostTenantContext.php`
- `app/Http/Middleware/ResolveHostTenantContext.php`
- `app/Http/Controllers/Landlord/LandlordTenantDirectoryController.php`
- `resources/views/landlord/*`
- `tests/Feature/Tenancy/LandlordHostFoundationTest.php`

Config:
- `TENANCY_LANDLORD_HOSTS` (comma-separated list)
- `TENANCY_LANDLORD_OPERATOR_ROLES` (comma-separated list, default `admin`)
- `TENANCY_LANDLORD_OPERATOR_EMAILS` (optional comma-separated allowlist)
- `config('tenancy.landlord.primary_host')`
- `config('tenancy.landlord.hosts')`
- `config('tenancy.landlord.operator_roles')`
- `config('tenancy.landlord.operator_emails')`

Local routing note:
- `config('tenancy.landlord.primary_host')` is derived from the first host in `TENANCY_LANDLORD_HOSTS` and is what landlord `Route::domain(...)` bindings use.
- Distinguish host examples in docs:
  - production example: `app.fireforgetech.com`
  - local example: `app.fireforgetech.test`
- Keep local examples explicit in docs/config comments so operators do not assume the full `hosts` list is domain-bound in routing.
- Fast local auth bootstrap path already exists and should be preferred over ad-hoc DB edits:
  - `php artisan users:ensure-approved your-email@example.com 'your-password' --name='Your Name' --role=admin`
  - this sets role, password, active/approved state, and verified email for local login.

Authorization note:
- Landlord routes use dedicated middleware `landlord.operator` instead of tenant-facing `role:admin,manager`.
- Interim safety model: default `admin` role access only, with optional landlord operator email allowlist.
- TODO for future hardening: replace role/email interim rules with a first-class landlord operator role/flag.

Important guardrails for future edits:
- Do not bypass host-locked landlord routing by making landlord pages globally available.
- Keep post-auth `tenant.access` middleware behavior unchanged unless explicitly requested.
- Keep landlord directory read-only until explicit write safety requirements are defined.
- Keep Shopify embedded/storefront/proxy behavior unchanged while evolving landlord/admin surfaces.

## Auth Findings (2026-03-25)

Production login verification findings:
- A local password reset does not affect production auth.
- Live user `johncollinsemail@gmail.com` was confirmed active/approved and production password reset verified (`PASSWORD_MATCH=1`).
- Google login failure is currently due to Google OAuth credential rejection, with production logs showing:
  - `401 invalid_client`
  - `The provided client secret is invalid.`

What to check first when this recurs:
1. Run diagnostics first:
   - `php artisan auth:doctor-google`
   - `php artisan auth:doctor-google --token-smoke`
2. Verify login uses `GOOGLE_CLIENT_ID` + `GOOGLE_CLIENT_SECRET` (not `GOOGLE_GBP_*` keys).
3. Confirm ID+secret are from the same Google OAuth client credential.
4. After env edits on production, run:
   - `php artisan config:clear`
   - `php artisan config:cache`
   - `php artisan queue:restart`
5. Re-test in incognito and inspect `storage/logs/laravel.log`.

Smoke test interpretation:
- `invalid_client` => wrong/revoked/mismatched OAuth pair
- `invalid_grant` => credentials accepted, grant intentionally invalid/expired
- `redirect_uri_mismatch` => callback URL mismatch in Google Console

## Dual-Track Strategy (Hard Guardrail)

This platform has two product tracks that must evolve together without breaking each other:

1. Shopify Product Track (flagship wedge)
- Remains first-class and commercially useful for Shopify merchants.
- Existing Shopify proof-of-concept behavior is sacrosanct during architecture and docs work:
  - storefront widgets and signed proxy flows
  - embedded dashboard/customers/settings flows
  - Candle Cash, birthdays, reviews, wishlist
  - canonical marketing identity pipeline
- Do not genericize away Shopify-specific value.

2. Broader Business Systems Track (expansion path)
- Extends the same canonical backend into direct business onboarding, integrations, uploads/imports, and tiered access.
- This is an expansion path, not a rewrite.
- Reuse shared core primitives only when real use cases justify extraction.

Authoritative direction docs:
- `docs/architecture/dual-track-product-direction.md`
- `docs/architecture/operational-multi-tenant-direction.md`
- `docs/architecture/tenant-entitlements-foundation.md`

This repo has important architectural and deployment realities that must be understood first:
- Laravel backend is the canonical system of truth
- Shopify theme repo is separate from backend repo
- Production deploys from GitHub `main`, not from local dirty branches
- Storefront features typically use signed app-proxy endpoints
- Customer identity must reuse canonical marketing identity models
- Do not create parallel systems for reviews, rewards, wishlist, or identity

Before building anything:
1. Audit existing models, services, controllers, routes, migrations, commands, and views
2. Confirm whether the work belongs in backend repo, theme repo, or both
3. Confirm whether the code is only local or actually deployed
4. Reuse existing architecture before inventing new systems
5. For customer/inventory/order/ops changes, read:
   - `docs/architecture/operational-multi-tenant-direction.md`

## Platform Product Doctrine

- Fire Forge Tech is the platform owner.
- The Forestry Studio is the flagship tenant, not the architectural center of the platform.
- Flagship customization is allowed; platform coupling is not.
- Platform nouns, config keys, service names, and data models should remain domain-neutral unless a constraint is truly tenant-specific.

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

## Storefront Sidecar Status (Recorded)

The current rewards storefront "sidecar" work was implemented in the separate Shopify theme repo:
- Theme repo: `/Users/johncollins/projects/modernforestry-live-theme`
- Runtime files:
  - `assets/forestry-rewards.js`
  - `assets/forestry-rewards.css`

Scope completed in that sidecar:
- Theme selector is hidden by default behind a compact top-right toggle
- Top summary/status clutter was removed from `/pages/rewards`
- Birthday experience was consolidated into a single expandable card
- Reward opportunity cards are collapsible by default for compact scanning
- Task/Rewards history blocks were removed from the rewards page UI
- Layout hierarchy and spacing were refined for mobile + desktop

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
- Keep canonical identity in `marketing_profiles` (+ existing link tables/pipelines).
- Reuse existing signed storefront contracts (`/apps/forestry/...`, `/shopify/marketing/v1/...`) before adding endpoints.
- Gate availability by tenant-scoped feature/billing state, not hardcoded store/email checks.
- Prefer one shared module architecture with tenant configuration over per-tenant forks.
- Avoid creating sidecar data models for rewards/reviews/wishlist/identity.
- Tenant-specific UI/presentation is allowed, but it must sit on top of shared module logic and canonical backend contracts.

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

## Historical TODO Backlog (Not Current Release Focus)

### Immediate launch goal
- [ ] Launch Candle Cash tomorrow in a way that is visibly working on the live storefront and in the Laravel admin/backstage system
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

### Next task after Candle Cash launch
- [ ] Get email working correctly
- [ ] Audit the current email flow end to end:
  - what sends
  - what triggers sends
  - what provider/config is in use
  - where failures or gaps exist
- [ ] Make email operational enough to support the customer/reward workflow after launch
- [x] Customer email timeline provider-context diagnostics now include:
  - row labels + summary chips
  - filters for provider resolution/readiness context
  - CSV export parity with active filters

### Platform direction (important but NOT current focus)
- [ ] Expand beyond Shopify into a general small-business operating system
- [ ] Support in-person onboarding for non-Shopify clients
- [ ] Allow flexible data models per business type (example: lawn care company storing:
  - customer
  - property photos
  - service history
  - plants installed / materials used)
- [ ] Ensure the system can adapt to different verticals without creating separate systems per industry
- [ ] Keep Laravel backend as the canonical system of truth across all verticals

### Scope discipline for agents
- [ ] Stay inside the current priority scope unless explicitly told otherwise
- [ ] Do not start broad multi-tenant refactors yet
- [ ] Do not start Shopify App Store packaging yet
- [ ] Do not expand into speculative AI automation work yet
- [ ] Reuse existing Candle Cash / marketing / identity architecture before creating anything new

### Operating principle
- [ ] The immediate business goal is not abstract architecture progress
- [ ] The immediate goal is a working, visible, revenue-adjacent customer system:
  - storefront
  - admin/backstage
  - email follow-through
