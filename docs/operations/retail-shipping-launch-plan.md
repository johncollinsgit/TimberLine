# Retail shipping launch plan

Native Website Commerce will support a dependable domestic retail flow in five
phases. Missing vendor weights are ordinary catalog setup: merchants receive
specific warnings and a way to resolve them. Products remain browsable, and
pickup remains available when configured. Only the affected shipping cart is
blocked. This plan does not change a Shopify merchant's discount policies.

## Phase 1 Shipping readiness and weights

Implemented in draft PR #394. Production activation remains separate.

Implementation is isolated to the tenant-owned Website lane.

- Show the number of non-archived physical products with available variants
  missing a positive shipping weight. Include a product-list filter and row
  warnings; services, quote products, archived products, and unavailable
  variants do not create missing-weight warnings.
- Let merchants enter weights per variant in ounces, pounds, or grams in the
  product editor and a paginated bulk weight screen. Existing storage uses whole
  ounces; converted measurements round upward, and the UI states that behavior.
- Preserve omitted measurements during ordinary edits and older CSV imports.
  Expanded catalog CSVs export one row per variant, with stable variant IDs,
  variant names, weights, and dimensions. IDs must belong to the same product,
  site, and tenant. Blank measurement cells preserve existing values; clear
  weights explicitly in the product editor. Import remains transactional and
  limited to 1,000 rows, with product counts deduplicated across variant rows.
- Respect editor availability, active-product publishing freezes, membership,
  tenant/site ownership, and stale product revisions. Bulk weight changes have
  their own audit event and increment catalog revisions.
- Reject missing-weight carts before requesting carrier rates or starting a
  shipping payment. Never substitute a box's tare for an unknown item weight.
  Recheck each current variant at checkout. Shoppers see the affected items and
  available next actions without being asked to configure merchant data.

Acceptance: a merchant finds missing weights, saves measured values, sees the
warning count resolve, and can round-trip every variant's shipping data through
CSV. Invalid, foreign, frozen, or stale updates cannot partially save. Missing
weights block shipping while a pickup checkout still succeeds.

Weight completeness is not full shipping readiness. This phase does not enable
commerce, configure carrier/payment accounts, buy postage, fill vendor weights,
or bypass the existing release and rollout gates. No schema migration is needed.

## Phase 2 Packages and accurate rates

Implementation follows Phase 1 on `codex/retail-shipping-phase-2`.

- Require a merchant-owned EasyPost connection, complete US state/DC origin,
  explicit test/production mode, and approved USPS Ground Advantage, Priority,
  or Express services. Filter provider rates by mode, USD, shipment identity,
  service, and valid nonnegative decimal amount. Match shipping/payment modes.
- Send the official `shipment` request envelope. Packed weight is the sum of
  physical item weights times quantities plus measured box/packing tare once.
- Review each package's outer dimensions, usable inside dimensions, maximum
  packed weight, and maximum physical item count. Try the preferred supported
  package, then the smallest supported alternative. Unreviewed presets cannot
  quote. Use a conservative rotated-item vertical stack for measured items.
- For missing dimensions, allow a merchant-confirmed, physically tested set of
  variants and every supported mix up to the package capacity. Never invent
  measurements. A known oversized item still fails the individual fit check.
  Separately shipped variants cannot share a parcel with other physical units.
- Bind quotes to current tenant/site/cart contents, quantity, price, weight,
  dimensions, separate-shipping flags, destination, origin, package rules,
  provider connection/key fingerprint, mode, currency, services, and published
  free-shipping threshold. Recheck after carrier latency and before checkout.
- Expire quotes on cart edits without deleting evidence. Copy the full plan,
  parcel, carrier/rate/account references, and costs to the order. Use that
  order-owned plan for labels even after cart/quote deletion, while rejecting a
  different quote, altered destination, or changed carrier account.
- Share rate selection across retail themes; clear selected rates on address or
  recipient edits, suppress stale responses, label test rates, and use the same
  published free-shipping threshold in copy and checkout.

Package rules and service policy use the site's existing `settings.shipping`
JSON. Quote context uses existing `parcel` JSON; order-owned evidence uses
`shipping_rate_snapshot`. No database migration or production configuration
change is required. Pre-Phase-2 quotes need new rates; orders without a reviewed
plan require staff review before automatic postage purchase.

Acceptance: one item, repeated quantities, and mixed products produce the
measured supported parcel. Unsupported fits stop before payment. Address/cart
changes invalidate eligibility without deleting shipping evidence for an order.
Shared retail themes show the same shipping policy and rates.

Provider contract references: [EasyPost shipments](https://docs.easypost.com/docs/shipments),
[parcels and units](https://docs.easypost.com/docs/parcels), and
[rates](https://docs.easypost.com/docs/shipments/rates). Contract tests use HTTP
fakes. A configured merchant sandbox and the authorized physical launch remain
separate verification gates; no real postage was purchased during implementation.

## Phase 3 Payment and inventory reliability

Implemented on `codex/retail-shipping-phase-3`, stacked on Phase 2. No migration
or production activation is included.

- Create the order, inventory reservations, and a versioned payment attempt in
  one transaction before calling Stripe. Site/cart locks serialize submissions;
  durable provider idempotency keys recover a lost response against that same
  attempt. Freeze provider requests and checkout URLs in encrypted order data.
  Payment JSON contains identities, accounting, and operation state only.
- Freeze cart/buyer details while an attempt is open. Both storefront themes
  expose resume/cancel actions resolved from the verified host and session cart.
  Only confirmed provider expiry releases stock. Local timestamps never discard
  a still-active hold, and staff cancellation reconciles a payment that wins the
  expiry race. Pending pre-versioned checkouts require manual provider review.
- Use card payments for the initial pilot, explicit 35-minute sessions, and a
  further five-minute recovery buffer. Persist the attempt-specific Stripe
  Customer before the Session request. Recovery lists that customer's Sessions;
  an unresolved provider outage retains holds and reports an actionable failure.
- Pin the native Stripe contract to `2025-06-30.basil`. Use destination charges,
  the connected merchant as settlement merchant and tax-liable account, and the
  configured application fee on the pre-tax products/shipping amount. Shipping
  is a native fixed shipping option, including free shipping, rather than a
  taxable product pretending to be freight.
- Freeze the accepted shipping/local-delivery destination as the attempt's
  saved Stripe Customer shipping address. Physical pickup requires a complete
  active US pickup origin for tax context. Billing is collected separately;
  pure services use the collected billing address. Actual merchant tax settings
  and registrations still require verification during the controlled launch.
- Require the dedicated webhook secret before checkout. Validate timestamp and
  every v1 signature candidate, reject bad JSON, and store PII-free event
  evidence. Bind events to the saved attempt, tenant, session, mode, and nonce;
  retrieve canonical platform-owned Session/PaymentIntent/Customer state rather
  than trusting event totals or arrival order. Validate the currency, product,
  shipping, discount, tax/total equation, tax liability, customer/address,
  destination, on-behalf-of account, and application fee before fulfillment.
- Commit tracked inventory once from the checkout snapshot under row locks.
  Missing/reduced stock or a previously cancelled order produces `paid_review`;
  mismatched provider details produce `payment_review`. Both block fulfillment.
  Late failures/expiry and replayed paid events cannot downgrade confirmed
  payment or decrement inventory after refunds. Capture available charge,
  transfer, balance-transaction, fee, and net references for settlement review.
- Reserve refund balances under the payment lock with per-request UUIDs and
  stable provider idempotency keys. Pending/unknown refunds consume available
  refund balance, but only canonical succeeded refunds increase refunded cents.
  Reverse the merchant transfer and proportionally return the application fee.
  Scheduled reconciliation and refund webhooks repair lost responses. Never
  restock automatically; product returns are a separate operator decision.
- Run `website:reconcile-payments --limit=100` every five minutes through the
  existing scheduler. It rotates bounded native attempts, recovers missed
  events with provider GETs, reconciles refunds, retains uncertain holds, and
  exits unsuccessfully when attempts need another retry. It does not enable
  commerce or start new provider checkout Sessions. Existing payments/refunds
  can be serviced while new checkout is disabled. Staff screens retain refund,
  cancellation, and durable pending-refund retry/check controls during that pause;
  an interrupted request keeps its original UUID and amount after navigation.
  Pickup/tax locations are configurable before carrier rollout; carrier services
  and package mutations remain separately gated.

Acceptance: HTTP-faked tests cover duplicate submission, lost responses, early
webhooks, last-unit holds, mismatches, event reordering, expiry, cancellation
races, pending/replayed refunds, paused checkout servicing, signature rotation,
and host/session scoping. The local verification includes 163 Website and Shopify
surface-isolation tests, a production asset build, and four browser screens at
desktop/mobile sizes. These tests do not establish a real merchant's tax,
provider fees, transfer/payout settlement, or physical USPS delivery; retain those
evidence requirements for Phase 5. See the rollback runbook for hold recovery.

Provider references: [Checkout creation](https://docs.stripe.com/api/checkout/sessions/create),
[Checkout tax address priority](https://docs.stripe.com/tax/checkout/page),
[destination charges](https://docs.stripe.com/connect/destination-charges), and
[refund transfer/application-fee behavior](https://docs.stripe.com/api/refunds/create).

## Phase 4 Labels printing tracking and customer updates

- Purchase, recover, and retain the label against the correct order-owned
  parcel. A charged-but-timed-out request must recover the existing label,
  never buy postage again to make a print retry work. Keep label purchase,
  replacement postage, void/refund, and printing as distinct audited actions.
- Build a printer-independent document path: an actual-size 4x6-inch thermal
  label PDF and a Letter/A4 sheet layout that preserves the carrier label's
  physical dimensions. Verify provider format and page size; an EasyPost PDF
  may contain a 4x6 label on a Letter page. Use the carrier's existing label
  image/conversion where needed rather than shrinking, cropping, redrawing
  barcodes, or repurchasing postage.
- Offer Print label, Download PDF, and Reprint from the stored purchased label.
  Reprint must not call a postage-buy endpoint. Preview the selected media size
  and orientation; provide actual-size, margin, and header/footer guidance.
  Support ordinary operating-system drivers and browser/PDF print dialogs as
  the common path for USB and network printers.
- Add saved workstation print profiles for printer/media/layout/orientation.
  Keep the document and provider layers independent of any one printer brand.
  Target DYMO, Rollo, Zebra, Brother, and ordinary laser/inkjet printers where
  the model and installed driver support the chosen media. Do not advertise
  an entire brand or every model as tested. Narrow printers must not receive
  a scaled-down shipping barcode that requires wider stock.
- The first named hardware test is the user's DYMO LabelWriter 4XL over USB,
  using 4x6 stock. Also define coverage for another thermal printer and a
  Letter/A4 desktop printer. Maintain a support matrix by exact model, media,
  connection, OS/driver, browser, document format, and physical test result.
  Confirm the actual workstation OS during printing implementation.
- Keep an adapter boundary for optional direct-print connectors and native
  ZPL/EPL2 output where both carrier and printer support it. A connector-specific
  setup must not prevent the standard PDF path from working. Direct/silent
  printing requires its own installed connector and compatibility verification.
- Add a no-postage setup/test print and actionable driver, offline, wrong-media,
  and print-dialog-cancel handling. Opening a dialog is a print request, not
  proof that a label reached paper. Separate requested, operator-confirmed,
  failed, and reprinted history. Printer failures never imply carrier handoff.
- Repair official EasyPost signature validation, account-scoped shipment
  matching, event ordering, and delivery states. Add unused-label refund states,
  replacement labels, pack/handoff actions, customer confirmations, tracking,
  and exceptions. Use explicit postage/refund permissions and merchant
  setup/health screens, and protect label documents containing addresses.

Acceptance: staff can move a paid order through packing, a valid USPS label,
printing/reprinting on supported thermal and sheet printers without duplicate
postage, carrier acceptance, delivery, and a separately reconciled refund/void
scenario. Verify exact printed dimensions, no clipped address/barcode, and a
scannable tracking barcode. Customers receive each transactional update once and
can track the order. A physical device test, including the USB DYMO 4XL, is part
of Phase 5 evidence; mocked/browser tests do not claim hardware compatibility.

Printing references: [EasyPost label format and size options](https://docs.easypost.com/docs/shipments/options)
and [DYMO model/OS software compatibility](https://www.dymo.com/user-guide.html).

## Phase 5 Controlled merchant launch

Start with one merchant, one origin, measured SKUs, a small package selection,
standard domestic services, and one published shipping/free-shipping policy.
Verify provider test mode, then run an authorized real paid shipment through
actual-size printing and barcode scan, acceptance, delivery, notifications,
payout, and final postage reconciliation.
Exercise a pause of new checkout while servicing already-paid orders.

Acceptance: retain the end-to-end evidence, pass the GitHub/Forge release gates,
then enable only the reviewed merchant. Expand from actual operating results.
Account setup and missing vendor data remain visible readiness tasks.

## Retail account boundaries before broader onboarding

Keep the native Website shipping release independent of Modern Forestry's
existing Shopify app. A Shopify organization/login, an individual store's app
installation and staff roles, an Everbranch tenant subscription, the shopper's
account, and the tenant's Stripe Connect payout account are separate boundaries.
Native shopper checkout does not require a Shopify admin or Everbranch staff login.

The 2026-10-15 read-only review found that the legacy embedded Shopify lane uses
global Modern Forestry retail/wholesale slots. Unrelated retailers must use a
separate tenant-owned connection/onboarding path. Before public Shopify client
onboarding, confirm app distribution, implement the appropriate Shopify app
billing lane, map staff capabilities, and prove token refresh/uninstall/reinstall
and cross-store isolation. Do not repurpose Modern Forestry's installed app,
credentials, store slots, or billing to perform that work. These onboarding
requirements do not activate or gate the disabled native shipping deployment.

References: [Shopify identity tokens](https://shopify.dev/docs/apps/build/authentication-authorization/id-tokens),
[distribution](https://shopify.dev/docs/apps/launch/distribution/select-distribution-method),
[app pricing](https://shopify.dev/docs/apps/launch/billing/shopify-app-pricing), and
[offline token lifecycle](https://shopify.dev/docs/apps/build/authentication-authorization/migrate-to-expiring-offline-access-tokens).

## Scope after the initial launch

Direct USPS, general 3D/multi-parcel packing, international/customs, automated
return labels, subscriptions, gift cards, and broad native discount parity are
separate expansions. The first native carrier path is EasyPost serving USPS.
Shopify-backed clients retain their Shopify commerce ownership.

The constrained pilot is a planning target of roughly two to three engineering
weeks plus merchant onboarding and a physical delivery cycle. This is not a
promise to activate payments or shipping after Phase 1.
