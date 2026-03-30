# Modern Forestry Backstage

## MT-4C Hardening Status (2026-03-30)

MT-4C is now complete.

Completed in MT-4C Pass 1 + Pass 2 + Pass 3 + Pass 4:
- Growave wishlist and opening-balance backfill flows now require deterministic tenant ownership proof and fail closed on missing/ambiguous/conflicting ownership.
- Legacy campaign/report/helper surfaces now enforce tenant ownership rails across campaign, segment, template, recommendation, and direct-messaging chains.
- Campaign send/retry execution now validates tenant-owned recipient scope before mutation; foreign/unproven recipients are blocked.
- Campaign performance analytics/report computation now supports tenant-scoped query predicates so foreign-tenant rows are excluded at the data layer.
- First-class schema-backed tenant ownership rails now exist for legacy authoring/storage entities:
  - `marketing_campaigns.tenant_id`
  - `marketing_segments.tenant_id`
  - `marketing_message_templates.tenant_id`
  - `marketing_event_source_mappings.tenant_id`
  - `marketing_order_event_attributions.tenant_id`
- Strict-mode authoring flows previously blocked for safety are now re-enabled where storage rails are deterministic:
  - segment create/store/duplicate
  - template create/store
  - tenant-owned event mapping create/edit/update/list
- Event source mappings and attribution projection rows are now tenant-partitioned at storage + query boundaries; foreign/unresolved mapping rows remain fail-closed.
- Legacy ownership backfill now assigns tenant ownership only for provable rows using deterministic rails (campaign/profile/group/conversion, variant/template, and tenant-owned Square evidence for mapping/attribution).
- Pass 4 added explicit unresolved-tail remediation + quarantine visibility with `marketing:remediate-authoring-ownership`:
  - dry-run inventory classification across campaigns/segments/templates/mappings/attributions,
  - deterministic `--apply` ownership assignment for provable rows only,
  - ambiguous/unprovable/unsupported rows left intentionally fail-closed.
- Pass 4 also hardened customer analytics/detail attribution reads to enforce tenant-scoped Square order/attribution matching when duplicate `square_order_id` values exist across tenants.
- Tenant-sensitive admin helper commands now require tenant scope in strict mode and block foreign ownership targets:
  - `marketing:send-approved-sms`
  - `marketing:send-approved-email`
  - `marketing:generate-recommendations`
- Targeted MT-4C regression coverage now includes campaign/report isolation, schema-backed authoring ownership rails, unresolved-row remediation and quarantine behavior, tenant-owned mapping behavior, strict command ownership guards, and shared-square-order attribution isolation.

MT-4C closure criteria used in Pass 4:
- campaigns, segments, templates, mappings, and attributions are tenant-owned when proof is deterministic;
- unresolved historical rows without safe proof remain explicitly quarantined (`tenant_id` null) and fail closed on tenant-sensitive surfaces;
- unresolved inventory is now observable/remediable through an explicit operator command rather than hidden compatibility behavior.

## Post-MT-4C Operator Phase (2026-03-30)

New landlord/operator capabilities are now available behind landlord host + landlord operator auth guards:
- Explicit tenant selector flow for landlord tenant operations entry.
- Guarded tenant snapshot export workflow (tenant-scoped only) for customer/marketing recovery inspection.
- Guarded tenant snapshot restore/import workflow (bounded MVP) with strict target-tenant confirmation.
- Guarded customer modify workflow (bounded editable fields only) scoped to one selected tenant.
- Guarded customer delete workflow implemented as safe archive/redaction (not hard delete), with explicit operator confirmation.
- Immutable landlord operator action trace for export/restore/customer modify/customer archive actions, including actor, tenant, action type, status, and result context.
- Tenant-scoped snapshot download flow with blocked/success audit records.

Additional operator hardening in the follow-up pass:
- Restore now supports explicit dry-run mode (`dry_run`) that returns projected table impact without mutating rows.
- Restore now enforces stronger artifact gates before apply:
  - supported schema version
  - source tenant id + source tenant slug match
  - scope manifest table list must match data payload tables exactly
  - max snapshot artifact size guard
- Restore apply and overwrite now require typed confirmation phrases:
  - apply phrase: `apply <tenant-slug>` when dry-run is off
  - overwrite phrase: `overwrite <tenant-slug>` when overwrite mode is enabled
- Export/restore actions now require explicit operator reason strings and persist them in audit confirmation metadata.
- Snapshot export now records artifact metadata (`artifact_bytes`, `generated_at`, `expires_at`, retention days); download is blocked once artifact retention expires.
- Download now enforces tenant path prefix checks (`landlord/tenant-ops/tenant-<id>/...`) in addition to tenant/action ownership checks.
- Customer modify/archive now require typed target confirmation (`confirm_profile_id` must match selected `profile_id`).
- Tenant action trace UI now includes status summaries and richer per-action details (mode, reason, expiry, blocked error context).

Operator safety constraints in this phase:
- Every operation requires explicit tenant id + tenant slug + confirmation phrase (`confirm <tenant-slug>`).
- Cross-tenant restore/import is blocked fail-closed.
- Cross-tenant customer modify/delete is blocked fail-closed.
- Snapshot download artifacts are tenant-locked by route + audit action ownership checks.
- Landlord operator action records are append-only; update/delete mutation is blocked at the model layer.

Consistency conventions standardized in this phase:
- Landlord tenant context now uses one canonical confirmation contract across UI + controller + service boundaries.
- Destructive/overwrite operator workflows now require explicit confirmation toggles plus confirmation phrase.
- Operator action statuses now follow one pattern (`success`, `blocked`, `failed`) with shared context/result payload structure for traceability.
- Recovery workflows now share one explicit mode contract (`dry-run` vs `apply`) with consistent confirmation and audit semantics.

Bounded scope (truthful MVP):
- Snapshot restore/import supports the landlord-generated tenant snapshot artifact format from this phase.
- Restore/import remains intentionally bounded to tenant-owned marketing/customer datasets included in the snapshot scope.
- Snapshot artifact retention is bounded by operator config and enforced on download paths; this is not yet a full artifact lifecycle management suite.
- Broad cross-environment migration tooling and unrestricted operator “god mode” edits are intentionally deferred.

## Merchant Experience Consolidation Phase (2026-03-30)

This phase shifts primary execution from backend hardening to merchant-facing product clarity and onboarding momentum across the embedded Shopify shell.

What now ships for merchant experience:
- Post-login and post-install landing (`/shopify/app`) now uses one clear hierarchy:
  - what the product does
  - next best setup/import action
  - current customer/setup snapshot
  - what unlocks after import
  - what is active now vs setup next vs purchasable unlocks
- Start Here, Plans, and Integrations pages now reuse one journey model so setup guidance and CTA logic stay consistent.
- Customer workflows now include shared setup/import status framing so merchants understand readiness before acting in manage/activity/questions views.
- Import-first orientation is now explicit:
  - import state is visible (`Not started`, `In progress`, `Needs attention`, `Imported`)
  - import CTA is preserved as the first meaningful action when setup is incomplete
  - post-import path is explained in plain merchant language.
- Feature discovery and monetization visibility is now structured and non-spammy:
  - `Available Now` (active)
  - `Setup Next` (included but not configured)
  - `Unlock Next` (upgrade/add-on eligible)

Feature metadata for this major UI phase:
1. Classification: Shared core
2. Tenant scope: Mixed (tenant-scoped merchant surfaces using existing host/context rails)
3. Entitlement/access level: Plan/add-on state and module access remain canonical via existing tenant entitlement resolvers
4. Canonical dependencies reused:
   - `App\Services\Tenancy\TenantCommercialExperienceService`
   - `ShopifyEmbeddedAppController`
   - `ShopifyEmbeddedCustomersController`
   - embedded shell/navigation components and existing module checklist state
5. Shopify-specific hooks preserved: embedded query context, App Bridge bootstrap, existing embedded routes/tabs, host/context fail-closed behavior
6. Setup/onboarding implications: no new identity/import architecture; customer onboarding remains tied to canonical import runs and module setup states
7. Shopify behavior preservation requirement: MT-4C tenant protections and fail-closed access logic remain unchanged
8. Non-Shopify applicability target: Later (pattern is reusable, this pass is implemented on merchant embedded surfaces now)

Intentionally deferred after this phase:
- Broad backend/operator refactors not required for merchant UX clarity
- App Store packaging work
- Additional module implementation depth beyond current entitlement/discovery visibility
- Advanced visual regression tooling (behavioral/rendering tests remain the primary guardrail today)

## Current Release State (2026-03-27)

This branch now includes the first commercialization/operator shell on top of tenant entitlements.

Implemented and navigable now:
- Embedded product shell:
  - `/shopify/app` (overview/dashboard)
  - `/shopify/app/start` (Start Here)
  - `/shopify/app/plans` (Plans & Add-ons informational)
  - `/shopify/app/integrations` (integrations placeholder surface)
- Public product surfaces:
  - `/platform/promo`
  - `/platform/contact`
- Landlord commercial control surface:
  - `/landlord/commercial` (host-locked, pricing-first admin controls for plans/add-ons/templates/tenant overrides)
- Operator diagnostics surfaces:
  - customer email timeline provider-context filters + CSV export parity
  - birthday analytics/reporting/export/comparison flows
  - campaign delivery diagnostics/provider-context sections

Integrations surface behavior (intentional in this release):
- placeholder-first, entitlement-aware cards
- setup detail drawer per integration
- deterministic read-only status registry context per card
- fallback-first guidance (manual/CSV/continue without connector)
- no live connector sync/OAuth/jobs/webhooks/API writes from this page

Commercialization/access state:
- product shell and entitlement-aware UI are in place
- public tier model is now normalized to `Starter`, `Growth`, `Pro` (legacy keys are mapped for compatibility)
- add-on model is now normalized to `referrals`, `sms`, `additional_channels`, `bulk_email_marketing`, `future_niche_modules`
- template library foundation is now normalized to `Candle`, `Law`, `Landscaping`, `Apparel`, `Generic`
- three guarded landlord-only Stripe actions are implemented:
  - customer reference create/sync
  - subscription-prep metadata sync
  - guarded live subscription reference create/sync (explicit trigger only; disabled-by-default config flag)
- guarded Stripe preflight now requires HTTPS for remote `services.stripe.api_base` endpoints (HTTP is loopback-only for local testing on `localhost`/`127.0.0.1`/`::1`)
- staging validation support is now explicit for these guarded Stripe actions:
  - run + evidence sequence is documented in `docs/operations/staging-commercial-uat-runbook.md`
  - operator evidence template is documented in `docs/operations/staging-commercial-uat-evidence-template.md`
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
    - command: `php -d memory_limit=512M ./vendor/bin/pest`
    - result: `845 passed`, `0 failed`
  - GitHub Actions results for commit `9c2502c`:
    - `linter`: `success`
    - `tests`: `success` (`ci (8.4)` and `ci (8.5)` passed)
    - `Deploy Production`: initial `failure` on push, then `success` on rerun `23687500356` after deploy-ops unblock
  - deploy-ops unblock completed in GitHub `production` environment:
    - configured `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT`, `DEPLOY_PATH`, `DEPLOY_SSH_KEY`
    - corrected server checkout branch at `DEPLOY_PATH` to `main` so workflow `git checkout main` succeeds
  - latest production rollout for commit `dbf0762` had been completed manually before deploy automation was restored:
    - deploy command: `ssh forge@129.212.138.111 'bash /home/forge/deploy_backstage.sh'`
    - health check: `curl -sS https://backstage.theforestrystudio.com/up` returned `Application up.`
  - manual SSH deploy remains available as fallback, but is no longer the primary required path while deploy secrets stay configured
- checkout and broad subscription lifecycle mutation writes are still intentionally disabled
- upgrade prompts are informational routing only
- multi-tenant completion estimate (historical snapshot at 2026-03-27): `45%`

Multi-tenant state:
- tenant-aware semantics are now established in email/birthday/provider diagnostics and shell module-state presentation
- full domain tenant isolation is still in progress and should not be overclaimed
- internal ops/inventory/pouring boundaries remain intentionally cautious and partially candle-shaped

Recommended next step after this push:
- deploy and run manual production verification of shell navigation, diagnostics filters/export parity, and integrations placeholder/drawer behavior before adding new scope
- use the staging operator runbook for landlord commercial assignment propagation checks:
  - `docs/operations/staging-commercial-uat-runbook.md`

## Strict Near-Term Execution Order (As of 2026-03-27)

1. Verify Candle Cash is trustworthy end to end for Modern Forestry (storefront truth, admin/backstage truth, reconciliation parity).
2. Fix email reliability for launch-critical reward/customer workflows.
3. Only then start broader platform expansion.

Do not start yet:
- broad multi-tenant refactors
- Shopify App Store packaging
- speculative AI automation work

## Product Architecture References (2026-03-27 Pass)

- Business concept and commercial model:
  - `docs/architecture/business-concept-and-product-architecture.md`
- Multi-tenant implementation inventory:
  - `docs/architecture/multi-tenant-inventory-2026-03-27.md`
- Entitlements/commercial shell foundation:
  - `docs/architecture/tenant-entitlements-foundation.md`

## Production Host Rules (Current Direction)

- Public marketing site: `forestrybackstage.com`
- Landlord/operator host: `app.forestrybackstage.com`
- Tenant host pattern: `<slug>.forestrybackstage.com`
- Landlord routes are host-locked; tenant directory remains read-only while commercial config writes are limited to safe scope (`/landlord`, `/landlord/commercial`, `/landlord/tenants`, `/landlord/tenants/{tenant}`).
- Unknown hosts must not silently fall back to the first tenant.

## Production DNS + Wildcard TLS Verification (2026-03-27)

Verified in production for Forestry Backstage:
- Wildcard certificate issuance for `*.forestrybackstage.com` is working.
- Tenant wildcard DNS now resolves through Cloudflare wildcard records.
- Tenant HTTPS now negotiates the wildcard cert and routes into Laravel.

Working production host model:
- public site: `forestrybackstage.com`
- landlord app: `app.forestrybackstage.com`
- tenant apps: `<slug>.forestrybackstage.com`

Operator notes (important):
- Forge DNS-01 challenge must use:
  - type: `CNAME`
  - name: `_acme-challenge`
  - target: Forge-provided `verify-<token>.ssl.on-forge.com`
  - proxy status: `DNS only` (gray cloud)
- Tenant wildcard record may stay proxied:
  - `A * -> 129.212.138.111` (Cloudflare proxied is acceptable)
- If Forge verification target returns `NXDOMAIN`, regenerate a fresh challenge in Forge and update only the CNAME target.

## Historical Auth Findings (2026-03-25, Legacy Host Check)

Observed during a historical live verification on legacy host `https://backstage.theforestrystudio.com/login` (kept for audit context; current production direction is `*.forestrybackstage.com`):
- Password resets run locally do not affect production.
- Production user `johncollinsemail@gmail.com` exists, is active/approved, and password reset was successfully applied on production (`PASSWORD_MATCH=1`).
- Google login failure is currently external-credential based, not route/UI based:
  - production log shows `POST https://www.googleapis.com/oauth2/v4/token` returning `401 invalid_client`
  - message: `The provided client secret is invalid.`

What was verified on production:
- `services.google.client_secret` loaded by Laravel matches the `.env` value fingerprint (same length/hash), so this is not a runtime config drift in the app process at verification time.
- Login Google credentials are distinct from `GOOGLE_GBP_*` credentials (no accidental key collision in current config).

Google login runbook:
1. Run local/production diagnostics (masked output only):
   - `php artisan auth:doctor-google`
   - `php artisan auth:doctor-google --token-smoke`
2. In Google Cloud Console, confirm the OAuth client ID + client secret pair are from the same OAuth credential entry.
3. Update production `.env` keys:
   - `GOOGLE_CLIENT_ID`
   - `GOOGLE_CLIENT_SECRET`
   - `GOOGLE_REDIRECT_URI`
4. Rebuild Laravel runtime config on production:
   - `php artisan config:clear`
   - `php artisan config:cache`
   - `php artisan queue:restart`
5. Retry in an incognito window and check `storage/logs/laravel.log`:
   - `invalid_client` => wrong/revoked/mismatched OAuth pair
   - `invalid_grant` => credentials accepted; test/code is intentionally invalid or expired
   - `redirect_uri_mismatch` => callback URL mismatch in Google Console

Interpretation of smoke test results:
- `invalid_client` = broken client ID/secret pair
- `invalid_grant` = credentials accepted by Google

Important:
- Do not mix login keys (`GOOGLE_CLIENT_*`) with Google Business Profile keys (`GOOGLE_GBP_*`); they are separate integrations.

## Auth Redirect Diagnostics (2026-03-26)

Ship-readiness manual validation and release checklist:
- `docs/operations/auth-tenant-ship-readiness.md`

Tenant-aware login landing decisions now emit:
- event: `auth.post_login.redirect_decision`
- category: `auth.redirect`

Redirect strategy meanings:
- `intended_url`: session `url.intended` was present and passed host/query safety checks.
- `tenant_intent`: no accepted intended URL; tenant intent existed and user membership check passed.
- `role_fallback`: no safe intended URL and no usable tenant intent; role default route used.
- `safe_fallback`: role fallback returned empty; hard fallback route used.

If tenant landing looks wrong, check in order:
1. `tenant_intent_exists`, `tenant_intent_tenant_id`, `tenant_membership_passed` in the redirect decision log.
2. `intended_present` and `intended_accepted` to confirm whether intended URL safety rejected a redirect.
3. `auth.tenant_context.resolved` log around entry/callback routes (`login`, `login.store`, `auth.google.callback`, `password.reset`, `password.update`) and compare `host`, `classification`, and `strategy`.
4. User tenant membership in `tenant_user` for the intended tenant.
5. Session reset lifecycle:
   - logout should clear tenant session context
   - failed login should not set `tenant_id`
   - successful login should consume `auth.tenant_intent`

Google OAuth callback failure severity:
- known OAuth classes (`invalid_client`, `invalid_grant`, `redirect_uri_mismatch`, `state_error`) are logged as structured warnings.
- only `unknown_oauth_failure` is additionally reported as an exception.

## Landlord Host Foundation (Phase 1, 2026-03-26)

What was added:
- New pre-auth host context resolution path for all web requests:
  - `app/Services/Tenancy/PreAuthTenantContextResolver.php`
  - `app/Support/Tenancy/HostTenantContext.php`
  - `app/Http/Middleware/ResolveHostTenantContext.php`
- Middleware is prepended to the `web` stack in `bootstrap/app.php` so host context is available before auth/login handling.
- Host behavior now supports:
  - landlord host (`app.forestrybackstage.com` in production, configurable) => landlord context (`isLandlordMode=true`), no tenant required
  - tenant host (`<slug>.forestrybackstage.com` in production) => resolves tenant by slug/subdomain
  - unknown host => unresolved context, no fallback to first tenant
- Existing auth tenant resolver (`GuestAuthTenantContextResolver`) now reuses this host resolver rather than duplicating host parsing logic.

Configuration added:
- `TENANCY_LANDLORD_HOSTS` env var (comma-separated, default `app.forestrybackstage.com`)
- `TENANCY_LANDLORD_OPERATOR_ROLES` env var (comma-separated, default `admin`)
- `TENANCY_LANDLORD_OPERATOR_EMAILS` env var (comma-separated, optional allowlist)
- `config/tenancy.php` now exposes:
  - `tenancy.landlord.hosts`
  - `tenancy.landlord.primary_host`
  - `tenancy.landlord.operator_roles`
  - `tenancy.landlord.operator_emails`

Local setup note:
- In this implementation, the first host in `TENANCY_LANDLORD_HOSTS` is treated as the landlord route domain (`tenancy.landlord.primary_host`) and is what `Route::domain(...)` uses for `/landlord*` routes.
- Host examples:
  - production example: `TENANCY_LANDLORD_HOSTS=app.forestrybackstage.com`
  - local example: `TENANCY_LANDLORD_HOSTS=forestrybackstage.test`
- Recommended local baseline:
  - `TENANCY_LANDLORD_HOSTS=forestrybackstage.test`
  - `TENANCY_LANDLORD_OPERATOR_ROLES=admin`
  - `TENANCY_LANDLORD_OPERATOR_EMAILS=`
- Fast local login bootstrap (if your expected admin login fails):
  - `php artisan users:ensure-approved your-email@example.com 'your-password' --name='Your Name' --role=admin`
  - then sign in at `/login` and open `/landlord`
- Leave `TENANCY_LANDLORD_OPERATOR_EMAILS` blank unless you explicitly want an additional email allowlist on top of role checks.

Landlord/operator routes added (landlord-host only):
- `GET /landlord` => landlord dashboard (`landlord.dashboard`)
- `GET /landlord/commercial` => landlord commercial configuration console (`landlord.commercial.index`)
- `GET /landlord/tenants` => read-only tenant directory (`landlord.tenants.index`)
- `GET /landlord/tenants/{tenant}` => read-only tenant detail (`landlord.tenants.show`)
- Guarded by `auth`, `verified`, and `landlord.operator`
- `landlord.operator` is an interim dedicated landlord check that defaults to `admin` role only (and can optionally enforce an email allowlist) until a first-class landlord role/flag exists.
- Implemented via:
  - `app/Http/Controllers/Landlord/LandlordTenantDirectoryController.php`
  - `resources/views/landlord/dashboard.blade.php`
  - `resources/views/landlord/tenants/index.blade.php`
  - `resources/views/landlord/tenants/show.blade.php`

Read-only directory fields now surfaced from existing schema/relations:
- tenant name
- slug/subdomain
- derived status label (from existing access/users/shopify/health signals)
- `created_at`
- user count
- connected Shopify stores count
- primary Shopify store/domain
- basic readiness/health indicators derived from current data

View data path prepared for host-aware UI:
- shared on every web request:
  - `hostTenantContext`
  - `hostTenant`
  - `isLandlordMode`
- wired into auth/app layouts as `data-landlord-mode` and `data-host-tenant` attributes for future branding/runtime branching.

Tests added:
- `tests/Feature/Tenancy/LandlordHostFoundationTest.php`
  - landlord host access for authorized landlord operators
  - tenant host resolution
  - unknown host no-fallback behavior
  - `/landlord`, `/landlord/tenants`, and `/landlord/tenants/{tenant}` blocked on tenant hosts
  - non-landlord-authorized users forbidden on landlord host routes

Explicit non-goals in this phase:
- no changes to post-auth `tenant.access` enforcement semantics
- no billing checkout/subscription lifecycle actions, impersonation, or unsafe landlord writes outside commercial configuration scope
- no Candle Cash/campaign/birthdays core-table changes
- no Shopify embedded/proxy/webhook behavior changes

## Shopify (Phase 1)
Required environment keys:
- `SHOPIFY_RETAIL_SHOP`
- `SHOPIFY_RETAIL_CLIENT_ID`
- `SHOPIFY_RETAIL_CLIENT_SECRET`
- `SHOPIFY_WHOLESALE_SHOP`
- `SHOPIFY_WHOLESALE_CLIENT_ID`
- `SHOPIFY_WHOLESALE_CLIENT_SECRET`
- `SHOPIFY_API_VERSION` (default `2026-01`)
- `SHOPIFY_SCOPES` (default `read_orders,read_products,read_customers`)
- `SHOPIFY_ALLOW_ENV_TOKEN_FALLBACK` (default `false`, legacy only)

OAuth (Admin) routes:
- `/shopify/auth/{store}`
- `/shopify/callback/{store}`
- `/shopify/reinstall/{store}`

CLI helper:
- `php artisan shopify:auth retail`
- `php artisan shopify:auth wholesale`

Notes:
- OAuth access tokens are stored in `shopify_stores` and encrypted at rest.
- CLI imports/sync use DB-installed OAuth tokens as primary source of truth.
- Static env access tokens are legacy fallback only when `SHOPIFY_ALLOW_ENV_TOKEN_FALLBACK=true`.
- `shopify:sync-customer-metafields` requires Admin API `read_customers` or `write_customers` scope; Customer Account `customer_*` scopes are not sufficient for Admin `customers` queries.
- Webhooks are verified with HMAC and dispatched to a sync queue (Phase 1).

## Deployment (GitHub Actions -> Production)
This repository deploys with `.github/workflows/deploy.yml`.

Triggers:
- Push to `main` (automatic deploy)
- Manual run via `workflow_dispatch` in GitHub Actions (with optional `run_tests` toggle)

Owner workflow:
```bash
git add .
git commit -m "Describe change"
git push origin main
```

Required GitHub secrets (configure in the `production` environment):
- `DEPLOY_HOST`
- `DEPLOY_USER`
- `DEPLOY_PORT`
- `DEPLOY_PATH`
- `DEPLOY_SSH_KEY` (private key for SSH access to the server)

Optional test prerequisites:
- `FLUX_USERNAME`
- `FLUX_LICENSE_KEY`

These are only needed for CI test/build when private Flux packages are required.

Server prerequisites:
- Git with the app already cloned at `DEPLOY_PATH`
- PHP 8.2+ and required extensions
- Composer 2
- Node.js + npm (this app uses Vite)
- Writable Laravel directories (`storage`, `bootstrap/cache`)
- Database connectivity from the server
- Queue worker process manager (Supervisor/systemd) if queues are active

Server deploy command sequence:
- `git fetch origin main`
- `git checkout main`
- `git pull --ff-only origin main`
- `composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev`
- `npm ci`
- `npm run build`
- `rm -f public/hot`
- `php artisan migrate --force`
- `php artisan route:clear`
- `php artisan config:cache`
- `php artisan view:cache`
- `php artisan queue:restart`

Notes:
- `route:cache` is intentionally not used because the app currently has closure routes.
- Deploy is fail-fast and concurrency-guarded so only one production deploy runs at a time.

Known push/deploy pitfalls (2026-03-26):
- GitHub Action fails before deploy steps with missing-input errors:
  - Cause: one or more required `DEPLOY_*` secrets are missing in the `production` environment.
  - Check: GitHub -> Settings -> Environments -> `production` -> Secrets and variables.
  - Required: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT`, `DEPLOY_PATH`, `DEPLOY_SSH_KEY`.
- Deploy runs but production code is stale:
  - Cause: server repo is not on `main` (for example, left on a temporary branch).
  - Check on server:
    - `cd "$DEPLOY_PATH"`
    - `git branch --show-current`
    - `git rev-parse --short HEAD`
    - `git rev-parse --short origin/main`
  - Recovery:
    - `git fetch origin main`
    - `git checkout main`
    - `git pull --ff-only origin main`
- Push succeeds but no production rollout occurs:
  - Check whether the `Deploy Production` workflow is disabled.
  - Check Actions run status and inspect the first failed step (most failures here are config/secrets, not app code).
- Security hygiene for server remotes:
  - Do not keep personal access tokens embedded in `origin` URLs on production.
  - If a tokenized remote URL is found, rotate the token and switch to SSH/deploy-key auth.

Manual deploy:
1. Go to GitHub -> Actions -> `Deploy Production`.
2. Click `Run workflow`.
3. Choose `main` and (optionally) set `run_tests` to false.

Temporarily disable deploy:
- In GitHub -> Actions -> `Deploy Production` -> `...` menu -> `Disable workflow`.

## Shopify Embedded Session Cookies
- Embedded Shopify Admin requests run inside an `admin.shopify.com` iframe, so production session cookies must allow secure cross-site usage.
- Production env should include:
  - `SESSION_SECURE_COOKIE=true`
  - `SESSION_SAME_SITE=none`
  - `SESSION_PARTITIONED_COOKIE=true`
- After changing those values, run:
  - `php artisan optimize:clear`
  - `php artisan config:clear`

## Twilio SMS Configuration
- Set `TWILIO_ACCOUNT_SID` and `TWILIO_AUTH_TOKEN`.
- Preferred: configure `MARKETING_TWILIO_SENDERS` as a JSON array of sender objects. All senders share the same `TWILIO_ACCOUNT_SID` and `TWILIO_AUTH_TOKEN`.
  - MG sender:
    - `[{"key":"toll_free","label":"Toll-free","type":"toll_free","status":"active","enabled":true,"default":true,"phone_number_sid":"PN...","messaging_service_sid":"MG..."}]`
  - Direct sender:
    - `[{"key":"local","label":"Local","type":"local","status":"active","enabled":true,"phone_number_sid":"PN...","from_number":"+15555550123"}]`
  - Mixed sender config:
    - `[{"key":"toll_free","label":"Toll-free","type":"toll_free","status":"active","enabled":true,"default":true,"phone_number_sid":"PN...","messaging_service_sid":"MG..."},{"key":"local","label":"Local","type":"local","status":"pending","enabled":false,"default":false,"phone_number_sid":"PN...","from_number":"+15555550123"}]`
  - `phone_number_sid` is metadata only.
- Optional: set `MARKETING_TWILIO_DEFAULT_SENDER` to force the default sender key.
- Backward-compatible migration fallback:
  - `TWILIO_MESSAGING_SERVICE_SID` (recommended, must start with `MG`), or
  - `TWILIO_FROM_NUMBER` (E.164 format like `+18339625949`).
- Enable provider flags:
  - `MARKETING_SMS_ENABLED=true`
  - `MARKETING_TWILIO_ENABLED=true`
- Operational verification:
  - `php artisan marketing:send-test-sms +15551234567 "Test message" --sender=toll_free`

## Candle Cash Gift Reporting
- Gift transactions now persist `gift_intent`, `gift_origin`, `notified_via`, `notification_status`, and `campaign_key` in `candle_cash_transactions`.
- Backstage surfaces a `Gift insights` tab under Candle Cash (`/marketing/candle-cash/gifts-report`) that summarizes total gifts, intent/origin/notification breakdowns, actor attribution, recent gift rows, and a simple post-gift conversion proxy.
- Use the date filters on that page to focus on a specific window and understand whether gifted customers later placed orders.

## Email Provider-Context Reporting
- Birthday analytics and exports expose provider context directly from canonical delivery metadata in `marketing_email_deliveries`.
- Supported reporting dimensions include:
  - `provider_resolution_source` (`tenant`, `fallback`, `none`, `unknown`)
  - `provider_readiness_status` (`ready`, `unsupported`, `incomplete`, `error`, `not_configured`, `unknown`)
- Embedded birthday analytics filters now include provider resolution/readiness context, and exports include matching breakdown rows.
- Campaign delivery diagnostics now show provider resolution/readiness/runtime-path summaries per campaign.
- Customer email timeline now shows row-level provider-context labels plus summary chips (tenant vs fallback paths, unsupported/incomplete attempts, and legacy/unknown rows) on `marketing.customers.show`.
- Customer email timeline now supports operator filters for `provider_resolution_source` and `provider_readiness_status`.
- Customer email timeline CSV export now has filter parity via `marketing.customers.email-deliveries.export` (`/marketing/customers/{marketingProfile}/email-deliveries/export`) and includes provider-context labels for legacy/unknown rows.
- Architecture details: `docs/architecture/birthday-provider-context-reporting.md`.

## Operational Architecture Guidance
- For cross-domain boundary decisions (tenant/platform vs reusable ops primitives vs candle-specific logic), use:
  - `docs/architecture/operational-multi-tenant-direction.md`
- This guidance is intended for future implementation runs touching customers, inventory/internal ops, order workflows, and lifecycle communications.

## Storefront Rewards Sidecar (Theme Repo)
- The current `/pages/rewards` UI sidecar lives in the separate Shopify theme repository:
  - `/Users/johncollins/projects/modernforestry-live-theme`
  - implemented in `assets/forestry-rewards.js` + `assets/forestry-rewards.css`
- Recent sidecar update delivered:
  - compact theme toggle/dropdown
  - removed top rewards-summary clutter
  - unified birthday reward + intake into one expandable card
  - collapsible opportunity cards for compact default scan
  - removed task/reward history blocks from page layout
  - mobile/desktop spacing and hierarchy polish
- Backend remains canonical for identity/rewards state; sidecar only presents and invokes existing contracts.

## Future Purchasable Add-Ons (Tenant-Scoped)
- Build future apps/modules as tenant-scoped add-ons attached to the shared platform shell.
- Reuse canonical identity and marketing architecture:
  - `marketing_profiles`
  - `customer_external_profiles`
  - `marketing_profile_links`
  - existing sync/service pipelines
- Prefer extending existing signed storefront/API contracts before adding new surfaces.
- Feature access must be tenant-scoped and billing-aware (no global hardcoded availability).
- Do not fork per-tenant architecture; use one reusable module with tenant-level configuration.
