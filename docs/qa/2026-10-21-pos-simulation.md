# POS readiness and simulation review — 2026-10-21

Scope: `johncollinsgit/TimberLine`, based on main
`bf6bd942745fda768ee9b8ed89d538572014c821`, plus this isolated source increment.
The existing backend and mobile working directories were not changed. No live
Stripe activation, credentials, merchant provisioning, customer import, actual
payment/refund, production migration, deployment or Tap to Pay installation was
performed. Commercial rates and contracts remain separate research.

## Readiness matrix

“Implemented” below means source and local test evidence. It does not mean live
merchant/device acceptance. The read-only Stripe platform UI showed a saved
connected merchant and a Terminal activation entry point; no activation or reader
operation was performed. SaaS billing and an existing connection do not establish
in-person checkout readiness.

| Evidence layer | State |
| --- | --- |
| Implemented | Local/test register, card/cash simulation, native SKU/stock, explicit native customer/guest choice, practice history, printable receipt and manager refund/reconciliation |
| Tested | Fake-provider cashier HTTP flow, real browser-helper logic, actual disposable MySQL recovery/concurrency, synthetic native Chrome visual/print preview |
| Deployed | No POS deployment or production migration. The separately authorized Alpha Printing workspace is an inactive demo setup plan, not activated checkout. |
| Physical acceptance | No reader, Tap to Pay device, scanner or client receipt printer accepted; no actual Stripe sandbox/API payment tested |

| Capability | Source / test evidence | Honest readiness |
| --- | --- | --- |
| Everbranch SaaS billing | `StripeHostedBillingService`, `AgreementStripeCheckoutService`, `TenantBillingSubscriptionLedger`, Billing feature tests | Existing subscription/invoice lane; distinct from tenant merchant POS |
| Tenant merchant connection | `TenantStripeConnectionService`, `TenantPaymentsReadinessService`, `docs/operations/self-service-payments.md`, `TenantPaymentSetupTest` | Existing gated Standard/direct and legacy Express routing; no new connection or live Terminal proof in this work |
| Native cart/catalog/order | `WebsiteCommerceController`, `WebsiteCheckoutService`, `WebsiteOrder`, `WebsiteCommerceOperationsTest` | Reusable tenant-native authority; POS uses physical merchandise only, server prices and `source=pos_simulation` |
| Tax | `WebsiteCommerceService`, register confirmed rate/basis and immutable attempt snapshot | Simulator arithmetic verified; merchant registrations, line taxability and live tax/refund acceptance remain open |
| Stock / locations | `WebsiteProductVariant`, `WebsiteInventoryReservation`, `WebsiteInventoryMovement`, `WebsiteFulfillmentLocation`, POS MySQL concurrency tests | Shared native stock, active same-tenant/site location; unknown tracked stock rejected; no multi-location stock engine |
| Register / staff | `PosAccess`, `PointOfSaleController`, POS surface tests | Private setup/register with fresh membership and assigned cashier checks; manager refunds; local/testing only |
| Card / cash demo | Frozen tender/received/change in `PosService`, `DatabaseSimulatedReader`, service tests | Fictional card reader and immediate canonical simulated cash; no actual card/cash collection, drawer, shifts or settlement |
| Barcode / SKU entry | `PosCatalogLookup`, register helper, HTTP/JS tests | Keyboard-wedge or typed exact native SKU, full register catalog lookup, ambiguity rejection; no independent UPC mapping or physical scanner acceptance |
| Attempts / uncertainty | `PosService`, `DatabaseSimulatedReader`, POS service and JS recovery tests | Immutable UUID/terms, frozen original provider identity, independent fake evidence, recovery without replacement, review quarantine and once-only stock |
| Refund / receipt | `PosService::refund`, `PosRefund`, `resources/views/pos/receipt.blade.php` | Manager simulation refund with pending-value reservation; printable fictional receipt; no actual refund, restock automation or delivery |
| Signed callbacks | Existing `WebsitePaymentService` and `StripeWebhookIngestService` with reliability tests | Existing hosted/web lanes; new Terminal callbacks, reader actions, provider reconciliation and settlement acceptance still required |
| Customer / Retail profiling | `WebsiteCustomer::orders`, `WebsiteOrder.website_customer_id`, `PosCustomerDirectory`, native directory and POS tests | Explicit Guest/Find/Create/selection and frozen identity; current-register latest-100 confirmed practice history with refund/net totals. Real/cross-channel analytics and production privacy/identity acceptance remain open |
| Separate marketing CRM | `MarketingProfileAnalyticsService`, `MarketingProfileWorkflowObserver`, `SalesChannelSummaryService` | Legacy/Square analytics are not native POS history. No automatic identity merge, workflow enrollment or consent inferred from a purchase |
| Physical Terminal / embedded Tap to Pay | No Terminal provider/native SDK added; mobile source uses React/Capacitor | Unavailable. Exact device, merchant/Terminal readiness, reviewed adapter or Android bridge and physical test acceptance required |
| Multiple processors | `PosPaymentProvider`, immutable provider/merchant/payment snapshots and capability checks | One database simulator. Future adapters can retain provider-specific capabilities; no automatic routing, alternative integration or fee claim |
| Shipping / postage / surcharges | [POS operating boundary](../operations/pos-simulation.md#postalshipping-location-acceptance-boundary) | No rating, postage/labels or postal migration; no surcharge/dual pricing. Shipping/postage excluded by user instruction; other policies unapproved |

## Minimal pilot architecture

```mermaid
flowchart LR
  A[Verified cashier in tenant register] --> B[Server authorization and catalog prices]
  B --> C[Native website order and stock hold]
  C --> D[Immutable attempt with original provider and merchant]
  D --> E[One provider adapter]
  E --> F[Canonical evidence and reconciliation]
  F --> G[Once-only stock and paid status]
  G --> H[Receipt and manager refund to original provider]
```

Only the durable database simulator occupies the provider boundary here. Unknown
outcomes remain unresolved and keep their hold. A future customer projection
must read canonical confirmed payments/refunds and preserve guest sales and
tenant scope; it must not become a second transaction ledger. See the
[delivery phases](../operations/pos-simulation.md#retail-pos-destination-and-delivery-phases)
and [embedded Android plan](../operations/pos-simulation.md#embedded-android-tap-to-pay-phase).

## Executed cashier transaction

The actual cashier HTTP walkthrough ran in testing with in-memory SQLite and
`DatabaseSimulatedReader`, not Stripe sandbox/API. See the
[sanitized transaction output](pos-simulated-transaction-2026-10-21.json) for exact
fictional IDs and request UUIDs. This is fixture tenant/register/customer 1, not
the separately prepared Alpha Printing workspace.

| Step | Verified output |
| --- | --- |
| Identified card attempt/order 1 | `SIM-POS-RKSMJRJ1HEKK`; subtotal 2,500 cents + tax 206 = 2,706; canonical simulation succeeded |
| Inventory and replay | Stock 5 → 4, one movement; repeated approval/reconciliation/retry did not decrement again |
| Guest attempt/order 2 | Guest identity remained null and did not appear in the selected customer's history |
| Manager refund 1 | 500 cents confirmed after reconciliation; shown customer gross 2,706, refunds 500, net 2,206; no automatic restock |
| Cash attempt 3 | 2,706 cents total, 3,000 received, 294 change; canonical simulation succeeded; excluded from identified history as a guest sale |
| Final evidence | Stock 2 after three separate sales and refund; zero external HTTP; reader/printer acceptance false |

Reproduce with fictional fixtures only:

```bash
POS_REGISTER_FIXTURES=1 php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/PointOfSale/PosIdentifiedTransactionTest.php
```

This run passed **3 tests / 106 assertions** and exported the simulation receipt,
customer history and transaction report. No live data, payment API, physical
reader or printer was involved. Request UUIDs in a new run will differ.

## Verification and review

Final local results for this foundation:

- Full PHP suite: **4,370 passed, 8 skipped, 31,871 assertions**. The eight skips
  are existing opt-in synthetic browser fixture exports, not POS failures.
- POS feature suites: **149 passed, 1,393 assertions**; browser request recovery,
  scanner/cash controls and customer creation/selection: **20 JavaScript tests passed**.
- Isolated MySQL 8.4 recovery: **2 passed, 19 assertions**; concurrency and stale
  authorization/customer/SKU checks: **15 passed, 153 assertions**.
- Frontend type checks, existing JavaScript checks and Vite build passed.
  Migration safety lint, generated baseline verification, historical upgrade
  rehearsal, all **50 changed PHP files** in Pint and `git diff --check` passed.
- Repository-wide Pint reports existing formatting failures in unrelated files;
  no broad formatting cleanup is included. The repository's changed-file CI
  formatting contract is satisfied locally.

The automated acceptance covers server-owned amount/tax, frozen original terms,
tenant and cashier isolation, duplicate request replay, disconnection/unknown
evidence, cancelled/declined/succeeded and review outcomes, repeated stock
commitment, pending/partial refunds, receipt access and browser request recovery.
MySQL 8.4 uses independent processes and retained REPEATABLE READ snapshots to
exercise one-reader admission, duplicate UUIDs, shared web/POS last-item stock,
refund caps and committed actor/membership/register/location revocation.

The migration has a partial-DDL recovery manifest entry, actual retained-row and
index/foreign-key recovery, historical upgrade rehearsal, and a generated
data-free baseline/fingerprint verified against full migrations and dump boot.
New CI steps run these POS checks within the existing test/release gate; this PR
does not alter deployment triggers or execute a deployment.

Independent source review found and verified fixes for receipt-after-refund
access, review-state races, retained MySQL snapshot reads, request admission
locking, fresh authorization after waits, unknown tracked stock, lost-response
UUID recovery, cashier register-list filtering, original-provider servicing and
unsupported customer-ID handling, customer selection/create response races, and
simulation exclusions from ordinary shopper/reporting/provider operations.
Final review is scoped to this local/testing
foundation and does not certify Stripe, hardware or live operational readiness.

Native Chrome visual inspection of fictional exported owner/staff setup,
register and receipt previews confirms the Everbranch layout, clear simulation
banner, owner/staff separation and illustration disclaimer. The cash receipt
renders as one page in native Chrome print preview; the preview was cancelled
without sending a print job. These are synthetic
fixtures, not a live tenant acceptance record. The original illustration was
created using built-in image generation; [asset and exact prompt provenance](pos-illustration-source.md)
is committed with the image.

## What can be promised today

There is a tested, reviewable local foundation for practicing merchandise
checkout, explicit native customer/guest choice, confirmed practice history,
inventory reservation, canonical outcome recovery, receipts and manager refunds.
The interface and architecture provide a concrete basis for
the full Retail POS phases. Do not promise today's merchant can accept payments
through Everbranch, that their unknown terminal/Galaxy Tab is compatible, that
production CRM profiling already includes POS, that any price or savings is
agreed, or that shipping/postal software is replaced.

## Implementation concerns ranked for the next pilot

| Rank | Concern | Severity / likelihood before mitigation | Mitigation and owner |
| --- | --- | --- | --- |
| 1 | Live provider and deployment acceptance are absent | Critical / certain gap | Keep hard local/testing and rollout gates off. Backend owner + merchant must separately prove original-account routing, signed asynchronous outcomes, refunds and daily settlement before a named release. The prior production-rollout rejection is not bypassed by this PR. |
| 2 | Exact reader, Android device, scanner and receipt printer are unknown | High / high | Merchant supplies model/ownership/firmware; integration owner verifies compatibility and physical sale/cancel/disconnect/refund/print tests. Browser print preview and keyboard SKU tests are not physical acceptance. |
| 3 | Print/postal services, tax and migration differ from a merchandise basket | High / high | Merchant and implementation owner verify current software/service catalog, source prices/taxes and return policy. Preserve postal/carrier workflows; no inferred postage success or surcharge policy. Load no unverified stock/customer data. |
| 4 | Customer identity, privacy and marketing boundaries need production acceptance | High / medium | Retail owner reviews cashier permissions, retention and reviewed linking. Keep native ownership, explicit Guest/Find/Create, frozen transaction identity and separate consent; never auto-merge CRM profiles or count simulation as real spend. |
| 5 | Cash and full store operations require additional controls | High / medium | Retail owner defines shifts/drawer reconciliation, returns/restock decisions, tax reversals and receipt retention. Simulated cash/change does not establish actual drawer balance or cash custody. |
| 6 | History coverage and reporting could be overstated | Medium / medium | Keep shown-register/latest-100 labels and incomplete flags. Reporting owner implements reconciled real cross-channel summaries through existing source-owned reporting contracts, without duplicating ledgers. |
| 7 | Processor economics and service capabilities can vary | Medium / high | Commercial owner confirms account-specific charges, payout/platform fees and hardware terms; provider owner adds capability-specific adapters. Preserve original-provider servicing and do not promise automatic lowest-cost routing. |

The implementation/test evidence above and the Alpha Printing setup plan are
separate from deployment and physical acceptance. A nonfinancial inactive demo
workspace does not enable the POS routes in production or establish a merchant
payment account.
