# Native Website Commerce Shipping Operations

## Scope

This is native Website Commerce only: US-domestic shipping, pickup, and local
delivery orders. It does not operate Shopify, Modern Forestry, or a connected
commerce source.

## Carrier lifecycle

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
3. After payment, staff purchase a label from the tenant-owned EasyPost
   account. The package, fulfillment lines, label URL, and tracking link are
   retained in the native shipment ledger.
4. Signed carrier webhooks update the shipment timeline idempotently.
5. Staff may void an unused native label. Returns-label automation, customs,
   duties, and international service are deliberately excluded.

## Daily checks

Review failed/exception shipments, rate availability, labels without tracking,
and webhook verification. Never work around a native Website shipping issue by
altering legacy orders, the Modern Forestry Shopify app, rewards, or its
shipping queue.
