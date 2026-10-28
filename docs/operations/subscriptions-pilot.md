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

## Controlled monthly execution

The separately gated executor uses Stripe Checkout in setup mode to obtain a
new customer-present card authorization; it neither copies the Recharge vault
nor charges during setup. An owner reviews the exact product, shipping address,
shipping amount, tax rate and tax basis. Their total must match the prepared
amount. Monthly delivery and billing intervals must match; prepaid execution,
term changes and card/address changes require later implementation/review.
Candle Cash and discounts have no execution path. Retailer Branch purchase
remains disabled while provider acceptance is incomplete; the existing catalog
price and included Modern Forestry entitlement are unchanged.

`SUBSCRIPTIONS_PILOT_SETUP_ENABLED`, `SUBSCRIPTIONS_PILOT_COLLECTION_ENABLED`,
`SUBSCRIPTIONS_PILOT_HANDOFF_ENABLED` and exact
`SUBSCRIPTIONS_PILOT_TENANT_IDS` are independent, default-off gates. Setup also
requires existing merchant setup/read rollout. Collection requires current
canonical Stripe readiness, a genuine signed setup callback bound to this
merchant, and `SUBSCRIPTIONS_PILOT_LIVE_WEBHOOKS_VERIFIED`. The separate
`SUBSCRIPTIONS_PILOT_STRIPE_WEBHOOK_SECRET` signs connected-account events at
`/webhooks/subscriptions/stripe` (Stripe API 2024-06-20). Configure Checkout
completion, PaymentIntent and charge/refund events; preserve other endpoints.
Never mark callback proof from a synthetic event or redirect.

Owner review is available from each pilot member. Recharge read setup accepts
only a private read-only token for the original owned store, with read_store,
read_customers, read_subscriptions and read_orders. It does not send a source
cancellation. Customer links are opaque, hashed for lookup, encrypted in retained
requests and session flashes, private/no-store and expire after seven days.
Owners can retrieve a link; selected authenticated Shopify customers can obtain
a current management link. No customer invitation is sent by these routes.

Activation requires actual Stripe SetupIntent/payment-method reads and fresh
Recharge store/customer/subscription/charge reads proving stopped source billing,
no active subscriptions, no queued/pending/error charges, and no collected cycle
charge. Complete owned Shopify reads must show no prior subscription order.
Only an individual explicit owner activation switches that member's authority.
Other customers remain with Recharge. No automatic cancellation is implemented.

Everbranch owns the schedule and creates an off-session PaymentIntent on the
current authorized date at/after 9 am in the customer's timezone. It does not
create an automatically charging Stripe Subscription. Each cycle reserves one
immutable encrypted request/key before HTTP; concurrent retries reuse it. Lost
responses recover the original by exact customer/day/cycle/request identity.
After 23 hours no create is retried; uncertainty needs operator reconciliation.
Early and overdue cycles cannot silently collect. Actual amount/customer/card/
account/environment/capture/refund checks precede fulfillment. Authentication
uses Stripe.js against the original PaymentIntent, never a replacement charge.

A captured payment hands the exact Shopify variant/customer/shipping/tax terms
to orderCreate as a PAID external `everbranch_stripe` transaction. This records
the Stripe receipt and does not collect with Shopify. Inventory obeys Shopify
policy. The request/in-flight marker is durable before the mutation. No automatic
orderCreate retry exists: timeout/5xx recovers by the original unique cycle tag;
an unresolved or conflicting result requires review. Customer receipts are not
sent by the pilot bridge. Existing Shopify fulfillment remains authoritative.
Confirmed orders and later ingestion project only the exact persisted order,
cycle/payment/account/terms mapping; attributes alone cannot authorize a new map.
Refunds/disputes block a new handoff and require review of an already handed-off
order. No automatic refunds or shipping cancellations are performed.

`subscriptions:pilot-process` without options is an offline summary.
`--collect --reconcile` runs every five minutes behind the gates, recovers original
requests, processes retained signed receipts and rechecks recent cycles. One
confirmed cycle advances the original billing anchor once. Unknown outcomes,
refunds and handoff errors stay visible. Public pause stops future billing until
review; native whole-month pauses skip intervals and resume on the original
anchor. Cancellation prevents future billing and retires a known unconfirmed
original PaymentIntent where Stripe permits. A payment already processing may
need support reconciliation. Existing native app pause/cancel payloads remain
compatible; opening the new management endpoint requires future native UI work.

Reports are offline. Stripe reads take precedence over older manual observations;
refunded payments leave the success chart. Imported external Shopify transactions
are never added as payment revenue. Cross-provider coverage is time stamped,
bounded to the reviewed customer/cycle and expires in the readout after 15
minutes. Missing/stale/failed coverage remains Not verified. Additional Stripe
objects set a durable possible-duplicate flag and block future collection.
A clean observation is not a guarantee about future external provider changes.

## Launch prerequisites still required

Production preparation recorded both selected members and historical source
orders, and created a new Standard/direct Modern Forestry merchant account.
The owner's business verification and payouts, a read-only Recharge credential,
reviewed delivery/tax terms, genuine signed callbacks, individual customer
consent, controlled provider acceptance and explicit source stop are still
required before collection. Never infer completion from an owner's intention
to finish setup. Keep all collection/handoff flags off until those checks pass.
The November 2, 2026 target is conditional on readiness; otherwise Recharge
continues primary and the pilot date needs a fresh review.

The two named pilot members are stored only in the private encrypted operator
records, with exact existing Shopify and Recharge identities. They are not
default seed data or tenant matching logic. Source recurring Shopify orders
show one paid, fulfilled order per selected member per month from May through
October 2026. These order records do not prove complete payment-vault history
or absence of charges in an unconnected Stripe account. Preserve original
source IDs; legacy Recharge portal order IDs and Shopify rc_charge_id values
can differ and must not be guessed to be interchangeable.
