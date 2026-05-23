# Everbranch Master Readiness Plan

Status: tenant blueprint foundation pass after access-surface separation and test access work.
Date: 2026-05-21.

## Executive Summary

Everbranch is best described as an internal alpha SaaS platform with strong foundations and uneven product cohesion. It is not ready for broad public or client onboarding.

What exists:
- Canonical host model is in code: public `theeverbranch.com`, landlord app `app.theeverbranch.com`, tenant apps `<slug>.theeverbranch.com`.
- Legacy runtime host rejection is covered by code and focused tests; legacy domains should remain edge redirects only.
- Shopify is the strongest integration path with canonical Everbranch OAuth callback hosts.
- Tenant module catalog, App Store filtering, landlord commercial configuration, and guarded Stripe preparation actions exist.
- Modern Forestry remains the flagship tenant and must stay stable.
- A Modern-Forestry-specific mobile product catalog API exists in the current working tree; it is not a generic Everbranch mobile platform.
- A PR 3 setup status skeleton exists for tenant `/start` and landlord onboarding diagnostics. It captures import path, Shopify status, Square/CSV/manual status, module interests, mobile interest, landlord review status, and next action without connector automation or checkout.
- PR 4 connects approved/matched public access requests to tenant setup status so landlord provisioning carries intake metadata forward without activating self-service onboarding.
- PR 5 adds a landlord intake queue at `/landlord/onboarding/intake` for filtering tenants by review, Shopify connection, import path, mobile interest, and next action.
- PR 6 improves tenant `/start` guidance so tenants understand Shopify, Square, CSV/manual, module interest, mobile interest, Everbranch review, and inactive billing/automation boundaries without adding workflows.
- PR 7 productizes module display metadata for tenant, Shopify embedded, and landlord module surfaces without adding modules, changing entitlements, or activating billing.
- PR 8 adds tenant custom module request intake plus landlord triage without creating modules, changing entitlements, generating quotes/invoices, or activating billing.
- PR 9 adds Shopify App Store readiness evidence for canonical URLs, embedded surfaces, webhook expectations, app proxy expectations, install/reinstall manual evidence, Partner Dashboard checks, and privacy webhook blockers.
- PR 10 maps existing Stripe/billing code and confirms billing remains disabled by default, with Stripe direct billing separated from future Shopify App Store billing.
- PR 11 adds conservative Shopify privacy webhook endpoints for `customers/data_request`, `customers/redact`, and `shop/redact`, backed by HMAC verification and durable manual-review evidence records.
- PR 12 adds the Shopify Partner Dashboard / CLI evidence runbook for TOML deployment, dev-store install/reinstall, app proxy, privacy webhook delivery, scope review, and evidence storage.
- PR 13 adds tenant plan/billing lane interest capture plus landlord commercial intent triage without activating billing, checkout, subscriptions, invoices, modules, or entitlements.
- PR 14 adds an operator-only commercial intent summary at `/landlord/commercial-intent` with billing lane decision statuses and blockers. It is decision support only and does not activate billing, checkout, invoices, subscriptions, modules, or entitlements.
- PR 15 adds a dated Shopify evidence packet and a scope/branding decision record. External Partner Dashboard, CLI deploy/release, dev-store install/reinstall, app proxy, and live privacy webhook delivery evidence remain pending unless a human operator captures them.
- PR 16 adds `/landlord/readiness` as a landlord/operator self-service readiness dashboard. It aggregates onboarding, intake, modules, custom requests, commercial intent, billing, Shopify, privacy webhook, external evidence, mobile, and launch posture without approving launch or activating anything.
- PR 17 confirms the current Shopify Partner/dev dashboard target app as `Modern Forestry Backstage`, handle `modernforestrybackstage`, and dev store `modernforestry.myshopify.com`. Everbranch public Shopify branding remains a later decision.
- PR 18 captures partial external Shopify evidence: read-only Shopify CLI app-info for `Modern Forestry Backstage`, partial live app-proxy health evidence through the Modern Forestry storefront, and explicit pending files for Partner Dashboard, install/reinstall, privacy webhook delivery, and scope review.
- PR 19 prepares the final operator screenshot pack with required screenshot slots and a step-by-step checklist before any deploy/release decision.
- PR 20 adds config-backed Everbranch brand assets across Laravel-rendered platform surfaces while preserving the current Shopify Partner/TOML identity.
- The access surface separation pass adds true `platform_admin` landlord routing, keeps completed tenant users in the tenant app, sends explicitly incomplete tenants to `/start`, labels demo/sandbox tenants in the app shell, and gives the landlord tenant detail page a clear operator control map.
- The test access/seed pass adds `php artisan everbranch:seed-access-surfaces` for safe local/staging landlord, Modern Forestry, demo, and sandbox records. The landlord tenant detail page now shows a read-only Test Access panel with lane warnings and available test-user emails; direct impersonation remains deferred until a future audited implementation.
- The tenant blueprint foundation pass adds landlord `/landlord/tenants/create` for config-backed business templates and operating modes across Shopify, direct/manual, CSV, Square-pending, demo, sandbox, and unknown tenants. Blueprint state reuses `TenantAccessProfile.metadata` and existing `tenant_setup_statuses`, and tenant `/start` reflects the blueprint for non-Shopify tenants. It does not build industry modules, install modules, change entitlements, trigger connector OAuth/imports, or activate billing.
- The blueprint edit/review pass adds landlord `/landlord/tenants/{tenant}/blueprint/edit` for safe existing-tenant blueprint updates and review states. Review metadata remains landlord-only; tenant `/start` receives only tenant-facing setup/profile guidance.
- The blueprint-driven module catalog alignment pass derives display-only module recommendation states from tenant blueprints. Tenant Module Store, landlord tenant detail, and tenant `/start` now show recommended/requested/planned/future/not-active-yet module families without creating modules, installing modules, changing entitlements, activating billing, running imports, enabling uploads/messaging, or creating mobile APIs.
- The Everbranch brand system and language sweep pass centralizes Evergrove/Everbranch labels, brand assets, visual tokens, and display-language terms in `config/everbranch.php`, then updates high-value public, tenant, landlord, shared/auth, and safe Shopify embedded copy away from generic Backstage and technical wording. It does not rename Shopify Partner/TOML identity, change OAuth/scopes, activate billing, install modules, change entitlements, run imports, or add mobile APIs.

What is missing:
- A fully coherent Everbranch product map that connects public site, signup, tenant app, Shopify embedded app, landlord portal, onboarding, modules, imports, billing readiness, and future mobile apps.
- Fully finished access-lane UX across every page. The four-door model now has routing, labels, seed/test users, and a landlord Test Access panel, but deeper route cleanup and audited direct impersonation remain future work.
- A first-class self-service tenant creation path. Landlord tenant blueprint creation exists, but public self-service tenant creation remains gated/manual.
- A clean client intake flow for Shopify, Square, CSV, and manual import setup.
- A generic Android/iOS mobile readiness model.
- Full tenant-facing onboarding automation after setup guidance; `/start` now explains import path next steps without implying automation is ready.
- Live Shopify Partner Dashboard/dev-store privacy webhook evidence. PR 12 documents exactly how to capture it and PR 18 adds partial CLI/app-proxy evidence, but deployment screenshots/log evidence, live privacy webhook rows, and a tested deletion/anonymization policy remain missing.
- Public billing activation evidence. Checkout and broad subscription lifecycle automation remain disabled by default, but tenant-facing Stripe checkout/portal code exists behind flags and must not be exposed to Shopify App Store merchants.
- Real paid plan activation. PR 13 captures plan interest only; Shopify Billing, Stripe checkout, manual invoice generation, and entitlement activation remain inactive.
- A fully unified visual language. The first PR 25 brand/language sweep removed generic Backstage and technical wording from high-value surfaces, but deeper public splash, app shell/sidebar/search, Bud assistant, customer profile, and Shopify embedded cleanup remain separate future passes.

What is dangerous:
- Activating checkout or broad billing lifecycle automation before readiness gates pass.
- Accepting unknown or legacy hosts at runtime.
- Falling back to the first tenant when host, session, or request context is unclear.
- Exposing internal, roadmap, disabled, or placeholder modules to tenants or public users.
- Treating the current Modern Forestry mobile catalog API as generic Everbranch mobile support.
- Launching client onboarding before Shopify, import, tenant-boundary, and support workflows have evidence.

## Canonical Product Shape

Public site:
- Home: what Everbranch does, who it is for, Shopify-first but not Shopify-only.
- Plans: clear package framing without live checkout until billing is approved.
- Modules: safe-to-market module catalog only.
- Integrations: Shopify flagship, Square/CSV/manual import planned setup concepts.
- Mobile: Android/iOS companion direction, explicitly not yet generally available.
- Contact/start: guided intake while self-service tenant creation remains gated.

Tenant app navigation:
- Home
- Setup
- Modules
- Integrations
- Customers/imports
- Mobile readiness
- Settings
- Support/request custom module

Landlord portal navigation:
- Dashboard
- Tenants
- Users
- Stores/integrations
- Onboarding/intake
- Modules and entitlements
- Custom module requests
- Billing readiness
- Audit/support tools
- Feature flags/config

Onboarding flow target:
1. Create account.
2. Create company/workspace.
3. Choose business type/template.
4. Select data sources: Shopify, Square, CSV, manual, other.
5. Connect Shopify when relevant.
6. Choose plan or start trial, still without live billing until approved.
7. Choose modules from safe App Store inventory.
8. Land in setup dashboard with progress and next required actions.
9. Capture Android/iOS mobile interest and readiness state.

Module App Store model:
- Modules are registered in `config/module_catalog.php`.
- Store visibility must fail closed.
- Only safe, visible, live or beta modules should reach tenant/public discovery surfaces.
- Lifecycle labels normalize to `draft`, `internal`, `beta`, `safe_to_market`, `live`, and `deprecated`.
- Product metadata includes category, setup effort, required integrations, mobile relevance, pricing impact, and entitlement requirement labels.
- Pricing and mobile labels are display-only until billing and mobile activation work are explicitly approved.

## Gap Matrix

| Area | Current state | Target state | Risk | Required changes | Suggested first PR |
| --- | --- | --- | --- | --- | --- |
| Product map | Many real surfaces, uneven naming and flow | One coherent public, tenant, Shopify, and landlord map | Users get lost | Document route/page ownership and cleanup sequence | This PR: product map doc |
| UI/brand | Backstage/Forestry/Modern Forestry language remains | Everbranch product brand, Modern Forestry tenant label | Confusing product identity | Brand inventory, shared labels, visual pass | Next PR: brand label inventory and config-backed naming |
| Tenant safety | Host guardrails exist; some fallback/query risks remain | No unknown host fallback, all mutations tenant-scoped | Cross-tenant exposure | Add invariant coverage and audit risky helpers | This PR: readiness gate tests |
| Shopify | Canonical callback exists; PR 9 evidence doc/test added; PR 11 privacy webhook handlers/evidence table added; PR 12 evidence runbook added; PR 18 evidence packet now has CLI app-info and partial app-proxy evidence; Partner Dashboard/install/privacy delivery evidence remains missing | Everbranch-ready Shopify app with Partner evidence | App review failure or broken installs | Execute remaining evidence runbook steps, verify Partner Dashboard, reduce/justify scopes, capture install/reinstall and privacy webhook delivery evidence | Next step: operator-assisted Partner Dashboard/dev-store evidence capture |
| Intake | Contact/start, setup status, access-request provisioning bridge, landlord intake queue, and tenant setup guidance exist but not full self-service tenant creation | Safe guided tenant creation with import/mobile inputs | Incomplete onboarding | Later self-service workspace creation and import execution evidence | Later PR after module productization |
| Modules | Catalog, fail-closed filtering, display metadata helpers, and tenant/Shopify/landlord module context exist | Productized App Store with detail pages and custom request bridge | Unsafe module exposure or overclaiming | Detail/readiness views and custom module request workflow | Next PR: custom module request workflow |
| Custom requests | Tenant request form/list/detail and landlord triage queue exist | Discovery workflow with clear operator follow-up and later reusable-module decision process | Feature sprawl or implied delivery | Add audit/reporting polish later; keep conversion manual | Later PR after Shopify readiness |
| Billing | Guarded Stripe prep exists; tenant Stripe checkout/portal code exists but is disabled by default; PR 10 audit/test added; PR 13 plan/billing lane intent capture added | Provider decision and billing activation evidence with Shopify App Store lane separated from Stripe direct lane | Accidental charges or App Store billing rejection | Keep disabled until gate passes; use Shopify App Pricing/Billing for App Store merchants; reserve Stripe for direct/custom/non-Shopify lanes | Next billing PR: lane decision/operator reporting before activation |
| Landlord | Core pages exist | Operator control center | Unsafe ops or poor support | Prioritize dashboard and auditability | Later PR: landlord dashboard clarity |
| Web quality | Many useful pages, scattered polish | Calm, coherent, human app | Alpha feel | Empty state, navigation, copy, mobile pass | Later PR: small shared layout polish |
| Mobile | Modern Forestry catalog API only | Everbranch mobile readiness for Android/iOS | Overclaiming capability | Keep scoped, design generic API later | This PR: scoped mobile invariant test |

## Implementation Roadmap

Phase 0: Audit and evidence
- Add readiness docs.
- Add consolidated invariant tests.
- Confirm Laravel app connectivity and deployment workflow posture.
- Keep billing, routes, modules, and UI behavior unchanged.

Phase 1: UI/navigation coherence
- Centralize Everbranch product naming while preserving Modern Forestry tenant naming.
- Clean navigation labels and remove confusing Backstage-only language from shared shells.
- Keep changes visual/copy-only and test smoke routes.
- PR 20 completed the first Everbranch logo/asset rollout for Laravel-rendered surfaces.
- The access surface separation pass adds clear landlord/tenant/demo/sandbox labels without changing routes broadly.

Phase 2: tenant/auth/Shopify safety
- Expand tenant-boundary tests around fallback behavior and mutation scoping.
- Add Shopify compliance webhook readiness.
- Verify Partner Dashboard URL, redirect, proxy, webhook, and scopes.
- PR 9 completed the first Shopify App Store readiness evidence pass. Privacy webhooks and live Partner evidence remain blockers.
- PR 11 implemented conservative privacy webhook handling. Live Partner Dashboard/CLI deployment evidence and a deletion/anonymization policy remain blockers.
- PR 12 documented the Partner Dashboard/CLI evidence capture process. It did not perform live external Shopify actions.
- PR 15 created the dated evidence packet and scope/branding decision record. It did not run live Shopify deploy/release, trigger webhooks, change scopes, or rename the app.
- PR 17 confirms the evidence run should target `Modern Forestry Backstage` / `modernforestry.myshopify.com` for now. It did not rename, deploy, release, or change scopes.
- PR 18 captures read-only `shopify app info`, partial app-proxy health, and scope-review evidence files. It did not deploy/release Shopify config, trigger webhooks, change scopes, rename the app, or activate billing.
- PR 19 prepares `screenshot-manifest.md` and `operator-checklist.md` for the remaining non-mutating evidence capture. It does not deploy/release Shopify config, trigger webhooks, change scopes, rename the app, or activate billing.

Phase 3: onboarding/client intake
- Harden account-to-tenant creation path.
- Add setup checklist/status that includes Shopify, Square, CSV, manual import, and mobile interest.
- Keep billing as readiness/status only.
- PR 3 completed the first setup status skeleton.
- PR 4 completed the access-request to setup-status provisioning bridge.
- PR 5 completed the first landlord intake queue and operator triage filters.
- PR 6 completed tenant-facing setup guidance polish.
- Tenant blueprint foundation completed the landlord-created profile layer for Shopify and non-Shopify tenants; later work still needs blueprint editing, public self-service handoff, and real import execution.
- Work-management blueprint intent is now captured for future project/task/assignment/communication/upload/mobile-capture modules, but all actual module implementations remain deferred.
- Later PRs must add module productization, custom request workflow, and import execution evidence.

Phase 4: module App Store productization
- Formalize lifecycle metadata and category/setup fields.
- Add detail views and entitlement-safe request actions.
- Do not add modules before the framework is stable.
- PR 7 completed display-only metadata helpers, tenant/Shopify card copy, landlord visibility context, and fail-closed tests.

Phase 5: custom module request workflow
- Add simple first-party request table, tenant form, and landlord queue.
- Keep conversion to reusable module manual and audited.
- PR 8 completed the first intake/triage workflow. Status labels remain labels only.

Phase 6: billing/recurring payment activation
- Decide Shopify Billing versus Stripe by customer path.
- Activate only after evidence, provider config, legal/privacy, and support readiness pass.
- PR 10 completed discovery: Stripe HTTP integration, hosted checkout/portal code, webhook ingestion, and fulfillment code exist, but defaults keep checkout and lifecycle disabled.
- PR 13 completed intent capture only: tenants can indicate plan and billing lane interest, while all paid activation remains disabled.
- PR 14 completed the landlord commercial intent summary and billing lane decision gate. It groups tenants by plan/lane, shows Shopify evidence and billing-disabled blockers, and supports review-only operator notes/actions.

Phase 7: public launch readiness
- Run full readiness gate, browser smoke, Shopify install, import, billing-disabled, tenant-boundary, and support drills.
- Verify DNS/TLS/edge redirects and rollback.
- PR 16 adds a landlord self-service readiness dashboard, but it is only an operator status surface. It does not approve launch.

## PR 1 Scope

This PR adds only:
- Readiness docs.
- Agent/snapshot documentation updates.
- Consolidated readiness invariant tests.

This PR must not:
- Activate billing.
- Rename routes.
- Move or add modules.
- Rewrite UI.
- Change tenant behavior.
- Generalize mobile APIs.

## PR 3 Scope Completed

PR 3 added:
- `tenant_setup_statuses` as a boring tenant-scoped setup status table.
- Tenant `/start` setup status capture for business profile, import path, Square/CSV/manual status, safe module interests, and Android/iOS mobile interest.
- Read-only Shopify connection status derived from existing Shopify store rows.
- Landlord onboarding diagnostics setup status table with review status, next action, and notes.
- Focused tests in `tests/Feature/Everbranch/ClientIntakeSetupStatusTest.php`.

PR 3 did not:
- Activate billing or checkout.
- Automate connector setup.
- Add modules.
- Generalize the Modern Forestry mobile API.
- Change Shopify OAuth/install behavior.

## PR 4 Scope Completed

PR 4 added:
- Access-request metadata promotion into `tenant_setup_statuses` during landlord approval/provisioning.
- Matching from manual landlord tenant creation by primary contact email and tenant slug.
- Idempotent setup status seeding that preserves existing tenant/operator edits.
- Internal notes and landlord diagnostics source labels showing the originating access request.
- Focused tests in `tests/Feature/Everbranch/AccessRequestProvisioningBridgeTest.php`.

PR 4 did not:
- Activate billing or checkout.
- Install modules or change entitlements.
- Automate Square or CSV imports.
- Change Shopify OAuth/install behavior.
- Generalize the Modern Forestry mobile API.

## PR 5 Scope Completed

PR 5 added:
- `/landlord/onboarding/intake` as a landlord-only setup status triage queue.
- Server-rendered filters for Everbranch review, Shopify selected but not connected, Square, CSV, manual, undecided import path, mobile interest, and reviewed statuses.
- Minimal model/service helpers for setup status triage.
- A dashboard link to the intake queue.
- Focused tests in `tests/Feature/Everbranch/LandlordIntakeQueueTest.php`.

PR 5 did not:
- Activate billing or checkout.
- Add or move modules.
- Change module entitlements.
- Automate Shopify, Square, CSV, or manual import workflows.
- Change tenant resolution.
- Generalize the Modern Forestry mobile API.

## PR 6 Scope Completed

PR 6 added:
- Display-only setup guidance helpers for setup phase, import path guidance, Shopify connection guidance, mobile interest guidance, module interest summary, Everbranch review guidance, and inactive capability boundaries.
- A clearer tenant `/start` setup section covering setup path, import/connection status, module interests, mobile interest, Everbranch review, next action, and what is not active yet.
- Focused tests in `tests/Feature/Everbranch/ClientIntakeSetupStatusTest.php`.

PR 6 did not:
- Activate billing or checkout.
- Add paid module activation, modules, or entitlement changes.
- Automate Shopify, Square, CSV, or manual import workflows.
- Implement jobs, projects, photos, quotes, invoices, or messaging.
- Build or scaffold a native/mobile app.
- Generalize the Modern Forestry mobile API.

## PR 7 Scope Completed

PR 7 added:
- Display-only module product metadata helpers in `TenantModuleCatalogService`.
- Tenant and Shopify embedded App Store card copy for category, lifecycle, setup effort, required integrations, pricing impact, entitlement requirements, and mobile relevance.
- Landlord commercial module table context for lifecycle, tenant visibility, setup effort, integrations, and pricing mode.
- Focused tests in `tests/Feature/Everbranch/ModuleAppStoreProductizationTest.php`.

PR 7 did not:
- Add modules.
- Install modules or change entitlements.
- Activate checkout, billing, or paid module purchasing.
- Implement custom module requests.
- Implement mobile modules, jobs/projects/photos/quotes/invoices, or messaging.
- Change tenant resolution or Shopify OAuth/install behavior.
- Generalize the Modern Forestry mobile API.

## PR 8 Scope Completed

PR 8 added:
- `custom_module_requests` as a tenant-scoped request/intake table.
- Tenant-facing request list, create, submit, and detail pages.
- Safe Module Store CTAs for "Request something custom" and per-card "Request customization".
- Landlord `/landlord/custom-module-requests` triage with filters and status/next-action/internal-note updates.
- Focused tests in `tests/Feature/Everbranch/CustomModuleRequestWorkflowTest.php`.

PR 8 did not:
- Add new product modules.
- Automatically convert requests into reusable modules.
- Install modules or change entitlements.
- Activate billing, checkout, paid module purchasing, quotes, or invoices.
- Implement jobs/projects/photos/messaging/mobile modules.
- Implement Square automation or CSV import execution.
- Change tenant resolution or Shopify OAuth/install behavior.
- Generalize the Modern Forestry mobile API.

## PR 9 Scope Completed

PR 9 added:
- A detailed Shopify readiness audit covering TOML identity, canonical URLs, OAuth redirects, app proxy expectations, required webhooks, privacy webhook blockers, scopes, embedded app surfaces, and Partner Dashboard manual checks.
- A focused local evidence test in `tests/Feature/Everbranch/ShopifyAppStoreReadinessTest.php`.
- Automated checks for canonical Shopify TOML URLs, runtime OAuth callback host, required webhook callback hosts, passive embedded App Store checkout copy, disabled billing flags, documented privacy webhook blockers, and Modern Forestry mobile separation.

PR 9 did not:
- Change Shopify OAuth or install/reinstall behavior.
- Change Shopify scopes.
- Implement Shopify privacy webhook handlers.
- Activate Shopify Billing, Stripe billing, checkout, or paid module purchasing.
- Install modules or change entitlements.
- Implement new Shopify features.
- Change tenant resolution.
- Generalize the Modern Forestry mobile API.

## PR 10 Scope Completed

PR 10 added:
- A billing/Stripe discovery audit in `docs/operations/everbranch-billing-readiness-audit.md`.
- A focused safety test in `tests/Feature/Everbranch/BillingStripeDiscoverySafetyTest.php`.
- A Shopify billing lane note in the Shopify readiness audit.
- Readiness evidence updates documenting that Shopify App Store merchants should use Shopify App Pricing/Billing later, while Stripe remains a separate direct/custom/non-Shopify lane.

PR 10 found:
- Laravel Cashier is not installed.
- Stripe SDK is not installed; Stripe calls use Laravel HTTP.
- Tenant-facing `/billing/checkout` and `/billing/portal` routes exist but are inert by default.
- Landlord guarded Stripe customer sync and subscription prep are enabled readiness actions.
- Landlord live subscription sync and lifecycle fulfillment are disabled by default.
- Stripe webhooks exist but require a configured webhook secret and lifecycle fulfillment remains gated.

PR 10 did not:
- Activate billing, checkout, Stripe Billing, Shopify Billing, or payment links.
- Create Stripe subscriptions or Shopify charges.
- Change module entitlements.
- Change Shopify OAuth/scopes or tenant resolution.
- Modify production payment behavior.

## PR 11 Scope Completed

PR 11 added:
- Shopify privacy webhook routes:
  - `/webhooks/shopify/customers/data-request`
  - `/webhooks/shopify/customers/redact`
  - `/webhooks/shopify/shop/redact`
- `ShopifyPrivacyWebhookController` for conservative privacy event handling.
- `ShopifyWebhookVerifier` for raw-body `X-Shopify-Hmac-Sha256` verification.
- `shopify_privacy_webhook_events` for durable evidence records with payload hashes, minimal summaries, status, action-required flags, and review fields.
- Shopify TOML compliance subscriptions for the three mandatory privacy topics.
- Focused tests in `tests/Feature/Everbranch/ShopifyPrivacyWebhookReadinessTest.php`.

PR 11 did not:
- Delete, anonymize, or redact customer/shop/tenant data destructively.
- Change Shopify OAuth/install behavior or scopes.
- Activate Shopify Billing, Stripe billing, checkout, or payment links.
- Change module entitlements or install modules.
- Change tenant resolution.
- Generalize the Modern Forestry mobile API.

## PR 12 Scope Completed

PR 12 added:
- `docs/operations/shopify-partner-dashboard-evidence-runbook.md` for exact Partner Dashboard, Shopify CLI deployment, dev-store install/reinstall, privacy webhook delivery, app proxy, scope review, privacy manual review, and evidence storage steps.
- `tests/Feature/Everbranch/ShopifyPartnerEvidenceRunbookTest.php` to keep the evidence runbook from drifting away from canonical URLs and safety posture.
- Readiness docs that mark external Shopify evidence as pending rather than completed.

PR 12 did not:
- Run live Shopify CLI deploy or change Partner Dashboard state.
- Change Shopify OAuth/install behavior or scopes.
- Activate Shopify Billing, Stripe billing, checkout, or payment links.
- Automate destructive privacy deletion/anonymization.
- Change module entitlements, tenant resolution, or Modern Forestry mobile behavior.

## PR 13 Scope Completed

PR 13 added:
- Commercial intent fields on `tenant_setup_statuses`.
- Tenant `/start` plan interest, billing lane interest, implementation help interest, and commercial notes controls.
- Landlord intake queue visibility for commercial intent and review-only commercial triage fields.
- Focused tests in `tests/Feature/Everbranch/PlanSelectionWithoutBillingTest.php`.

PR 13 did not:
- Activate billing, checkout, Shopify Billing, Stripe Billing, payment links, quotes, invoices, or subscriptions.
- Create Stripe checkout sessions or Shopify charges.
- Install modules or change module entitlements.
- Change tenant resolution, Shopify OAuth, Shopify scopes, or privacy webhook behavior.
- Implement custom module billing.

## PR 14 Scope Completed

PR 14 added:
- `/landlord/commercial-intent` as a landlord-only operator summary for commercial intent and billing lane readiness.
- Billing lane decision labels:
  - `intent_only`
  - `needs_landlord_review`
  - `needs_billing_lane_decision`
  - `blocked_billing_disabled`
  - `blocked_shopify_evidence_pending`
  - `blocked_scope_or_branding_review`
  - `ready_for_manual_follow_up`
  - `not_ready`
- Read-only blockers for missing plan/lane, incomplete commercial review, disabled billing posture, Shopify Partner Dashboard/CLI evidence, Shopify scope/branding review, Stripe direct activation, manual invoice workflow, and custom module request review.
- Review-only landlord actions for commercial review status, next action, and commercial notes.
- Focused tests in `tests/Feature/Everbranch/LandlordCommercialIntentGateTest.php`.

PR 14 did not:
- Activate billing, checkout, Shopify Billing, Stripe Billing, payment links, quotes, invoices, or subscriptions.
- Create Stripe checkout sessions or Shopify charges.
- Install modules or change module entitlements.
- Change tenant resolution, Shopify OAuth, Shopify scopes, or privacy webhook behavior.
- Implement custom module billing.

## PR 15 Scope Completed

PR 15 added:
- `docs/operations/evidence/shopify/2026-05-21/README.md` as a dated Shopify evidence packet.
- `docs/operations/shopify-scope-branding-decision-record.md` documenting current TOML scopes, runtime scope needs, broad/unproven scopes, and app name/handle options.
- `tests/Feature/Everbranch/ShopifyExternalEvidenceReadinessTest.php`.

PR 15 observed:
- Shopify CLI is installed locally at `/opt/homebrew/bin/shopify`.
- `shopify version` returns `3.92.1`.
- `shopify app deploy --help` and `shopify app webhook trigger --help` confirm command shapes.

PR 15 did not:
- Run `shopify app deploy` or create/release a Shopify app version.
- Trigger live privacy webhooks.
- Capture Partner Dashboard screenshots.
- Capture dev-store install/reinstall/app proxy evidence.
- Change Shopify OAuth behavior, scopes, app name, or handle.
- Activate Shopify Billing, Stripe billing, checkout, charges, or subscriptions.
- Automate destructive privacy deletion/redaction.

## PR 16 Scope Completed

PR 16 added:
- `/landlord/readiness` as a landlord-only self-service readiness dashboard.
- `EverbranchSelfServiceReadinessService` for simple structured readiness sections.
- Dashboard sections for tenant onboarding, intake queue, Module App Store, custom module requests, commercial intent, billing, Shopify app, privacy webhooks, Shopify external evidence, mobile, and launch readiness summary.
- Focused tests in `tests/Feature/Everbranch/SelfServiceReadinessDashboardTest.php`.

PR 16 did not:
- Activate billing, checkout, Shopify Billing, Stripe billing, charges, subscriptions, quotes, or invoices.
- Change Shopify OAuth, scopes, app name, handle, or privacy webhook behavior.
- Install modules or change module entitlements.
- Implement a generic Everbranch mobile app/API.
- Complete external Shopify evidence or approve public launch.

## PR 18 Scope Completed

PR 18 added:
- Evidence files under `docs/operations/evidence/shopify/2026-05-21/` for CLI, Partner Dashboard, dev-store install, app proxy, privacy webhook delivery, scope review, and summary status.
- Read-only Shopify CLI evidence confirming `Modern Forestry Backstage`, client ID `197d01d6597c938c96b3b35fae6a087c`, dev store `modernforestry.myshopify.com`, extension components, and broad TOML scopes.
- Partial live app proxy evidence showing unsigned direct canonical requests are rejected and storefront `/apps/forestry/health` returns healthy app-proxy JSON through the primary storefront domain.
- Initial scope review evidence without changing scopes.

PR 18 did not:
- Run `shopify app deploy`, create a version, release a version, or trigger privacy webhooks.
- Capture Partner Dashboard screenshots or perform dev-store install/reinstall.
- Change Shopify OAuth, scopes, app name, handle, or privacy webhook behavior.
- Activate billing, checkout, charges, subscriptions, modules, or entitlements.

## PR 19 Scope Completed

PR 19 added:
- `docs/operations/evidence/shopify/2026-05-21/screenshot-manifest.md` with screenshot slots `01-partner-app-overview.png` through `12-scope-review-notes.png`.
- `docs/operations/evidence/shopify/2026-05-21/operator-checklist.md` with step-by-step operator instructions for identity confirmation, Partner Dashboard screenshots, dev-store evidence, app proxy evidence, privacy webhook planning, and deploy/release approval.
- Evidence doc references so pending Partner Dashboard, install/reinstall, privacy webhook, and scope evidence remain visible and not falsely complete.

PR 19 did not:
- Run `shopify app deploy`, `shopify app release`, `shopify app webhook trigger`, or `shopify app dev`.
- Change Shopify OAuth, scopes, app name, handle, billing, modules, entitlements, tenant resolution, or privacy deletion behavior.

## Known External Blockers

- Grovebud Cloudflare/TLS currently fails externally with 520/525 behavior. Treat this as an edge/DNS launch blocker, not a Laravel behavior change for PR 1.
- Shopify Partner Dashboard settings must be manually verified and captured as evidence.
- PR 18/19 evidence packet exists at `docs/operations/evidence/shopify/2026-05-21/README.md`; it now contains partial CLI/app-proxy evidence and screenshot/operator checklist prep, but still marks Partner Dashboard, deploy/release, install/reinstall, and live privacy webhook delivery evidence as pending.
- Shopify App Store privacy webhook local coverage is implemented and tested; PR 12 documents Partner Dashboard/CLI deployment evidence steps, but the external evidence and manual privacy review/deletion policy remain required before review.
- Shopify TOML scopes appear broader than runtime defaults and must be reduced or justified before review; PR 15 decision record is pending.
- Current Shopify evidence should be captured against `Modern Forestry Backstage` and `modernforestry.myshopify.com`; public Everbranch app branding remains a future release/submission decision.
- Stripe direct billing must remain disabled until a later lane-specific activation PR, and must not be used for Shopify App Store merchant app charges unless Shopify explicitly approves that distribution/billing path.
- Direct self-service checkout remains blocked until readiness evidence passes.
- Plan selection is currently intent capture only and must not be confused with active paid plan selection.
- The commercial intent gate is decision support only. Billing activation still requires a future explicit PR plus Shopify/Stripe/manual lane evidence.
- The self-service readiness dashboard is status/control visibility only. It does not turn readiness into launch approval.
