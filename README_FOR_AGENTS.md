# START HERE

Read `SYSTEM_SNAPSHOT.md` before making changes.

## Agreement and Billing-Lane Guardrails (2026-07-16)

- Treat `agreement_versions`, `agreement_acceptances`, and `agreement_events` as immutable/append-only legal evidence. Never update or delete accepted evidence; create a new version or child amendment.
- Public proposals belong only on configured Evergrove hosts. Store only the SHA-256 token lookup and encrypted token, hash passwords, throttle attempts, never log plaintext secrets, and fail closed for expiry/revocation.
- Tenant “User Agreements” must re-resolve current tenant membership and financial access. Never expose `internal_notes`, token/password fields, raw IP, user agent, or internal audit references.
- Agreement acceptance is not billing activation. Shopify App Store merchants use `shopify_app_pricing`; direct/non-Shopify customers may use `stripe_direct`. Never charge one subscription through both providers and never route a Shopify App Store merchant around Shopify billing.
- Pricing is agreement-specific and may be à la carte. Preserve exact authorized line items, content/version hashes, provider plan/subscription references, and provider-confirmed tax/receipt values. Do not derive tax locally.
- Any future checkout or entitlement activation must pass `AgreementBillingActivationGuard` or an equally strict pre-check requiring exact accepted version, approved lane, verified active provider subscription, and audited fulfillment. Defaults stay disabled until provider evidence exists.

## Front Yard Foods Scheduling Guardrails (2026-07-15)

- Keep Class Scheduling reusable and tenant-scoped. Public signup must resolve a visible, enabled Branch and published class, then enforce capacity and normalized-email uniqueness under a database transaction.
- Use `php artisan everbranch:prepare-front-yard-foods` for demo content. It must remain idempotent, preserve memberships, prefer tenant ID 4 only when free, fall forward to the smallest open ID, and audit commercial/module changes.
- Reminder creation requires class consent plus a usable channel. Demo preparation may schedule reminders but must never deliver SMS or email automatically; live test delivery requires provider readiness and explicit action-time confirmation.
- Job images must be imported into canonical private workspace assets and retain source/license attribution. Never hotlink untracked images.
- Mobile class, enrollment, customer, message, and reminder requests must re-resolve membership, tenant, Branch access, and resource ownership on the server.

## Production Infrastructure Reality (verified 2026-07-06)

- Production is ONE DigitalOcean droplet: IP `129.212.138.111`, hostname `Backstage`, managed by Laravel Forge (`modern-forestry` / `backstage-pfw`). One nginx serves every domain: `theeverbranch.com` (canonical, incl. `app.` and tenant wildcards), `backstage.theforestrystudio.com` (legacy), `evergrovesoftware.com`, `forestrybackstage.com`. All are Cloudflare-proxied.
- MySQL lives on the same droplet (`DB_CONNECTION=mysql`). The scheduler cron (`schedule:run` every minute) is installed directly in the forge crontab and IS active, even though Forge's UI scheduler toggle looks off.
- Deploys ship from GitHub Actions on `main` via `scripts/deploy_backstage.sh`. The Forge site still tracks stale branch `agent/codex` — do not use Forge push-to-deploy.
- Prod is a Laravel-managed Forge server (server ID `1165565`, VPC "Laravel Managed", created Feb 20 2026): Laravel provisions and bills the underlying DO droplet, so it does NOT appear in John's own DigitalOcean account. Resize/scale prod through Forge (or Laravel billing), not the DO console.
- ⚠️ TRAP: a second, identically-named "Backstage" droplet (`134.209.43.25`) sits in John's personal DO account (`johncollinsemail@gmail.com`, GitHub login). It is a blank, never-provisioned box serving nothing (root-SSH verified 2026-07-06), billing ~$12/mo since Feb; it is NOT production. Always confirm the target IP is `129.212.138.111` before any prod infra action.
- Prod was resized to 8 GB RAM / 4 vCPU on 2026-07-06 (fixes the earlier `vite build` exit-137 OOM). Resize a Laravel VPS via Forge → Settings → General → Size, not the DigitalOcean console.
- Deploys are gated: `.github/workflows/deploy.yml` runs the Pest suite + asset build on every push to `main` and blocks the deploy on failure (a manual `workflow_dispatch` with run_tests unchecked is the only emergency bypass).

## Developer Control Center (landlord operator dashboard)

- Route `/landlord/developer` (`landlord.developer`), controller `App\Http\Controllers\Landlord\LandlordDeveloperDashboardController`, view `resources/views/landlord/developer/index.blade.php`. Landlord-operator only (host-locked group). Read-only.
- Live data comes from `App\Services\Operations\OperationalStatusService` (scheduler heartbeat, last backup, open integration health events, last Shopify import). It is defensively `rescue()`-wrapped so one failing probe never blanks the page.
- "Last backup" reads a cache key stamped by `php artisan ops:record-backup`. Wire that command to Forge's "run a command after backup" hook (Server → Database → Backups) so the widget reflects real backup completions.
- Three landlord-global (NOT tenant-scoped) models back the content: `AgenticChange` (recent changes log), `VisionIdea` (vision board), `ReadinessChecklistItem` (production-readiness checklist, status `done|partial|todo`). Seed/update them idempotently with `php artisan db:seed --class=DeveloperDashboardSeeder` (rows keyed on `slug`).
- **When you ship something that was on the vision board:** set the matching `VisionIdea` status to `done` (it then drops off the board — the controller filters `status != done`), flip the matching `ReadinessChecklistItem` to `done`, and add an `AgenticChange` entry. Do this in `DeveloperDashboardSeeder` (idempotent) so it stays reproducible, then run the seeder on prod.

## Everbranch Customer Electrician Tutorial Restore Rule (2026-07-01)

- The customer-facing electrician intake/tutorial is now intentionally hidden by default behind `FEATURE_CUSTOMER_ELECTRICIAN_TUTORIAL=false`.
- Hidden customer/public surfaces include:
  - the public promo `Electrician` profile
  - public access-request/demo/start business-type options for `electrician`
  - tenant `/start` electrician onboarding teaser, modal, and reopen CTA
  - tenant `/onboarding` for customer users, which now redirects back to `/start` while the flag is off
  - the tenant quick action labeled `Setup plan`
- Landlord/internal electrician setup remains intact. Do not delete the `electrician` template key, blueprint metadata, or landlord onboarding lane just because the customer-facing tutorial is hidden.
- To restore it later, turn `FEATURE_CUSTOMER_ELECTRICIAN_TUTORIAL=true` back on and keep the current filtering/redirect guards in place rather than re-adding hardcoded customer-facing electrician copy by hand.

## Collins Electric Guided Launch Rule (2026-07-11)

- Collins Electric (`collins-electric`) is the first guided electrician launch-partner workspace. It is not a 3-day trial, public self-service tenant, or billing/subscription activation.
- Use `php artisan everbranch:prepare-collins-electric --seed-demo-job` to create or refresh the tenant, apply the `electrician` blueprint, attach `johncollinsemail@gmail.com` as active verified admin, and keep SMS provider status `not_verified`.
- `collinselectric91@gmail.com` is provisioned as Collins owner until Nathan supplies another verified identity. Do not remove John's other memberships or use either email as a tenant-scoping shortcut.
- Setup interests may include `billing`, `uploads`, and `quickbooks`, but final blueprint modules must remain limited to safe workspace modules (`customers`, `field_service`, `messaging`, `reporting`) unless a future approved PR adds real fulfillment.
- QuickBooks is a reusable opt-in beta Branch with tenant-scoped OAuth plus read-only audit/sync commands. CSV/XLSX remains the concierge fallback through `php artisan field-service:import-quickbooks`. Do not enable payments, write-back, webhooks, CDC, estimator write-back, or payroll automatically. Collins may use the shared Estimator in owner/admin draft-only mode.
- Field-service tenants have one canonical Work surface: `field_service`. Hide duplicate `work_core` discovery and preserve old routes only as compatibility aliases. Member job visibility requires assignment, participation/following, a task assignment, or a mention; never rely on client filtering.
- QuickBooks lifecycle reconciliation derives Quote, Active, Needs details, Complete, and History while preserving manual overrides. Records older than one year remain searchable/history-only and must not inflate current job or receivable counts.
- Job photos selected through the iOS photo picker are private Everbranch copies. Do not crawl Apple Shared Albums or treat an iCloud URL as permanent storage.
- SMS/reminder configuration is admin-guided setup intent only. Do not enable real customer sends for Collins Electric until provider readiness, consent state, and delivery logs have passed a smoke test.

## Tenant Import + Workspace Guide Isolation Rule (2026-07-13)

- Import ownership is tenant-based, never email-based. Every OAuth callback, importer command, run, exception, normalization, banner, search result, and admin action must carry or resolve `tenant_id` and fail closed when an active tenant cannot be established.
- `store_key` is source context, not a sufficient authorization boundary. Legacy rows may be backfilled through their order or installed store, but new writes must stamp `tenant_id` directly.
- `/wiki` is protected by `tenant.access`. Modern Forestry uses the legacy production wiki; every non-flagship workspace uses the shared Everbranch guide baseline and its own tenant-specific override file. Direct links to another tenant's wiki articles must resolve as unavailable.
- Multi-membership users are expected. Switching the active workspace must switch import alerts, import history, exception queues, search, dashboards, OAuth connections, and guide content within the same session.

## Modern Forestry Facebook Scent-Share Preview Refresh Rule (2026-06-30)

- Modern Forestry scent-personality sharing is intentionally **latest-only** per account. Retakes should update the current public share target instead of creating immutable historical share snapshots.
- Facebook preview freshness depends on a result-derived revision token appearing in both:
  - the public scent-share page URL
  - the scent-share `og:image` / `twitter:image` URL
- Do not regress the share metadata back to a token-only/static image URL. Facebook can cache that preview aggressively and keep showing the old card after a retake.
- Keep the current `socialShareConfig` payload shape. The revision change belongs in the returned `share_url`, not in a new API field.
- Do not switch this flow to per-retake token rotation or immutable share history unless product behavior is intentionally being redesigned.

## Modern Forestry Live Checkout Recovery + Account First Paint Rule (2026-06-30)

- End-to-end checkout now prioritizes producing a usable Shopify checkout URL over preserving every signed-in prefill detail.
- Signed-in mobile checkout recovery order is now:
  - first attempt unchanged
  - retry without `deliveryAddress.phone`
  - retry without the full `delivery` block
  - if Shopify still returns `Phone is invalid`, create a clean anonymous Shopify checkout and return it as a successful recovery
- Treat `anonymous_checkout` as a designed fallback, not a visible customer error. Keep it operator-facing through logs/metadata only.
- Keep `buyerIdentity.phone` omitted in the signed-in Modern Forestry mobile path.
- `ModernForestryMobileCheckoutService` now emits one `checkoutAttemptId` across retries/fallback so phone repros can be matched to production logs quickly.
- `GET /api/mobile/v1/modern-forestry/account` should stay lightweight on first paint:
  - do not reintroduce synchronous per-line product-detail enrichment
  - keep non-critical sections fail-soft so wishlist/support/rewards hiccups do not blank the whole Account tab
  - preserve timing logs for `customer`, `orders`, `wishlist`, `support`, `rewards`, and total payload time

## Modern Forestry Mobile Phone Invalid Recovery Rule (2026-06-30)

- The signed-in Modern Forestry mobile checkout path already omits `buyerIdentity.phone`; do not add it back.
- If Shopify returns `Phone is invalid` during mobile cart refresh, treat it as a recovery case before treating it as an error:
  - first retry without `deliveryAddress.phone`
  - if needed, retry once more without the entire `delivery` block
- If recovery succeeds, return the recovered cart normally and do not promote it to a visible customer error state.
- If recovery fails, include diagnostics metadata in the API error so the iOS bag can explain the cart-refresh problem without exposing raw Shopify phrasing.
- Current root-cause theory is stale Shopify cart or delivery identity state, not the iOS client sending `buyerIdentity.phone`.
- The Home API may still emit shell/stale diagnostics for operators and tests, but the iOS Home surface should keep that context off-screen.
- Instagram Story sharing must use the native `instagram-stories://share` composer route. Do not cargo-cult the Facebook `source_application` query string onto Instagram, or the handoff can degrade into opening the share link page instead of the Story publisher.
- Device-QA caveat: the current Debug iPhone build still points at the live `app.theeverbranch.com` mobile API, so a phone repro can persist even when the local Laravel patch is correct. Separate “local code fixed” from “backend deployed” before assuming the recovery logic regressed.

## Modern Forestry Mobile Checkout + Performance Rule (2026-06-29)

- Mobile checkout is Shopify Storefront Cart API based when the storefront token is available. Do not regress it to a handcrafted permalink-only flow. The intended path is: validate bag lines against Laravel product detail, create Shopify cart, apply buyer identity, attach delivery address when available, and return Shopify `checkoutUrl`. Anonymous checkout remains supported.
- Mobile Candle Cash rule: if rewards redemption hits an existing live issued storefront code, prefer returning that reusable code to the iOS client instead of a dead-end blocked error. The bag should only show a lower total once Shopify's own checkout estimate reflects the discount; never fake the subtraction locally.
- Buyer identity phone is stricter in Shopify than local app formatting. Only send `buyerIdentity.phone` when it normalizes to E.164; omit non-normalizable values or signed-in checkout can fail with `Phone is invalid`.
- Mobile sessions can be stale even when the phone thinks the user is signed in. Refresh/validate the session before depending on signed-in checkout, Candle Cash, or account-linked bag behavior.
- iPhone Mirroring auth QA note: if the Shopify sign-in sheet opens, Continue returns to the app still signed out, or Account appears stuck at a sign-in/loading state after a fresh install, fully close the app from the iPhone app switcher and reopen it before diagnosing the build or backend. This has cleared stale auth presentation/session state during device testing.
- `/api/mobile/v1/modern-forestry/home` is the slowest mobile endpoint on cold cache. It now serves a local shell first and defers full Shopify-backed refresh; continue moving the app toward stale-while-revalidate bootstrap so bag, product, and shop navigation do not feel blocked by Home.
- Home boot rule: do not add root-level iOS startup tasks that wait on Candle Cash cleanup, checkout recovery, or session-linked bag repair. Keep reward-release and bag-repair work scoped to explicit Bag / checkout actions, or Home can look frozen even when the regression is unrelated to Home itself.
- `/api/mobile/v1/modern-forestry/products/{handle}` uses short server-side caching for repeat product detail access.
- Production deploy warning: GitHub Actions can fail during `vite build` with exit code 137 under memory pressure. For backend-only PHP changes, a manual SSH deploy without rebuilding assets is acceptable, followed by `optimize:clear`, config/route/view cache rebuilds, `queue:restart`, and nginx/php-fpm reload when available. A failed asset build can leave the API feeling degraded or inconsistent until caches/processes are reset.

## Everbranch Readiness Operating Rule (2026-05-21)

- Everbranch is the product/platform brand.
- Modern Forestry is the flagship tenant and must remain stable; do not treat Modern Forestry-specific code as generic platform capability unless that generalization is explicitly implemented and tested.
- Product display labels live in `config/everbranch.php`; prefer those labels over new hardcoded platform-brand strings.
- Route/page ownership for brand and navigation work is tracked in `docs/operations/everbranch-route-page-ownership-inventory.md`.
- Agents must update the relevant README, `SYSTEM_SNAPSHOT.md`, readiness doc, runbook, or changelog after meaningful work. UI-affecting work must also update `docs/ui/UI_CHANGELOG.md`.
- Shopify is the flagship integration path, but Everbranch must not require Shopify for every customer. Setup/readiness work should account for Shopify, Square, CSV import, manual import, and future connector paths.
- Android and iOS mobile app readiness are product requirements. Modern Forestry customer catalog APIs remain product-specific; the separate cross-tenant Everbranch contract lives under `/api/mobile/v1` and follows the Everbranch tenant mobile rule below.
- Module store work must use `config/module_catalog.php`, `TenantModuleCatalogService`, and `TenantModuleAccessResolver`. Discovery never activates access; only verified, audited commercial fulfillment may change paid entitlements while lifecycle flags are enabled.
- Custom module requests are intake/triage records only. Do not convert them into modules, entitlements, quotes, invoices, billing, or mobile/job/photo/messaging implementations without a separate approved PR.
- Plan selection is commercial intent only until a future approved billing activation PR. Do not turn plan interest or billing lane interest into checkout, subscriptions, quotes, invoices, payment links, module installs, or entitlements.
- The landlord commercial intent gate is decision support only. Do not add charge, checkout, subscription, invoice, module install, or entitlement activation actions to it without a separate approved billing activation PR.
- Do not activate checkout or broad subscription lifecycle automation until the billing readiness gates pass and the activation is explicitly requested.
- Billing lane rule: Shopify App Store merchant app charges should use Shopify App Pricing/Billing in a future approved PR; keep Stripe direct billing separate for direct SaaS, custom, service, manual contract, or non-Shopify lanes.
- Shopify privacy webhook rule: compliance webhooks must verify `X-Shopify-Hmac-Sha256`, record minimal auditable evidence, and avoid destructive deletion/anonymization unless a separate tested privacy policy/runbook explicitly approves it.
- Shopify external evidence rule: do not mark Partner Dashboard, Shopify CLI deploy/release, dev-store install/reinstall, app proxy, or live privacy webhook delivery evidence complete unless artifacts are stored under `docs/operations/evidence/shopify/`.
- Shopify scope/branding rule: do not change TOML scopes, app name, or handle until `docs/operations/shopify-scope-branding-decision-record.md` has an approved decision and matching tests/evidence.
- Current Shopify evidence target: use Partner/dev dashboard app `Modern Forestry Backstage`, handle `modernforestrybackstage`, and dev store `modernforestry.myshopify.com` until a future public Everbranch Shopify app branding decision is approved.
- Current Shopify evidence packet: `docs/operations/evidence/shopify/2026-05-21/` contains PR 18 read-only CLI app-info evidence and partial app-proxy health evidence. Partner Dashboard screenshots, deploy/release output, dev-store install/reinstall, and live privacy webhook delivery rows are still pending.
- Current Shopify screenshot pack: use `docs/operations/evidence/shopify/2026-05-21/screenshot-manifest.md` and `docs/operations/evidence/shopify/2026-05-21/operator-checklist.md` before any deploy/release decision.
- Everbranch brand assets live in `public/brand/everbranch-*.svg` and are referenced through `config('everbranch.brand_assets')`; do not reintroduce hardcoded Forestry Backstage brand assets on Laravel platform surfaces.
- Retired Backstage visual assets (`public/brand/forestry-backstage-*`) are intentionally removed. Do not restore them for app-shell, auth, public, email, or share-image fallbacks.
- Do not confuse retired Backstage visual branding with the current Modern Forestry Shopify app identity. Shopify is still the flagship integration path, and the TOML/Partner app name/handle must remain unchanged until `docs/operations/shopify-scope-branding-decision-record.md` approves a rename with matching evidence.
- Evergrove is the broader company/ecosystem name; Everbranch is the product/platform name. Use `config/everbranch.php` for product/company/landlord/flagship/legacy labels instead of scattering literal brand strings.
- User-facing copy should prefer human labels from `config('everbranch.display_language')`: workspace address/type, setup, feature, access, status, setup plan, and plan interest. Avoid showing technical terms like `tenant slug`, `metadata`, `entitlement`, `canonical`, `module key`, or `provisioning` unless the surface is explicitly internal/operator-focused.
- Self-service readiness dashboard rule: `/landlord/readiness` is status/control visibility only. Do not use it to approve launch, activate billing, install modules, change entitlements, or imply generic mobile readiness.
- Access lane rule: keep the four doors distinct. Public Everbranch explains/request-access/demo/login; tenant app users work in their workspace and use `/start` only for setup status; landlord operators manage provisioning/control under `/landlord`; demo/sandbox tenants must be visibly labeled and must not be confused with Modern Forestry production-alpha usage.
- Test access rule: `php artisan everbranch:seed-access-surfaces` is the safe local/staging entry for standard landlord, Modern Forestry, demo, and sandbox accounts. Do not add direct impersonation or login bypass controls unless they are audited, reversible, visibly bannered, and separately tested.
- Tenant blueprint rule: landlord-created tenant blueprints live in `TenantAccessProfile.metadata.tenant_blueprint` plus existing `tenant_setup_statuses` fields. Use `config/tenant_blueprints.php` and `TenantBlueprintProfileService`; do not create industry-specific route forks, install modules, activate entitlements, or start connector/billing automation from blueprint choices.
- Work management blueprint rule: project/task/assignment/communication/photo/file/mobile-capture fields are blueprint intent only. Do not create project/task/upload/message tables, storage flows, notifications, mobile APIs, modules, billing, or entitlements from these fields without a separate approved implementation PR.
- Blueprint review rule: landlord blueprint review/edit state lives in `TenantAccessProfile.metadata.tenant_blueprint` and is landlord-only. Do not expose `blueprint_internal_notes` or `blueprint_next_action` on tenant-facing pages; use `onboarding_next_action` for tenant-facing guidance.
- Blueprint module recommendation rule: use `TenantBlueprintModuleRecommendationService` for blueprint-driven module display states. Recommendations, requested/planned/future labels, and work-management module families are display-only; they must not create modules, install modules, grant entitlements, start billing, run imports, enable uploads/messaging, or create mobile APIs.
- Do not add new modules, expose roadmap/internal modules, or rewrite navigation/UI broadly during readiness work. Prefer audits, guardrail tests, documentation, and narrow coherence fixes.

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
- Phase 2 instrumentation hardening checkpoint (2026-04-20):
  - fixed embedded React runtime crash on messaging analytics path by stabilizing action-search store snapshots
  - baseline storefront funnel tracking is no longer campaign-gated (direct/organic sessions can post session, landing, product, cart events)
  - explicit payload attribution fields now flow through funnel ingestion (UTM + Meta `fbclid`/`fbc`/`fbp`)
  - `purchase` is now a distinct storefront event type (not aliased to `checkout_completed`)
  - Shopify order ingest now records deterministic storefront linkage + confidence and writes a durable purchase lineage event (`shopify_storefront_purchase`)
  - migration required: `2026_04_20_150000_add_storefront_linkage_columns_to_orders_table`
  - authenticated click-path coverage config added: `tests/e2e/click-path-routes-auth.json` (`npm run qa:click-path:auth`)
- Phase 3 analytics usefulness checkpoint (2026-04-20):
  - messaging analytics home is now decision-first with four panels:
    - Attribution Quality
    - Acquisition Funnel
    - Retention
    - Action Queue
  - panel computation lives in `MessageAnalyticsService` (`decision_panels` payload)
  - controller now loads decision panels on home tab only (`include_decision_panels`)
  - legacy message-only cards are still present but visually demoted as secondary operational detail
  - QA/handoff notes: `docs/qa/phase-3-analytics-decision-useful.md`
- Phase 4 workflow rollout checkpoint (2026-04-20):
  - lifecycle rollout service is live: `app/Services/Marketing/LifecycleWorkflowRolloutService.php`
  - Flows (`/marketing/automations`) now renders workflow-by-workflow status with blockers + one-click staging actions
  - first three workflows are stageable into manual approval queues:
    - `welcome`
    - `winback`
    - `post_purchase_cross_sell`
  - staging route/action:
    - `POST /marketing/automations/{workflow}/prepare`
    - `MarketingPagesController::prepareAutomationWorkflow`
  - lifecycle staging writes auditable rows to `marketing_automation_events` (queued/skipped/suppressed with reasons + cooldown checks)
  - wishlist remains operator/manual-first through existing queue primitives; cart/checkout abandonment remain blocked until token/profile continuity thresholds are met
  - QA/handoff notes: `docs/qa/phase-4-workflow-rollout.md`
  - regression coverage:
    - `tests/Feature/Marketing/LifecycleWorkflowRolloutServiceTest.php`
- Phase 5 AI budget readiness checkpoint (2026-04-20):
  - advisory-only budget readiness service is live:
    - `app/Services/Marketing/AiBudgetReadinessService.php`
    - `app/Services/Marketing/AiBudgetRecommendationService.php`
  - Meta spend ingestion baseline is live:
    - `app/Services/Marketing/MetaAdsSpendSyncService.php`
    - `app/Console/Commands/MarketingSyncMetaAdsSpend.php`
    - `app/Models/MarketingPaidMediaDailyStat.php`
    - migration: `2026_04_20_200000_create_marketing_paid_media_daily_stats_table.php`
  - Message Analytics Home now includes `AI Budget Readiness (Advisory only)`:
    - readiness tier + scorecard + guardrail matrix + recommendation queue + next-fix list
  - autonomous budget control remains blocked by policy:
    - no auto budget mutation
    - no auto pausing
    - no auto channel reallocation
  - QA/handoff notes: `docs/qa/phase-5-ai-budget-readiness.md`
  - regression coverage:
    - `tests/Feature/Marketing/AiBudgetReadinessServiceTest.php`
    - `tests/Feature/Marketing/MetaAdsSpendSyncServiceTest.php`
- Phase 7 live storefront activation checkpoint (2026-04-21):
  - production app proxy runtime is healthy (`/apps/forestry/health` returns 200 from storefront context)
  - Shopify Customer Events pixel is connected (`gid://shopify/WebPixel/2117271811`) but recent `web_pixel` event flow is still absent
  - published theme embed for Forestry tracking is currently inactive on the live main theme (`settings_data.json` has no Forestry app-embed block)
  - organic funnel emission remains sparse until merchant-side app embed activation is completed in Shopify Theme Editor
  - diagnostics/verification commands:
    - `php artisan marketing:diagnose-storefront-tracking --tenant-id=1 --store=retail --days=30 --json`
  - latest runtime hardening shipped in extension assets:
    - checkout_started now also emits on checkout form submit paths (not only click hooks)
    - checkout token extraction now supports checkout URLs before navigation
  - phase-7 verification notes: `docs/qa/phase-7-live-storefront-activation.md`
- Phase 8 linkage continuity checkpoint (2026-04-21):
  - storefront runtime now injects `_mf_*` linkage properties into add-to-cart forms to improve checkout/order continuity.
  - order linkage service now reads fallback continuity signals from Shopify order `line_items[].properties[]`.
  - attribution source-meta builder now records `line_item_property_signals` for diagnostics.
  - diagnostics command remains:
    - `php artisan marketing:diagnose-storefront-tracking --tenant-id=1 --store=retail --days=30 --json`
  - phase-8 verification notes: `docs/qa/phase-8-linkage-continuity.md`
- Embedded product shell is live and navigable:
  - `/shopify/app` (overview/dashboard)
  - `/shopify/app/start`
  - `/shopify/app/plans`
  - `/shopify/app/integrations`
  - `/shopify/app/assistant` (`AI Assistant`)
  - `/shopify/app/assistant/opportunities`
  - `/shopify/app/assistant/drafts`
  - `/shopify/app/assistant/setup`
  - `/shopify/app/assistant/activity`
- AI Assistant foundation status (2026-04-10):
  - tenant-aware/tier-aware access and page rendering are driven from canonical module access services
  - `Start Here` is tenant-facing and intentionally fast (welcome + state strip + next-click actions + what-it-helps-with)
  - `Top Opportunities` is recommendation-backed and tenant-facing (top 5 paginated cards with explainable why-lines, plain-English priority labels, and one next action per card)
  - `Setup` is tenant-facing and checklist-based (up to 6 plain-English readiness cards with one obvious action each)
  - `Draft Campaigns` is now a tenant-facing human-review page (recent/pending drafts list + simple `Review Draft` editor + recommendation-to-draft creation actions)
  - `Activity` is now a tenant-facing history page (recent opportunities, drafts, approvals/rejections, and key status changes with paginated older history)
  - stage 6 hardening is in place:
    - tier matrix: `Starter` preview-only, `Growth` (`Start Here`/`Top Opportunities`/`Setup`), `Pro` (+`Draft Campaigns` + `Activity`)
    - AI surface gating is capability-driven (`required_capability`) and routes fail closed when locked
    - assistant nav/search hides locked/coming-soon child surfaces for non-eligible tenants
    - landlord entitlement/module overrides still flow through canonical resolver decisions
    - embedded shell uses tenant-scoped cached capability summaries to avoid repeated resolver work
  - tenant-facing module state labels are standardized (`Ready`, `Needs Setup`, `Locked`, `Coming Soon`)
  - Modern Forestry alpha override remains centralized in `ModernForestryAlphaBootstrapService` and explicitly configures `ai` module state
  - no autonomous send behavior is implemented
- Public product surfaces are implemented:
  - `/platform/promo`
  - `/platform/contact`
- Landlord commercial config surface is implemented:
  - `/landlord/commercial` (host-locked, pricing-first admin controls)
- Authenticated onboarding wizard is implemented (2026-04-12):
  - UI surface:
    - `/onboarding` (tenant-aware; shared Shopify/direct wizard shell)
  - Wizard API seams consumed by the UI (backend-driven; no duplicate client canon):
    - `GET /api/onboarding/wizard-contract`
    - `POST /api/onboarding/blueprint-draft` (autosave)
    - `POST /api/onboarding/blueprint-finalize`
    - `GET /api/onboarding/blueprint-post-provisioning-summary` (read-only orchestration; gated)
  - Internal-only harness/debug surface (gated by `app.debug` + feature flag):
    - `/internal/onboarding/harness`
  - Workflow UI primitives (shared polish layer):
    - `resources/css/forestry-ui.css` (`fb-workflow-*`, `fb-panel*`, `fb-stepper*`, `fb-state*`, `fb-module-card*`)
- Diagnostics/operator surfaces are implemented and test-covered:
  - customer email timeline provider-context filters + CSV export parity
  - birthday analytics/reporting/export/comparison
  - campaign delivery diagnostics/provider-context visibility
  - storefront tracking diagnostics hardening (2026-04-09):
    - Messaging Setup now provides explicit tracking inventory + health summary (theme embed inference, pixel connection, scope verification, recent events, last event, checkout completion seen/not-seen)
    - Messaging Analytics now includes a storefront tracking health card and raw diagnostics payload
    - message-level storefront funnel summary now renders `checkout_completed`
    - Shopify-native analytics/report scope status is surfaced, but native analytics/report API reads are still not wired
- Integrations is placeholder-first:
  - setup drawer exists
  - read-only deterministic status registry exists
  - entitlement-aware states (`connected`, `setup_needed`, `locked`, `coming_soon`) exist
  - no real connector sync/OAuth/jobs/webhooks/API writes exist
- Agentic discovery + brand graph backend release is merged and live (2026-04-10):
  - tenant-scoped discovery persistence now exists (`tenant_discovery_profiles`, `tenant_discovery_pages`)
  - backend discovery services now resolve canonical brand/domain/audience/trust/merchant signals
  - machine-readable endpoints are available:
    - `/.well-known/brand-discovery.json`
    - `/api/public/discovery/brand/{tenant}`
    - `/api/public/discovery/structured/{tenant?}`
    - `/sitemaps/discovery.xml`
  - audit command is available:
    - `php artisan modern-forestry:audit:domains`
  - Modern Forestry defaults seed via existing alpha bootstrap flow (idempotent/non-destructive)
  - deployment status:
    - `main` commit `cdfce8d` deployed by GitHub Actions run `24220680927` (`Deploy Production`: `success`)
  - known external operational blocker remains:
    - `theforestrystudio.com` custom-domain stale render/cache mismatch is still outside backend-only control (diagnostics are now in place)
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
  - canonical landlord host (production): `app.theeverbranch.com`
  - canonical tenant host pattern (production): `<slug>.theeverbranch.com`
  - legacy domains are edge-redirect sources only (Cloudflare), not runtime-accepted hosts
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
- canonical Everbranch TLS must exist for `theeverbranch.com`, `app.theeverbranch.com`, and `*.theeverbranch.com`
- legacy domains must be redirected at edge with path/query preservation
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
- `TENANCY_CANONICAL_BASE_DOMAIN`
- `TENANCY_TENANT_BASE_DOMAINS` (comma-separated list for allowed tenant subdomain bases)
- `TENANCY_LEGACY_BASE_DOMAINS`
- `TENANCY_LANDLORD_PRIMARY_HOST`
- `TENANCY_LANDLORD_HOSTS` (comma-separated list)
- `TENANCY_LANDLORD_OPERATOR_ROLES` (comma-separated list, default `admin`)
- `TENANCY_LANDLORD_OPERATOR_EMAILS` (optional comma-separated allowlist)
- `config('tenancy.domains.canonical.*')`
- `config('tenancy.domains.legacy.*')`
- `config('tenancy.domains.tenant_base_domains')`
- `config('tenancy.domains.public_redirect.*')`
- `config('tenancy.landlord.primary_host')`
- `config('tenancy.landlord.hosts')`
- `config('tenancy.landlord.alias_hosts')`
- `config('tenancy.landlord.operator_roles')`
- `config('tenancy.landlord.operator_emails')`

Local routing note:
- `config('tenancy.landlord.primary_host')` is canonical (`TENANCY_LANDLORD_PRIMARY_HOST`) and is used for named route generation.
- Distinguish host examples in docs:
  - production canonical host: `app.theeverbranch.com`
  - local example host set: `app.theeverbranch.test`
- Keep local examples explicit in docs/config comments so operators do not assume the full `hosts` list is domain-bound in routing.
- Fast local auth bootstrap path already exists and should be preferred over ad-hoc DB edits:
  - `php artisan users:ensure-approved your-email@example.com 'your-password' --name='Your Name' --role=admin`
  - this sets role, password, active/approved state, and verified email for local login.

Authorization note:
- Landlord routes use dedicated middleware `landlord.operator` instead of tenant-facing `role:admin,manager`.
- Interim safety model: default `admin` role access only, with optional landlord operator email allowlist.
- TODO for future hardening: replace role/email interim rules with a first-class landlord operator role/flag.

Operational runbooks:
- `docs/operations/domain-cutover-everbranch-runbook.md`
- `docs/operations/domain-cutover-everbranch-rollback.md`
- `docs/operations/domain-cutover-everbranch-smoke-checklist.md`

Shopify cutover guardrails:
- ship URL changes from source `shopify.app.toml` (do not trust stale generated deploy artifacts)
- reauthorize required stores after cutover
- verify/repair webhook callbacks after cutover
- keep Shopify App Store readiness evidence in sync with `docs/operations/everbranch-shopify-readiness-audit.md`
- do not claim Shopify App Store readiness until Partner Dashboard values, privacy webhooks, scopes, and live install/reinstall evidence are verified
- do not expose Stripe checkout inside Shopify embedded/App Store merchant flows unless a future compliance decision explicitly approves a non-App-Store distribution lane

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

## Modern Forestry Mobile Live Deploy Cohesion (2026-06-30)

- The Modern Forestry mobile app is now sensitive to partial live deploys in two specific places:
  - Home boot: the cached-home flow requires both `app/Services/Mobile/ModernForestryMobileProductCatalogService.php` and `app/Jobs/RefreshModernForestryMobileHomeCache.php`. If the service expects the queued job but the job is missing, Home can 503 before the shell payload returns.
  - Checkout error handling: `app/Http/Controllers/Mobile/ModernForestryProductCatalogController.php`, `app/Services/Mobile/ModernForestryMobileCheckoutService.php`, and `app/Services/Mobile/ModernForestryMobileCheckoutException.php` must stay in sync. If the controller calls `$exception->diagnostics()` but the exception class is still old, Bag shows a raw HTTP 500 even though the intended checkout recovery path may be fine.
- Treat these files as one deployment unit for Modern Forestry mobile changes:
  - `app/Http/Controllers/Mobile/ModernForestryProductCatalogController.php`
  - `app/Services/Mobile/ModernForestryMobileProductCatalogService.php`
  - `app/Services/Mobile/ModernForestryMobileCheckoutService.php`
  - `app/Services/Mobile/ModernForestryMobileCheckoutException.php`
  - `app/Jobs/RefreshModernForestryMobileHomeCache.php`
- Real-device QA after syncing those files confirmed:
  - Home recovered and warmed from shell to fresh payload.
  - Account loaded signed-in data again.
  - Rewards resumed snapshot-first behavior.
  - Bag stopped surfacing the stale HTTP 500 after a successful refresh.
  - Native checkout opened Shopify successfully from the bag.

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

## Modern Forestry Mobile Rewards Latency Note (2026-06-30)

- If Rewards or Account suddenly feels like it takes around 8-10 seconds to become live after sign-in, check whether mobile requests are repeatedly calling Shopify Customer Account GraphQL just to resolve the same customer identity.
- `ModernForestryMobileCustomerSessionService` now caches resolved customer identity by token hash for a short TTL clamped to JWT expiry. Preserve that behavior unless you are intentionally changing the auth trust model.
- Signed-in mobile flows should continue omitting buyer phone in checkout identity and should continue treating Laravel as the canonical profile store after identity resolution.
- On the client side, the app now kicks Account and Rewards refreshes together. Do not reintroduce a single serialized dashboard bootstrap path unless you also accept slower Rewards first paint.
## Everbranch Tenant Mobile Rule (2026-07-10)

- `../everbranch-mobile` is the cross-tenant Everbranch app (`com.everbranch.app`) and its canonical remote is the private `johncollinsgit/everbranch-mobile` repository. Do not merge its concerns into the Modern Forestry SwiftUI customer app or its product-catalog APIs.
- Everbranch app 1.3.0 consumes mobile contract v2 plus Field Service contract v3. Say Branch in tenant-facing copy, keep canonical `module_key` identifiers internally, return `branches`, and preserve `modules` plus `/modules/...` only as temporary compatibility aliases.
- Tenant bottom navigation is `Home`, `Work`, `Search`, `Account`, `Branches`. Field-service Work opens Calendar first; Workspace Pulse belongs in Account. Do not restore duplicate Work/Field Service entries or technical implementation copy on Home.
- Operational Branches must provide a meaningful native workflow. Do not restore generic Share controls or expose summary-only/placeholder catalog entries.
- Work Branch uses canonical `work_core`; resolve orders/jobs/clients from tenant blueprint and experience signals. Never accept a client-selected vertical.
- Messaging Branch aggregates store keys server-side and reuses `MessagingResponseInboxService` plus channel readiness rules. Modern Forestry App threads are only `source_type=modern_forestry_app`, `store_key=retail`, tenant 1, and require an eligible authorized app device.
- Mobile APIs live under `/api/mobile/v1`. Use browser Fortify + one-time S256 PKCE exchange and Sanctum device tokens; never accept password credentials in the app or persist tokens outside Keychain/Android Keystore.
- Resolve `{tenant}` only through authenticated memberships with `EnsureMobileTenantAccess`, then use `TenantContext`, canonical roles, and `TenantModuleAccessResolver`. Scope every referenced job, customer, store, channel, module, and billing record again on the server.
- A module is absent unless its canonical `mobile.status` is `ready` or `beta`, its contract version is supported, and the tenant is entitled. Payloads may use only the finite primitives in `TenantMobileModuleRegistry`; they may not send JavaScript or arbitrary remote UI.
- Branches is the mobile name for the module store. Its discovery and gates must continue through `TenantModuleCatalogService` and `TenantModuleAccessResolver`. A client assertion never grants access.
- Tenant header branding comes from `TenantDiscoveryProfile`, falls back to the tenant name, and uses the published Modern Forestry wordmark for tenant 1 when no profile logo exists. Only the resolved tenant `admin` role may update mobile branding, and the change must stay audited.
- Trade Home metrics come from the tenant blueprint and tenant-scoped job records. Preserve the definitions for in-progress jobs, gross/contract value, distinct crew assignment, and potential/estimate/quoted pipeline work; never accept a client-selected work type or aggregate.
- Landlord access is not tenant membership. Authorized operators may reuse the current device session to switch context, but landlord APIs must still pass `MobileLandlordAccessService`. Landlord navigation is Home, Tenants, Tickets, Reports, and Account; never render tenant Work or Branches there.
- Tenant support tickets are a base mobile service. Tenant reads, creates, and replies must resolve through the current tenant; landlord assignment, replies, waiting, and resolution use the separate landlord routes and audit layer.
- Landlord reporting may expose catalog-derived MRR, tenant/user/activity totals, tenant mix/growth, and per-tenant users/Branch readiness only through landlord-authorized payloads. Do not add portfolio information to normal tenant bootstrap.
- Every mobile-store Branch needs a purpose icon and useful owned-state product/setup copy. Do not restore generic Share actions, inert summaries, or client-only availability decisions.
- Mobile billing is a US-only, system-browser Stripe handoff behind existing checkout and lifecycle flags. Keep non-US purchase CTAs closed, maintain idempotent webhook/audit behavior, and recheck Apple/Google rules immediately before submission.
- Landlord direct Stripe invoices live at `/landlord/invoices` and are only for approved Everbranch service or Evergrove implementation/supplemental/milestone work. Keep them behind `EVERBRANCH_STRIPE_INVOICING_ENABLED` plus tenant allowlisting, reject Shopify/third-party pass-through lines, mirror Stripe-confirmed totals/tax/receipt links, and never let invoice payment mutate module entitlements.
- New-module work is incomplete until the catalog declaration, tenant scoping, entitlement checks, provider/schema, supported actions, backend/client tests, phone screenshots, and relevant READMEs are updated. The exact checklist is in `docs/architecture/everbranch-mobile-platform.md`.

## QuickBooks Branch + Financial Access Rule (2026-07-13)

- QuickBooks is the shared `quickbooks` beta Branch. Do not create tenant-specific QuickBooks forks. Discovery in onboarding is interest only; it must never create an entitlement, begin OAuth, run an import, or activate billing.
- OAuth, full audits, and live sync require an enabled tenant entitlement plus a verified tenant owner/admin. Commands must fail closed when the Branch is not enabled.
- Keep the connector read-only and on demand. Do not add payments, QuickBooks write-back, webhooks, CDC, recurring sync, Estimator activation, or payroll activation without a separate approved release.
- Store OAuth tokens, realm identifiers, raw source snapshots, and audit payloads encrypted and tenant-scoped. Never print raw QuickBooks records, customer details, or invoice notes to logs, CI, chat, or support tickets.
- Do not equate a QuickBooks line description with a field job. Import every estimate/invoice as a financial document, classify job evidence through `QuickBooksJobEvidenceClassifier`, and leave insufficiently evidenced documents in owner/admin review. Team members must never receive standalone financial-document search results or detail routes.
- Financial documents and lines remain separate from field-service jobs. Link or create a job only from source evidence, preserve manual jobs, use provider/type/external-ID identities, and prove repeated imports remain duplicate-free.
- QuickBooks `PrivateNote` content is owner/admin-only. Team members may receive operational customer memos and work-line descriptions, but not financial reports, amounts, receivables, P&L wages, contract labor, price-book costs, billing controls, or integration credentials.
- Dashboard ranges are `1d`, `1w`, `1m`, `30d`, and `ytd`; `1m` means current calendar month and is the default. QuickBooks reports retain their requested report period and must not be relabeled to match a dashboard filter.
- Collins-specific ownership and workflow notes belong in `docs/collins-electric-access-and-quickbooks.md`; shared connector behavior belongs in canonical services/config/tests.
