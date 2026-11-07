# Pay tenant rewards, customer capture and event follow-through

November 6, 2026 source candidate. This is a focused extension to the shared Pay,
customer-commerce and rewards services. QR, saved-contact authorization, owner
policy publication and rewards-only purchases are implemented behind independent
rollout gates. Installation and physical acceptance remain separate release steps.

## Tenant rules

Every tenant uses its own existing rewards program, label, earning and redemption
rules. `earning_rules.purchase_earn_bps` is optional (0–10000 basis points); absent
or null leaves percentage earning unconfigured. The tenant policy editor displays
percentages. Pay freezes these rules with the original sale and posts through the
existing ledger after confirmed payment and explicit customer association. Existing
milestones and member multipliers remain separately configured. Partial/full
refunds use original terms and the existing allocation/review rules. Receipt and
history reads never award credit.

Modern Forestry's requested caps are $5 per ordinary in-person purchase and $10
for verified active Candle Club members. The proposed percentage is 5%, derived
from its [published FAQ](https://theforestrystudio.com/pages/rewards-program-faq):
one point per dollar spent and 100 points worth $5. Current tenant Backstage
settings have a second-order bonus but no purchase percentage. Legacy points-to-
credit conversion values are not earn rates. Publishing the proposed rule still
requires the tenant's normal policy and register review.

`pos_candle_cash_redemption.member_max_minor` is optional and versioned with the
standard cap. The customer disclosure and proof use the customer's effective cap.
A native active subscription agreement with an explicit canonical-customer link
can establish membership. An assigned owner/admin can also record a current
account review for at most 24 hours (and no later than event tax expiry), or revoke
it from the customer rewards panel. This changes only the cap, never a balance or
join metric; an old tag or a title cannot grant the larger limit. Reservation and start
checks re-read eligibility. Existing ledger and authorization gates remain.

## Operator location

The register list returns an additive `operator_context`. Only registers already
authorized for the current tenant, operator and device are candidates. A reviewed,
current event link, its stock-pool binding and an exact current task assignment
are required. Asana reads use the operator's own selected projects, through either
the personal Teams connection or the existing Events operations connection.
Native Teams uses the authorized work graph. An ambiguous or unavailable source
cannot silently choose a location. A matching reviewed address and unexpired tax
review are required before recommending a default.

A pending sale keeps its original register and immutable tax/location snapshot.
An idle automatic selection follows a newly verified assignment on foreground
refresh; an open basket, customer selection or explicit session choice is preserved.
A new session can prefer the current assignment over an old saved register. Assignment does not authorize an arbitrary
address or rate; the established tax calculator and review remain authoritative.

Macon's November 6–8 event is the CentrePlex, 200 Coliseum Drive, Macon GA 31217,
America/New_York. The Georgia Department of Revenue's chart effective October 1,
2026 lists Bibb County at 8%, matching the event assignment. The exact event,
empty pool and owner-device register have been prepared operationally using the
existing services. Collection remains disabled and stock quantities remain unknown.
Private review documents stay outside source control.

## Customer lookup, history and receipts

The register's Customers control opens a lookup without quoting a basket or
starting payment. Closing it restores the prior checkout customer. Name, email
and phone search and explicit customer creation reuse the canonical directory.
Confirmed linked Pay purchases expose their original location and item/component
snapshots. Staff authority and tenant filtering still apply on every read.

Receipts include the tenant reward name, posted earning, redeemed amount, current
ledger balance with observation time, and available original rules. Pending or
reviewed earning is disclosed. Existing account contact is suggested separately
from saved receipt contact. Saving contact does not send mail. Staff explicitly
choose Send receipt email or Send receipt text at the customer's request.

Email can explicitly use `POS_RECEIPT_EMAIL_TRANSPORT=tenant_gateway` for the
existing tenant email route; its durable intent prevents a resend after uncertain
acceptance. The original platform outbox remains the default transport.

Text receipts use the tenant messaging account resolver, existing suppression
state, an explicit expected phone, and a durable encrypted intent committed before
provider contact. Replays after provider uncertainty do not send again. Provider
acceptance is distinct from delivery. Receipts above the text length limit require
email or native sharing. `POS_RECEIPT_SMS_ENABLED` defaults false pending sender
and received-message acceptance. No receipt request grants marketing consent.

## Event customer cohorts

New customer creation at a current reviewed event records a `pos_event_capture`
source link, with actor, register, event, local capture date and timestamp. Lookup
of an existing customer does not manufacture a new acquisition. The reporting
model attributes to the earliest recorded capture after canonical resolution.
Earlier confirmed purchases exclude a record from the new-customer cohort.

Owners/admins use `/pos/admin/events?tenant=SLUG` to view later confirmed online
customers, orders and paid amounts after recorded refunds. These are operational
USD amounts, not additive accounting revenue or proof of causation. Test payments,
unconfirmed purchases and fully refunded orders do not count. Unknown history and
bounded coverage are disclosed; historical acquisition has not been fabricated.

Membership joins require original subscription-created timestamps and an explicit
tenant/store/customer mapping. The current Recharge adapter reads verified complete
generations through mapped subscription members. Native billing activation of an
existing subscription is not treated as a new join. Missing source timing is shown
as unknown. Modern Forestry currently has no connected Recharge read feed, so
membership-join coverage must be connected and accepted before claiming complete
Candle Club attribution. Until then the larger cap requires current native billing
evidence or the explicit short-lived owner account review above.

## Manual counted stock

Owners can open a product's Adjust stock menu, enter the physical remaining units
and save an absolute count, including products whose saved quantity is positive.
The existing version check rejects intervening activity, and a count cannot fall
below units reserved for open sales. Count component units individually for bundles.

A new POS binding may select `tracking_mode: counted` for its own empty reviewed
pool. This makes unknown quantities require a count and reserves/consumes counted
units, even when the canonical merchandise was historically untracked elsewhere.
Other pools retain their prior behavior. Switching a used binding into this mode,
removing the mode, or using it for online publication is rejected. No initial
quantities are inferred. Counted setup requires protected release before activation.

## Remaining release work

- Protected CI/release precedes enabling `POS_IN_PERSON_REWARDS_ENABLED`,
  `POS_DURABLE_START_CLAIMS_ENABLED` and `POS_VERIFIED_CUSTOMER_LINKING_ENABLED`.
  Tenant policy publication and the reviewed register binding are also required.
  Publication locks actual tenant program/settings rows; the final native handoff
  rechecks those rows, the identity fence, wallet hold and membership cap inside
  its original journal transaction. Neither publication nor a scanned code starts
  a payment. Independent MySQL processes exercise both acquisition orders.
- Customer QR: live terms → explicit cap consent → a 120-second, one-use code →
  cashier preview → explicit amount → original quote hold → confirmed payment.
  Raw codes stay in memory and are removed on background/disappearance; they are
  absent from persisted drafts. Edit/cancel releases the original unstarted hold;
  another authorization is required to spend it on a different quote.
- Browser alternative: `/apps/forestry/rewards/show-code` through the existing
  Shopify app proxy. Only a fresh exact proxy signature and signed customer ID
  authorize a linked wallet. Private consent nonces bind store, subject and terms;
  anonymous/new-account sessions without a proxy customer ID cannot spend a wallet.
- Saved-contact alternative: the customer requests a one-time code to the account's
  saved email/phone. A bounded six-attempt, five-minute challenge precedes the same
  private authorization. A durable send intent survives delivery uncertainty;
  retries do not send a second message. Contact possession is not marketing consent.
- Existing wallet links require an authenticated customer request, independent
  owner review, and separate explicit finalization. Imports, bulk model writes and
  customer merge writers share a durable tenant identity fence with issuance and
  payment admission. Conflicts fail closed; balances are never copied or moved.
- A basket fully covered by rewards completes atomically with wallet and stock
  evidence and no Square descriptor or charge. Its receipt identifies Everbranch
  rewards as the source. Owner return restores the original wallet once and does
  not restock inventory. Zero paid spend earns no percentage or purchase bonus.
- Card-based repeat recognition requires a supported provider identity adapter and
  current provider review. The Square SDK returns payment details after collection;
  a card tap cannot select a customer and reduce that same charge beforehand.
  Do not repurpose Tap to Pay details into marketing profiles or store raw cards.
- Complete the protected CI/release, install the exact signed build on the original
  approved owner phone, review the current merchant evidence and enter physical
  counts. The owner phone was unavailable to this Mac during preparation.
- Verify one real customer across event capture, receipt delivery, the shared
  rewards ledger, customer app, later online purchase and membership source.
  Repeat with another tenant and different rules. Preserve provider, signing,
  merchant/device, original-attempt and receipt evidence throughout.
