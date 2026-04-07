# START HERE

Read `SYSTEM_SNAPSHOT.md` before making changes.

## UI Editing Guide (Required)

Read these before any UI/theme change:
1. `docs/ui/UI_SYSTEM.md`
2. `docs/ui/UI_CHANGELOG.md`

Canonical UI ownership:
- Canonical backstage shell: `resources/views/layouts/app/sidebar.blade.php`
- Public landing pages: `resources/views/platform/promo.blade.php` and `resources/views/platform/contact.blade.php`
- Auth branding shell: `resources/views/layouts/auth/simple.blade.php`
- Shared tokens + component styles: `resources/css/forestry-ui.css` (imported via `resources/css/app.css`)
- Embedded shell primitives:
  - `resources/views/components/shopify-embedded-shell.blade.php`
  - `resources/views/components/app-shell.blade.php`
  - `resources/views/components/app-topbar.blade.php`
  - `resources/views/components/app-sidebar.blade.php`

UI maintenance rules:
- Do not add large inline `<style>` blocks to shell/layout files.
- Prefer shared tokenized classes and reusable components.
- Every UI-affecting change must update `docs/ui/UI_CHANGELOG.md`.

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
- Landlord commercial config surface is implemented:
  - `/landlord/commercial` (host-locked, pricing-first admin controls)
- Diagnostics/operator surfaces are implemented and test-covered:
  - customer email timeline provider-context filters + CSV export parity
  - birthday analytics/reporting/export/comparison
  - campaign delivery diagnostics/provider-context visibility
- Integrations is placeholder-first:
  - setup drawer exists
  - read-only deterministic status registry exists
  - entitlement-aware states (`connected`, `setup_needed`, `locked`, `coming_soon`) exist
  - no real connector sync/OAuth/jobs/webhooks/API writes exist
- Billing remains guarded and landlord-controlled:
  - guarded Stripe customer-reference sync action exists on `/landlord/commercial`
  - guarded Stripe subscription-prep metadata sync action exists on `/landlord/commercial`
  - guarded Stripe live subscription create/sync action exists on `/landlord/commercial` (landlord-only, explicit trigger, disabled-by-default config flag)
  - guarded Stripe preflight requires HTTPS for remote `services.stripe.api_base` endpoints (HTTP is loopback-only for local testing on `localhost`/`127.0.0.1`/`::1`)
  - staging validation for the 3-step guarded Stripe sequence is documented and evidence-driven:
    - `docs/operations/staging-commercial-uat-runbook.md`
    - `docs/operations/staging-commercial-uat-evidence-template.md`
  - latest repo-side validation status (2026-03-29):
    - real staging landlord operator evidence is attached for a guarded run on tenant `modern-forestry`
    - blocked-run record: `docs/operations/staging-commercial-uat-blocked-run-2026-03-28.md`
    - staging Stripe sandbox + operator follow-up: runtime Stripe auth succeeds and all required recurring lookup-key prices are present/verified (`tier_starter_monthly`, `tier_growth_monthly`, `tier_pro_monthly`, `addon_referrals_monthly`, `addon_sms_monthly`, `addon_additional_channels_monthly`, `addon_bulk_email_marketing_monthly`, `addon_future_niche_modules_monthly`), and the landlord operator account `modernforestryteam@gmail.com` is route-ready
    - tenant-row unblock follow-up (2026-03-29): existing `TenantSeeder` was executed on staging; `/landlord/commercial` now renders one selectable tenant row (`Modern Forestry`, slug `modern-forestry`)
    - guarded run evidence artifacts (2026-03-29): `docs/operations/evidence/2026-03-29/guarded-stripe-run-2026-03-29T16-23-07.524Z/`
    - guarded step outcomes from the real run:
      - step 1 customer sync: `PASS` (`cus_UEpZQoP8cJadrs`)
      - step 2 subscription-prep sync: `PASS` (`eaaddd980cf88b07e7f52f3ce7db5856a7394ff9eb08c602ee87afeb4b6ad563`)
      - step 3 live subscription create/sync: `FAIL` (`Missing email. In order to create invoices that are sent to the customer, the customer must have a valid email.`)
    - full guarded 3-step PASS evidence is still not attached because step 3 failed in real staging execution
    - follow-up commit `9c2502c` (CI assertion alignment after dotenv bootstrap fix) is pushed to `main`
    - local CI-equivalent rerun for this pass:
      - `php -d memory_limit=512M ./vendor/bin/pest` => `845 passed`, `0 failed`
    - GitHub Actions results for commit `9c2502c`:
      - `linter`: `success`
      - `tests`: `success` (`ci (8.4)` and `ci (8.5)` passed)
      - `Deploy Production`: initial `failure` on push, then `success` on rerun `23687500356` after deploy-ops unblock
    - deploy-ops unblock completed in GitHub `production` environment:
      - configured `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT`, `DEPLOY_PATH`, `DEPLOY_SSH_KEY`
      - corrected server checkout branch at `DEPLOY_PATH` to `main` so workflow `git checkout main` succeeds
    - latest known production rollout for `dbf0762` was manual before deploy automation was restored:
      - `ssh forge@129.212.138.111 'bash /home/forge/deploy_backstage.sh'`
      - `curl -sS https://backstage.theforestrystudio.com/up` => `Application up.`
    - manual SSH deploy remains available as fallback, but is no longer the primary required path while deploy secrets stay configured
  - checkout and broad subscription lifecycle mutation flows remain intentionally disabled
- multi-tenant completion estimate is currently `45%`
- Landlord/admin Phase 1 host foundation is now in place:
  - pre-auth host tenant context is globally resolved via middleware
  - landlord host (production): `app.forestrybackstage.com`
  - tenant host pattern (production): `<slug>.forestrybackstage.com`
  - landlord routes (host-locked): `/landlord`, `/landlord/commercial`, `/landlord/tenants`, `/landlord/tenants/{tenant}`
  - landlord directory pages remain read-only
  - landlord commercial writes are limited to safe configuration scope (plan/add-on/template catalog, tenant assignment/overrides)
  - unknown hosts do not silently fallback to first tenant

Commercial model normalization now in repo:
- public tiers: `Starter`, `Growth`, `Pro`
- add-ons: `referrals`, `sms`, `additional_channels`, `bulk_email_marketing`, `future_niche_modules`
- template library: `Candle`, `Law`, `Landscaping`, `Apparel`, `Generic`
- billing lifecycle remains guarded-first (Stripe primary with landlord-only guarded actions, Braintree secondary readiness, no checkout activation)

Production DNS/TLS status (2026-03-27):
- wildcard TLS for `*.forestrybackstage.com` was successfully issued via Forge DNS-01
- `_acme-challenge` CNAME must remain `DNS only` in Cloudflare
- wildcard tenant DNS is active (`* -> 129.212.138.111`) and tenant HTTPS reaches app login routes

Current execution priority:
- deploy/verify/stabilize this shell and diagnostics release
- avoid new feature sprawl unless a concrete regression requires it

Strict near-term execution order (current operator rule):
1. Candle Cash verified live and trustworthy for Modern Forestry.
2. Email reliability fixed for launch-critical reward/customer workflows.
3. Only then broader platform expansion.

Canonical Candle Cash drift-repair sequence (tenant-scoped, live-safe):
1. `php artisan marketing:audit-candle-cash-composition --tenant-id=1`
2. `php artisan marketing:reconcile-candle-cash-balances --tenant-id=1` (preview-only)
3. `php artisan marketing:reconcile-candle-cash-balances --tenant-id=1 --apply`
4. `php artisan marketing:audit-candle-cash-composition --tenant-id=1`
5. `php artisan marketing:validate-candle-cash-legacy-conversion --json --limit=10`

Operator notes:
- Preview returns non-zero when drift is detected.
- Use `--profile-id={id}` for isolated repair and `--chunk={n}` for large-scope tuning.

Legacy Growave duplicate-profile rehome sequence (retail-only, live-safe):
1. `php artisan marketing:rehome-legacy-growave-candle-cash --tenant-id=1 --store=retail` (preview)
2. Require: `ambiguous_old_profiles=0` and `ambiguous_target_profiles=0` before apply.
3. `php artisan marketing:rehome-legacy-growave-candle-cash --tenant-id=1 --store=retail --apply`
4. Re-run the canonical drift-repair sequence immediately after apply.

If points import appears missing again, use this exact SOP:
1. Diagnose duplicate-profile drift via rehome preview counters.
2. Run retail-only rehome apply (do not include wholesale in broad pass).
3. Reconcile + audit Candle Cash (`marketing:audit-candle-cash-composition`, `marketing:reconcile-candle-cash-balances`, `marketing:validate-candle-cash-legacy-conversion`).
4. Verify top non-wholesale customers by order count and Candle Cash balance.
5. Keep wholesale-touched profiles quarantined unless a separate manually reviewed pass is approved.

Do not start yet:
- broad multi-tenant refactors
- Shopify App Store packaging
- speculative AI automation work

Current backend release-order rule:
- use `docs/architecture/backend-release-order-2026-04-01.md` before promoting the waiting backend branch
- Release A is stabilization-only for Shopify / rewards / storefront / marketing-manager behavior
- split later commercialization work into smaller releases instead of pushing the mixed branch to `main`
- prepared split branches now exist and should stay scoped:
  - `release-a-stabilization`
  - `release-b-commercial-core`
  - `release-c-module-discovery`
  - `release-d-unified-shell`
  - `release-e-polish-docs-assets`
- Releases A through E are now merged on `main`.
- Active standalone follow-up work is the deferred email/provider reliability pass; do not mix it back into module, shell, or commercialization scope.

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
  - production example: `app.forestrybackstage.com`
  - local example: `forestrybackstage.test`
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
- Keep landlord writes constrained to commercial configuration scope only.
- Keep Shopify embedded/storefront/proxy behavior unchanged while evolving landlord/admin surfaces.

Architecture references for this pass:
- `docs/architecture/business-concept-and-product-architecture.md`
- `docs/architecture/multi-tenant-inventory-2026-03-27.md`
- `docs/operations/staging-commercial-uat-runbook.md` (operator UAT sequence for landlord commercial assignment propagation)

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
