# Everbranch App Surface Inventory

Last updated: 2026-05-21

## Executive Summary

The existing Laravel codebase is already the best candidate foundation for the multi-tenant Everbranch app. It is not just a Modern Forestry one-off: it contains landlord/admin surfaces, tenant-aware app shell and dashboard services, an authenticated onboarding wizard, setup status pages, public access request flow, Shopify embedded app pages, canonical module catalog services, and tenant App Store surfaces.

No separate native mobile app project was found in this workspace. There are no native Android folders, native iOS folders, Expo files, React Native files, Capacitor/Ionic files, Tauri/Electron files, or PWA service worker/manifest files beyond build/deploy manifests. The current mobile work is a Modern Forestry-scoped mobile catalog API plus onboarding metadata for mobile intent.

Preserve and fold these existing surfaces into Everbranch rather than duplicating them in a new app:

- Laravel landlord, tenant, public, auth, onboarding, Shopify embedded, and module store surfaces.
- `UnifiedAppNavigationService`, `UnifiedDashboardService`, `TenantExperienceProfileService`, `TenantModuleCatalogService`, and `TenantModuleAccessResolver`.
- The authenticated onboarding wizard and onboarding API seams.
- The tenant Start Here/setup status page.
- The landlord onboarding diagnostics and intake queue.
- The workflow UI primitives in `resources/css/forestry-ui.css`.
- The Modern Forestry mobile catalog API as tenant-specific beta/reference work.

Do not generalize yet:

- The Modern Forestry mobile catalog API. It is explicitly Modern Forestry-scoped and should remain so until a separate mobile architecture decision is made.
- Shopify OAuth/install behavior. It is already a critical flagship path and should not be changed as part of app discovery.
- Module install/entitlement behavior. Module productization should use the existing catalog/access services.
- Billing/checkout. Billing remains guarded and disabled until readiness gates pass.

## App Surface Map

| surface/folder/file | current brand/context | purpose | tenant-aware? | landlord-aware? | Shopify-aware? | mobile/native/PWA relevance | readiness level | recommendation |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `routes/web.php` | Everbranch platform with legacy Modern Forestry/Backstage route history | Single route registry for public, auth, tenant, landlord, Shopify, mobile catalog, onboarding, and API-like web endpoints | Yes | Yes | Yes | Contains Modern Forestry mobile catalog routes | Alpha SaaS foundation | Preserve as canonical app map; avoid route renames until route/page ownership cleanup is planned. |
| `app/` | Mixed Everbranch, Modern Forestry, legacy Backstage internals | Laravel application services, controllers, models, jobs, policies | Yes | Yes | Yes | Includes mobile catalog controller/service and mobile onboarding support | Strong app foundation with mixed legacy names | Fold into Everbranch; centralize new behavior through existing services. |
| `resources/views/` | Everbranch, Modern Forestry, Shopify embedded, landlord/admin | Blade views for public, auth, tenant shell, landlord, onboarding, Shopify embedded, marketing modules | Yes | Yes | Yes | Responsive web only | Usable but visually uneven | Preserve; continue targeted coherence and setup polish before broad redesign. |
| `resources/js/` | Laravel/Vite app with React islands and onboarding wizard | Tenant/admin/marketing React islands, Shopify embedded dashboard, vanilla onboarding wizard | Partly | Partly | Yes | No native runtime; web JS only | App-like web frontend | Preserve; avoid creating a second frontend until clear extraction need exists. |
| `resources/css/app.css` and `resources/css/forestry-ui.css` | Legacy `fb-*` workflow primitives | Shared styling, workflow shells, panels, chips, buttons, setup states | Yes | Yes | Partly | Responsive web only | Valuable but legacy-named | Preserve primitives; later decide whether to alias names to Everbranch without breaking CSS. |
| `/` root and public platform routes | Everbranch public platform | Public promo, plans, demo, access request, contact, public catalog | No tenant session required | No | Indirect | Responsive web only | Early public-product surface | Continue public copy and clarity work; do not imply complete self-service. |
| `resources/views/platform/*` | Everbranch public platform | Public marketing, access request, catalog, request submitted | No tenant session required | No | Indirect | Responsive web only | Readiness/alpha | Preserve and keep access request flow simple. |
| Auth views and shared layouts | Everbranch product label after PR 2 | Login/register/auth-adjacent pages | User-aware | No | No | Responsive web only | Coherent enough for alpha | Preserve; only safe copy updates. |
| `/dashboard` and dashboard Livewire/views | Everbranch tenant app shell | Tenant launchpad, adaptive navigation, module cards, command palette | Yes | No | Indirect through tenant stores/modules | Responsive web only | App foundation | Use as tenant app home; polish navigation/setup hierarchy next. |
| `UnifiedAppNavigationService` | Everbranch/tenant operational shell | Tenant-aware app navigation from modules and permissions | Yes | No | Indirect | Responsive web only | Important foundation | Preserve as canonical navigation source. |
| `UnifiedDashboardService` | Everbranch tenant dashboard | Launchpad payloads, pinned modules, next actions | Yes | No | Indirect | Responsive web only | Important foundation | Preserve and use for future setup guidance. |
| `TenantExperienceProfileService` | Tenant operating context | Derives operating mode/channel/use-case from stores, Square-like signals, modules | Yes | No | Yes | No direct native support | Valuable bridge for non-Shopify paths | Preserve; use for Square/CSV/manual readiness language later without automation. |
| `/start` and `/start/setup-status` | Everbranch tenant setup | Tenant Start Here and setup guidance/status panel from PR 3, polished in PR 6 | Yes | No | Derives Shopify status | Captures mobile interest as intent only | Clear alpha setup guidance | Preserve; keep as the tenant-facing setup home until fuller onboarding automation is ready. |
| `resources/views/onboarding/start-here.blade.php` | Everbranch setup/readiness | Tenant setup overview, import path, mobile interest, module interests, plan/commercial summaries | Yes | No | Yes | Mobile intent display only | Useful alpha surface | Preserve; clarify copy and action hierarchy next. |
| `/onboarding` Livewire wizard | Authenticated onboarding wizard | Wizard UI for outcomes, modules, data source, mobile intent, review/start | Yes | No | Supports Shopify as one rail | Captures mobile intent; no native app | App-like onboarding system | Preserve as a major Everbranch onboarding foundation. |
| `/api/onboarding/wizard-contract` | Onboarding API seam | Returns wizard contract/options | Yes | No | Supports Shopify/direct rails | Includes mobile intent options | Solid internal API seam | Preserve; document contract before expanding. |
| `/api/onboarding/blueprint-draft` | Onboarding API seam | Persists onboarding blueprint drafts | Yes | No | Supports onboarding rail context | Includes mobile intent fields | Solid internal API seam | Preserve; use for self-service skeleton after safety gates. |
| `/api/onboarding/blueprint-finalize` | Onboarding API seam | Finalizes onboarding blueprint | Yes | No | Supports onboarding rail context | Includes mobile intent fields | Solid internal API seam | Preserve; keep guarded. |
| `/api/onboarding/blueprint-post-provisioning-summary` | Onboarding API seam | Post-provisioning summary for setup handoff | Yes | Admin/creator guarded where needed | Indirect | No native runtime | Internal readiness seam | Preserve; keep permission checks. |
| `app/Livewire/Onboarding/Wizard.php` | Authenticated tenant onboarding | Mounts wizard with tenant-scoped contract/action URLs and module cards | Yes | No | Indirect | Mobile intent only | Strong app-like system | Preserve. |
| `app/Http/Controllers/Onboarding/*` | Onboarding lifecycle | Wizard API, provisioning status, internal handoff, telemetry | Yes | Admin/creator for guarded provisioning | Indirect | Mobile intent only | Strong but needs product hardening | Preserve; do not expose provisioning broadly. |
| `/landlord` dashboard | Everbranch Admin Console | Landlord/admin overview and operational entry point | No tenant context as runtime tenant | Yes | Indirect | No native runtime | Alpha operator portal | Preserve; continue control-center coherence. |
| `/landlord/onboarding/journey` | Everbranch landlord diagnostics | Onboarding diagnostics and journey evidence | Tenant records visible to landlord | Yes | Indirect | No native runtime | Good evidence surface | Preserve for readiness and triage. |
| `/landlord/onboarding/intake` | Everbranch landlord intake queue | PR 5 intake triage for tenant setup statuses and import/mobile/commercial intent | Tenant records visible to landlord | Yes | Shows derived Shopify status | Mobile intent triage only | New operator surface | Preserve; keep actions review/status only. |
| `/landlord/commercial-intent` | Everbranch landlord commercial intent gate | PR 14 operator summary for plan/lane intent, implementation help, custom requests, and billing lane blockers | Tenant records visible to landlord | Yes | Shopify lane blockers/evidence only | Mobile only through custom/setup intent metadata | Decision-support surface | Preserve as decision support only; do not add charge, checkout, subscription, invoice, module install, or entitlement actions without a future activation PR. |
| `/landlord/readiness` | Everbranch landlord readiness dashboard | PR 16 self-service launch posture summary across onboarding, Shopify, billing, modules, privacy, evidence, mobile, and launch blockers | Tenant counts/context only | Yes | Summarizes Shopify local/external evidence posture | Marks generic Everbranch mobile as not started | Operator status surface | Preserve as status/control visibility only; it does not approve launch or activate billing. |
| `LandlordTenantDirectoryController` | Everbranch landlord tenant management | Tenant directory and provisioning/access approval bridge | Tenant records visible to landlord | Yes | Indirect | No native runtime | Important provisioning bridge | Preserve; avoid broad behavior changes. |
| `/landlord/tenants/create` | Everbranch landlord tenant blueprint | Landlord-created tenant blueprint/profile for Shopify, direct/manual, CSV, Square-pending, demo, sandbox, unknown, and future work-management tenants | Creates tenant record and setup status | Yes | Shopify can be selected without triggering OAuth | Captures mobile field-capture intent only; no generic mobile runtime | Blueprint foundation with work-management intent | Preserve as planning/setup metadata only; do not install modules, entitlements, billing, connector automation, project/task/upload/messaging systems, mobile APIs, or industry-specific route forks from template choices. |
| `PlatformAccessRequest` flow | Everbranch public intake | Public request to access, captures import path and mobile interest | Becomes tenant-linked after approval | Yes after approval | Shopify can be selected as import path | Captures mobile interest only | Safe intake foundation | Preserve; keep landlord/manual review. |
| `TenantSetupStatus` and service | Everbranch setup status | Setup state, import path, mobile interest, source request context | Yes | Yes | Shopify status derived from stores | Captures intent only | New safety layer | Preserve as the central setup skeleton. |
| `shopify.app.toml` | Shopify app still named Modern Forestry Backstage; canonical URLs use Everbranch host | Shopify CLI app config, embedded app URL, callbacks, app proxy | Indirect via app runtime | No | Yes | No native runtime | Canonical host ready, PR 9 evidence added, brand/scopes/privacy webhooks still require review | Preserve config behavior; Partner Dashboard/manual brand review and privacy webhook implementation next. |
| `/shopify/app/*` routes | Shopify embedded app | Embedded dashboard, start, plans, App Store, integrations, customers, assistant, messaging, rewards, settings | Yes through store/tenant context | No | Yes | No native runtime | Strong flagship integration surface with PR 9 readiness evidence | Preserve; no OAuth/install changes without a dedicated Shopify readiness PR. |
| `resources/views/shopify/*` | Shopify embedded app UI | Embedded Shopify pages and shell | Yes | No | Yes | Responsive embedded web | Functional but mixed legacy/tenant copy | Preserve; polish labels later without changing behavior. |
| `app/Http/Controllers/ShopifyEmbedded*` | Shopify embedded controllers | Embedded app pages, API endpoints, search, modules, messaging, rewards, settings | Yes | No | Yes | No native runtime | Strong but broad | Preserve and keep Shopify path flagship. |
| `extensions/forestry-marketing-embed` | Shopify storefront extension, legacy Forestry naming | Marketing storefront tracker app embed | Storefront/store-aware | No | Yes | No native runtime | Existing Shopify extension | Preserve; brand rename needs separate Shopify extension plan. |
| `extensions/forestry-marketing-pixel` | Shopify web pixel, legacy Forestry naming | Shopify pixel extension source | Storefront/store-aware | No | Yes | No native runtime | Existing Shopify extension | Preserve; do not rename extension folders in discovery. |
| `config/module_catalog.php` | Canonical module/commercial catalog | Plans, modules, capabilities, visibility, billing mode, CTA routing | Yes | Yes | Yes | No native runtime | Canonical source of truth | Preserve; PR 7 should productize through this file/services. |
| `TenantModuleCatalogService` | Everbranch module App Store/catalog | Filters modules, public payload, tenant activate/request behavior, and PR 7 display-only module product metadata | Yes | Yes | Yes | Future mobile relevance labels only | Strong foundation | Preserve; fail closed for unsafe/internal/roadmap modules and keep pricing/mobile labels display-only. |
| `TenantModuleAccessResolver` | Tenant module entitlement/access | Module access checks and visible module cards | Yes | No | Indirect | No native runtime | Strong foundation | Preserve; no ad hoc module checks. |
| `resources/views/marketing/modules.blade.php` | Tenant Module Store | Tenant-facing module discovery/request/activation surface with PR 7 metadata labels and PR 8 custom request CTAs | Yes | No | Indirect | Responsive web only; mobile relevance is display-only | Productized alpha App Store surface | Preserve; custom requests stay intake-only and separate from entitlements. |
| `resources/views/shopify/app-store.blade.php` | Shopify embedded Module Store | Embedded App Store surface inside Shopify with PR 7 metadata labels | Yes | No | Yes | Responsive embedded web; mobile relevance is display-only | Productized alpha App Store surface | Preserve; keep Shopify OAuth/install behavior unchanged. |
| `custom_module_requests` + `/custom-module-requests` | Everbranch tenant custom request intake | Tenant custom module/workflow request list, form, and detail pages | Yes | Landlord sees via triage | Indirect only when linked to a safe module | Mobile relevance metadata only | Intake workflow | Preserve as request/triage only; do not create modules, entitlements, billing, quotes, invoices, or mobile APIs from request statuses. |
| `/landlord/custom-module-requests` | Everbranch landlord custom request triage | Landlord queue for status, next action, and internal notes | Tenant records visible to landlord | Yes | Indirect | Mobile relevance filter only | Operator intake workflow | Preserve as triage only; conversion to reusable module remains manual/future. |
| `app/Http/Controllers/Mobile/ModernForestryProductCatalogController.php` | Modern Forestry-specific mobile catalog API | Mobile catalog endpoints for Modern Forestry product data | Explicit Modern Forestry tenant only | No | Uses Shopify store lookup | Mobile API, not native app | Beta/reference, fail-closed | Preserve as tenant-specific; do not generalize until mobile architecture PR. |
| `app/Services/Mobile/ModernForestryMobileProductCatalogService.php` | Modern Forestry-specific mobile catalog service | Shopify-backed mobile catalog payloads with local/testing fake mode | Explicit Modern Forestry tenant only | No | Yes | Mobile API, not native app | Beta/reference, scoped | Preserve; keep test coverage. |
| `config/mobile_catalog.php` | Mobile catalog test/runtime config | Fake mode flag for Modern Forestry catalog tests/local | Explicit mobile catalog support config | No | Indirect | Mobile API support only | Narrow config | Preserve; not a generic mobile config. |
| `app/Support/Onboarding/MobileIntent.php` | Onboarding mobile intent | Value object for mobile access needs, roles, jobs, priority | Yes as onboarding payload | No | No | Captures mobile needs, not app runtime | Useful intake metadata | Preserve; connect to future mobile requirements. |
| `app/Support/Onboarding/MobileRole.php` and `MobileJob.php` | Onboarding mobile roles/jobs | Enumerates mobile user roles and jobs requested | Yes as onboarding payload | No | No | Captures mobile app requirements | Useful discovery/intake metadata | Preserve; do not imply active native support. |
| `tests/Feature/Mobile/ModernForestryMobileProductCatalogTest.php` | Modern Forestry mobile catalog | Guards beta/scoped mobile catalog behavior | Yes, explicitly Modern Forestry | No | Yes | Mobile API test | Important guardrail | Preserve and run during mobile-adjacent changes. |
| `package.json` and `vite.config.js` | Laravel Vite web app | Web frontend build with React islands, Shopify Polaris, Tailwind, Playwright tooling | App-wide | App-wide | Yes | No native framework detected | Web app foundation | Preserve; not a separate mobile app project. |
| `public/build/manifest.json` | Vite build manifest | Asset manifest generated by Laravel/Vite build | No | No | No | Not a PWA manifest | Generated artifact | Do not treat as PWA/mobile. |
| `.shopify/deploy-bundle/manifest.json` | Shopify deploy bundle | Shopify CLI deploy bundle metadata | No | No | Yes | Not a PWA/mobile manifest | Generated/deploy artifact | Do not treat as PWA/mobile. |
| `README.md`, `README_FOR_AGENTS.md`, `SYSTEM_SNAPSHOT.md` | Project docs and agent rules | Operating context, readiness posture, guardrails | N/A | N/A | N/A | Notes current mobile API scope | Useful governance | Keep updated after meaningful work. |

## Existing App-Like Systems

### Authenticated Onboarding Wizard

The authenticated onboarding wizard is already a real app-like onboarding surface. It includes:

- `app/Livewire/Onboarding/Wizard.php`
- `resources/views/livewire/onboarding/wizard.blade.php`
- `resources/js/onboarding/wizard.js`
- `app/Http/Controllers/Onboarding/OnboardingWizardApiController.php`
- `app/Http/Controllers/Onboarding/OnboardingProvisioningApiController.php`
- `/onboarding`
- `/api/onboarding/wizard-contract`
- `/api/onboarding/blueprint-draft`
- `/api/onboarding/blueprint-finalize`
- `/api/onboarding/blueprint-post-provisioning-summary`

This should be preserved as Everbranch's future guided setup foundation. It is tenant-aware and already includes data-source and mobile-intent concepts. It should remain guarded; provisioning and automation should not be broadened until readiness gates pass.

### Start Here Setup Page

The Start Here setup page is the safest current tenant-facing setup surface:

- `/start`
- `/start/setup-status`
- `resources/views/onboarding/start-here.blade.php`
- `TenantSetupStatus`
- `TenantSetupStatusService`

It shows setup phase, import path guidance, Shopify status, Square/CSV/manual status, module interests, mobile interest, Everbranch review status, next actions, and inactive-capability guardrails without pretending that self-service automation is complete.

### Landlord Intake Queue

The landlord intake queue from PR 5 is now the operator's clearest triage surface:

- `/landlord/onboarding/intake`
- `LandlordOnboardingJourneyDiagnosticsController`
- `resources/views/landlord/onboarding/intake.blade.php`

It helps identify tenants needing review, Shopify-selected-but-not-connected cases, Square/CSV/manual/manual-review cases, undecided import paths, and mobile interest.

### Landlord Onboarding Diagnostics

Landlord onboarding diagnostics are already an evidence and support surface:

- `/landlord/onboarding/journey`
- onboarding journey diagnostics views and tests
- provisioning bridge context from access requests

This should remain operator-facing and evidence-oriented.

### Shopify Embedded App

Shopify is already the flagship embedded app path:

- `shopify.app.toml`
- `/shopify/app`
- `/shopify/app/start`
- `/shopify/app/store`
- `/shopify/app/integrations`
- `/shopify/app/customers/*`
- `/shopify/app/assistant/*`
- `/shopify/app/messaging/*`
- `/shopify/app/rewards/*`
- `/shopify/app/settings`
- `resources/views/shopify/*`
- `app/Http/Controllers/ShopifyEmbedded*`

The app config uses canonical Everbranch URLs, while the Shopify app name still carries legacy Modern Forestry Backstage naming. Brand changes in Shopify configuration and Partner Dashboard should be handled in a separate, explicit PR/checklist.

### Module Store / Module App Store Surfaces

The repo already has module store foundations:

- `config/module_catalog.php`
- `TenantModuleCatalogService`
- `TenantModuleAccessResolver`
- `resources/views/marketing/modules.blade.php`
- `resources/views/shopify/app-store.blade.php`
- tenant module store routes
- Shopify embedded App Store routes

PR 7 productized the module lifecycle, labels, visibility matrix, setup requirements, and tenant/operator explanations through these existing services instead of creating a separate module framework. PR 8 added custom request entry points from the tenant Module Store, but those requests are separate from known-module access requests and do not create modules or mutate entitlements.

Modules can eventually have web, Shopify, landlord, tenant, and future mobile surfaces. Future mobile module behavior must remain API-driven, tenant-scoped, and entitlement-checked. Planned job/photo/quote/invoice/team communication/client communication capabilities remain future module candidates, not live features from this pass.

### Workflow UI Primitives

The workflow UI primitives are concentrated in:

- `resources/css/forestry-ui.css`
- `resources/js/onboarding/wizard.js`
- workflow-oriented Blade views in onboarding, landlord diagnostics, and setup surfaces

The `fb-*` naming is legacy, but the primitives are valuable. A later UI PR can decide whether to add Everbranch aliases or wrapper classes. Discovery should not rename CSS primitives.

### Modern Forestry Mobile Catalog/API Work

The current mobile work is explicitly Modern Forestry-scoped:

- `/api/mobile/v1/modern-forestry/home`
- `/api/mobile/v1/modern-forestry/collections`
- `/api/mobile/v1/modern-forestry/collections/{handle}/products`
- `/api/mobile/v1/modern-forestry/products`
- `/api/mobile/v1/modern-forestry/products/{handle}`
- `ModernForestryProductCatalogController`
- `ModernForestryMobileProductCatalogService`
- `ModernForestryMobileProductCatalogTest`

This is not a generic Everbranch mobile API. It should be preserved as beta/reference work and kept fail-closed.

### Tenant Dashboard / App Shell / Navigation

The tenant app shell is already present:

- `/dashboard`
- `resources/views/livewire/dashboard/launchpad.blade.php`
- `resources/views/components/app-shell.blade.php`
- `resources/views/components/app-sidebar.blade.php`
- `resources/views/components/app-topbar.blade.php`
- `UnifiedAppNavigationService`
- `UnifiedDashboardService`
- command palette and search payloads

This should become Everbranch's primary tenant app surface after setup guidance and navigation polish.

### Public Access Request Flow

The public access request flow is the safe current front door:

- `/platform/access-request`
- `PlatformAccessRequest`
- `CustomerAccessApprovalService`
- `LandlordTenantDirectoryController`
- `TenantSetupStatusService::seedFromAccessRequest()`

This flow supports import path and mobile interest capture while keeping tenant creation and review under landlord/operator control.

## Mobile/App Discovery

Discovery found no separate mobile/native project in this workspace:

- Native Android files: not found.
- Native iOS files: not found.
- Expo files: not found.
- React Native files: not found.
- Capacitor/Ionic files: not found.
- Tauri/Electron files: not found.
- PWA manifest/service worker files: not found.
- Only responsive web/mobile catalog API work was found.

Files that look like manifests are not mobile/PWA app manifests:

- `public/build/manifest.json` is a Laravel/Vite build asset manifest.
- `.shopify/deploy-bundle/manifest.json` is Shopify CLI deploy metadata.

The mobile-relevant implementation that does exist is:

- Responsive Laravel/Blade/Livewire/Vite web surfaces.
- Shopify embedded web surfaces.
- A Modern Forestry-scoped mobile catalog API.
- Onboarding fields/value objects that capture mobile intent, roles, jobs, and priority.

## Recommendation

Recommendation: A. Fold existing app surfaces into Everbranch as the multi-tenant app.

Reasoning:

- The repo already contains the core multi-tenant SaaS surface area: public access request, tenant app shell, onboarding wizard, setup status, landlord portal, Shopify embedded app, module store, canonical catalog services, tenant boundary services, and readiness guardrails.
- No separate Android/iOS/mobile app project was found that would be a better foundation.
- Building a second Everbranch app beside this one would duplicate navigation, tenancy, module, onboarding, Shopify, and landlord control logic.
- The safer path is to keep hardening this app into a coherent Everbranch platform while preserving Modern Forestry-specific behavior behind tenant-scoped routes, services, and tests.

No separate `TimberLine` folder, app, package, or native project was found during this discovery pass. If TimberLine refers to this Laravel codebase/workspace, then this is the best Everbranch app foundation found locally. If TimberLine is expected to be a separate repository or folder, that source was not present in `/Users/johncollins/Code/myapp`.

## Proposed PR Sequence After Discovery

### PR 6: Tenant-Facing Setup Guidance Polish

Completed in PR 6 using the existing `/start` and `/start/setup-status` surfaces. Setup now explains Shopify, Square interest, CSV/manual import, module interests, mobile intent, Everbranch review, and inactive capabilities without adding automation.

The PR stayed limited to copy/layout polish, display helpers, docs, and tests. It did not activate billing, Shopify OAuth changes, Square automation, CSV execution, module installs, or generic mobile support.

### PR 7: Module App Store Productization

Completed in PR 7 using `config/module_catalog.php`, `TenantModuleCatalogService`, and `TenantModuleAccessResolver`. The pass added display-only product metadata, clarified safe-to-market/live/beta/internal/roadmap behavior, and improved tenant, Shopify embedded, and landlord module explanations.

No new modules were added.

### PR 8: Custom Module Request Workflow

Completed in PR 8 using the existing tenant/landlord app shell patterns. Added request capture, tenant list/detail, landlord triage, statuses, and review fields. Conversion to reusable modules remains a documented operator workflow, not an automated behavior.

## Evidence Commands

Useful discovery commands run during this pass:

```bash
rg --files | rg -i '(^|/)(android|ios|capacitor|ionic|expo|react-native|tauri|electron|manifest|service-worker|sw\.|mobile|pwa|app\.json|app\.config|capacitor\.config|ionic\.config|eas\.json|metro\.config|package\.json|vite\.config|shopify\.app\.toml|README|SYSTEM_SNAPSHOT|UI_CHANGELOG)'
find app resources routes config database tests -maxdepth 3 -type d | sort
find . -maxdepth 3 -type f | rg -i '(capacitor|ionic|expo|react-native|tauri|electron|android|ios|service-worker|pwa|manifest|app\.json|eas\.json|metro\.config)'
rg -n 'Modern Forestry|Forestry Backstage|Everbranch|Backstage|mobile|android|ios|companion|onboarding|wizard|module store|app store|tenant|landlord|Shopify embedded|Capacitor|Expo|React Native|Ionic|PWA|manifest|service worker|push notification|native|mobile catalog'
rg --files tests/Feature/Onboarding tests/Feature/Shopify tests/Feature/Everbranch tests/Feature/Mobile | sort
```
