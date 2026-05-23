# Everbranch Readiness Evidence Index

Status: test access and seed surface evidence update after access surface separation.

## Mission

Make readiness measurable with repeatable tests and manual evidence.

## Existing Evidence Sources

- Domain cutover runbooks:
  - `docs/operations/domain-cutover-everbranch-runbook.md`
  - `docs/operations/domain-cutover-everbranch-smoke-checklist.md`
  - `docs/operations/domain-cutover-everbranch-rollback.md`
- Auth tenant readiness:
  - `docs/operations/auth-tenant-ship-readiness.md`
- Staging commercial UAT:
  - `docs/operations/staging-commercial-uat-runbook.md`
  - `docs/operations/staging-commercial-uat-evidence-template.md`
  - `docs/operations/staging-commercial-uat-blocked-run-2026-03-28.md`
- Billing gates:
  - `docs/operations/pre-billing-readiness-gate.md`
  - `docs/operations/billing-activation-checklist.md`
- UI system:
  - `docs/ui/UI_SYSTEM.md`
  - `docs/ui/UI_CHANGELOG.md`

## PR 1 Automated Gate

New test file:
- `tests/Feature/Everbranch/ReadinessGateTest.php`

It covers:
- Canonical Everbranch host defaults.
- Legacy runtime host rejection.
- Shopify OAuth canonical callback host.
- Billing lifecycle flags remain disabled.
- Guarded Stripe defaults remain narrow.
- App Store hides unsafe/internal/roadmap modules.
- Landlord routes are landlord-only.
- Current mobile catalog remains explicitly Modern Forestry scoped.

## PR 25 Brand System and Language Sweep Evidence

Test file:
- `tests/Feature/Everbranch/BrandSystemLanguageSweepTest.php`

It covers:
- Everbranch/Evergrove product labels, brand asset paths, brand tokens, and display-language labels resolving from `config/everbranch.php`.
- Public access request copy using “preferred workspace address” rather than tenant-slug language.
- Tenant Module Store copy using workspace feature/access/setup language instead of entitlement-heavy product internals.
- Shopify embedded App Store copy using safe Everbranch/access language while `shopify.app.toml` still preserves the current Partner/dev app identity: `Modern Forestry Backstage` / `modernforestrybackstage`.
- PR 25 remaining a visual/language pass only, with no billing, checkout, module install, entitlement, Shopify deploy/release/dev, OAuth/scope, import, mobile API, or privacy-deletion activation.

## PR 22-24 Tenant Blueprint Evidence

Test files:
- `tests/Feature/Everbranch/LandlordTenantBlueprintFoundationTest.php`
- `tests/Feature/Everbranch/LandlordTenantBlueprintReviewFlowTest.php`

They cover:
- Landlord-only tenant blueprint creation for Shopify and non-Shopify tenants.
- Config-backed business templates, operating modes, data-source preferences, labels, starter recommendations, setup notes, and tenant-facing next actions.
- Work-management intent fields for project/work tracking, tasks, assignments, communication, photo/file uploads, and mobile field capture as requested/planned only.
- Landlord-only blueprint edit/review routes for existing tenants.
- Blueprint review statuses: `unreviewed`, `needs_follow_up`, `reviewed`, and `archived`.
- Reviewed metadata being set when saved as reviewed and cleared when moved away from reviewed.
- Tenant detail showing review status, reviewed-by/at context, internal notes, internal next action, and edit link.
- Tenant `/start` reflecting tenant-facing blueprint updates while hiding landlord-only internal notes.
- Modern Forestry, demo, and sandbox account lanes remaining stable.
- Blueprint create/edit flows not activating billing, checkout, subscriptions, Shopify Billing, Stripe checkout, Shopify OAuth, module installs, entitlements, imports, uploads, messaging, notifications, mobile APIs, or privacy deletion.

## PR 3-5 Intake Evidence

Test files:
- `tests/Feature/Everbranch/ClientIntakeSetupStatusTest.php`
- `tests/Feature/Everbranch/AccessRequestProvisioningBridgeTest.php`
- `tests/Feature/Everbranch/LandlordIntakeQueueTest.php`

They cover:
- Tenant setup status page access for authorized tenant users.
- Cross-tenant setup page denial.
- Landlord setup status review visibility and minimal review updates.
- Import path options: Shopify, Square, CSV, manual, other, undecided.
- Mobile interest options as intent only: none, Android, iOS, both, undecided.
- Access request approval creating/updating tenant setup status.
- Access request metadata promotion for import path, mobile interest, and safe module interests.
- Safe defaults when access request metadata is missing.
- Idempotent provisioning bridge behavior with no duplicate setup status rows.
- Preservation of existing tenant/operator setup edits.
- Shopify status remaining derived from `shopify_stores`.
- Landlord intake queue access control.
- Landlord intake queue filters for review, Shopify not connected, Square, CSV, manual, undecided import path, mobile interest, and reviewed statuses.
- Review-only operator actions on the intake queue.
- Billing/checkout flags remaining disabled.

## PR 7 Module App Store Evidence

Test file:
- `tests/Feature/Everbranch/ModuleAppStoreProductizationTest.php`

It covers:
- Tenant module store payload exposes product-grade display metadata for safe visible modules.
- Tenant App Store hides draft, internal, unsafe, and deprecated modules.
- Tenant Module Store renders setup effort, pricing impact, entitlement requirement, and mobile relevance as guidance.
- Module interests remain separate from installed or entitled modules.
- Shopify embedded App Store renders the same safe metadata and keeps billing language passive.
- Landlord commercial module table shows internal/hidden/beta/tenant-visible context read-only.
- Pricing impact labels do not activate checkout.
- Mobile relevance labels do not imply active generic Everbranch mobile APIs.

## PR 8 Custom Module Request Evidence

Test file:
- `tests/Feature/Everbranch/CustomModuleRequestWorkflowTest.php`

It covers:
- Tenant users can submit custom module requests.
- Tenant users can list/view only their tenant's requests.
- Cross-tenant request access is hidden/denied.
- Landlord/admin users can view all requests.
- Non-landlord users cannot access landlord triage.
- Landlord/admin users can update status, next action, and landlord notes.
- Module Store can launch the request form with a safe related module key.
- Unsafe/internal related module keys are rejected for tenant-facing submissions.
- Request submission does not install, enable, or entitle modules.
- Request submission does not activate billing or checkout.
- Mobile relevance is planning metadata only.
- Terminal statuses such as `converted_to_reusable_module` are labels only.

## PR 9 Shopify App Store Readiness Evidence

Test file:
- `tests/Feature/Everbranch/ShopifyAppStoreReadinessTest.php`

It covers:
- `shopify.app.toml` uses canonical `app.theeverbranch.com` App URL, OAuth callback URLs, and app proxy URL.
- Runtime Shopify OAuth emits canonical Everbranch callback hosts.
- Required webhook subscription callbacks resolve to the canonical landlord host.
- Embedded Shopify App Store copy keeps checkout/pricing language passive.
- Billing lifecycle flags remain disabled.
- Mandatory privacy webhook topics are verified after PR 11 as local routes, TOML compliance subscriptions, and conservative manual-review handlers.
- Modern Forestry mobile catalog routes remain separate from generic Shopify App Store readiness.

## PR 11 Shopify Privacy Webhook Evidence

Test file:
- `tests/Feature/Everbranch/ShopifyPrivacyWebhookReadinessTest.php`

It covers:
- Canonical privacy webhook routes exist for `customers/data_request`, `customers/redact`, and `shop/redact`.
- `shopify.app.toml` includes app-specific `compliance_topics` subscriptions using canonical Everbranch endpoint URLs.
- Valid HMAC requests are accepted and recorded.
- Invalid or missing HMAC requests are rejected and not recorded.
- Unexpected topics and invalid payloads are rejected and not recorded.
- Evidence records store payload hash and minimal summary only.
- Customer email/phone values are hashed rather than stored raw in the summary.
- Events default to `manual_review_required` and `action_required`.
- No destructive customer/shop/tenant deletion or redaction is performed.

## PR 12 Shopify Partner Dashboard / CLI Evidence Runbook

Runbook:
- `docs/operations/shopify-partner-dashboard-evidence-runbook.md`

Test file:
- `tests/Feature/Everbranch/ShopifyPartnerEvidenceRunbookTest.php`

It covers:
- Runbook includes canonical app URL, redirect URLs, app proxy URL, and privacy webhook endpoints.
- Runbook includes Shopify CLI deployment and webhook trigger command families.
- Runbook includes Partner Dashboard manual checklist.
- Runbook includes dev-store install/reinstall and app proxy evidence requirements.
- Runbook includes privacy manual review instructions for `shopify_privacy_webhook_events`.
- Runbook states billing is disabled/not active for now.
- Runbook states Partner Dashboard and Shopify CLI deployment evidence remains pending until manually verified.
- Runbook includes scope review and evidence storage conventions.

## PR 10 Billing / Stripe Discovery Safety Evidence

Test file:
- `tests/Feature/Everbranch/BillingStripeDiscoverySafetyTest.php`

It covers:
- Billing lifecycle defaults remain disabled.
- Guarded Stripe defaults remain narrow.
- Tenant hosted Stripe checkout route is inert by default and does not call Stripe.
- Tenant setup, Module Store, and custom module request surfaces do not expose active checkout controls.
- Stripe webhook route requires webhook secret and does not mutate entitlements when missing.
- Landlord Stripe actions are landlord-host and operator gated.
- Custom module requests remain billing-neutral.
- Shopify embedded App Store continues to state checkout is not active.

## PR 13 Plan Selection Without Billing Evidence

Test file:
- `tests/Feature/Everbranch/PlanSelectionWithoutBillingTest.php`

It covers:
- Tenant users can select/update plan interest.
- Tenant users can select billing lane interest or leave it undecided.
- Tenant users see copy that checkout, billing, quotes, invoices, subscriptions, and entitlements are not activated.
- Plan selection does not call Stripe/Shopify HTTP services.
- Plan selection does not create billing fulfillment rows.
- Plan selection does not change module entitlements or the current access profile plan.
- Landlord/admin users can view commercial intent in the intake queue.
- Landlord/admin users can update commercial review status and next action.
- Non-landlord users cannot access landlord commercial intent triage.
- Cross-tenant commercial intent updates are denied.
- Existing billing disabled guardrails remain false.

Billing discovery doc:
- `docs/operations/everbranch-billing-readiness-audit.md`

It documents:
- Stripe/Cashier dependency posture.
- Tenant-facing versus landlord-only Stripe surfaces.
- Whether each surface can charge money today.
- Shopify App Store billing versus Stripe direct billing lane recommendations.
- Remaining blockers before billing activation.

## PR 14 Landlord Commercial Intent Gate Evidence

Test file:
- `tests/Feature/Everbranch/LandlordCommercialIntentGateTest.php`

It covers:
- Landlord/admin users can view `/landlord/commercial-intent`.
- Non-landlord users cannot view the commercial intent gate.
- Tenant commercial intent is grouped/shown by plan interest and billing lane interest.
- Shopify App Store lane rows show pending Partner Dashboard/CLI/dev-store evidence, scope review, branding, and Shopify Billing/App Pricing blockers.
- Stripe direct lane rows show billing-disabled and future activation blockers without exposing tenant checkout.
- Manual invoice lane rows remain manual follow-up only.
- Landlord/admin users can update commercial review status, next action, and commercial notes.
- The gate has no payment, subscription, invoice, module install, or entitlement controls.
- Review updates do not call Stripe/Shopify services, create billing fulfillment rows, or change module entitlements.

## PR 15 Shopify External Evidence And Decision Record

Evidence packet:
- `docs/operations/evidence/shopify/2026-05-21/README.md`

Decision record:
- `docs/operations/shopify-scope-branding-decision-record.md`

Test file:
- `tests/Feature/Everbranch/ShopifyExternalEvidenceReadinessTest.php`

It covers:
- The dated evidence packet exists.
- The evidence packet marks Partner Dashboard, Shopify CLI deploy/release, dev-store install/reinstall, app proxy, and live privacy webhook delivery evidence as pending unless captured.
- The scope/branding decision record exists.
- The decision record includes the current TOML app name and handle.
- The decision record includes current TOML scopes and identifies broad/unproven scopes.
- The Shopify readiness audit links to the evidence packet and decision record.
- Docs do not falsely mark external Partner Dashboard/dev-store evidence complete.
- Billing remains disabled.

PR 17 identity confirmation update:
- Shopify evidence should be captured against `Modern Forestry Backstage`.
- Current handle remains `modernforestrybackstage`.
- Current dev store remains `modernforestry.myshopify.com`.
- Everbranch public Shopify App Store branding remains pending.

PR 18 external evidence capture update:
- Evidence packet now includes `evidence-summary.md`, `cli-evidence.md`, `partner-dashboard-evidence.md`, `dev-store-install-evidence.md`, `app-proxy-evidence.md`, `privacy-webhook-delivery-evidence.md`, and `scope-review-evidence.md`.
- Captured: read-only Shopify CLI app-info evidence for `Modern Forestry Backstage`, handle/client ID, dev store, extension components, and broad TOML scopes.
- Captured: partial live app proxy health evidence. Direct unsigned canonical route rejects with missing signature headers; storefront `/apps/forestry/health` on the primary domain returns healthy app-proxy JSON.
- Pending: Partner Dashboard screenshots, Shopify CLI deploy/release output, dev-store install/reinstall, embedded app open screenshots, live privacy webhook delivery rows, final scope decision, and public Everbranch branding decision.
- No Shopify deploy/release, webhook trigger, scope change, app rename, billing, checkout, module install, or entitlement change occurred.

PR 19 screenshot/operator checklist update:
- `docs/operations/evidence/shopify/2026-05-21/screenshot-manifest.md` defines screenshot slots `01-partner-app-overview.png` through `12-scope-review-notes.png`.
- `docs/operations/evidence/shopify/2026-05-21/operator-checklist.md` defines the operator sequence for identity confirmation, Partner Dashboard screenshots, dev-store evidence, app proxy evidence, privacy webhook delivery planning, and deploy/release approval.
- Deploy/release remains blocked until the operator explicitly approves the exact command and expected effect.
- Partner Dashboard evidence remains pending until screenshots or written verification are attached.

## PR 16 Self-Service Readiness Dashboard Evidence

Dashboard:
- `/landlord/readiness`

Service:
- `app/Services/Readiness/EverbranchSelfServiceReadinessService.php`

Test file:
- `tests/Feature/Everbranch/SelfServiceReadinessDashboardTest.php`

It covers:
- Landlord/admin users can view the readiness dashboard.
- Non-landlord users cannot view it.
- Dashboard shows onboarding, intake queue, Module App Store, custom requests, commercial intent, billing, Shopify app, privacy webhook, Shopify external evidence, mobile, and launch readiness sections.
- Billing is shown as disabled/blocked, not active.
- Shopify external evidence is shown as `pending_external`.
- Privacy webhooks show local readiness while live delivery evidence remains pending.
- Mobile is shown as `not_started`, not an active generic Everbranch mobile app.
- Dashboard has no checkout/payment/subscription/module activation controls.
- The readiness service returns expected status keys and conservative launch answer.

## Access Surface And Test Access Evidence

Test files:
- `tests/Feature/Everbranch/AccessSurfaceSeparationTest.php`
- `tests/Feature/Everbranch/AccessSurfaceSeedAndTestAccessTest.php`

They cover:
- Platform admins and legacy landlord admins on the landlord host route to `/landlord`.
- Completed tenant users route to the tenant dashboard.
- Explicitly incomplete tenant users route to `/start`.
- Demo and sandbox tenant users route to the tenant dashboard with visible lane banners.
- Tenant admins cannot access landlord tenant management routes simply because their tenant role is admin-like.
- `php artisan everbranch:seed-access-surfaces --dry-run` does not write records.
- The seed command is idempotent for Modern Forestry, Everbranch demo, sandbox, and platform admin records.
- The seed command refuses production unless explicitly forced.
- Landlord operators can view the tenant Test Access panel.
- The Test Access panel is read-only and does not activate direct impersonation.
- Modern Forestry remains a normal production/alpha tenant lane, not demo or sandbox.

## Tenant Blueprint Foundation Evidence

Test file:
- `tests/Feature/Everbranch/LandlordTenantBlueprintFoundationTest.php`

It covers:
- Landlord operators can open `/landlord/tenants/create` and save non-Shopify tenant blueprints.
- Tenant admins cannot access landlord tenant blueprint creation.
- Shopify blueprints remain supported without changing OAuth or checkout behavior.
- Direct/manual/CSV tenant blueprints display non-Shopify setup guidance on `/start`.
- Business templates change labels and starter module recommendations without creating industry-specific route systems.
- Saving a blueprint does not create tenant module states, tenant module entitlements, billing fulfillment rows, checkout sessions, Shopify charges, Stripe subscriptions, Shopify OAuth, Square automation, or CSV import execution.
- Modern Forestry remains the flagship tenant lane while demo/sandbox lanes stay visibly marked.

## Work Management Blueprint Intent Evidence

Test file:
- `tests/Feature/Everbranch/LandlordTenantBlueprintFoundationTest.php`

It covers:
- Landlord operators can save work-management intent fields on tenant blueprint creation.
- Template defaults include project/task/assignee/communication/upload labels for landscaping, electrician, law, maker, apparel, generic, and custom templates.
- Tenant detail shows work-management blueprint intent, labels, notes, and recommended future modules.
- Tenant `/start` shows project/task/photo-upload/mobile-capture intent as requested/planned/not active yet.
- Work-management intent does not create projects, tasks, assignments, comments, uploads, notifications, mobile APIs, module installs, entitlements, billing, checkout, subscriptions, Shopify OAuth, Square connection, or imports.

## Blueprint-Driven Module Catalog Alignment Evidence

Test file:
- `tests/Feature/Everbranch/BlueprintDrivenModuleCatalogAlignmentTest.php`

It covers:
- Landscaping, electrician, law, maker, and Shopify blueprints produce template-aware module recommendation families.
- Shopify/ecommerce blueprints preserve Shopify-aware recommendations while manual/direct/CSV tenants avoid Shopify-only assumptions.
- Work-management intent fields drive requested/planned/not-active-yet display states without activating feature behavior.
- Tenant Module Store, landlord tenant detail, and tenant `/start` show blueprint module guidance consistently.
- Demo, sandbox, and Modern Forestry contexts remain distinct.
- Recommendation states do not create projects, tasks, assignments, comments, uploads, notifications, mobile APIs, module installs, entitlements, billing, checkout, subscriptions, Shopify OAuth, Square connection, or imports.

## Requested Test Commands

- `./vendor/bin/pest tests/Feature/Everbranch/BlueprintDrivenModuleCatalogAlignmentTest.php`
- `./vendor/bin/pest tests/Feature/Everbranch/LandlordTenantBlueprintFoundationTest.php`
- `./vendor/bin/pest tests/Feature/Everbranch/ReadinessGateTest.php`
- `./vendor/bin/pest tests/Feature/Everbranch/BrandNavigationCoherenceTest.php tests/Feature/Everbranch/ClientIntakeSetupStatusTest.php tests/Feature/Everbranch/AccessRequestProvisioningBridgeTest.php tests/Feature/Everbranch/LandlordIntakeQueueTest.php tests/Feature/Everbranch/ModuleAppStoreProductizationTest.php tests/Feature/Everbranch/CustomModuleRequestWorkflowTest.php tests/Feature/Everbranch/ShopifyAppStoreReadinessTest.php`
- `./vendor/bin/pest tests/Feature/Everbranch/BillingStripeDiscoverySafetyTest.php`
- `./vendor/bin/pest tests/Feature/Everbranch/ShopifyPrivacyWebhookReadinessTest.php`
- `./vendor/bin/pest tests/Feature/Everbranch/ShopifyPartnerEvidenceRunbookTest.php`
- `./vendor/bin/pest tests/Feature/Everbranch/PlanSelectionWithoutBillingTest.php`
- `./vendor/bin/pest tests/Feature/Everbranch/LandlordCommercialIntentGateTest.php`
- `./vendor/bin/pest tests/Feature/Everbranch/ShopifyExternalEvidenceReadinessTest.php`
- `./vendor/bin/pest tests/Feature/Everbranch/SelfServiceReadinessDashboardTest.php`
- `./vendor/bin/pest tests/Feature/Tenancy/DomainMigrationConfigTest.php tests/Feature/Tenancy/LandlordHostFoundationTest.php tests/Feature/ShopifyAuthDomainMigrationTest.php tests/Feature/Tenancy/ModuleCatalogConfigConsistencyTest.php tests/Feature/ShopifyCommercializationPagesTest.php tests/Feature/ShopifyWebhookSubscriptionEnforcementTest.php`
- `./vendor/bin/pest tests/Feature/Billing/HostedBillingHandoffTest.php tests/Feature/Billing/StripeWebhookConfirmationTest.php tests/Feature/Billing/StripeCommercialFulfillmentTest.php tests/Feature/Tenancy/LandlordCommercialConfigurationTest.php`
- `./vendor/bin/pest tests/Feature/Mobile/ModernForestryMobileProductCatalogTest.php`

## Manual Evidence Still Required

- Shopify Partner Dashboard URL, redirect, proxy, scope, privacy webhook, install/reinstall, and app branding screenshots.
- Shopify Partner Dashboard/CLI deployment evidence for privacy webhook compliance subscriptions and test deliveries for `customers/data_request`, `customers/redact`, and `shop/redact`.
- Execution artifacts from `docs/operations/shopify-partner-dashboard-evidence-runbook.md`, stored under `docs/operations/evidence/shopify/YYYY-MM-DD/`.
- Manual privacy review runbook and approved deletion/anonymization policy before destructive automation.
- Shopify TOML/runtime/Partner Dashboard scope alignment decision.
- Scope/branding decision remains pending in `docs/operations/shopify-scope-branding-decision-record.md`.
- Billing lane decision signoff: Shopify App Pricing/Billing for App Store merchants, Stripe only for approved direct/custom/non-Shopify lanes.
- Browser evidence that tenant-facing Shopify/App Store flows do not expose Stripe checkout.
- DNS/TLS/edge redirect verification for launch domains.
- Grovebud Cloudflare/TLS blocker resolution or launch decision.
- Tenant boundary exploratory test for highest-risk mutations.
- Browser screenshots for public, auth, landlord, tenant, Shopify embedded, and setup pages.
- Import readiness drill for Shopify, Square, CSV, and manual paths.
- Billing readiness signoff before checkout is exposed.
- Commercial lane decision artifact before plan intent is converted into active paid plan selection.
- Commercial intent gate review evidence before any lane is converted into active paid plan selection.
- Mobile readiness decision for Android/iOS architecture before generic API work.

## Pass Criteria

- PR 1 test suite passes.
- Existing focused domain, Shopify, catalog, commercialization, and mobile suites pass.
- Manual blockers are documented rather than papered over.
- Agents update this evidence index after meaningful readiness work.

## Fail Criteria

- Test coverage loosens host, billing, module, landlord, or mobile guardrails.
- Manual evidence is claimed without artifacts.
- Readiness docs drift from code behavior.
