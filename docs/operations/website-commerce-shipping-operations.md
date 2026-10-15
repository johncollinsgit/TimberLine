# Native Website Commerce Shipping Operations

## Scope

This is native Website Commerce only: US-domestic shipping, pickup, and local
delivery orders. It does not operate Shopify, Modern Forestry, or a connected
commerce source.

## Carrier lifecycle

### Reviewed parcel and rate setup

Save explicit test/production mode and approved USPS services in Native shipping
setup, then a complete US state/DC origin. Review package outside dimensions,
usable inside dimensions after protective packing, box/packing tare, maximum
packed weight, and maximum physical item count. Tare is added once to measured
item weights. The preferred supported preset is tried first, then alternatives
by outside volume. Existing unreviewed presets are skipped.

Measured items must fit a conservative vertical stack with rotation. To use
variants without dimensions, explicitly select a physically tested product mix
and confirm every combination up to the capacity. This approval never supplies
invented measurements or overrides a known oversized item. Mark items that must
ship alone in the product editor. Unsupported carts stop before a carrier request.

Rates use the tenant's EasyPost account and official `shipment` envelope. Only
approved USD services in the configured mode are offered. A cart/address,
catalog/packing, origin/account, service-policy, or free-shipping-threshold change
requires new rates. Checkout also requires matching Stripe and carrier modes.

Cart edits expire quotes but retain their evidence. Checkout copies a complete
shipping plan to the order; labels use the order's accepted parcel and address
even after cart/quote deletion. An unrelated rate, modified address, or different
carrier credential is rejected. Old quotes without a plan must be replaced;
old orders without a reviewed plan need staff review. Original-account postage
recovery, refund states and signed canonical tracking are described below.

### Catalog readiness

Products shows missing-weight warnings for non-archived physical products with
available variants. Use **Add weights** to update several variants, or edit an
individual product. Ounces, pounds, and grams are accepted and rounded upward
to whole stored ounces. Use the weight of one item with its retail packaging;
box tare and packing material belong in package setup.

Missing item weights stop quoting and shipping payment without removing the
product from the catalog or preventing configured pickup. Do not fill guessed
weights to clear a warning. Weight completeness alone does not verify carrier,
package, payment, tax, or signed webhook readiness.

Catalog CSV exports now contain one row per variant and include variant IDs,
names, weights, and dimensions. Import IDs must belong to the target product,
site, and tenant. Omitted or blank measurement cells preserve existing values;
clear a weight in the product editor. Each import is one transaction, at most
1,000 variant rows. Active-product imports respect the publishing freeze.

The staged launch sequence and acceptance criteria are in
retail-shipping-launch-plan.md.

### Provider workflow

For a tenant-allowlisted Pirate Ship manual workflow, use the separate CSV bridge in `sawyer-naturals-demo-and-pirate-ship.md`. It exports paid orders and imports tracking after staff buy the label in Pirate Ship. It cannot quote rates or purchase labels by API.

1. A shopper supplies a US address and chooses an accepted EasyPost rate.
2. Checkout stores the selected rate and address snapshot before Stripe
   payment. No client price is trusted.
3. After verified payment, staff approve postage from the tenant-owned EasyPost
   account. The native attempt and line allocation exist before external calls.
   A preflight outage leaves Resume approved postage available. Once a buy POST
   may have started, Check shipment uses GETs against that same shipment only.
4. Print or download the protected label. Use the no-postage calibration and
   actual-size driver settings in `website-label-printing.md`. A print failure
   retains postage; neither print nor purchase marks an order shipped.
5. Mark packed, then explicitly record physical carrier handoff. Canonical
   carrier acceptance can also confirm fulfillment. Signed account-specific
   events and bounded polling update tracking with precise timestamp ordering.
6. Request an unused-label refund only for an unused, unaccepted label. It is
   retired immediately for local use, while provider approval remains pending.
   Check shipment reconciles submitted/refunded/rejected states. A replacement
   needs a separate rate/cost approval and a fresh check that the old parcel was
   not accepted. A postage refund never refunds the shopper automatically.

Do not reset an ambiguous buy to prepared or create another label to work around
a timeout. If canonical evidence cannot resolve an attempted POST, retain the
attempt and review the original provider account. Ambiguous replacement creation
without a saved provider shipment ID also requires operator/provider review.
Existing recovery, documents and servicing use the original credentials while
new checkout/shipping is paused. Credential rotation saves a new encrypted row;
it never overwrites the key bound to an existing attempt.

`website:reconcile-shipments --limit=100` runs every five minutes. It processes
durable events and original-account GETs only. A missing/altered key, mismatched
provider evidence, or unresolved purchase reports a retry/review failure. It
never activates shipping or initiates a postage purchase.

### Transactional customer updates

`website_order_notifications` is an encrypted, native-only outbox. Payment,
packing, handoff, delivery and exception transitions record deterministic unique
updates in their transaction. The after-commit job carries only a row ID;
`website:dispatch-order-notifications --limit=100` runs each minute to recover
missed queue dispatches. The existing persistent queue worker sends messages.

The global notification gate, tenant allowlist, explicitly approved transactional
mailer/sender, published Website and verified public host must all be ready.
Marketing/Shopify resolvers, customer consent and messaging credits are excluded.
Disabled historical rows stay blocked even after configuration is enabled.

Accepted means the transport accepted a message, not that it reached the inbox.
An uncertain send or expired send lease becomes Unknown and never auto-resends.
On the order, a scoped manager records actual provider acceptance/nonacceptance
evidence. Confirmed nonacceptance does not send: a separate reviewed retry is
required. Local render failures can retry with bounds; stale shipping stages are
superseded so delayed workers cannot announce preparation/shipping after delivery
or an active delivery exception.

The guest tracking link binds the verified store host, tenant/site, order and
56-character token. Keep it private. Customer tracking omits address-bearing
labels, staff notes, billing details and provider payloads.

### Release and activation

Phases 1–3 deployed disabled through PR #397, Forge `80340808`, exact commit
`88bda81a`. Phase 4 adds the outbox table; its migration independently repairs
retained MySQL constraints/indexes after interrupted DDL. Generate/verify the
data-free schema baseline in disposable MySQL and pass the named release checks.
Do not bypass the protected migration gate.

Keep checkout, shipping and notification activation separate from code release.
Phase 5 needs a configured merchant test account, signed-event evidence, real
tax/fee/payout verification, and an explicitly authorized real shipment with
physical printer/barcode, carrier acceptance/delivery and postage reconciliation.
Return-label automation, customs and international service remain excluded.

## Daily checks

Review failed/exception shipments, rate availability, labels without tracking,
and webhook verification. Never work around a native Website shipping issue by
altering legacy orders, the Modern Forestry Shopify app, rewards, or its
shipping queue.
