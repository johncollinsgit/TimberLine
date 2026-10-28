# Subscriptions pilot

## Product contract

Subscriptions is a reusable paid retail Branch at the existing USD 99/month
catalog price. Modern Forestry retains its included entitlement. The pilot is
tenant configuration, not a tenant-specific fork. Tenant-owned customers,
provider accounts, product mappings, billing terms and delivery dates vary.
Reuse SubscriptionAccessService, canonical module entitlements, existing
customer identities, Stripe payment-account readiness and Shopify Backstage.
It must work for a second entitled retailer without source changes.

Recharge remains the billing authority while a member is prepared. The owner
authorized a two-member Stripe pilot targeting November 2, 2026, with Shopify
products, orders, shipping and fulfillment. Monthly and prepaid billing must
retain independent delivery schedules. Candle Cash must never discount a
subscription. Existing Recharge customers and existing provider execution gates
are not bulk migrated or activated by preparation.

## Evidence and reporting

Private pilot records retain explicit source identities, exact reviewed terms
and append-only observations. Merchant-portal observations are attributed manual
evidence, not a complete API capture. A missing feed is unknown, never zero.
Shopify orders referencing Stripe payments are fulfillment evidence, not another
payment. Payment success, Shopify order confirmation, fulfillment and duplicate
billing verification are separate outcomes. Opening reports is offline.

Live collection requires verified merchant-account ownership, fresh source
billing-stop evidence, customer-present Stripe authorization, exact future
billing terms and a reconciled Shopify order mapping. A prepared record, local
checkbox, historical intent, or customer name cannot authorize collection.
Uncertain provider outcomes require reconciliation rather than another charge.

All activation and deployment use the protected test/build and migration gates.
No customer-facing invitation, charge or Recharge cancellation is performed by
the preparation command or report.

## Operations

- `/subscriptions/pilot?tenant=WORKSPACE` is also included in the existing
  `/subscriptions` workspace and the authorized Shopify Backstage page.
- `subscriptions:pilot summary --tenant=WORKSPACE --actor=ID` reports readiness.
  `prepare` and `observe` accept `--file=/absolute/private.json`; inputs must be
  regular 0600 files at most 64 KiB. They preview by default; `--apply` saves
  local encrypted evidence only. Replays converge and conflicting terms fail.
- An entitled Subscriptions workspace can use the existing owner/admin Accept
  payments setup, with the same exact tenant rollout allowlists. Account creation
  is separate from collection readiness. The owner requested a new Modern Forestry
  Standard Stripe account; preserve other merchants, frozen attempts and routing.
  Stripe requires both card_payments and transfers capabilities even for Standard
  direct-charge accounts. Only its exact HTTP 400 card-without-transfers validation
  rejection permits a separate corrected reservation. The rejected payload/key
  stays unchanged; timeouts, ambiguous failures and old attempts cannot create
  another reservation. This does not change charge routing or move any money.
- The member's Refresh Shopify order evidence button performs bounded fixed
  GraphQL queries against the original tenant-owned persisted store, checks the
  returned domain/customer and rechecks credentials and membership after I/O.
  It never calls a Shopify mutation. A different tenant/store/credential or an
  incomplete cursor is not an empty result.
- The existing Shopify order ingestion lane projects orders and fulfillments
  only for the prepared customer's exact reviewed Recharge subscription IDs.
  A successful actual Shopify Payments SALE/CAPTURE is payment evidence; a blank
  or external/manual gateway is only an order mirror. No new webhook registration,
  marketing send, billing retry or source-billing cancellation is introduced.
- Original provider updated times determine the latest Shopify state; a late
  webhook cannot replace newer refund evidence. Reports retain older evidence.
  Payment charts preserve missing-feed gaps and never sum mixed currencies.

## Launch prerequisites still required

This release prepares and monitors the pilot. It contains no Stripe collection
executor or customer payment link. The production inspection found Modern
Forestry's included Subscriptions entitlement enabled, no merchant payment
account and no Recharge read credential. Complete merchant-owned Stripe setup,
fresh Recharge read coverage and customer-present authorization before a later
collection release. Existing Recharge billing stays primary throughout.

The two named pilot members are stored only in the private encrypted operator
records, with exact existing Shopify and Recharge identities. They are not
default seed data or tenant matching logic. Source recurring Shopify orders
show one paid, fulfilled order per selected member per month from May through
October 2026. These order records do not prove complete payment-vault history
or absence of charges in an unconnected Stripe account. Preserve original
source IDs; legacy Recharge portal order IDs and Shopify rc_charge_id values
can differ and must not be guessed to be interchangeable.
