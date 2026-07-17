# Everbranch Billing / Stripe Readiness Audit

## 2026-07-17 Direct Invoice Production-Ready Pending Live Gates

- Landlord-created direct Stripe invoices have moved from implementation-ready to **production-ready pending live gates**.
- Sandbox evidence exists for one internal card-paid hosted invoice: Stripe invoice `GHPJWFCX-0001`, amount `$299.00`, `livemode=false`, `status=paid`, paid on July 17, 2026 with Stripe events `invoice.paid` and `invoice.payment_succeeded`.
- Evidence file: `docs/operations/evidence/2026-07-17/direct-stripe-invoice-sandbox-smoke.md`.
- Focused regression tests passed: `tests/Feature/Billing/DirectStripeInvoiceTest.php`, `tests/Feature/Agreements/AgreementStripePaymentsTest.php`, and `tests/Feature/ConfigDoctorTest.php` (`23 passed`, `163 assertions`).
- Live billing is still not enabled. The remaining launch gates are live Stripe keys in the production secret store, production webhook registration/signing, Relay payout verification, accountant tax determination, production mail configuration, first-tenant allowlisting, and final `config:doctor --env=production` pass on the server.
- Proposal Checkout, tenant self-serve billing, Shopify App Store billing, subscription entitlement fulfillment, and broad tenant rollout remain outside this status.

## 2026-07-16 Agreement and Dual-Lane Update

- Agreement-first authorization now exists for tenant-specific, à-la-carte pricing. Acceptance records an immutable exact version and normalized subscription authorization but does not activate checkout, subscriptions, billing, or entitlements.
- Front Yard Foods uses the explicitly approved `stripe_direct` client-services lane. This does not activate Shopify App Pricing or private Shopify plans. Agreement checkout remains disabled until credentials, webhook signing, tax decision, Relay payout verification, allowlisting, and test evidence are complete.
- Landlord direct Stripe invoices now exist for approved Everbranch/Evergrove one-time, milestone, and supplemental work. They remain disabled unless `EVERBRANCH_STRIPE_INVOICING_ENABLED=true` and the tenant is allowlisted. They exclude Shopify/third-party expenses, send Stripe hosted invoices, mirror provider receipt/tax/status evidence, and never mutate entitlements.
- Existing Shopify and hosted Stripe rails remain disabled options for a later separately approved billing decision. The current deliverable is agreement and implementation readiness, not provider architecture.
- `AgreementBillingActivationGuard` requires an accepted active agreement, exact accepted version, matching approved provider lane, provider-verified active subscription, and audited applied fulfillment. This guard must be connected to the final Shopify and direct Stripe activation work before either lane is enabled.
- `tenant_billing_receipts` is an idempotent tenant-bound provider receipt mirror. It rejects inconsistent subtotal/tax/total values, cross-tenant provider receipt reuse, and non-HTTPS receipt links. Everbranch does not calculate provider taxes.
- Provider selection, production reconciliation, tax configuration, refund/dunning SOPs, and provider receipt ingestion remain later external activation decisions and blockers.

Status: PR 14 landlord commercial intent gate.
Date: 2026-05-21.

## Mission

Discover every billing, Stripe, checkout, subscription, invoice, webhook, and payment-related surface before any Shopify Billing, Stripe Billing, module purchasing, or recurring payment activation work.

PR 10 is discovery, documentation, and safety tests only. It does not activate billing.

## Executive Summary

Stripe is present as first-party HTTP integration code, not as Laravel Cashier:

- `laravel/cashier` is not installed.
- `stripe/stripe-php` is not installed.
- Stripe calls are made with Laravel HTTP clients against `services.stripe.api_base`.
- Stripe env keys are referenced in `config/services.php` and `.env.example`.
- Tenant-facing hosted billing routes exist, but default config keeps them inert.
- Tenant-facing plan selection/interest now exists on `/start`, but it is explicitly commercial intent only.
- Landlord-only guarded Stripe customer sync and subscription prep are enabled by default as readiness actions.
- Landlord-only live subscription create/sync exists but is disabled by default.
- Stripe webhook ingestion exists and can update tenant billing mapping when a valid webhook secret/signature is configured.
- Stripe fulfillment can mutate tenant plans/add-ons only when `commercial.billing_readiness.lifecycle_mutations_enabled=true`; default is false.

Current local config probe, without printing secrets:

| Check | Result |
| --- | --- |
| Stripe secret present | No |
| Stripe secret appears live | No |
| Stripe webhook secret present | No |
| `checkout_active` | false |
| `lifecycle_mutations_enabled` | false |
| guarded customer sync | true |
| guarded subscription prep | true |
| guarded live subscription sync | false |
| Stripe API base | `https://api.stripe.com` |

Default answer: no tenant-facing Stripe flow can charge money today with the current checked-in defaults and current local config. However, the codebase contains live-capable Stripe paths if config flags and Stripe secrets are deliberately enabled later.

PR 13 adds plan and billing lane interest capture to `tenant_setup_statuses`. This does not create Stripe checkout sessions, Stripe subscriptions, Shopify charges/subscriptions, payment links, quotes, invoices, module installs, or entitlement changes.

PR 14 adds a landlord-only commercial intent summary and billing lane decision gate at `/landlord/commercial-intent`. It is operator decision support only. It groups plan/lane intent, surfaces blockers, and allows review-only status/next-action/notes updates. It does not create Stripe checkout sessions, Stripe subscriptions, Shopify charges/subscriptions, payment links, quotes, invoices, module installs, or entitlement changes.

## Billing Surface Inventory

| File/surface | Provider | Purpose | Current enabled/disabled status | Tenant-facing or landlord-only | Can charge money today? | Uses live env values? | Risk | Recommendation |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `composer.json` | Stripe/Cashier | PHP dependency inventory | No Cashier, no Stripe SDK | N/A | No | No | Low | Keep explicit: Stripe integration is HTTP-client based. |
| `package.json` | Shopify | Shopify CLI/front-end packages | Shopify packages present; no Stripe JS | N/A | No | No | Low | No billing change. |
| `.env.example` | Stripe | Documents guarded Stripe env vars and keys | Keys blank; guarded action flags documented | Operator config | No by itself | Yes when configured | Medium | Keep keys blank in examples; never print secret values. |
| `config/services.php` | Stripe | Reads `STRIPE_SECRET`, `STRIPE_KEY`, webhook secret, API base, timeout | Passive config | Shared service config | No by itself | Yes | Medium | Keep secret presence checks in tests/docs; no live calls in tests. |
| `config/commercial.php` | Stripe/manual | Commercial catalog, prices, billing flags, guarded action flags, Stripe lookup-key map | `checkout_active=false`, `lifecycle_mutations_enabled=false`, live sync false | Shared config | No by default | Guarded actions use env flags | High if loosened | Treat as billing source of truth; require tests before changing defaults. |
| `/billing/checkout` route | Stripe | Tenant hosted Checkout handoff | Route exists but `TenantBillingNextStepResolver` blocks while checkout/lifecycle flags are false | Tenant-facing authenticated + tenant-scoped | No by default; yes if flags + Stripe secret + valid billing interest | Yes | High | Keep disabled until provider decision and readiness gates pass. Shopify App Store merchants should not use this lane. |
| `/billing/portal` route | Stripe | Tenant Stripe Billing Portal handoff | Route exists but blocked unless billing flags, Stripe secret, and customer/subscription refs exist | Tenant-facing authenticated + tenant-scoped | No charge creation; can manage payment methods/invoices if enabled | Yes | High | Keep disabled for App Store merchants; direct lane only after approval. |
| `StripeHostedBillingService` | Stripe | Creates Checkout Sessions and Billing Portal Sessions | Inert unless billing flags and `sk_` secret are configured | Called from tenant routes | No by default; live-capable if enabled | Yes | High | Keep behind disabled flags; do not expose for Shopify App Store billing. |
| `TenantBillingNextStepResolver` | Stripe/manual | Decides tenant billing CTA/mode | Defaults to `landlord_follow_up` or unavailable while hosted billing disabled | Tenant-facing output | No | Reads Stripe secret presence | Medium | Keep language conservative; no checkout CTA by default. |
| `/webhooks/stripe/events` route | Stripe | Receives Stripe webhooks | Route exists, CSRF-exempt, throttled, requires valid webhook secret/signature | Public webhook endpoint | Does not create charges; can process charge/subscription state | Yes | High | Keep signature tests; fulfillment must remain lifecycle-gated. |
| `StripeWebhookIngestService` | Stripe | Stores events, updates billing mapping, can call fulfillment | Requires `STRIPE_WEBHOOK_SECRET`; fulfillment blocked unless lifecycle enabled | Server-side webhook | No charge creation; can mutate access if lifecycle enabled | Yes | High | Keep lifecycle disabled until explicit activation. |
| `StripeCommercialFulfillmentService` | Stripe | Applies confirmed Stripe plan/add-ons into tenant access | Blocks unless `lifecycle_mutations_enabled=true` | Server-side webhook or landlord repair | No by default; mutates entitlements if enabled | Reads tenant Stripe mapping | High | Keep disabled; never let untrusted client data drive fulfillment. |
| `/landlord/tenants/{tenant}/commercial/billing/stripe/customer-sync` | Stripe | Create/update Stripe Customer reference | Guarded action enabled by default, landlord-only | Landlord-only | No subscription/charge; can create Stripe customer if key configured | Yes | Medium | Allowed readiness action; keep landlord-only and audited. |
| `/landlord/tenants/{tenant}/commercial/billing/stripe/subscription-prep` | Stripe | Resolve price lookup keys and save subscription prep metadata | Guarded action enabled by default, landlord-only | Landlord-only | No subscription/charge | Yes | Medium | Allowed readiness action; keep landlord-only and audited. |
| `/landlord/tenants/{tenant}/commercial/billing/stripe/subscription-live-sync` | Stripe | Create/sync Stripe subscription reference | Disabled by default, landlord-only | Landlord-only | No by default; yes/live-capable if enabled with key | Yes | High | Keep disabled until billing activation PR; especially not for Shopify App Store merchants. |
| `/landlord/tenants/{tenant}/commercial/billing/stripe/fulfillment-reconcile` | Stripe/local | Replays billing fulfillment from server-side Stripe mapping | Landlord-only; service blocks while lifecycle disabled | Landlord-only | No charge creation; can mutate access if lifecycle enabled | No direct Stripe call | High | Keep lifecycle-gated and audited. |
| `landlord/commercial` views | Stripe/manual | Shows billing readiness, mappings, guarded action buttons | Landlord-only | Landlord-only | Customer sync can call Stripe; no tenant checkout | Uses configured Stripe services through actions | Medium | Preserve as operator console; do not expose tenant payment controls. |
| `resources/views/onboarding/start-here.blade.php` | Manual/Stripe status | Tenant setup + billing status copy | Says billing checkout is not active | Tenant-facing | No | No | Low | Keep explicit inactive copy. |
| `tenant_setup_statuses` commercial intent fields | Shopify/Stripe/manual/free intent | Captures plan interest and billing lane interest | Intent-only status metadata | Tenant-facing + landlord review | No | No | Low | Keep as planning signal only; do not wire to checkout or entitlements. |
| `/landlord/onboarding/intake` commercial intent columns | Shopify/Stripe/manual/free intent | Landlord triage for selected plan/billing lane | Review/status only | Landlord-only | No | No | Low | Use for operator review, not billing actions. |
| `/landlord/commercial-intent` | Shopify/Stripe/manual/free intent | Operator summary and billing lane decision gate | Review/status only | Landlord-only | No | No | Low | Use to decide next follow-up. It must not become a charge/subscription/entitlement surface without a future activation PR. |
| `/landlord/invoices` | Stripe direct invoice | Landlord drafts/sends approved direct invoices | Disabled by default through `EVERBRANCH_STRIPE_INVOICING_ENABLED=false` and tenant allowlist | Landlord-only | No by default; yes if explicitly enabled with Stripe keys and gates | Yes | High | Use only for approved Everbranch/Evergrove service work. Reject Shopify/third-party lines and never trigger entitlement fulfillment. |
| `resources/views/marketing/modules.blade.php` | Display-only | Module pricing labels | Says checkout is not active | Tenant-facing | No | No | Low | Keep pricing labels display-only. |
| `resources/views/shopify/app-store.blade.php` | Display-only / future Shopify lane | Embedded module pricing labels | Says checkout is not active | Shopify embedded tenant-facing | No | No | Medium | For App Store merchants, future billing lane should be Shopify App Pricing/Billing, not Stripe checkout. |
| `custom_module_requests` surfaces | Manual/custom | Discovery/intake for custom work | Explicitly does not activate billing or generate quotes/invoices | Tenant + landlord triage | No | No | Low | Keep requests separate from quotes, invoices, and entitlement changes. |
| `stripe_webhook_events` table/model | Stripe | Durable webhook receipts | Active if webhook route receives valid event | Server-side | No | No | Medium | Good evidence primitive; keep payload minimized. |
| `tenant_billing_fulfillments` table/model | Stripe/local | Fulfillment audit/replay records | Only used when fulfillment runs | Server-side/landlord | No | No | Medium | Keep idempotency and audit coverage. |
| `tests/Feature/Billing/*` | Stripe | Existing billing behavior tests | Some tests intentionally enable flags | Test-only | No live keys | No | Low | Continue running before billing changes. |

## Current Stripe Posture

Answers from code inspection:

- Is Stripe installed? No SDK package; first-party HTTP integration exists.
- Is Laravel Cashier installed? No.
- Are Stripe keys referenced? Yes: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_API_BASE`, `STRIPE_API_TIMEOUT`.
- Are Stripe customers created? Yes, via a landlord-only guarded customer sync action if Stripe is configured.
- Are subscriptions created? Code exists in the guarded landlord live subscription sync path, but that action is disabled by default.
- Are checkout sessions created? Code exists in tenant hosted billing, but it is disabled by default because checkout/lifecycle flags are false.
- Are billing portal sessions created? Code exists, but it is disabled by default and requires Stripe customer/subscription references.
- Are invoices generated? Yes, landlord direct invoice code exists for approved Everbranch/Evergrove work, but the send action is disabled by default and requires explicit Stripe invoicing flags, tenant allowlisting, signed webhooks, and production readiness gates. Live subscription sync may also generate/send invoices if separately enabled.
- Are payment links used? No Stripe Payment Links implementation found.
- Are Stripe webhooks registered? A local webhook endpoint exists at `/webhooks/stripe/events`; Stripe Dashboard registration is external/manual.
- Is any Stripe flow tenant-facing? Yes, `/billing/checkout` and `/billing/portal` exist, but are inert by default.
- Is any Stripe flow landlord-only? Yes: customer sync, subscription prep, live subscription sync, fulfillment reconcile, and commercial override mapping.
- Is any Stripe flow live-capable today? Code is live-capable if secrets and flags are enabled. Current defaults and current local config do not make it live-charge capable.
- What tests guard disabled behavior? `ReadinessGateTest`, `BillingStripeDiscoverySafetyTest`, `HostedBillingHandoffTest`, `StripeWebhookConfirmationTest`, `StripeCommercialFulfillmentTest`, and commercialization tests.

## Billing Lane Decision Matrix

| Lane | Intended customers | Provider direction | Current status | Activation requirements | Non-negotiable guardrails |
| --- | --- | --- | --- | --- | --- |
| A. Shopify App Store Billing / Shopify App Pricing | Merchants who install Everbranch through the Shopify App Store | Shopify App Pricing / Shopify Billing API | Not active | Partner Dashboard pricing setup, Shopify billing implementation, install/reinstall billing evidence, privacy webhook readiness, scope review | Do not route App Store merchant app charges through Stripe unless Shopify explicitly allows the distribution/billing lane. |
| B. Stripe Direct Billing | Direct SaaS, non-Shopify tenants, custom module retainers, manual contracts, service work | Stripe Billing + Checkout/Portal if approved | Code exists but disabled by default | Provider/legal/product decision, checkout activation PR, tax/refund/cancellation/support evidence, tenant-boundary tests | Keep separate from App Store merchant billing. Require explicit flags, audit, and no accidental entitlement activation. |
| C. Direct Stripe invoice / service billing | Early implementation work, supplemental/milestone charges, consulting, one-off service contracts | Stripe hosted invoice sent by landlord operator | Code exists but disabled by default | Operator approval record, tenant allowlist, Stripe keys/webhook, tax/Relay gates, invoice lifecycle tests | Customer payment action is required. Do not convert Shopify/third-party expenses into Everbranch charges or grant access from invoice payment. |
| D. Free/internal/demo tenants | Modern Forestry/internal/staging/demo tenants | No automated billing | Active posture | Landlord plan/entitlement controls only | Keep Modern Forestry stable; comped/internal access must be explicit and auditable. |

## Shopify Billing Research Notes

Official Shopify docs say Shopify App Pricing monetizes apps distributed through the Shopify App Store and is the default/recommended approach for published App Store apps. Shopify also states that apps published on the App Store are required to use a Shopify-provided billing solution. App Store requirements state apps using off-platform billing cannot be distributed through the Shopify App Store unless Shopify notifies otherwise, and app charges must use Shopify App Pricing or the Shopify Billing API.

Sources:
- `https://shopify.dev/docs/apps/launch/billing`
- `https://shopify.dev/docs/apps/launch/shopify-app-store/app-store-requirements`

Everbranch decision:
- Shopify App Store merchants should eventually use Shopify App Pricing / Shopify Billing.
- Stripe should remain available only for direct/non-Shopify/custom/manual billing lanes unless future legal/compliance guidance explicitly separates distribution and billing.

## Existing Test Evidence

Existing billing/commercial tests:
- `tests/Feature/Billing/HostedBillingHandoffTest.php`
- `tests/Feature/Billing/StripeWebhookConfirmationTest.php`
- `tests/Feature/Billing/StripeCommercialFulfillmentTest.php`
- `tests/Feature/Tenancy/LandlordCommercialConfigurationTest.php`
- `tests/Feature/ShopifyCommercializationPagesTest.php`

PR 10 added:
- `tests/Feature/Everbranch/BillingStripeDiscoverySafetyTest.php`

PR 13 added:
- `tests/Feature/Everbranch/PlanSelectionWithoutBillingTest.php`

PR 10 test coverage:
- Billing lifecycle defaults remain disabled.
- Tenant hosted checkout route is inert by default and does not call Stripe.
- Tenant setup, Module Store, and custom request surfaces do not expose active checkout controls.
- Stripe webhook route requires webhook secret and does not mutate entitlements with missing secret.
- Landlord Stripe actions are landlord-host/operator gated.
- Custom module requests remain billing-neutral.
- Shopify embedded App Store still says checkout is not active.

PR 13 test coverage:
- Tenants can select/update plan interest and billing lane interest.
- Plan selection does not call Stripe/Shopify, create billing fulfillments, or change entitlements.
- Landlord/admin can view and triage commercial intent.
- Non-landlord and cross-tenant access remains denied.
- Billing/checkout flags remain disabled.

PR 14 added:
- `tests/Feature/Everbranch/LandlordCommercialIntentGateTest.php`

PR 14 test coverage:
- Landlord/admin users can view the commercial intent summary.
- Non-landlord users cannot access the summary.
- Tenants are shown by plan interest and billing lane interest.
- Shopify lane rows show Partner Dashboard/CLI evidence, scope review, branding, and Shopify Billing/App Pricing blockers.
- Stripe lane rows show billing-disabled and future activation blockers without exposing tenant checkout.
- Manual lane rows remain manual follow-up only.
- Landlord/admin users can update commercial review status, next action, and commercial notes only.
- No payment, subscription, invoice, module install, or entitlement controls are present.
- Review updates do not call Stripe/Shopify, create billing fulfillments, or change module entitlements.

## Blockers Before Billing Activation

- Decide Shopify App Store billing lane before adding any paid Shopify App Store flows.
- Implement Shopify privacy webhooks and pass App Store compliance evidence.
- Reduce or justify broad Shopify TOML scopes.
- Decide whether tenant-facing Stripe checkout routes should remain in code before public Shopify review, given App Store off-platform billing rules.
- Capture live/staging evidence for any intended Stripe direct lane.
- Add tax, refund, cancellation, dunning, support, and accounting SOPs.
- Confirm all billing activation toggles are operationally controlled and not accidentally enabled by environment drift.
- Add browser evidence proving tenant-facing surfaces do not present Stripe checkout to Shopify App Store merchants.
- Execute a separate lane-specific billing activation design before turning plan interest or commercial intent gate status into paid plan selection.

## Recommended Next PR

PR 15 should focus on either Shopify external evidence capture or a manual commercial follow-up SOP. Stripe direct billing and Shopify Billing should stay paused until after Shopify compliance blockers are resolved and a separate billing activation PR explicitly chooses the lane.
