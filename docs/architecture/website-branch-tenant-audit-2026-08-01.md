# Website Branch tenant audit and delivery plan

**Status:** planning and safe UX refinement only. Managed Website remains
default-disabled. This document does not authorize domain purchases, DNS
changes, payment-flow changes, public-host changes, or a replacement of the
existing public-site runtime.

## Scope and evidence

This audit followed the tenant route/controller/service path, rendered the
tenant Branch catalog in feature tests, and reviewed the Website editor,
commerce, host, publish, and onboarding contracts. It did not use production
credentials or make a registrar, Stripe, or DNS call. Logged-in mobile/device
QA remains a release acceptance task.

Classification: `managed_website` is a shared, tenant-scoped, purchasable
add-on. It is not a Shopify feature and it must continue to use the dedicated
`website_*` commerce lane. Its canonical services are
`TenantModuleCatalogService`, `TenantModuleAccessResolver`,
`ManagedWebsiteService`, and `WebsiteCommerceService`.

## Current tenant journey

| Journey point | Current evidence | Tenant experience assessment |
| --- | --- | --- |
| Register and create workspace | Fortify registration, then `/workspace/create` and `FirstLoginWorkspaceProvisioner` create a tenant, explicit admin membership, direct/base plan, brand profile, and setup status. | Solid tenant isolation and a useful popup-capable first-login flow. Product/tool choices are intentionally interests, not silent installations. |
| Complete onboarding | `/start`, `/onboarding`, and tenant blueprint services collect business type and goals. | Good foundation, but Website-specific questions are not collected when Website is added. |
| Discover/add a Branch | `/marketing/modules` is tenant- and role-gated; `TenantModuleCatalogService` supplies only safe, visible catalog entries and actions are server-validated/audited. | Previously too dense. The revised directory adds visual cards, categories, instant filtering, transparent pricing, and a three-step focused decision dialog. |
| Create Website | Entitled rollout tenants can create one `tenant_sites` draft at `/website`. | Correctly fail-closed. The first screen still says “safe draft” and internal concepts before it asks customer-friendly setup questions. |
| Theme/pages/brand | Themes, structured blocks, immutable draft versions, page creation, SEO fields, and a full-screen editor exist. Workspace brand is reused. | Strong safe foundation. Theme selection is disconnected from business purpose and page navigation/brand settings are partly presentational in the editor. |
| Products and services | Native `website_products`, variants, inventory, carts, orders and fulfillment exist. A product may be quote-only. | Product data is real and isolated, but a service is still presented through a product-oriented route/model. Booking, duration, deposits, service area, staff, and intake are absent. |
| Payments and customer actions | Stripe Connect checkout has commercial, entitlement, allowlist, webhook, and tax gates; quote forms create tenant-scoped submissions only. | Correctly fail-closed. No customer should be told checkout is ready until every gate passes. External booking/quote CTAs are possible, but setup is not guided. |
| Preview/publish | Draft → immutable published page versions, cache invalidation, rollback, and desktop/mobile editor preview are present. | A strong safety baseline. The subdomain displayed in the UI needs a truthful, public preview URL and a launch checklist before publication. |
| Domains | Initial `subdomain` is stored on `tenant_sites`; public rendering resolves only approved published hosts. Custom domains are explicitly deferred. | No tenant custom-domain connection, verification, primary-domain, redirect, status, or troubleshooting workflow exists. |
| Post-launch management | Pages, themes, native catalog/customers/orders, and rollback are available behind Website access. | Navigation needs capability-aware Products, Services, Orders, Leads, Appointments, Content, Domains, and Settings destinations rather than one generic “Online store” workspace. |

## What is already solid

- Tenant-bound model queries and controller ownership checks guard pages,
  versions, Website products, and public forms. Public rendering requires a
  verified resolved host plus a published snapshot.
- Drafts and published versions are additive and immutable; rollback changes a
  pointer instead of destroying content.
- Managed Website is independently gated for commercial availability, editor
  allowlist, publishing, and public rendering. Existing Shopify/Modern
  Forestry surfaces are explicitly excluded.
- Native Website commerce is stored only in `website_*` tables. It does not
  reuse legacy Shopify orders, customers, checkout, rewards, or webhooks.
- The initial workspace flow gives a reusable business template/blueprint
  starting point without granting risky modules merely because a user chose an
  interest.

## Gaps and tenant-facing problems

1. **Activation is not Website onboarding.** Adding the Branch leads to a
   catalog action, not “Tell us about your business” or a setup checklist.
2. **The Website landing page is retail-first.** “Online store,” Products,
   Customers, and Orders appear even for a field-service or professional
   business; Services, quote requests, bookings, and appointments are absent.
3. **Terminology leaks implementation.** “Managed,” “native,” “immutable,”
   “rollout gate,” and “Stripe Connect/tax gates” belong in protected status
   details, not primary tenant copy.
4. **Some editor affordances are disconnected.** Page template chooser,
   navigation save, social image selection, lead inbox, and custom-domain
   controls include presentational/inert elements and should not appear active
   until routed to an implemented tenant-safe action.
5. **Domains are only a stored Everbranch subdomain.** No bring-your-own-domain
   service, DNS verification, TLS state, primary-domain selection, alternate
   redirect, removal, or failed-domain help exists.
6. **Service selling is incomplete.** Quote forms exist, but no first-class
   service attributes, booking/availability, deposits, or appointment records
   are attached to Website offerings.
7. **Checklist/readiness is scattered.** Commerce readiness is a blocker list;
   it is not the persistent, ordered “what to do next” checklist required for
   a nontechnical owner.
8. **Mobile and accessibility require live QA.** The current editor has
   responsive CSS and focus styles; Branch directory cards and the new dialog
   must be exercised on phone widths and with keyboard/screen reader before
   release.

## Target Website Branch experience

After an owner chooses **Add Website Branch**, launch a resumable wizard:

1. Tell us about your business.
2. Choose what visitors can do: buy, request a quote, book, pay a deposit,
   call, or contact.
3. Choose a starting design matched to that purpose.
4. Add products, services, or both.
5. Confirm business details and branding.
6. Configure the relevant payment, booking, quote, or contact action.
7. Preview desktop and mobile.
8. Use the included Everbranch address or connect a domain.
9. Publish.

Keep a persistent Website setup checklist in the tenant shell. It should show
one recommended next action, completion status, and blockers in plain language;
it should never describe hosting, SSL, database, routes, deployments, or
webhooks. The Website navigation should be capability-driven:

`Website · Products · Services · Orders · Leads · Appointments · Customers · Content · Marketing · Domains · Settings`

Only show a destination when that capability is selected or has data. “Website”
contains Design, Pages, Navigation, Brand, Homepage, Preview, SEO, Domain, and
Publish; it is not an unrestricted code/page builder.

## Recommended offering model

Preserve `website_products` and extract shared sellable behavior rather than
pretending a service is inventory:

- Add `website_offerings` as the common tenant/site-owned record: name,
  description, media references, category, visibility, SEO, price behavior,
  tax behavior, and primary visitor action.
- Attach `website_product_details` for SKU, variants, inventory, shipping or
  pickup. Migrate/add a compatibility adapter from current `website_products`
  only after a separate approved migration plan.
- Attach `website_service_details` for duration, service area, staff,
  availability source, on-site/remote delivery, quote-only/starting-at pricing,
  deposit rule, recurring cadence, and intake schema.
- Model quotes, appointment requests, bookings, and deposits as separate,
  tenant-scoped Website records with their own authorization and lifecycle;
  never convert a Website form into a marketing customer or legacy order.

This supports products, services, and hybrids while retaining dedicated
inventory behavior only where it belongs.

## Domain recommendation and lifecycle

Phase 4 should ship bring-your-own-domain first. On creation, always provide
`<tenant-slug>.theeverbranch.com`; it is the fallback/preview address, not a
claim that custom DNS is already complete. A custom-domain record needs tenant
ownership, hostname, verification method/token, requested/verified/active/
failed state, primary flag, redirect target, TLS state, provider reference, and
audited lifecycle events.

Lifecycle: normalize hostname → reject reserved/duplicate/unverified host →
show DNS record → poll/verify → provision certificate → mark active → select
primary → redirect alternates → surface a customer-friendly remediation state.
Removal must first select/retain a valid primary address. Tenant deletion must
detach host routing and preserve an audit record; it must never silently cancel
customer-owned registration. The runbook must cover rate limits, DNS ownership
disputes, pending validation, TLS failure, expiry, transfer lock/AuthInfo,
renewal failure, redemption, and support escalation.

### Registrar/reseller options for Phase 5

| Option | Fit | Important constraints | Recommendation |
| --- | --- | --- | --- |
| Cloudflare Registrar API (beta) | Strong technical fit for Everbranch DNS/TLS hosting and a narrow pilot. It supports search, real-time check, registration, registration state, auto-renew setting, and privacy state. | The current beta lacks API renewals, transfers, and contact updates; it also has extension and premium-registration limits, and requires a billing profile/default payment method on the platform account. | Do not use as the customer domain-reseller system yet. Revisit only after its lifecycle API covers transfers, renewals, contacts, and tenant-owned billing/registrant flow. |
| OpenSRS/Tucows reseller | Mature registrar-reseller shape; its domain API covers registrant contact, nameservers, transfer auth codes, locks, and privacy controls. | XML/HTTPS integration and reseller operational onboarding are heavier; obtain current wholesale, support, webhook, privacy, and transfer terms before commitment. | Best candidate for an RFP and controlled reseller pilot. |
| GoDaddy Reseller API | Modern REST API with availability, suggestions, quote-before-register, registration, DNS, contacts, renewal/lock, and transfer operations. | Commercial/reseller eligibility, pricing/margin, notification/webhook behavior, privacy/TLD coverage, and transfer support must be contractually verified. | Viable second RFP candidate, likely lower Laravel integration cost than XML. |
| ResellerClub/OrderBox | Purpose-built white-label reseller positioning with registrar-compliance handled by the provider. | Its API platform is being rebuilt; validate API maturity, lifecycle coverage, pricing, support, webhook and data-portability commitments. | Consider only after API and contractual due diligence. |

Everbranch should be a reseller/platform, not an ICANN-accredited registrar.
The registrar agreement must name the tenant/customer as registrant, offer the
customer accurate RDAP/registrant data and privacy choices, retain clear
receipt/renewal history, expose unlock/AuthInfo and a transfer-out path, and
prohibit Everbranch from trapping domains. ICANN’s transfer policy requires a
standardized transfer path, while escrow is an accredited-registrar obligation;
the chosen accredited registrar/reseller contract must make responsibility and
data portability explicit. Sources: [Cloudflare Registrar API](https://developers.cloudflare.com/registrar/registrar-api/), [GoDaddy Domains API](https://developer.godaddy.com/en/docs/api-users), [OpenSRS Domains API overview](https://domains.opensrs.guide/docs/overview), [ResellerClub platform API](https://www.resellerclub.com/platform-api), and [ICANN Transfer Policy](https://www.icann.org/en/contracted-parties/accredited-registrars/resources/domain-name-transfers).

## Phased implementation plan

### Phase 1 — Refine and make it discoverable

**Outcome:** tenants can find and understand Branches and Website without
changing access by accident.

- Systems: Branch catalog, navigation, Managed Website index/editor, setup
  status service.
- Database: none required for the catalog refinement.
- Backend: keep `TenantModuleCatalogService`/`TenantModuleAccessResolver` as
  authority; remove any inert control or make it explicitly unavailable.
- Tenant UI: category directory, instant search, clear price, focused three-step
  decision dialog, customer-facing Website language, truthful empty states.
- Public site: none.
- Security/tests: preserve role + tenant route middleware and audit actions;
  test search markup, catalog visibility, foreign-tenant denial, and no action
  on browsing.
- Dependency/risk: local image assets only; no provider or billing change.
- Acceptance: a tenant can locate Website by category/search, see price/status,
  and understand the next step before submitting an add/request action.

### Phase 2 — Guided Website setup

**Outcome:** a new Website owner completes a resumable purpose-led setup.

- Systems: Managed Website service/controller, tenant setup status, catalog,
  blueprint/profile and app navigation.
- Database: additive `tenant_website_setup_profiles` and checklist/item state;
  preserve existing site/page/version records.
- Backend: server-validated answers/preset resolution; setup completion derived
  from site/brand/offerings/action/domain state, not only browser flags.
- Tenant UI: wizard, suitable templates, persistent checklist, desktop/mobile
  preview, nontechnical publishing summary.
- Public site: templates render only approved snapshot sections.
- Security/tests: authorization, setup isolation, preset idempotency, preview
  cannot publish, publishing gates still fail closed.
- Dependencies/risk: product-owner approval for copy/presets; no public runtime
  rewrite.
- Acceptance: all five business modes can choose a purpose, receive an editable
  preset, and resume setup without re-answering.

### Phase 3 — Products, services, and customer actions

**Outcome:** each business can sell a product, service, hybrid offer, quote,
booking, deposit, or contact request naturally.

- Systems: isolated Website commerce, forms/leads, scheduling capability.
- Database: additive offerings/service-details/quote/booking/deposit tables;
  tenant IDs and foreign keys on every record.
- Backend: dedicated visitor action resolver; Stripe Connect remains the only
  native payment lane after current gates; bookings and leads do not create
  legacy customers/orders.
- Tenant UI: separate Products/Services, structured forms, capacity/availability
  setup when selected, Orders/Leads/Appointments only when relevant.
- Public site: approved sections read published offers and route to isolated
  action endpoints.
- Security/tests: cross-tenant ID denial; price/tax source of truth; webhook
  idempotency; booking capacity; no legacy/Shopify writes.
- Dependency/risk: payment/deposit and booking provider decisions need product,
  legal, and compliance approval.
- Acceptance: a service business can request a quote without inventory; a hybrid
  business can sell both models without duplicated data.

### Phase 4 — Custom domains

**Outcome:** owners can connect an existing domain with clear status and safe
fallback to the Everbranch address.

- Systems: host resolver, TLS/DNS integration, public renderer, domain status
  UI, support runbook.
- Database: additive `tenant_site_domains`, verification attempts, TLS/status
  events, redirect/primary-domain fields.
- Backend: canonical hostname normalization, verification/poll jobs, domain
  authorization, cache-safe host activation/deactivation.
- Tenant UI: Connect your domain flow, plain DNS instructions, status,
  troubleshooting, primary/redirect/remove controls.
- Public site: verified primary/alternate host routing only.
- Security/tests: host confusion, cross-tenant custom-host rejection, stale
  verification, certificate failure, redirect loops, unknown host fail-closed.
- Dependency/risk: approved DNS/TLS provider and operational monitoring.
- Acceptance: connection/recovery works without exposing a tenant to SSL or
  infrastructure jargon.

### Phase 5 — Domain purchasing

**Outcome:** customers can search, register, renew, transfer, and leave with
their domain ownership intact.

- Systems: selected reseller API, Stripe/billing policy, domain lifecycle
  workers, support/notification operations.
- Database: registrar account/reference, encrypted registrant contacts,
  price quote/receipt, renewal/payment state, transfer and dispute records.
- Backend: quote/check immediately before purchase, explicit customer consent,
  idempotent provisioning, lifecycle polling/webhooks, renewal/failed-payment
  notifications, transfer-in/out and AuthInfo flows.
- Tenant UI: domain search, price/renewal disclosure, registrant contact form,
  ownership receipt, privacy choice, renewal controls, transfer-out help.
- Public site: activate only after registration/DNS/TLS succeeds.
- Security/tests: encrypted PII, least-privilege API credentials, replay-safe
  registration, fake provider status tests, audit trail, deletion portability.
- Dependency/risk: contract, margin, taxes, merchant-of-record, abuse, support,
  and legal approval are mandatory before build.
- Acceptance: the customer is the registrant, can transfer out, and can recover
  from failed provision/renewal without Everbranch data loss or misleading UI.

### Phase 6 — Platform maturity

**Outcome:** mature themes, analytics, marketing, integrations, and operational
connections can be added without fragmenting Website ownership.

- Systems: theme registry, analytics/SEO, marketing, approved integrations.
- Database/backend/UI/public site: add only tenant-scoped, capability-gated
  records and snapshot-safe rendering contracts.
- Security/tests: consent, performance budgets, abuse/rate limits, isolation,
  rollback, and provider failure behavior accompany every addition.
- Acceptance: extensions never bypass Website snapshots, entitlements, tenant
  authorization, or the isolated commerce boundary.

## Decisions requiring product-owner approval

1. Which business presets and visitor actions are in the first Website pilot.
2. Whether appointments are native, an approved external booking link, or a
   selected provider integration.
3. Stripe Connect account ownership, platform-fee, tax, refund, dispute, and
   merchant-of-record policy.
4. DNS/TLS provider and custom-domain operational ownership.
5. Registrar-reseller partner, commercial terms, margin, compliance/support
   responsibilities, and customer ownership language.
6. Whether native Website shoppers should ever be linked to a separate tenant
   customer system (current safe answer: no automatic merge).
