# Native Website Commerce Shipping Operations

## Scope

This is native Website Commerce only: US-domestic shipping, pickup, and local
delivery orders. It does not operate Shopify, Modern Forestry, or a connected
commerce source.

## Carrier lifecycle

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
