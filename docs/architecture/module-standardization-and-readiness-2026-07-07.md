# Module Standardization & Multi-Tenant Readiness

> 2026-07-07 · Read-only audit of all 16 modules + the module framework + the integration/OAuth layer, plus the target "Standard Module Contract" every module plugs into. This is the map for professionalizing Everbranch into a cohesive, modular, provably-isolated multi-tenant platform **without** redesigning the flagship (candle-ops) modules before they're finished/tested.

## The one-paragraph synthesis

The audit found almost **no numeric `tenant_id === 1` hardcoding**. Flagship coupling lives in four structural forms: candle *vocabulary* baked into shared code (scent/pour/box/wax), operational tables with **no `tenant_id` column** (markets, events, pouring, retail plans), queries that never call `->forTenant()`, and nav gated by a slug-based `isFlagshipTenant` check. Every module audit independently surfaced the **same two systemic gaps**, which are therefore the highest-leverage fixes:

1. **Isolation is not enforced.** `HasTenantScope` is opt-in query helpers, not a global scope; a null tenant is a silent "all tenants." The candle-ops surface (Orders/Shipping/Pouring/Retail/Markets) queries `Order`/etc. completely unscoped, and several of its tables lack `tenant_id` entirely. The Marketing surface is mostly scoped but armed by a single global switch (`tenantCount() > 0`).
2. **Module entitlements are not enforced.** `config/module_catalog.php` defines plans/modules/billing, and `TenantModuleAccessResolver` produces a correct per-module decision — but **nothing enforces it at the request layer**. Every route is role-gated only; `canAccess()` only hides nav/search. Any role-holder reaches any module regardless of plan.

Fixing those two — an **enforced tenant scope** and a **`module:{key}` route gate** — is what turns "discipline-based multi-tenant" into "architecturally multi-tenant." Everything else is vocabulary de-branding and per-tenant provider config, which can follow module by module.

## Multi-tenant readiness map

Rating: GREEN = tenant-scoped + generic + entitlement-aware · YELLOW = scoped data but flagship vocabulary / config gaps · RED = missing tenant_id / unscoped / candle-shaped domain.

| Module | Rating | Core issue |
|---|---|---|
| **customers** | 🟡 | Scoped + entitlement-gated + label resolver (the reference for "done"). Candle vocabulary ("Candle Cash/Club", "Scent audiences") in shared views/routes. |
| **field_service** | 🟢/🟡 | In `module_catalog`, entitlement-checked in one controller (the closest to the target contract). |
| **orders** | 🔴 | Not a module (no catalog entry); UI never scopes `Order::query()`; candle-shaped (scent/pour/SLA); nav-gated to flagship. |
| **shipping** | 🟡→🔴 | Generic fulfillment core, but unscoped queries + candle line editor (scent/size/wick). Cross-tenant read/write via direct URL. |
| **pouring** | 🔴 | Candle domain in schema; `pour_batches`/`pour_requests`/`pouring_measurements` have no `tenant_id`; `/pouring/order/{order}` is a concrete cross-tenant leak. |
| **retail / markets / events** | 🔴 | Tables (`retail_plans`, `markets`, `market_plans`, `events`, `event_instances`, ~11 total) have **no `tenant_id` at all** — architecturally single-tenant. Candle box math + one hardcoded Asana calendar feed. |
| **inventory** | 🔴/🟡 | Candle-material shaped; scoping to confirm per its tables. |
| **marketing (overview/segments)** | 🟡 | Request path scoped; `MarketingSegmentOpportunityService` is fully unscoped + hardcodes "Florida"/"Flowertown"; scent-axis metric vocabulary is hardcoded in the rule surface. |
| **campaigns** | 🟡 | Sends correctly scoped; reward issuance hard-wired to CandleCash currency. |
| **messaging: email** | 🟡 | **Per-tenant** provider config (`tenant_email_settings`, encrypted) — the good pattern. Falls back to a shared global SendGrid silently. |
| **messaging: sms** | 🔴 | **Single global Twilio account**, no tenant dimension. `sendSms()` takes no tenant. A 2nd tenant physically cannot send SMS as themselves. |
| **birthdays** | 🟡 | Data scoped + reward config per-tenant; "The Forestry Studio"/flagship domain baked into shared dispatch copy. |
| **reviews** | 🔴 | Native reviews ~YELLOW, but Google Business Profile is **one platform-wide Google account** (no `tenant_id` in GBP schema). |
| **wishlist** | 🟡 | Best-scoped of the loyalty set (`forTenantId` + ownership asserts); "Modern Forestry" hardcoded in outreach message body. |
| **integrations** | 🔴 (framework) | Not a connector framework — a read-only status registry. |

**Cross-cutting:** none of the loyalty/marketing/ops modules are entitlement-gated; the flagship gate is nav-visibility only (slug-based), not access control.

## The integration / OAuth layer (answering "is there an OAuth library for e-commerce?")

**No single universal e-commerce OAuth library exists** — Shopify, WooCommerce, BigCommerce, Square, Etsy each ship their own OAuth + API. The professional standard is: **Laravel Socialite (already installed here, used only for Google login) + community providers for the handshake, plus a normalized per-tenant connections table + a refresh job for storage.** Socialite community providers exist for Shopify, QuickBooks, Google, Stripe, Etsy, Facebook/Meta; not for Square/BigCommerce/WooCommerce (custom driver or API-key).

Today there are **four bespoke, copy-pasted OAuth flows** (Shopify, Google login, Google Business, Asana/Calendar) and credential storage is scattered across `shopify_stores`, `square_config` JSON (plaintext), `google_business_profile_connections` (no tenant_id), and `tenant_marketing_settings`. Only **email/SendGrid** is a clean per-tenant pattern.

**Target: one `integration_connections` table** — `(tenant_id NOT NULL, provider, external_account_id, encrypted access_token/refresh_token, expires_at, scopes, status, metadata, connected_by_user_id)`, `unique(tenant_id, provider, external_account_id)` — modeled on the already-correct `TenantEmailSetting`/`GoogleBusinessProfileConnection` shapes, behind a `ProviderConnector` interface + `ConnectionManager` (`buildAuthUrl / handleCallback / refresh / client`) and a single `connections:refresh` scheduled command. Use Socialite where a provider exists; keep thin bespoke connectors only for Shopify HMAC/webhook/session-token and Square/Meta. This retires the four scattered stores and makes every tenant able to connect their own accounts.

## The Standard Module Contract (the standard every module plugs into)

The system is **~2/3 of the way there**: the *decision + presentation* layer is already a real, uniform contract (`module_catalog.php` declaration fields, `TenantModuleAccessResolver` → uniform `{enabled, reason, ui_state: active|setup_needed|locked|coming_soon, cta}`, shared `components/tenancy/module-*` cards, audited enable/disable via `LandlordCommercialConfigService`). What's missing is the *wiring* layer. A module **conforms** when it provides:

**A. One declaration** (extend its `module_catalog.php` entry — keep all existing fields, add):
- `nav`: `{group, route, label_key, icon, position, children}` — nav rendered from the catalog, not hardcoded in `UnifiedAppNavigationService`.
- `routes`: a module-owned route group (`routes/modules/{key}.php`) with the `module:{key}` gate applied.
- `config_schema`: `{labels:{...}, settings:{...}}` — the tenant-customizable surface, declared not hardcoded.
- `setup`: ordered step keys that deterministically define "configured".

**B. One per-tenant config store** validated against `config_schema` (generalize `TenantDisplayLabelResolver` + a `tenant_module_settings` store), so adding a label/setting is declaration-only.

**C. One access gate** (already exists — formalize the entry points): a `module:{key}` route middleware, a `@moduleEnabled('key')` Blade directive, and the resolver call — all reading the single decision (entitled AND enabled AND channel-supported AND dependencies-enabled AND setup-complete).

**D. Tenant-owned data**: every module table carries `tenant_id` + FK, uses `HasTenantScope`, and (target) a `BelongsToTenant` global scope bound to resolved tenant context so scoping is default-on.

**E. UI contract**: nav from the declared `nav`; locked/setup/active states rendered only through `TenantModuleUi` + the shared `module-*` components. No bespoke lock screens.

**F. Lifecycle via existing services**: a per-module `provisionDefaults(tenantId)` install hook invoked by `FirstLoginWorkspaceProvisioner` (replacing hardcoded `ModernForestryAlphaBootstrapService`); enable/disable via `LandlordCommercialConfigService`; setup writes `tenant_module_states.setup_status`.

Optional thin interface + `ModuleRegistry` service provider that iterates `config('module_catalog.modules')`, binds implementations, registers gated routes, feeds nav, and validates config — the single place the shell/nav/search consult.

## Smallest incremental path (fail-closed, each independently shippable)

1. **`module:{key}` gate middleware** (alias in `bootstrap/app.php`) reading `resolver->module()->enabled`, rendering the shared locked/setup component. Migrate `field_service` + the embedded controllers first. *One enforcement pattern — highest leverage.*
2. **Enforced tenant scope** — convert `HasTenantScope` to a global scope with an audited `forAllTenants()` escape hatch; fix the Tier-1 IDOR sites (StackOrders, Shipping saveOrder, MarketingIdentityReview, MappingExceptions, CandleCash). Extend `TenantIsolationGuardrailTest` to assert cross-tenant reads/writes fail. **This protects the untested candle modules — it does not redesign them; it makes their single-tenant queries physically unable to reach another tenant.**
3. **Declarative `nav` block** rendered by `UnifiedAppNavigationService` (module-by-module; leave flagship/role nav initially).
4. **`config_schema` + generic per-tenant settings store**; generalize `TenantDisplayLabelResolver`.
5. **`integration_connections` + `ConnectionManager` + Socialite** (per-tenant OAuth), migrating Shopify/GBP/Square off their scattered stores.
6. **Backfill `tenant_id`** onto the operational tables that lack it (markets/events/pouring/retail) with backfill to tenant 1 — a prerequisite before those modules could ever be opened, but safe to stage behind isolation.
7. **`TenantModule` interface + `ModuleRegistry`**, adopted for one reference module, then opportunistically.

Steps 1–2 alone close the two loudest gaps (gating + isolation) with minimal risk, because the resolver already produces exactly the data both need — this is wiring, not new logic.

## Non-negotiable constraint (owner directive, 2026-07-07)

Do **not** open the candle-ops modules (Orders/Shipping/Pouring/Retail/Markets/Events/Inventory) to other tenants yet — they are unfinished/untested and would each need a generic (non-scent/size/wick/box) redesign + per-tenant config. Enforced isolation (step 2) makes them **safe as tenant-1-only**; entitlement gating (step 1) makes them **invisible/inaccessible to tenants that don't have the candle entitlement**. That combination is the professional posture: keep the vertical flagship modules flagship-only, gated and isolated, while the tenant-neutral core (auth, tenancy, module framework, customers, marketing identity, integrations) generalizes.
