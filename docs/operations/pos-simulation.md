# In-person checkout: local simulation foundation

This source increment is a local/testing POS simulator. It is not an activated
Stripe Terminal integration, a production checkout, a merchant onboarding flow,
or a promise of reader compatibility. It creates no external payment or refund,
reads no provider credentials and sends no receipts. Commercial terms are deferred.

## Boundary and ownership

- `/pos` register routes require sign-in, verified active tenant membership,
  canonical Managed Website access, `POS_SIMULATION_ENABLED=true` and an exact
  integer tenant ID in `POS_SIMULATION_TENANT_IDS`. There is no wildcard. Access
  is hard-denied outside Laravel's `local` and `testing` environments even when
  flags are set. Terminal and live-payment config values are hardcoded false.
- Admins/managers operate registers and refunds. A member can sell only on a
  register whose explicit `cashier_user_ids` includes that member. Platform
  operator status is not tenant membership. Every operation rechecks access.
- This is a native retail channel within the existing `website_*` authority.
  Orders use source `pos_simulation`; variants, stock, holds and inventory
  movements retain the existing tenant/site ownership. Native Website customers
  retain their own authority. No legacy, Marketplace, Shopify, soccer, rewards
  or subscription ledger is copied or changed.
- Simulation orders are excluded from ordinary native shopper/token/tracking,
  customer/order views, dashboards, real sales summaries and quote attribution.
  Existing native payment, shipping, fulfillment and notification paths reject
  simulation orders before provider/operational writes. Practice history remains
  available only through the gated POS projection. Shared stock holds still
  protect inventory from an unsafe catalog import; simulations are never imported
  into a real merchant workspace.
- Four additive `pos_*` tables hold register bindings, immutable payment intents,
  refund requests and a separate durable simulated-provider ledger. There is no
  production register provisioning endpoint, reader discovery, USB driver or
  Stripe adapter. Register configuration must use fictional `sim_` merchant and
  reader identities and an active same-tenant/site fulfillment location.
- `PosPaymentProvider` declares payment/refund primitives, a stable provider key
  and explicit capabilities. Its only implementation is the database simulator;
  `SimulatedReader` adds simulation controls. Each attempt freezes its provider
  key, merchant and payment identity, and every refund inherits that original
  identity. A changed or unavailable provider fails closed before a call; there
  is no automatic processor fallback or transaction-routing engine. Future
  providers must keep their own capabilities, credentials, event verification
  and original-payment servicing rather than borrow another provider's features.
- Simulator records and stock changes belong only in a disposable fictional
  workspace/database. Never point this rehearsal at production or imported
  customer data. A simulation receipt must never be represented as a real sale.

## Money, stock and uncertainty

The server resolves variants and prices, freezes the register's explicitly
confirmed simulation tax rate/basis, and calculates integer-cent totals. Client
prices and totals cannot establish the amount. A tax rate in this simulator is
an approved test example, not a live tax determination or Stripe Tax acceptance.
Tracked items with unknown stock are unavailable; only explicitly untracked
physical merchandise can be sold without stock accounting. The catalog currently
contains physical native products only. Non-stock services are not supported by
relabeling them as merchandise.

A request UUID and payload hash bind one immutable attempt. Register and variant
locks serialize admission and protect the shared inventory authority. An
unresolved reader attempt blocks another sale on that register. Stock is held
before the simulated reader operation and committed once after canonical
provider evidence matches the original tenant, order, merchant, reader,
environment, amount and currency. Browser success flags do not establish payment.

The provider simulator stores evidence independently from the application
attempt. Approved, declined, cancelled and unknown outcomes exercise the same
reconciliation boundary. An unknown/processing or mismatched result must not
release inventory, create a replacement attempt or produce a paid receipt.
Reconciliation recovers the original attempt; repeated success cannot decrement
stock twice. A confirmed cancellation/failure can release its reservation.

Manager refunds require their own UUID, exact original payment and amount, and a
reason. Pending/unknown refunds reserve refundable value so another request
cannot over-refund. Refunds do not automatically restock. The printable receipt
shows frozen line/tax totals and verified simulation status; it does not claim
inbox delivery, card-network receipt compliance, settlement or real card payment.

The demonstration supports fictional card and cash tenders. Card rehearsal uses
the simulated reader outcomes. Cash rehearsal requires a bounded integer received
amount covering the server-priced total; the server freezes tender, received
amount and exact change in the immutable terms. Canonical simulated cash evidence
settles immediately and commits stock once. Changed tender/received input cannot
reuse the original UUID. Refunds inherit the original tender/provider and refund
the sale amount, not the tendered amount or change. Older card attempts without
these fields remain serviceable. No real cash is recorded, drawer opened or
physical payment requested; live cash operations/shifts still require acceptance.

Barcode entry supports a keyboard-wedge scanner or typed code that exactly equals
an existing native catalog SKU. A private read-only server lookup checks the
current register's tenant/site, physical merchandise availability and stock,
including items beyond the bounded browse list. Unknown or duplicate exact SKUs
fail without choosing an arbitrary product. Server checkout resolves price and
stock again. There is no independent UPC/EAN/barcode mapping or camera/USB driver;
source catalog mappings and the actual scanner still need verification.

## Local verification

Use the existing fake-provider PHP tests with an in-memory SQLite database and
stray HTTP requests blocked. MySQL schema recovery/concurrency tests require a
separately initialized disposable local MySQL 8.4 database with a test-like name.
Never reuse application environment files or production credentials. The
committed schema dump/fingerprint must be generated by the existing disposable
baseline workflow, not hand-edited. The migration has a durable partial-DDL
scenario in the existing recovery manifest.

```bash
php scripts/ci/lint-migrations.php --base=origin/main --working-tree
php vendor/bin/pest --compact tests/Feature/PointOfSale
php vendor/bin/pest --compact tests/Feature/ManagedWebsite/WebsitePaymentReliabilityTest.php tests/Feature/ManagedWebsite/WebsiteInvoicePaymentTest.php
```

The MySQL integration tests use the explicit `POS_DISPOSABLE_MYSQL=1` opt-in in
addition to their database/environment guards. CI runs migration recovery,
prior-release upgrade and baseline verification before concurrency checks.
Physical-device/browser acceptance remains a separate stage.

The private `/pos` setup hub distinguishes owner-controlled provider connection
from staff sign-in and register assignment. Saved provider state and its checked
time are not proof of live POS readiness. Setup GETs never refresh a provider or
start onboarding. Existing payment-connection links retain their independent
owner/admin and rollout gates. Other processors and physical reader support
remain planned. The hero is an AI-generated generic illustration, not a photo of
the client's equipment; see [asset provenance](../qa/pos-illustration-source.md).

## Retail POS destination and delivery phases

The intended product is a ready-to-use Everbranch Retail POS: cashier register,
inventory, customer lookup/create or guest checkout, customer transaction history,
receipts, returns/refunds and reporting. This increment is its disabled simulation
foundation; it is not a complete retail system or an activated client installation.

The register defaults to Guest and supports an explicit active native customer
selection through `website_customer_id`. The ambiguous legacy `customer_id` is
rejected. The server resolves ownership and freezes the selected ID/contact
snapshot into the original request and native order; a changed customer cannot
reuse the original UUID. Contact edits or archival do not rewrite issued terms.
Guest sales retain no customer link and never create a shared anonymous profile.

Private Find/Create controls reuse `WebsiteCustomer`; create is explicit and
UUID-bound, including no-email contacts. Duplicate email asks the cashier to
select the existing native record rather than merging identities. No customer
contact is stored in browser request recovery. Customer creation, selection and
purchase history cause no marketing enrollment, consent or messaging workflow.

The practice customer profile shows the latest 100 canonically confirmed
simulation sales at the current assigned register, with confirmed refunds/net
and unresolved refunds separate. Totals explicitly cover shown practice sales,
with incomplete coverage flagged; they are not all-time, cross-register or real
merchant revenue. Native `WebsiteCustomer::orders()`/`website_customer_id` are
reused; pending, unknown, failed, guest and unrelated customer sales cannot become
paid profile history. Simulations remain excluded from real customer analytics.

Keep native retail shoppers and `website_*` orders in their existing tenant lane.
The separate MarketingProfile/legacy retail customer directory is not an
interchangeable customer ledger. Do not automatically merge identities, use
name/email similarity as identity proof, copy Website orders into legacy orders,
or treat a purchase as marketing permission. Customer history must include actual
confirmed sales and their returns/refunds, distinguish simulations, preserve
anonymous sales and never store PAN/CVV or infer sensitive personal traits.

| Phase | Concrete deliverable | Dependencies / acceptance |
| --- | --- | --- |
| 1 — this PR | Private polished setup/register; SKU barcode entry; Guest/Find/Create and bounded practice customer history; card/cash rehearsal and server change; server prices/tax snapshot; native stock; durable attempts/reconciliation; printable receipt; manager refund | Local/testing only, fictional allowlisted tenant, default-off flags, automated MySQL recovery/concurrency and source review |
| 2 — retail customer/history acceptance | Production-ready native profile permissions/identity policy and payment-confirmed cross-channel history after real provider acceptance | Review retention, deduplication and read-only CRM projection contract; preserve guest choice and consent; distinguish channel/refund uncertainty and real payments from simulations |
| 3 — provider/device test pilot | One supported smart-reader adapter and/or embedded Android Terminal bridge, signed callbacks, missed-event reconciliation | Exact merchant/account/routing/country and device eligibility, separate approved test credentials/activation, server-bound location/reader, canonical payment/refund acceptance |
| 4 — store operations | Reviewed returns/restock decisions, cashier permissions/shifts as required, daily payment/refund/settlement reporting, receipt delivery and selected peripherals | Return/tax/receipt policy, evidence of original-provider settlement/refund behavior, inventory audit and printer/scanner hardware acceptance; no fabricated revenue or duplicate channels |
| 5 — shipping handoff and controlled launch | Verified service/merchandise mapping and carrier-software handoff, migration rehearsal, named tenant rollout | Existing shipping workflow documentation, approved data mapping, no postage surcharges, independent carrier/payment outcomes, explicit acceptance and protected release authorization |

Processor integrations after the first remain separate capability-specific
increments. No current phase commits a rate, contract, completion date, arbitrary
hardware compatibility or replacement of postal/carrier software.

## Live implementation and acceptance still required

1. Identify the merchant, country/currency, exact terminal model, ownership and
   processor provisioning. Arbitrary PC USB terminals are unsupported. Choose a
   Stripe-supported internet-connected smart reader for a server-driven pilot,
   or separately scope a native mobile SDK. Preserve provider independence.
2. Add a reviewed live provider adapter with server-owned connected-account,
   location and reader bindings. Define capability freshness/revocation,
   asynchronous reader actions, signed PaymentIntent/reader webhook ingestion,
   missed-event recovery and provider idempotency expiry handling. No existing
   hosted Checkout handler may be relabeled as a Terminal handler.
3. Verify real merchant onboarding, Terminal activation and test-mode physical
   acceptance. Confirm tax registrations, product tax codes/location sourcing,
   refund tax reversals, compliant receipt choice/content and peripherals.
4. Exercise isolation, two cashiers/one reader, last-item web/POS stock contention,
   disconnection, cancellation/success races, unknown captures/refunds, delayed
   callbacks and daily settlement reconciliation. Provider simulator results do
   not establish Stripe, network, printer or hardware acceptance.
5. Obtain separate live acceptance authorization and a named tenant rollout.
   Release through the existing PR/CI/MySQL/Forge path; then verify exact-release
   readiness and runtime flags. This implementation authorizes none of those
   production actions. Leave all rollout/payment gates off until that stage.

Offline payments, cash drawer, split tender, tips, discounts, gift cards,
exchanges, multi-location stock and printer integrations are excluded. Their
absence must remain visible when scoping a customer pilot.

## Embedded Android Tap to Pay phase

Tap to Pay belongs inside Everbranch's Android app. Installing the Stripe
Dashboard app is not this integration. The inspected `everbranch-mobile` source
uses React with Capacitor; its package list, Android Gradle dependencies and
manifest contain no Stripe Terminal implementation. A web register or WebView
alone cannot supply the native payment collection boundary.

The next separately reviewed increment should add a narrow Capacitor-to-native
Android Terminal SDK bridge, authenticated server-issued connection tokens and
server-owned merchant/location/payment bindings. Keep amounts, permissions and
canonical reconciliation on the server; expose SDK lifecycle/outcomes to the app
without passing card data through JavaScript. Reuse immutable original-provider
references and the existing unknown-outcome recovery rules. Test lifecycle
interruption, reconnection, cancellation, attestation/requirements errors and
SDK-controlled payment screens before any tenant acceptance.

As checked on 2026-10-21, Stripe documents embedded Android/React Native SDK
support, Android 13+, integrated NFC/ARM, an unmodified locked device, Google
Mobile Services/Play Store, recent security updates, compatible hardware
keystore, internet and disabled Developer options. Its device examples include
Galaxy Tab Active5; that does not establish compatibility for an unspecified
Galaxy Tab. Record the exact model/variant, OS/security patch and NFC capability,
then verify SDK eligibility on that device. Emulators do not establish acceptance.
Recheck the [current Stripe requirements](https://docs.stripe.com/terminal/payments/setup-reader/tap-to-pay?platform=android)
when implementing; requirements and SDK versions can change. No mobile dependency,
device setting, installation or payment capability is changed by this PR.

## Postal/shipping-location acceptance boundary

The prospective shipping/postal business is research context, not an imported
tenant or an approved migration. This foundation does not replace its current
postal/shipping application. It implements no USPS/carrier rating, postage
purchase, manifests, shipping labels, package acceptance, tracking, regulated
postal workflow, scale, scanner or specialty printer integration. Existing native
website shipping code is not evidence of compatibility with that business's
software or hardware. No service catalog, inventory or customer data is loaded.

Before broadening the basket, identify which merchandise is stock-tracked,
which services have no stock, and which charges/taxes originate in the existing
postal/shipping system. Freeze an authoritative source price/tax snapshot on an
approved handoff; never infer postage cost, taxability, margin, purchased-label
status or carrier success from a card approval. Keep payment and carrier outcomes
separate and preserve original-provider references for reversals. Peripherals,
data mapping and provider integration require verified source documentation and
explicit acceptance before replacing any existing workflow.

Shipping and postage are excluded from any future surcharge policy by the user's
instruction. This foundation applies no surcharge or dual pricing to any item.
USPS's [published CPU criteria](https://about.usps.com/suppliers/becoming/contract-postal-unit.htm)
also prohibit surcharges on Postal products/services; this business's current CPU
contract status is unverified. Do not infer eligibility for other merchandise,
services or debit cards from those exclusions. Any later policy needs verified
agreements and applicable rules before separate approval and implementation.

Before introducing mixed service/merchandise baskets, freeze each line's category,
source/provider ownership, price and tax policy version, plus immutable allocation
of the original payment across principal, tax and any separately authorized fee.
Refund/reversal allocation must use that original snapshot and provider, never a
newly selected processor or current fee policy. The current physical-merchandise
simulator has no mixed-basket categories, fee engine or such allocation ledger;
those are explicit future design/acceptance dependencies.

## Rollback

Disable local simulation availability/allowlisting to stop access and new work.
Preserve immutable attempts, simulated-provider evidence, refunds and movement
history for review. Do not run destructive down migrations, clear shared tables,
or rewrite a payment outcome to make a rehearsal look successful. This source
change does not modify any production rollout or provider configuration.
