# Everbranch System Inventory

> **Generated:** 2026-07-04 · Read-only survey. No code was modified. No secret values are printed — all credential/env references are by key name only.
> **Repo root surveyed:** `/Users/johncollins/Code/myapp` (plus sibling clients under `/Users/johncollins/Code/`)
> **Git:** branch `main`, working tree **not clean** (14+ uncommitted files at time of survey), many stale agent/release branches present.

---

## 1. Executive Summary

"Everbranch" (product/brand umbrella; flagship tenant is **Modern Forestry**, a candle / home-fragrance business) is a **multi-tenant SaaS monolith** built on **Laravel 12 + Livewire 4 + React islands**, deployed to a single Laravel Forge host, with several satellite clients:

| Surface | Tech | Location |
|---|---|---|
| **Core web app / back office** | Laravel 12, PHP 8.2+, Livewire 4, Livewire Flux 2, Tailwind 4, React 18 islands | `/Users/johncollins/Code/myapp` |
| **Embedded Shopify admin app (retail)** | Blade shell + React (Polaris) SPA in Shopify Admin iframe | `myapp/resources/js/shopify/*`, `resources/views/shopify/*` |
| **Embedded Shopify admin app (wholesale)** | Second Shopify app, same codebase | driven by `shopify.app.wholesale.toml` |
| **Shopify storefront extensions** | Theme App Extension + Web Pixel Extension (attribution) | `myapp/extensions/*` |
| **Shopify Online Store 2.0 theme** | Liquid, heavily app-laden | `/Users/johncollins/Code/modern-forestry-theme-159737250051` |
| **Internal field-service mobile app ("Everbranch Work")** | Expo SDK 57 / React Native 0.86 (iOS-first) | `myapp/everbranch-work-app` |
| **Consumer mobile app (Modern Forestry)** | Native Swift / SwiftUI (Xcode) | `/Users/johncollins/Code/modernforestry-build` |

The backend is **service-heavy and thin-controller**: 73 controllers delegate to **326 service classes** across ~22 domains, with **185 Eloquent models** over ~187 migrations. It bundles an embedded Shopify app, a production/wholesale ops backend (pouring room, inventory, markets/events), a large marketing/CRM/loyalty automation layer ("Candle Cash"), a client-project portal, a field-service module, and two companion mobile APIs — all in **one `web` route file** (no `api.php`).

**Scale signals:** `app/Services` = 326 files, `app/Models` = 185, `routes/web.php` = 1,788 lines / 710 route declarations, `resources/css/forestry-ui.css` ≈ 7,224 lines, three overlapping "state of the system" docs totaling ~225 KB.

---

## 2. Apps / Packages / Repos Present

### Under `/Users/johncollins/Code/`
- **`myapp/`** — the Laravel monolith (this is "Everbranch backstage"). Contains the embedded Shopify app, storefront extensions, and the Everbranch Work Expo app nested inside it.
- **`myapp/everbranch-work-app/`** — Expo / React Native internal field-service app (nested in the Laravel repo, own git-ignored `node_modules`, own `package.json`).
- **`modern-forestry-theme-159737250051/`** — the live Shopify Online Store 2.0 theme (Liquid).
- **`modernforestry-build/`** — a **native Swift/SwiftUI iOS app** (Xcode project `modernforestry.xcodeproj`) — the consumer-facing Modern Forestry app; talks to `/api/mobile/v1/modern-forestry/*`. Not a theme.
- **`myapp-untracked-hold/`**, **`ProductionOS/`**, **`cartool/`**, **`materialize.swift`**, **`.tmp/`** — assorted holds / scratch / tooling (dead-code / cleanup candidates).

### Package manifests
- `myapp/composer.json` — Laravel 12 app (`laravel/livewire-starter-kit` lineage).
- `myapp/package.json` — Vite/React/Tailwind/Shopify-CLI frontend + tooling.
- `myapp/everbranch-work-app/package.json` — Expo app.
- `shopify.app.toml` + `shopify.app.wholesale.toml` — **two** Shopify CLI app configs.
- Two Shopify extension manifests under `extensions/*/shopify.extension.toml`.

---

## 3. Frontend Frameworks & Entry Points

**Primary paradigm:** server-rendered **Blade + Livewire 4** (with the paid **Livewire Flux** component kit), enhanced by **React 18 islands** mounted into specific Blade pages, and a large hand-written design-system stylesheet.

### Build tooling
- **Vite** (`vite.config.js`) with `laravel-vite-plugin`, `@vitejs/plugin-react`, `@tailwindcss/vite` (Tailwind v4). Dev server pinned to `localhost:5173`. Build output → `public/build` (checked into working tree at survey time).
- **TypeScript** is a thin layer (`tsconfig.json`: `strict:false`, `noEmit:true`) scoped to `resources/js/**`; esbuild/Vite transpiles.

### Vite entry points (5)
1. `resources/css/app.css` — Tailwind v4 entry; imports Flux CSS + `forestry-ui.css` (~7.2k-line in-house design system). Brand accent `#123c43` (deep forest green).
2. `resources/js/app.js` — site-wide orchestrator; lazily mounts vanilla-JS + React "contextual modules" keyed by CSS-selector presence, re-mounting after Livewire DOM updates (`livewire:navigated`, `message.processed`).
3. `resources/js/admin/master-data-grid.tsx` — React + Glide Data Grid admin spreadsheet.
4. `resources/js/marketing/customers-grid.tsx` — React marketing customer grid.
5. `resources/js/shopify/dashboard.tsx` — React embedded-admin analytics dashboard.

Additional lazily-loaded React apps: `shopify/messaging` (SMS/email composer), `shopify/responses`, `shopify/command-menu` (full command palette w/ its own `__tests__/` suite).

### Key JS deps
`@shopify/polaris` + `polaris-icons`, `@shopify/web-pixels-extension`, `react`/`react-dom` 18, `@glideapps/glide-data-grid`, `chart.js`, `cmdk`, `fuse.js`, `sortablejs`, `axios`, `tailwindcss` 4. Dev: `playwright` (click-path QA), `concurrently`.

### Views (`resources/views/`, Blade)
The UI bulk. Feature domains: `admin/`, `analytics/`, `marketing/` (largest — campaigns, candle-cash, consent, customers, segments, templates, wishlist, public pages), `livewire/` (largest concentration — admin catalog, pouring/pouring-room, markets/events, retail, shipping, onboarding), `shopify/` (embedded-app shells + React mount points), `landlord/` (SaaS operator console), `platform/` (product marketing site), `client/projects/`, `field-service/`, `wiki/`, `subscriptions/`, `evergrove/` (sub-brand), plus `layouts/`, `components/` (app-shell, sidebar, command-palette, tenancy state), auth `pages/`, custom `errors/`.

---

## 4. Backend — Framework, Routes, Controllers, Jobs, Queues, Auth, APIs

### Framework
- **Laravel 12.49.0**, PHP `^8.2` (env has 8.5). Lineage: `laravel/livewire-starter-kit`.
- Key deps: `laravel/fortify` (auth), `laravel/socialite` (Google OAuth), `livewire/livewire` 4, `livewire/flux` 2, `doctrine/dbal`, `phpoffice/phpspreadsheet`. **Test framework: Pest 4.** **Notably absent:** Sanctum, Passport, `spatie/laravel-permission`.

### Routing
- Configured in `bootstrap/app.php` via `withRouting(web:, commands:, health:'/up')`. **No `routes/api.php` and no `routes/channels.php`** — *all* endpoints (web, JSON/mobile APIs, webhooks) live in `routes/web.php` (**1,788 lines, 710 route declarations**: 339 GET / 290 POST / 21 PATCH / 8 DELETE / 2 PUT). No `Route::resource` — everything hand-declared. Livewire full-page components used directly as route targets.
- Global middleware (order): `ProfileShopifyEmbeddedRequest` → `ResolveHostTenantContext` → `EnforceCanonicalRuntimeHost`.
- Middleware aliases: `role`, `landlord.operator`, `marketing.storefront.verify`, `tenant.access`, `auth.tenant.context`.

**Major route areas:** public/marketing + short-link redirector; **landlord panel** (host-restricted, `Route::domain(...)`); **Everbranch Work mobile API** (`/api/mobile/work/v1`, CSRF-exempt, throttled, magic-link auth); **Modern Forestry mobile API** (`/api/mobile/v1/modern-forestry/*`); **main authenticated app** (`auth`+`verified`+`role:*`+`tenant.access` — dashboard, onboarding, client projects, Production OS (shipping/pouring/retail), Admin catalog CRUD, Marketing, Birthdays, Inventory, Markets/Events, Wiki); **webhooks** (`webhooks/shopify/*`, `/twilio/*`, `/sendgrid/*`, `/stripe/*`); **Shopify storefront widget API** (`shopify/marketing` + `shopify/marketing/v1` — *duplicated, versioned-migration in progress*, signature-verified); **Shopify embedded admin app** (`shopify/*` iframe SPA + `shopify/app/api/*` JSON backend + OAuth `auth`/`callback`/`reinstall`); **Google SSO** (`auth/google/*`); signed-URL exports.

### Controllers (73 files, 8 namespaces)
Thin, delegating to services. Namespaces: root (31 — incl. `ShopifyEmbedded*`, `ShopifyAuthController`, `ShopifyWebhookController`, `ShopifyPrivacyWebhookController`, wiki, client-projects, field-service, global-search), `Billing/` (2), `Birthdays/` (1), `Discovery/` (1), `Landlord/` (8), **`Marketing/` (23 — largest)**, `Mobile/` (2), `Onboarding/` (5).

### Middleware (8)
`EnforceCanonicalRuntimeHost`, `ResolveHostTenantContext`, `ProfileShopifyEmbeddedRequest`, `EnsureUserRole`, `EnsureLandlordOperator`, `EnsureTenantAccess` (blocks cross-tenant IDOR), `ResolveAuthTenantContext`, `VerifyMarketingStorefrontRequest` (HMAC for storefront widgets).

### Jobs & Queues
- **Queue connection:** `database` (default). Connections available incl. `sync`, `sqs`, `redis`, `failover`. Failed jobs: `database-uuids`. **No persistent worker daemon** — the scheduler runs `queue:work --stop-when-empty` **every minute** to drain the DB queue (a scaling limitation).
- **13 queued jobs** — e.g. `ShopifyUpsertOrder`, `ShopifySyncCustomerFromWebhook`, `SyncMarketingProfileFromOrder`, `DispatchTenantRewardsReminderJob` (queue `marketing`, unique), `DispatchMessagingCampaignBatch`, `ProcessMessagingCampaignRecipientJob`, `SyncShopifyCustomerBirthdaysJob`, `RefreshModernForestryMobileHomeCache`.
- **Scheduled tasks** (in `routes/console.php`; Laravel 12 has no `Kernel.php`): Square syncs (customers daily, orders/payments every 30 min), Shopify order import (every 30 min), webhook drift verify (daily), Google Business reviews (every 15 min), marketing profile sync (hourly), rewards reminders (hourly), finance/scent-quiz/bag reminders (scheduled), `automation:run` (every 10 min), `integration-health:prune` (daily), plus the every-minute queue drain.

### Auth
- **Session/cookie only** via **Laravel Fortify** (guard `web`; no `api` guard, no Sanctum/Passport). Features: registration (env-gated), password reset, email verification, 2FA (confirm required). Login & 2FA rate-limited 5/min.
- **Approval workflow:** self-registration creates `is_active=false`, `role=pouring`, random password → needs admin approval. `authenticateUsing()` distinguishes pending-approval vs disabled.
- **Password policy** (production): min 12 chars, mixed case + number + symbol, HaveIBeenPwned check.
- **Google OAuth** (Socialite, `GOOGLE_LOGIN_ENABLED` off by default) — auto-provisions + auto-approves google-sourced users.
- **Shopify OAuth** — dual-store (retail/wholesale), custom HMAC/state verification, DB-stored encrypted tokens (see §8).
- **Authorization:** hand-rolled two-tier RBAC — global `users.role` string (`admin`/`manager`/`pouring`/`marketing_manager`) + optional per-tenant `tenant_user.role` pivot override; enforced by `EnsureUserRole`/`EnsureTenantAccess`/`EnsureLandlordOperator` + **4 Gates** in `AppServiceProvider`. **No Policy classes, no Events/Listeners dirs; exactly 1 Observer** (`MarketingReviewHistoryObserver`).

### Service layer (326 files)
Largest: **Marketing (~161)** — Candle Cash loyalty engine, messaging/SMS/email delivery + attribution, Growave/Google Business/Meta integrations, segments/recommendations. **Shopify (~51)** — OAuth, GraphQL/REST/CLI admin clients, webhook verify/subscribe, embedded session/context/credentials, dashboards, customer sync, wholesale approval. Then Onboarding (24), Tenancy (17), Mobile (12), Search (10), Pouring (8), Automation (7), Discovery/Reporting (6 each), Billing/Work (4 each), and smaller domains (Recipes, ScentGovernance, Forms, Inventory, Dashboard, Bud AI, etc.). `app/Support` adds 36 stateless helpers/value objects.

### Providers (only 2)
`AppServiceProvider` (registers onboarding-rail adapter registry + the review observer, forces `CarbonImmutable`, `DB::prohibitDestructiveCommands()` in prod, prod password policy, the 4 Gates) and `FortifyServiceProvider` (custom response contracts, tenant-aware redirect/URL logic, rate limiters). No `RouteServiceProvider`/`EventServiceProvider`/`BroadcastServiceProvider`.

---

## 5. Mobile / iOS / Android

### Everbranch Work (internal field-service — `myapp/everbranch-work-app`)
- **Expo SDK 57 / React Native 0.86 / React 19.2.3**, New Architecture + Hermes. Bundle id `com.everbranch.work`, scheme `everbranch://`, universal-link domain `app.theeverbranch.com`.
- **Single-file monolith:** `App.tsx` (~1,374 lines) holds all UI/styles/logic. No navigation library, no state manager, no test runner (only `tsc --noEmit`). `src/api.ts` (fetch client + Secure Store token) and `src/types.ts`.
- **Auth:** passwordless magic-link + deep-link/universal-link; multi-tenant aware (tenant picker). Bearer token in Keychain (`expo-secure-store`). Push via `expo-notifications`.
- **Tabs:** Home (upcoming jobs / today's tasks / in-progress), Jobs (search + week strip), Team (nudge teammates). Detail bottom-sheet with status changes, comments, activity, call/maps links, lock-box code. Demo/fallback content fills empty states.
- **API:** `https://app.theeverbranch.com/api/mobile/work/v1` (prod) / `http://127.0.0.1:8000/...` (dev). ~15 endpoints (auth, bootstrap, tenants, home, jobs, tasks, comments, activity, team, push register).
- **iOS:** Expo prebuild CocoaPods project (`ios/EverbranchWork.xcworkspace`), deployment target 16.4. **Android:** config only — no `android/` native project generated.
- **Gaps flagged:** `extra.eas.projectId` is **empty** (blocks EAS builds/push); `app.json` requests `applinks:` associated domain but generated `EverbranchWork.entitlements` is **empty** → universal links won't work until re-prebuild.

### Modern Forestry (consumer — `/Users/johncollins/Code/modernforestry-build`)
- **Native Swift / SwiftUI** Xcode project (`ModernForestryApp.swift`, `Assets.xcassets`, unit + UI test targets). Talks to `/api/mobile/v1/modern-forestry/*` (home/collections/products/checkout via Shopify Storefront Cart API, Candle Cash, scent-share). APNs push configured server-side (`MODERN_FORESTRY_APNS_*`). Customer Account API OAuth via custom URL scheme.

---

## 6. Laravel Structure — Migrations, Models, Services, Policies, Commands

- **`app/` layout:** `Http` (88), `Models` (185, flat + `Concerns`), **`Services` (326)**, `Livewire` (60 components), `Support` (36), `Console/Commands` (86, flat), `Jobs` (13), `Actions` (6: Fortify/Inventory/ScentGovernance), `Mail` (5), `Notifications` (4), `Providers` (2), `Concerns` (2), `Observers` (1). **No `Policies/`, `Events/`, `Listeners/`.**
- **Migrations:** ~187 files. Default driver **SQLite** (`database/database.sqlite`); MySQL/MariaDB/PgSQL/SQLSrv also configured. Extensive **driver-aware raw SQL** (PRAGMA vs information_schema), table **rebuilds** (`orders`, `order_lines`, `wholesale_custom_scents`) that dropped FK constraints, and a **points→Candle Cash** rename backfill across 6 tables.
- **Console commands (86, flat):** ~60 are `marketing:*` (Square/Growave/Shopify/Meta sync, imports, attribution backfills, Candle Cash audit/reconcile, campaign send/report, rewards). Plus `shopify:*` (12), `catalog:*` (5), `markets:*`/`events:*` (4), `integration-health:*`, `automation:run`, `orders:purge`, `master-data:import`, `users:ensure-approved`, `auth:doctor-google`.
- **Policies:** none — authorization via role strings + middleware + 4 Gates (see §4).

### Migrations → Tables (by domain)
Core/auth (`users` w/ 2FA + Google + approval fields, `sessions`, `cache`, `jobs`/`job_batches`/`failed_jobs`); **Tenancy/entitlements/onboarding** (`tenants`, `tenant_user`, `tenant_access_profiles`, `tenant_module_states`/`_entitlements`/`_access_requests`, `tenant_onboarding_*`, `tenant_setup_statuses`, intake tables); **Landlord/billing/webhooks** (`landlord_catalog_entries`, append-only `landlord_operator_actions`, `tenant_billing_fulfillments`, `stripe_webhook_events`, `shopify_privacy_webhook_events`, polymorphic `integration_health_events`); **Orders** (`orders`, `order_lines`, `order_line_scent_splits`); **Catalog/recipes/pouring/inventory** (`scents` self-ref, `sizes`, `wicks`, `blends`/`blend_components`, `scent_recipes/_components`, `base_oils`, `oil_movements`, `pour_batches/_lines/_pitchers`, `pour_requests`, `inventory_counts`, `wax_inventories`, `catalog_item_costs`, `retail_plans/_items`, `shopify_stores` (encrypted token), `shopify_import_runs/_exceptions`); **Events/markets** (`events`, `event_instances`, `event_mappings` + `event_match_overrides` (overlapping), `markets`, `market_pour_lists` + pivots); **Marketing** (`marketing_profiles` + ~50 `marketing_*` tables: campaigns/variants/recipients/conversions, segments/groups, deliveries/events/attributions, templates, `messaging_conversations/_messages`, `square_customers/orders/payments`, `tenant_email_settings` encrypted); **Loyalty** (`candle_cash_balances/_transactions/_rewards/_redemptions/_referrals/_tasks*`, `customer_birthday_profiles`, `birthday_reward_issuances`, `marketing_review_summaries/_histories`, `google_business_profile_*`, wishlist tables); **Subscriptions/Candle Club** (~15 `subscription_*` tables incl. Recharge→Shopify migration + polls/votes); **Client portal / field service / forms / automation** (`client_projects/_phases/_milestones/_tickets`, `field_service_jobs/_tasks/_materials/_job_photos`, `form_templates`/`tenant_forms`/`form_submissions`, `automation_workflow_states/_links`); **Mobile/work** (`mobile_push_devices`, `mobile_login_challenges`, `work_notifications/_deliveries`, `work_item_comments/_watchers`, `work_activity_events`).

### Models — notable patterns
- **Tenant scoping is opt-in** via `Concerns\HasTenantScope` local scopes (`->forTenant()`), **not a global scope** — isolation is application-enforced (correctness risk).
- `Tenant` and `MarketingProfile` are aggregate roots with enormous relationship surfaces.
- **Encrypted casts:** `ShopifyStore.access_token`, `GoogleBusinessProfileConnection.access_token/refresh_token`, `TenantEmailSetting.provider_config`.
- **Legacy-compat trait** (`TracksLegacyCandleCashCompatibility`) keeps legacy `points*` columns in sync with `candle_cash*` and logs every legacy access.
- **Append-only:** `LandlordOperatorAction` throws on update/delete. **STI:** `BlendTemplate extends Blend`. **Natural-key relations:** Square models.

### Seeders / Factories
Seeder chain: `TenantSeeder → FormsSeeder → MasterDataSeeder (Catalog → BlendRecipe → WholesaleCustomScents) → OrderSeeder → ScentTemplateSeeder`. `OrderSeeder`/`PouringRoomDemoSeeder` are demo data. Factories: User (many states), Order/OrderLine, CatalogItemCost, MarketingProfile.

---

## 7. Shopify CLI / App / Theme Integrations

### Two Shopify apps (same codebase, separate Partner registrations)
| | Retail (`shopify.app.toml`) | Wholesale (`shopify.app.wholesale.toml`) |
|---|---|---|
| Name | Modern Forestry Backstage | MF Wholesale Backstage |
| client_id (public) | `197d01d6597c938c96b3b35fae6a087c` | `1ec941453c4df97bb2402f696839b786` |
| app_url | `.../shopify/app` | `.../shopify/app/wholesale` |
| dev store | `modernforestry.myshopify.com` | `s2vscq-rf.myshopify.com` |
| Webhook API version | `2026-01` | `2026-01` |

Both are embedded, use the modern OAuth grant flow, declare the 3 mandatory GDPR privacy webhooks (`customers/data_request`, `customers/redact`, `shop/redact` → `webhooks/shopify/*`), and share an **app proxy** (`/apps/forestry/*` → `shopify/marketing/v1/*`). Wholesale has a **separate embedded client id/secret** for session-token verification. Retail requests a few extra scopes (subscription-contract self-management). **Manual-sync footgun:** shared webhook/proxy URLs must be kept identical across both TOMLs by hand.

### Integration architecture (fully custom — no Shopify SDK)
No `osiset`/`gnikyt`/`shopify/shopify-api`. Built on Laravel `Http` under `app/Services/Shopify/` (~35–50 files):
- **Multi-store registry** `ShopifyStores` (retail/wholesale, DB-token-preferred, encrypted).
- **OAuth** `ShopifyAuthController` + `ShopifyOAuth` (state + `ShopifyHmacVerifier` query HMAC; token exchange; scope confirmation via `currentAppInstallation` GraphQL; webhook subscription enforcement).
- **Signature verification** (hand-rolled): `ShopifyHmacVerifier` (OAuth), `ShopifyWebhookVerifier` (webhook body HMAC), `ShopifySessionTokenVerifier` (App Bridge JWT: HS256, aud/iss/dest/exp checks).
- **Admin clients:** `ShopifyClient` (REST, cursor pagination, 429/5xx backoff), `ShopifyGraphqlClient` (GraphQL, backoff, profiling), `ShopifyCliAdminClient`.
- **Webhooks:** operational topics (`orders/*`, `refunds/create`, `customers/create|update`, `subscription_contracts/*`, `subscription_billing_attempts/*`, `customer_payment_methods/*`) registered dynamically per-shop; handled by `ShopifyWebhookController` → jobs. Privacy topics recorded to `shopify_privacy_webhook_events` as `MANUAL_REVIEW_REQUIRED` — **no automatic redaction/deletion is performed** (PII hashed for audit).
- **Embedded admin app:** family of `ShopifyEmbedded*Controller` + `ShopifyEmbedded*` services (dashboards, customers, messaging, rewards "Candle Cash", AI assistant "Bud", settings, subscriptions).
- **App proxy:** `Marketing\MarketingShopifyIntegrationController` (~3,700 lines) verifies app-proxy signed requests (`MARKETING_SHOPIFY_*` secrets).

### Storefront extensions (`myapp/extensions/`)
- **`forestry-marketing-embed/`** — Theme App Extension; app-embed block injects first-party attribution tracker posting to `/apps/forestry/funnel/event`.
- **`forestry-marketing-pixel/`** — Web Pixel Extension (`strict` sandbox); subscribes to standard analytics events, resolves UTM/fbclid/`mf_*` attribution, respects Customer Privacy consent, posts to the same funnel endpoint.

### Shopify theme (`/Users/johncollins/Code/modern-forestry-theme-159737250051`)
Full OS 2.0 layout (layout/templates/sections/snippets/assets/config/locales; localized en/de/es/fr/it/ja). **Heavy third-party app accretion:** SocialShopWave (`ssw-*`, ~20 snippets), Wholesale Lock Manager (`wlm-*`), Growave, Storeifyapps, Searchanise-style (`sc-qs-*`), plus in-house `forestry-*` features (rewards, wishlist, product reviews, wholesale gating) that appear to be **replacing** the third-party apps. **Debt artifacts:** `broken-main-search.liquid`, `*_backup_do_not_delete.liquid`, `theme-bak-wlm.liquid`.

### `.shopify/` (git-ignored)
Local CLI state + `deploy-bundle*` (Brotli build cache from last `shopify app deploy`; manifest lists the theme-embed + web-pixel extensions). Regeneratable, not source of truth.

---

## 8. Environment Variables & External Services (no secret values printed)

**Source:** `.env.example` (13 KB). Live `.env` keys were listed by name only. **`.env.example` and `.env` have diverged** (see Risks).

### Env var groups (key names only)
- **App/core:** `APP_*`, `FORTIFY_ALLOW_REGISTRATION`, `BCRYPT_ROUNDS`, `LOG_*`.
- **Session (embedded-aware):** `SESSION_*` incl. `SESSION_PARTITIONED_COOKIE`, `SESSION_SAME_SITE` (Shopify iframe needs `None`+partitioned in prod).
- **DB:** `DB_CONNECTION` (sqlite default), `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD`.
- **Cache/queue/Redis:** `CACHE_STORE`, `QUEUE_CONNECTION`, `REDIS_*` (documented prod cutover to Redis).
- **Mail:** `MAIL_*`; provider keys `POSTMARK_API_KEY`, `RESEND_API_KEY`, `SENDGRID_*` (SendGrid only in live `.env`), `MARKETING_EMAIL_*`.
- **AWS/S3:** `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`.
- **Shopify:** `SHOPIFY_API_VERSION`, `SHOPIFY_SCOPES`, `SHOPIFY_ACTIVE/REQUIRED_STORE_KEYS`, `SHOPIFY_RETAIL_*` (⚠ **missing from `.env.example`** though retail is required), `SHOPIFY_WHOLESALE_*` incl. `_EMBEDDED_*`, `SHOPIFY_CUSTOMER_ACCOUNT_*`, `SHOPIFY_EMBEDDED_PERF_*`, `MARKETING_SHOPIFY_*` (app-proxy signing).
- **Payments:** `STRIPE_*` (billing-prep only, guarded), `BRAINTREE_*` (inactive), `SQUARE_*` (⚠ read by config, **absent from `.env.example`** — legacy reconciliation).
- **Third-party:** `TWILIO_*` + `MARKETING_TWILIO/SMS_*` (SMS), `GOOGLE_*` (login), `GOOGLE_GBP_*` (Business Profile), `GOOGLE_CALENDAR_*`, `ASANA_*` + `AUTOMATION_*` (Asana↔GCal), `MARKETING_GROWAVE_*` (loyalty migration), `MARKETING_META_ADS_*` (ad spend), `MARKETING_AI_BUDGET_*` (human-gated), `PEXELS/UNSPLASH/PIXABAY` (stock photos), Slack bot token.
- **Multi-tenancy/domains:** `TENANCY_*`, `AUTH_FLAGSHIP_*`, `AUTH_TENANT_HOST_MAP`, `EVERGROVE_*`, `WHOLESALE_APPLICATION_*`.
- **Mobile:** `MODERN_FORESTRY_APNS_*`, `EVERBRANCH_WORK_EXPO_PUSH_URL`, `MF_APP_REVIEW_DEMO_*` (App Store review demo login).
- **Feature flags:** `FEATURE_*` (some config-only, see `config/features.php`).
- **Paid tooling:** Livewire Flux requires `FLUX_USERNAME`/`FLUX_LICENSE_KEY` (composer auth, in CI secrets).

### External services
Shopify (2 stores) · AWS S3/SES · Stripe (billing-prep, guarded) · Braintree (inactive) · Square (legacy reconciliation) · Twilio (SMS) · SendGrid + Postmark/Resend (email) · Google (SSO / Calendar / Business Profile) · Asana (automation) · Growave (loyalty, being replaced) · Meta Ads Graph API · Pexels/Unsplash/Pixabay · Slack · Expo Push · APNs · Livewire Flux (paid) · Fortify + Socialite · PhpSpreadsheet.

> **⚠ No error-tracking / APM** (no Sentry / Bugsnag / Datadog anywhere) — production visibility relies solely on Laravel `log` channels + `laravel/pail`.

---

## 9. Build / Test / Deploy Commands

### Build / dev
- `composer run setup` — first-time bootstrap (install, `.env`, key:generate, migrate, npm install + build).
- `composer run dev` / `npm run dev:full` — Laravel serve + queue + pail + Vite concurrently.
- `npm run build` / `npm run dev` — Vite.
- `npm run shopify:app:dev[:wholesale]` / `:deploy[:wholesale]` / `:info[:wholesale]` — Shopify CLI per store.
- Everbranch Work: `npm run start` (`expo start`), `npm run ios`/`android`, `npm run test:types`.

### Test / lint
- `composer test` — config:clear → Pint check → **Pest** (`php -d memory_limit=512M vendor/bin/pest`).
- `composer run lint` / `test:lint` — Pint (`{"preset":"laravel"}`).
- `npm run qa:click-path[:auth]` — Playwright click-path audit (`scripts/qa/click-path-audit.mjs`, routes in `tests/e2e/click-path-routes*.json`).
- **Tests:** ~287 files. `tests/Feature/` (26 domains, Marketing largest), `tests/Unit/` (27). `phpunit.xml`: sqlite `:memory:`, sync queue, array cache/mail.

### CI (`.github/workflows/`)
- `tests.yml` — PHP 8.4/8.5 matrix, Flux composer auth via secrets, build + Pest. Branches `develop`/`main`/`master`/`workos`.
- `lint.yml` — Pint check.
- `deploy.yml` — push to `main` (or manual) → SSH deploy running `scripts/deploy_backstage.sh`. ⚠ **The test/build gate only runs on manual dispatch** — auto-deploys can ship **without running tests**.
- `maintenance-reset-password.yml` — manual ops runbook (SSH + `users:ensure-approved`).

### Deploy target
Laravel Forge host (`/home/forge/backstage.theforestrystudio.com/current`). `scripts/deploy_backstage.sh`: git sync → `composer install --no-dev` → `migrate --force` → cache config/route/view → asset build (with self-healing retry) → `queue:restart` → best-effort nginx/php-fpm reload.

---

## 10. Major Risks, Missing Docs, Fragile Areas, Dead Code, Duplication

### High
1. **Deploys can skip tests.** `deploy.yml`'s test/build gate only runs on manual dispatch; auto-deploy to `main` proceeds even if tests would fail (and silently skips if Flux secrets are missing). No CI safety net on the hot path.
2. **No observability.** No Sentry/APM anywhere; production errors visible only via log files. High integration surface (Shopify/Twilio/Square/Stripe/Google/Meta) with no alerting.
3. **Opt-in tenant isolation.** `HasTenantScope` provides local scopes, not a global scope. A single forgotten `->forTenant()` in any of hundreds of queries is a cross-tenant data-leak. Mitigated only by `EnsureTenantAccess` middleware + discipline.
4. **`.env.example` is broken for onboarding.** Missing the entire `SHOPIFY_RETAIL_*` block (the *required* store) and `SQUARE_*`; a fresh setup from the template cannot configure the primary store. `.env`/`.env.example` broadly diverged.
5. **Privacy webhooks don't redact.** GDPR `customers/redact`/`shop/redact` are recorded for "manual review" with no automated deletion — a compliance gap if the app is publicly listed.

### Medium
6. **FK integrity gaps.** `order_lines.scent_id/size_id` and several pipeline/webhook tables lost or never had DB-level FK constraints (table rebuilds); integrity is app-enforced only. Latent down-migration bug in `2026_02_04_..._add_meta_to_orders_table` (drops a column it never created).
7. **Single-file `web.php` doing API duty.** 710 routes incl. mobile + webhook JSON APIs under the `web` group; no `api.php`, no versioned API guard, CSRF exemptions scattered per-route. Storefront widget routes exist as both `shopify/marketing` and `shopify/marketing/v1` (mid-migration duplication).
8. **No persistent queue worker.** Queue drained by an every-minute scheduled `queue:work --stop-when-empty` — fragile under load / long jobs; move to a supervised daemon or Redis/Horizon.
9. **Two Shopify TOMLs kept in sync by hand.** Divergent scopes + shared proxy/webhook URLs; a one-sided edit breaks one app.
10. **Doc sprawl.** Three overlapping "state" docs — `README.md` (115 KB, running changelog), `README_FOR_AGENTS.md` (50 KB), `SYSTEM_SNAPSHOT.md` (59 KB) — with independent edit histories and no canonical index; already drifting. `docs/architecture/multi-tenant-viability-and-customer-ingestion.md` **and** `.txt` duplicate. `docs/operations/evidence/` (14 MB of screenshots) committed to the app repo.

### Dead code / cleanup candidates
- SQLite backup blobs in `database/`: `database.sqlite.bak.*`, `.before-tenant1-repair-*`, `.malformed-*.bak` (~11 MB; not pattern-gitignored; imply past corruption/repair incidents).
- Stray files: `welcome.blade.php.sb-*` sandbox backup, PNG screenshots inside `resources/views/layouts/app/` and `livewire/retail/`, a `Screenshot*.png` in `database/factories/`, root `.phpunit.result.cache`.
- Theme debt: `broken-main-search.liquid`, `*_backup_do_not_delete.liquid`, `theme-bak-wlm.liquid`.
- Sibling scratch dirs: `myapp-untracked-hold/`, `.tmp/`, `cartool/`.
- Stale git branches: `agent/codex*`, `backup-agent-*`, `codex/*`, `temp/*`, `release-a`…`release-e` (merged), `shopify-client-hotfix`, `deploy-*fix`.
- `PERF_NOTES.md` references `ShopifyEmbeddedPerformanceBenchmarkTest.php` (results "pending") that **doesn't exist**.
- Overlapping mechanisms: `event_mappings` vs `event_match_overrides`; legacy `points*` vs `candle_cash*` columns; `config/commercial.php`/`entitlements.php` as compat shims over `config/module_catalog.php`.

---

## 11. Recommended Next 10 Engineering Tasks (priority order)

1. **Make CI a real deploy gate.** Change `deploy.yml` so tests + build must pass on every push to `main` (not just manual dispatch), and fail loudly if Flux secrets are absent. *Prevents shipping broken code.*
2. **Add error tracking + alerting.** Wire Sentry (or equivalent) into Laravel, the two React SPAs, and both mobile apps; alert on webhook/queue/integration failures via the existing `integration_health_events`. *Restores production visibility.*
3. **Harden tenant isolation.** Convert `HasTenantScope` to a global scope (with explicit `withoutTenantScope()` escape hatch) or add an automated test/static check that every tenant-scoped model query is tenant-filtered. *Closes the biggest data-leak risk.*
4. **Repair `.env.example` and reconcile `.env`.** Add the missing `SHOPIFY_RETAIL_*` and `SQUARE_*` keys, document every service group, and add a `config:doctor`-style command asserting required keys per environment. *Unblocks clean onboarding + prevents prod misconfig.*
5. **Implement (or explicitly defer) GDPR redaction.** Turn the `MANUAL_REVIEW_REQUIRED` privacy-webhook records into an actual redaction workflow with an audit trail, or document the manual SLA. *Required before/for public App Store listing.*
6. **Move the queue to a supervised worker.** Replace the every-minute `queue:work --stop-when-empty` with a Horizon/Redis (or Forge daemon) worker; keep the scheduler for cron only. *Removes a throughput/reliability ceiling.*
7. **Extract a versioned API layer.** Split mobile + webhook + storefront JSON endpoints out of `web.php` into `routes/api.php` (or clearly-versioned prefixes) with consistent signature/token middleware; retire the duplicate `shopify/marketing` vs `/v1`. *Reduces the 1,788-line footgun and CSRF-exemption sprawl.*
8. **Restore DB referential integrity.** Add back FK constraints on `order_lines.scent_id/size_id` and other rebuilt tables (or document the intentional looseness), and fix the `add_meta_to_orders_table` down-migration bug. *Prevents orphaned/corrupt commerce data.*
9. **Consolidate documentation.** Merge the three overlapping state docs into one canonical `SYSTEM_SNAPSHOT.md` (this inventory can seed it) with pointers; move `docs/operations/evidence/` screenshots out of the app repo. *Stops doc drift.*
10. **Repo hygiene sweep.** Delete SQLite backup blobs + stray screenshots/sandbox files, gitignore `*.sqlite*` backups, prune merged/agent/temp branches, and finish the Expo app's EAS `projectId` + associated-domains entitlement so its builds/universal-links work. *Shrinks the checkout and removes dead weight.*

---

*End of inventory. Sourced from a read-only six-way parallel survey of the Laravel backend, database schema, frontend/theme, Expo mobile app, Shopify integration, and configuration/tooling/docs. No secret values were read or reproduced.*
