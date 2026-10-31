# One customer, purchases across channels, one rewards history

Owner direction recorded October 30, 2026: every Everbranch tenant should have
the capability to connect a payment to one customer entry and track that
customer's online and in-person purchases and rewards together. Modern Forestry
is the first pilot, not a tenant-specific implementation or exclusive entitlement.

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
  refunds; contradictory customer associations are hidden for identity review.
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

The branded Modern Forestry Pay development build is installed. Its local
catalog and basket do not yet submit sales. The live POS candidate remains a
separate draft, and physical payment, canonical-customer association, combined
history, earning/refund and settlement acceptance are not established.

Remaining release milestones: finish the native register's canonical-customer selection
and sale snapshot against the live POS candidate; implement and validate the
configured earning/refund lifecycle without changing historical terms; distribute
the paired native customer updates; and pass the two-tenant live
acceptance matrix before marketing the end-to-end result as demonstrated.

Source implementation and passing fixture tests must be reported separately
from deployment and real customer acceptance. Only after the full matrix passes
may the pilot be described as demonstrated online/in-person customer history
and rewards. Availability to every tenant is the platform requirement; it is
not a claim that every tenant or provider has already been activated.
