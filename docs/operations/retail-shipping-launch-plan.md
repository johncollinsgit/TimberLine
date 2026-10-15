# Retail shipping launch plan

Native Website Commerce will support a dependable domestic retail flow in five
phases. Missing vendor weights are ordinary catalog setup: merchants receive
specific warnings and a way to resolve them. Products remain browsable, and
pickup remains available when configured. Only the affected shipping cart is
blocked. This plan does not change a Shopify merchant's discount policies.

## Phase 1 Shipping readiness and weights

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

Configure a merchant-owned carrier connection, ship-from address, package
presets, and approved services. Add item weights, box tare, and packing material
correctly; define supported box/quantity rules and separately shipped products.
Repair the EasyPost request envelope. Bind each quote to the exact cart revision,
destination, origin, package, account, currency, and service. Re-rate on material
changes and preserve an immutable order-owned shipping plan.

Acceptance: one item, repeated quantities, and mixed products produce the
measured supported parcel. Unsupported fits stop before payment. Address/cart
changes invalidate eligibility without deleting shipping evidence for an order.
Shared retail themes show the same shipping policy and rates.

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
