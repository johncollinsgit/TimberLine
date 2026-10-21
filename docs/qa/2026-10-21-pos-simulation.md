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

| Capability | Source / test evidence | Honest readiness |
| --- | --- | --- |
| Everbranch SaaS billing | `StripeHostedBillingService`, `AgreementStripeCheckoutService`, `TenantBillingSubscriptionLedger`, Billing feature tests | Existing subscription/invoice lane; distinct from tenant merchant POS |
| Tenant merchant connection | `TenantStripeConnectionService`, `TenantPaymentsReadinessService`, `docs/operations/self-service-payments.md`, `TenantPaymentSetupTest` | Existing gated Standard/direct and legacy Express routing; no new connection or live Terminal proof in this work |
| Native cart/catalog/order | `WebsiteCommerceController`, `WebsiteCheckoutService`, `WebsiteOrder`, `WebsiteCommerceOperationsTest` | Reusable tenant-native authority; POS uses physical merchandise only, server prices and `source=pos_simulation` |
| Tax | `WebsiteCommerceService`, register confirmed rate/basis and immutable attempt snapshot | Simulator arithmetic verified; merchant registrations, line taxability and live tax/refund acceptance remain open |
| Stock / locations | `WebsiteProductVariant`, `WebsiteInventoryReservation`, `WebsiteInventoryMovement`, `WebsiteFulfillmentLocation`, POS MySQL concurrency tests | Shared native stock, active same-tenant/site location; unknown tracked stock rejected; no multi-location stock engine |
| Register / staff | `PosAccess`, `PointOfSaleController`, POS surface tests | Private setup/register with fresh membership and assigned cashier checks; manager refunds; local/testing only |
| Attempts / uncertainty | `PosService`, `DatabaseSimulatedReader`, POS service and JS recovery tests | Immutable UUID/terms, frozen original provider identity, independent fake evidence, recovery without replacement, review quarantine and once-only stock |
| Refund / receipt | `PosService::refund`, `PosRefund`, `resources/views/pos/receipt.blade.php` | Manager simulation refund with pending-value reservation; printable fictional receipt; no actual refund, restock automation or delivery |
| Signed callbacks | Existing `WebsitePaymentService` and `StripeWebhookIngestService` with reliability tests | Existing hosted/web lanes; new Terminal callbacks, reader actions, provider reconciliation and settlement acceptance still required |
| Customer / Retail profiling | `WebsiteCustomer::orders`, `WebsiteOrder.website_customer_id`, tenant-native directory endpoints | Current POS explicitly guest-only. Native linkage is reusable; POS lookup/create/selection, confirmed customer history and native refund analytics remain unbuilt |
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

## Verification and review

Final local results for this foundation:

- Full PHP suite: **4,280 passed, 8 skipped, 31,007 assertions**. The eight skips
  are existing opt-in synthetic browser fixture exports, not POS failures.
- POS feature suites: **59 passed, 529 assertions**; browser request recovery:
  **5 JavaScript tests passed**.
- Isolated MySQL 8.4 recovery: **2 passed, 19 assertions**; concurrency and stale
  authorization: **11 passed, 103 assertions**.
- Frontend type checks, existing JavaScript checks and Vite build passed.
  Migration safety lint, generated baseline verification, historical upgrade
  rehearsal, all **24 changed PHP files** in Pint and `git diff --check` passed.
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
unsupported customer-ID handling. Final review is scoped to this local/testing
foundation and does not certify Stripe, hardware or live operational readiness.

Native Chrome visual inspection of fictional exported owner/staff setup,
register and receipt previews confirms the Everbranch layout, clear simulation
banner, owner/staff separation and illustration disclaimer. These are synthetic
fixtures, not a live tenant acceptance record. The original illustration was
created using built-in image generation; [asset and exact prompt provenance](pos-illustration-source.md)
is committed with the image.

## What can be promised today

There is a tested, reviewable local foundation for practicing merchandise
checkout, inventory reservation, canonical outcome recovery, receipts and
manager refunds. The interface and architecture provide a concrete basis for
the full Retail POS phases. Do not promise today's merchant can accept payments
through Everbranch, that their unknown terminal/Galaxy Tab is compatible, that
existing customer profiling already includes POS, that any price or savings is
agreed, or that shipping/postal software is replaced.
