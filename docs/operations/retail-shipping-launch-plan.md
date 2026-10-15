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

Use one reusable checkout attempt per cart revision. Align inventory holds with
session expiry and define late/asynchronous payment handling. Verify event
signature, merchant/account, mode, session, currency, and financial components.
Keep payment transitions idempotent and ordered. Validate shipping/billing tax
context, payout accounting, and customer refunds with the merchant's setup.

Acceptance: duplicate submissions create one purchase; the last stock unit
cannot be sold twice; mismatched events cannot mark an order paid; payment,
shipping, tax, inventory, refunds, and settlement reconcile.

## Phase 4 Labels tracking and customer updates

Purchase, print, reprint, and recover labels against the correct order-owned
parcel. Handle charged-but-timed-out requests without duplicate postage. Repair
official EasyPost signature validation, account-scoped shipment matching, event
ordering, and delivery states. Add unused-label refund states, replacement
labels, pack/handoff actions, customer confirmations, tracking, and exceptions.
Use explicit postage/refund permissions and merchant setup/health screens.

Acceptance: staff can move a paid order through packing, a valid USPS label,
carrier acceptance, delivery, and a separately reconciled refund/void scenario.
Customers receive each transactional update once and can track the order.

## Phase 5 Controlled merchant launch

Start with one merchant, one origin, measured SKUs, a small package selection,
standard domestic services, and one published shipping/free-shipping policy.
Verify provider test mode, then run an authorized real paid shipment through
acceptance, delivery, notifications, payout, and final postage reconciliation.
Exercise a pause of new checkout while servicing already-paid orders.

Acceptance: retain the end-to-end evidence, pass the GitHub/Forge release gates,
then enable only the reviewed merchant. Expand from actual operating results.
Account setup and missing vendor data remain visible readiness tasks.

## Scope after the initial launch

Direct USPS, general 3D/multi-parcel packing, international/customs, automated
return labels, subscriptions, gift cards, and broad native discount parity are
separate expansions. The first native carrier path is EasyPost serving USPS.
Shopify-backed clients retain their Shopify commerce ownership.

The constrained pilot is a planning target of roughly two to three engineering
weeks plus merchant onboarding and a physical delivery cycle. This is not a
promise to activate payments or shipping after Phase 1.
