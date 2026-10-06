# Connected Commerce Migration Runbook

## Default: connected operations

Use this path for a retailer that wants Everbranch operational branches while
retaining Shopify, WooCommerce, Squarespace, or Wix as the storefront,
checkout, payment, and fulfillment authority.

1. Enable the imports gate only for an internal or approved
   non-Modern-Forestry tenant.
2. Create a source-specific mapping report and select only the approved
   categories: catalog, inventory, customers, orders, fulfillment, content,
   and consent evidence.
3. Review provider capability warnings. Unsupported pages, navigation, blog
   content, media, or tracking must be reported, never fabricated.
4. Confirm the report records no write-back, no native Website tables, and no
   marketing enrollment.
5. Keep the source platform authoritative. There is no two-way sync in v1.

## Cutover proposal, not activation

A native Website Commerce cutover is never implicit. Record owner approval
after import reconciliation, Stripe Connect and tax readiness, EasyPost
connection and fulfillment-location validation, and production-site preview.
It remains reversible until the customer site is published. Modern Forestry is
outside this runbook: do not reuse its Shopify app install, scopes,
credentials, checkout, customers, rewards, Candle Club, or fulfillment data.

## Incident response

Pause the tenant import lane, preserve mapping and audit events, and disable
the imports allowlist if any mapping, isolation, or consent issue occurs.
Prove no records escaped the read-only commerce lane before resuming.
