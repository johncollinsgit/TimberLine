# Everbranch Route/Page Ownership Inventory

Status: PR 2 brand and navigation coherence inventory.
Date: 2026-05-21.

## Purpose

This inventory documents who each major route/page belongs to so future work can improve product coherence without renaming routes, moving modules, or weakening tenant/Shopify/billing guardrails.

## Ownership Table

| Route or page | Surface type | Current visible brand/copy | Intended product owner/context | Safe label recommendation | Code change now? |
| --- | --- | --- | --- | --- | --- |
| `/` guest home | public | Public promo page, previously Forestry Backstage in title/footer/logo alt | Everbranch public platform | Everbranch | Yes, safe display labels only |
| `/platform/promo` | public | Public product page; previous title/footer/logo alt used Forestry Backstage | Everbranch public platform | Everbranch | Yes, safe display labels only |
| `/platform/plans` | public | Plans & Add-ons, billing-disabled explanatory copy | Everbranch public platform | Keep "Plans & Add-ons"; do not imply checkout is ready | No additional change |
| `/platform/contact` | public | Previous headline "Talk with the Forestry Backstage team" | Everbranch sales/contact | Talk with the Everbranch team | Yes |
| `/platform/start` | public/client intake | Demo/production access request with setup/import and mobile intent fields | Everbranch guided intake | Keep "Request production access"; do not call self-serve ready | PR 3 updated |
| `/platform/demo` | public/client intake | Demo request | Everbranch demo intake | "See Everbranch in action" | Yes through config |
| `/platform/request-submitted` | public/client intake | Request confirmation | Everbranch guided intake | Keep honest manual approval language | Later |
| `/platform/catalog` | public API/feed | Safe public module catalog feed | Everbranch module discovery | Everbranch module catalog; safe modules only | No |
| `/login` | auth | Tenant-aware auth page; previous workspace copy said Forestry Backstage | Everbranch auth with tenant context | Everbranch workspace, tenant label preserved | Yes |
| `/register` | auth | Account creation, not full tenant self-service | Everbranch auth | Keep account language; do not imply tenant creation is complete | Later |
| `/forgot-password`, `/reset-password`, `/verify-email` | auth | Shared auth shell | Everbranch auth | Everbranch meta/app label | Yes through shared shell/head |
| `/landlord` | landlord | Landlord Operator Console | Everbranch operator/admin context | Everbranch Admin Console | Yes |
| `/landlord/tenants` | landlord | Tenant Workspace Directory | Everbranch Admin tenant management | Keep tenant/admin language | Later |
| `/landlord/commercial` | landlord | Tenant Management/commercial readiness | Everbranch Admin commercial controls | Keep guarded billing/readiness language | Later |
| `/landlord/agreements` | landlord | Agreement portfolio, draft/version/send/evidence/termination tools | Everbranch Admin legal/commercial operations | Operator-only; immutable accepted versions and audited mutations | Yes, 2026-07-16 |
| `/landlord/tenants/{tenant}/agreements` | landlord | Per-workspace agreement list | Everbranch Admin tenant commercial operations | Tenant-scoped operator view | Yes, 2026-07-16 |
| `/landlord/onboarding/journey` | landlord | Onboarding diagnostics plus client setup status review | Everbranch Admin diagnostics | Keep diagnostics/admin language; setup review remains lightweight | PR 3 updated |
| `/landlord/onboarding/intake` | landlord | Intake queue with setup status filters | Everbranch Admin intake triage | Keep operator triage language; no connector or billing actions | PR 5 added |
| `POST /landlord/onboarding/setup-status/{tenant}` | landlord | Setup review save action | Everbranch Admin diagnostics | Landlord-only review status, next action, and internal notes | PR 3 added |
| `/dashboard` | tenant | Tenant dashboard/launchpad | Tenant workspace inside Everbranch | Everbranch in shared brand shell, tenant copy unchanged | Yes through shared shell only |
| `/start` | tenant | Start Here plus setup status skeleton | Tenant setup inside Everbranch | Keep Start Here; import/mobile are intent/status only | PR 3 updated |
| `POST /start/setup-status` | tenant | Setup status save action | Tenant setup inside Everbranch | Captures intent/status only; no connector or billing activation | PR 3 added |
| `/onboarding` | tenant | Authenticated onboarding wizard | Tenant setup inside Everbranch | Keep onboarding, do not claim self-service complete | Later |
| `/billing/checkout`, `/billing/portal` | tenant guarded billing | Hosted billing handoff routes exist behind guards | Tenant billing readiness | Keep disabled/guarded posture | No |
| `/agreements` | tenant | User Agreements and provider receipt mirrors | Tenant owner/admin financial records | Read-only accepted copies; hide landlord and access evidence | Yes, 2026-07-16 |
| `/agreements/{agreement}` | tenant | Accepted immutable agreement | Tenant owner/admin financial records | Exact tenant/version only | Yes, 2026-07-16 |
| `/proposals/{public_token}` | Evergrove public | Password-protected proposal and electronic signature | Evergrove client agreement delivery | Evergrove-host-only, noindex, throttled, expiring/revocable | Yes, 2026-07-16 |
| `/marketing/*` | tenant | Backstage appears as operational/internal source language in many mature pages | Tenant marketing/operations | Change only after page-by-page tenant UX pass | Later |
| `/admin/*` | internal tenant/admin | Backstage/admin operational language | Internal/tenant admin tools | Keep for now unless surfaced publicly | Later |
| `/wiki/*` | internal/tenant knowledge base | Backstage Wiki | Internal/tenant knowledge base | Keep for now; needs separate IA decision | Later |
| `/shopify/app` | Shopify embedded | Shared embedded shell, app pages | Shopify flagship embedded Everbranch app | Everbranch in shell; page content stays tenant/module-specific | Yes through shared shell only |
| `/shopify/app/start` | Shopify embedded | Start Here/setup checklist | Shopify tenant setup | Keep; later add imports/mobile setup clarity | Later |
| `/shopify/app/store` | Shopify embedded | App Store safe module catalog | Tenant module discovery | Keep safe-module language | No |
| `/shopify/app/integrations` | Shopify embedded | Placeholder-first integrations | Tenant integrations setup | Keep Shopify, Square, CSV, manual, mobile readiness | No |
| `/shopify/app/edit` | Shopify embedded | Modern Forestry app content editor | Native app/customer dashboard content editing | Top-level Edit App page with Customer Dashboard and Mobile Home tabs; tenant 1 only | Yes |
| `/shopify/auth/{store}` | Shopify auth | OAuth redirect | Shopify install/auth | Canonical Everbranch callback host | No |
| `/shopify/callback/{store}` | Shopify auth | OAuth callback | Shopify install/auth | Canonical Everbranch callback host | No |
| `/shopify/reinstall/{store}` | Shopify auth | Reinstall path | Shopify install/auth | Keep route/copy until Partner Dashboard decision | No |
| `/shopify/marketing/v1/*` | Shopify app proxy | Storefront proxy operations | Modern Forestry/tenant storefront workflows | Keep proxy subpath/behavior unchanged | No |
| `/apps/forestry/*` | Shopify app proxy | Legacy storefront app proxy alias | Existing Shopify/storefront behavior | Keep for compatibility | No |
| `/api/mobile/v1/modern-forestry/*` | mobile API | Modern Forestry catalog/home/collection JSON | Modern Forestry tenant-specific mobile catalog | Keep Modern Forestry scoped; not generic Everbranch mobile | No |
| `/api/onboarding/*` | tenant API | Onboarding wizard contracts | Tenant setup API | Keep route names/behavior | No |
| `/internal/onboarding/harness` | internal | Internal harness | Internal debug/admin only | Keep internal language | No |
| `shopify.app.toml` | Shopify config | Name/handle still Modern Forestry Backstage | Shopify Partner app identity | Requires deliberate Partner Dashboard/app review decision | Later |

## Brand Copy Findings

| Search term | Main occurrence class | Safe now | Decision |
| --- | --- | --- | --- |
| `Forestry Backstage` | Public meta/copy, auth fallbacks, shared shell labels, historical docs/tests, Shopify app TOML | Partial | Changed public/auth/shared shell labels; kept Shopify app TOML and historical docs |
| `Backstage` | Operational tenant pages, wiki/internal tools, JS messages, service diagnostics, docs | Partial | Changed obvious shared platform labels only; tenant/internal operational wording stays for later page-by-page pass |
| `Grovebud` / `GroveBud` | Legacy domain tests/docs/config references | No | Keep as legacy/edge/domain migration evidence |
| `Modern Forestry` | Flagship tenant, mobile catalog, Shopify store examples, wiki/content, tests | No for tenant copy | Preserve where tenant-specific; do not replace mobile/catalog/customer content |
| `Everbranch` | New platform brand docs, tests, canonical host docs | Yes | Use for public/auth/shared platform/admin labels |
| `The Everbranch` | Company/legal style label | Later | Central label exists; not used broadly yet |
| `platform` | Generic product/technical term | N/A | Keep where it describes product/platform concepts |
| App name labels | `config/app.php`, auth meta/head, shell components | Yes | Centralized through `config/everbranch.php` and safe fallbacks |

## Files Inspected

- `routes/web.php`
- `shopify.app.toml`
- `config/product_surfaces.php`
- `config/tenancy.php`
- `config/app.php`
- `config/shopify_embedded.php`
- `config/shopify_webhooks.php`
- `resources/views/partials/head.blade.php`
- `resources/views/platform/*.blade.php`
- `resources/views/layouts/auth/*.blade.php`
- `resources/views/components/app-*.blade.php`
- `resources/views/components/shopify-embedded-shell.blade.php`
- `resources/views/landlord/*.blade.php`
- `resources/views/shopify/*.blade.php`
- `resources/views/marketing/*.blade.php`
- `resources/views/wiki/*.blade.php`
- Mobile routes/controllers/services in the current working tree

## PR 2 Code Change Boundary

Changed now:
- Added `config/everbranch.php` display labels.
- Updated public/auth/shared shell/admin labels to Everbranch where they describe the platform.
- Added focused brand/navigation assertions.

Not changed now:
- Route names or URLs.
- Shopify app TOML name/handle.
- Shopify app proxy paths.
- Module catalog entries or visibility.
- Billing flags or lifecycle behavior.
- Modern Forestry-scoped mobile catalog language.
- Tenant/internal Backstage operational copy in marketing, wiki, diagnostics, and mature feature pages.

## Recommended Next Step

PR 3 should focus on intake/setup clarity: make the setup surface explicitly capture Shopify, Square, CSV, manual import, and Android/iOS mobile readiness without activating billing, adding modules, or generalizing the mobile API.
