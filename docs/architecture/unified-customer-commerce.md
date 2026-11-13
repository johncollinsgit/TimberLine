# Universal customer profile across every Everbranch app

Owner direction recorded October 30, 2026: every Everbranch tenant should have
the capability to connect a payment to one customer entry and track that
customer's online and in-person purchases and rewards together. Modern Forestry
is the first pilot, not a tenant-specific implementation or exclusive entitlement.

Owner direction expanded November 13, 2026: every current and future
customer-facing or staff app that touches a business's customers must use the
same tenant-owned canonical customer. This included capability applies to future
retail businesses using these branches as well as Modern Forestry. Each business
owns its own customer relationships; it is not a shared customer directory across
unrelated businesses.

## Current sources and future replacements

Modern Forestry's current source assignments are explicit. A future adapter joins
the same canonical customer; installing an app never performs a source cutover.
Other businesses configure their own sources without inheriting these connections.

| Domain | Current Modern Forestry authority | Future Everbranch authority |
| --- | --- | --- |
| Online retail purchases | Shopify | Everbranch retail / Website commerce after reviewed cutover |
| In-person purchases | Square | Everbranch Pay after reviewed cutover; its processor retains payment evidence |
| Event operations, dates, booth details | Asana | Everbranch Teams after reviewed cutover |
| Recurring subscriptions and original membership history | Recharge | Everbranch Subscriptions after reviewed cutover |

Growave/Yotpo imports and historical Square connections remain provenance-bearing
customer, loyalty and permission evidence. They do not replace current purchase,
event or subscription authority. Source-specific cutover requirements still apply;
especially preserve Recharge billing anchors and avoid duplicate subscription
collection. The subscription replacement runbook owns that transition.

## Required customer experience and app integration

Opening an authorized customer reference from Customers, Pay, Teams, retail,
Subscriptions, a mobile app or another customer-touching branch must resolve the
same canonical customer ID in that business. Surfaces may show only data the
current actor can access; shared identity does not grant financial, marketing or
customer-account permissions.

The shared profile brings together:

- Confirmed online and in-person purchases, recorded products/scents/sizes,
  purchase frequency and rewards. Distinguish an explicitly saved preference from
  a most-purchased candle; missing item or refund coverage stays visible.
- Exact reviewed event purchase/capture relationships with original event IDs,
  plus separate recorded purchase-area evidence. A city/tax label can support an
  area audience without proving attendance at a particular event or a home move.
- Current subscriptions and original dated membership evidence from the active
  subscription source. A billing migration is not a new membership join.
- Behavior-based marketing groups and saved audience definitions, with their
  qualifying evidence and dates. Group membership and possession of an address or
  phone are separate from channel permissions and send admission.
- Source coverage, refresh times, unresolved identity conflicts and reconciliation
  provenance, so staff can tell what is known and what remains incomplete.

Use `MarketingProfile`, `CanonicalMarketingProfileResolver`, explicit
`MarketingProfileLink` / external source associations and the shared
`CustomerCommerceSnapshotService`, `ConfirmedCustomerPurchaseProjection`,
`CustomerBehaviorProfile`, `CustomerEventValueService` and audience services.
Extend these versioned contracts when a required domain is missing. Do not create
an app-local customer master, duplicate purchase/rewards ledger, inferred merge or
independent behavior calculation. Original provider/domain records remain the
authoritative evidence behind the shared read model.

Customer discovery and reviewed reconciliation belong permanently in Customers
and its existing source/audit records. Future imports and app adapters must reuse
those results. A one-time campaign export is not the customer source of truth.

Every new app or customer-related change must document its canonical customer
reference, authoritative source and refresh/cutover behavior, shared read contract,
and actor/tenant permissions. Before release, prove cross-surface identity,
current permissions, replay/refund correctness, ambiguity handling and tenant
isolation with relevant tests. Unadapted coverage must be labeled incomplete;
that app cannot claim the universal customer experience is finished. Apply this
review requirement to existing branches when changing them, and to every future
customer-touching app before launch.

For inventory-owned Pay registers, guest collection precedes optional audited customer association. See [finished-goods stock](finished-goods-stock.md). Financial snapshots stay immutable; receipt contact alone does not verify account identity.

## Product contract

- Included capability for every tenant and business template. The Customers
  module is already shared core. Rewards availability must not require a plan
  upgrade; a merchant still decides whether to configure and run a program.
- One canonical customer per tenant, using `MarketingProfile`, its existing
  canonical alias resolver, external profiles and explicit source links. The
  same person at two vendors has two separate vendor customer relationships.
- A sale may be attached only to an explicitly selected, currently authorized
  customer. A card tap, receipt name, matching name, or unverified email is not
  sufficient customer-identity evidence. Ambiguous identities require review.
- Channel records remain authoritative and separate. An explicit identity link
  lets a read-only customer view present their records together; it does not
  merge Website/Shopify/provider customers, copy orders between domains, grant
  consent, or turn provider payment records into additional sales revenue.
- Online, in-person and unknown channels are displayed from recorded evidence.
  Unconfirmed payments, rehearsals and simulated receipts never become real
  purchase history or earn rewards. Unknown coverage is shown as incomplete.
- The existing Candle Cash ledger is the shared rewards engine. Tenant-facing
  copy uses the tenant's program name or Rewards. No second points/wallet ledger.
- Purchase confirmation and refund processing use immutable original sale and
  customer references and replay-safe operations. A retry cannot create another
  purchase, earn points twice, or move a refund to another account/customer.
- Financial/customer access, source permissions and tenant boundaries apply to
  every web, mobile, embedded and customer-facing read. A public rewards lookup
  does not gain access to private purchase history or staff notes.

## Implementation sequence

1. **Universal contract and catalog.** Record this instruction in AGENTS,
   SYSTEM_SNAPSHOT and README_FOR_AGENTS. Include the capability in every plan,
   expose setup rather than an upgrade for rewards, and retain merchant program,
   consent, provider and payment-readiness checks.
2. **Canonical customer commerce read model.** Add a shared, read-only snapshot
   of explicitly linked confirmed purchases and the existing rewards balance.
   Resolve retained aliases, enforce tenant scope on every source query, retain
   original source IDs/currency/channel, and suppress only proven duplicate
   representations of the same sale. Never match purchases by name alone.
3. **Consistent customer presentation.** Reuse the snapshot in retail and trades
   customer details, the shared mobile customer API, embedded customer details
   and authenticated customer account surfaces. Keep old client contracts
   compatible. Show absent channels/programs as not connected/not configured,
   rather than claiming a complete zero-history account.
4. **Identity linking and payment adapters.** Register explicit source-customer
   associations through an audited, owner/admin-reviewed flow. For native POS,
   select the canonical customer before quoting; freeze that identity with the
   original sale; bind the same Shopify customer where that store is authoritative.
   Finish this against the guarded native/Stripe POS candidate (draft PR #490),
   not the practice POS or the local catalog's displayed subtotal.
5. **Confirmed purchase/rewards lifecycle.** Ingest the original confirmed sale
   once, apply the merchant's configured earn rules through the existing ledger,
   and reverse the appropriate earning on refunds according to those rules.
   Validate tenant-owned program settings before activating a new merchant:
   the existing settings resolver's legacy global fallback is not evidence that
   this merchant approved its own program. The shared read snapshot deliberately
   uses tenant-owned setup only and performs no program activation or migration.
   A gateway callback, redirected browser or successful app build is not payment
   confirmation. Preserve existing historical balances and recorded program terms.
6. **Cross-surface acceptance and release.** Run the acceptance matrix below,
   Modern Forestry regressions, tenant isolation and normal protected release
   gates. Activate one bounded Modern Forestry pilot, then a different tenant.
   No broad rollout, data backfill, customer merge or balance recalculation is
   authorized merely by source installation.

## Acceptance matrix

For Modern Forestry and a second tenant with a different business template:

1. Start with a real customer who has an identifiable online purchase; retain
   the canonical customer ID and opening rewards balance.
2. Select that customer at the register, complete one consented real sale and
   verify the original provider payment and merchant settlement.
3. Confirm the online and in-person purchases appear once under that customer
   on the staff web app, applicable mobile app and authenticated customer view.
4. Confirm any configured earning posts once. Replayed/reordered events,
   repeated refreshes and interrupted responses leave the same history/balance.
5. Verify partial/full refund handling and the corresponding rewards adjustment.
6. Verify guest sales stay guests; an ambiguous email, another customer or another
   tenant cannot acquire this sale, history, receipt or balance.
7. Verify archived aliases resolve to the same survivor without adding duplicate
   purchases or separate balances. Broken/cyclic/cross-tenant links fail closed.
8. Verify an unconfigured tenant can access the included setup/capability without
   inheriting another tenant's merchant account, rewards rules or customer data.

## Current evidence and claim boundary

Implemented in the October 30 source candidate:

- Every plan includes Customers and Rewards availability through the canonical
  module catalog. Merchant overrides and program activation remain effective.
- The same read-only commerce contract feeds retail/trades staff details,
  Shopify embedded customer details, Field customer APIs, Modern Forestry's
  authenticated account API, signed Shopify customer accounts and authenticated
  Website shopper accounts with reviewed source associations.
- The paired Field source (Everbranch Mobile PR #63) renders that contract on
  its customer screen and falls back to the prior view with older backends.
  The Modern Forestry customer app source also adopts the additive account
  field in Account → Purchases & Rewards, preserving order details/reorder.
  Native distribution is separate; an API field or source build does not prove
  an installed customer binary displays it.
- Owner/admin review can connect existing source records with an audit entry,
  active membership checks, tenant validation, replay-safe source IDs and conflict
  rejection. This never merges customers, changes source orders or grants consent.
- New Shopify ingestion captures provider financial/channel evidence separately
  from fulfillment status, retains original shop-currency totals, and ignores
  older evidence replays. Older imports without evidence remain unverified;
  this change performs no historical import or financial recalculation.
  Shopify test orders are excluded, and recorded refund amounts use successful
  unique refund transactions in the original currency, not line subtotals.
- Confirmed Square order/tender representations are shown once with recorded
  refunds; contradictory customer associations are hidden for identity review. Imported Square mirrors of a known Pay receipt remain excluded until the
  original Pay payment is confirmed and its explicit selection or audited receipt
  association resolves to that same canonical customer. A later provider customer
  suggestion, receipt delivery contact, or pending Pay state cannot supply this
  attribution; unrelated original Square purchases retain their source links. Prior-purchase reward eligibility
  keeps incomplete identity/refund evidence in review. Its internal receipt claim
  excludes only the current verified transaction by exact original references;
  other historical issues and club review remain. An audited owner review can
  resolve eligibility for a guest or preselected receipt without changing the
  payment snapshot, published policy, independent reward spend or stock effects.
  Website practice receipts remain excluded. Original records are not copied.
- Rewards use the survivor's existing balance and ledger. Customer-safe labels
  exclude staff descriptions. Viewing a customer never earns, redeems or adjusts
  rewards. Unknown/unconnected history remains visibly incomplete.

The initial adapters support the listed two-decimal currencies in
`CustomerCommerceSnapshotService`. Other currency scales are deliberately
unverified with amounts withheld until a currency-aware adapter is added.
Field-service accounting invoices and other unadapted payment providers are
not yet normalized into this history; their existing authorized views remain
authoritative. These are coverage gaps, never a complete zero-purchase claim.

Event purchase reads share one tenant/runtime-scoped candidate-ID lookup within
each HTTP request, so an audience does not repeatedly decrypt every Teams event
for each customer and purchase. Current candidate records, source review and
actor permissions are still reloaded and checked. The lookup stores no customer
payloads and survives neither requests nor queue jobs; console callers rebuild
it. The existing five-second customer projection bounds refresh after explicit
attribution, while event occurrence/series provenance and unknown refund
coverage remain unchanged.
General event cohorts and audience rules reuse that same five-second confirmed-purchase
projection once per canonical customer, including exact original-source keys.
Each cohort still resolves the current tenant-owned canonical root; marketing
permissions and suppressions are checked separately at preview/send admission.
Reporting does not evict caches or change receipt/rewards eligibility authority.
Empty source-ID sets skip queries rather than loading unrelated channel data.
An actual matched HTTP request also retains its admitted customer projection
while cohort and rule evaluation finish, preventing a slow request from rebuilding
the same history after the shared TTL expires. Every hit still resolves current
tenant/canonical/archive state. A new request, console task or queue job gets no
retained request result; the shared five-second TTL and fresh channel checks stay
unchanged.

Typed event-buyer and captured-contact previews use the same original snapshot
and event-attribution readers in selected-source mode. They select the chosen
event's external originals and retain native receipts for original-order mirror
priority, global source/customer conflict checks, current capture dates and
reviewer permissions. They do not build unrelated lifetime product preferences,
rewards or membership history. Their options read reviewed event identities
without loading the full historical catalog. Each resumed preview batch proves
the current relationship again; canonical identity, contact permission and
suppression checks remain fresh. Custom, product, series and geographic rules
retain their full reporting evaluation and message preparation keeps its normal
eligibility gates. Workspace location coverage groups exact original ZIP/country
bytes before applying the same Census validation, rather than hydrating every
customer. These changes do not extend a cache lifetime or establish live latency.

Server-backed Pay checkout now implements optional canonical-customer selection,
confirmed card, cash and rewards-only completion, audited later customer
association, receipts, and replay-safe stock and reward effects. Original provider
confirmation, saved reservations and the shared rewards ledger remain authoritative.
The earlier read-only implementation milestones above describe the historical
delivery sequence.

Implemented source and fixture checks do not establish physical readiness. Each
tenant still needs reviewed program/register/inventory configuration and its
normal collection and signing gates. Acceptance on the exact deployed release
must prove actual phone checkout, customer attribution, payment and receipt
delivery, earning/refund behavior, settlement and measured performance. The
two-tenant live acceptance matrix remains required before describing the complete
experience as demonstrated.

Source implementation and passing fixture tests must be reported separately
from deployment and real customer acceptance. Only after the full matrix passes
may the pilot be described as demonstrated online/in-person customer history
and rewards. Availability to every tenant is the platform requirement; it is
not a claim that every tenant or provider has already been activated.

## Native Square follow-through (October 31; source candidate)

The customer-linked native checkout and rewards outbox are now implemented in
the focused Everbranch Pay candidate. See `../operations/everbranch-pay-customer-square.md`
for API changes, exact original-provider associations, policy semantics and the
remaining deployment/provider/physical acceptance gates. Earlier outstanding
implementation milestones above describe the read-only release, not live proof.
