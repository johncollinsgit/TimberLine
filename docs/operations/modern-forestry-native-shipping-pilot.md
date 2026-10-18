# Modern Forestry native shipping pilot

## Destination and source

The owner selected Modern Forestry on October 15, 2026 and explicitly requested
bringing its Shopify products and weights into the pilot through Shopify CLI.
Use existing tenant `modern-forestry` (ID 1), native site ID 1. Its published
landing page and included host already exist. Do not create a duplicate tenant,
change the published site snapshot, switch DNS, or repurpose its Shopify app.

The initial read-only check found no native products, orders, shipments,
ship-from locations, package presets or payment account. The Managed Website
editor entitlement exists; checkout/shipping and tenant rollout are disabled.
Recheck actual state before applying any preparation command.

Read the product catalog only from `modernforestry.myshopify.com`. Shopify CLI
3.92.1 authenticated successfully and verified that exact shop and USD currency.
Shopify reports 421 products and 1,819 variants at initial capture. Export all
pages and verify final completeness and identity; these counts are observations,
not constants that justify truncating a later catalog.

Retain stable source GIDs, variants/options, prices/compare-at prices, SKUs,
images, inventory snapshots and recorded measurement units. Only typed
measurement metafield values are relevant; do not request or retain arbitrary
reviews, app-private JSON, customer/order records or credentials. Source is
read-only. Imported native products/collections stay draft, and unsupported
gift cards, selling plans, bundle components, mixed shipping semantics or
validation limits must be reported rather than silently converted.

Source-GID mappings and encrypted provenance provide idempotency. Reimports
must detect operator edits, preserve manual measurements and reject cross-tenant
targets. Do not overwrite inventory once native orders or holds exist. Preserve
unknown/negative/incompatible source values in evidence and report limitations.

## What the import establishes

The draft catalog lets staff inspect real products, prices, images and recorded
weights. Positive weights convert to whole ounces conservatively, using the
shared shipping conversion. Missing/zero weights remain missing and visible;
never derive them from a product title, net-fill size, price or another SKU.

Shopify's ordinary inventory measurement exposes weight, not a package's three
dimensions. Typed dimension metafields require known axis meaning. Unreviewed
dimensions stay incomplete; a merchant-tested packing capacity can establish
fit without guessing individual product dimensions.

Inventory is a point-in-time snapshot, not a shared-stock integration. Allocate
and review pilot stock before activation; ongoing Shopify sales do not decrement
the native copy. Prices/compare-at values do not reproduce Shopify discount
codes, automatic discounts, subscription pricing, gift-card balances or checkout
functions. Shopify retains its existing commerce behavior.

## Release and merchant proof

1. Release the reviewed schema reconciliation and verify production fingerprint.
2. Release/retest Phase 4 disabled, including scoped draft-catalog preparation.
   Apply only a complete reviewed snapshot, under an active tenant manager's
   audited identity, to this existing site. Confirm products remain draft and
   no public snapshot, billing, rollout or Shopify record changed.
3. In secure native setup, establish the merchant's Connect/tax readiness,
   reviewed origin, measured pilot SKUs, packaging/tare and supported services.
   Configure a dedicated carrier connection/mode and per-account webhook secret,
   plus an approved native transactional sender. Do not borrow unrelated
   provider accounts or switch the shared Stripe platform's production key to
   test mode. Sandbox work needs isolated test credentials/environment.
4. Collect signed provider test evidence and verify tax, fees, transfers,
   payout expectations and recovery. Run an explicitly reviewed paid shipment
   only once actual recipient, parcel, service and cost are known.
5. Verify exact printer/OS/driver/media combinations, barcode scanning, carrier
   acceptance and delivery, notifications, final postage and settlement. Enable
   only the reviewed merchant after this evidence is retained.

## Hardware preparation

The first target is USB DYMO LabelWriter 4XL on both Mac and Windows, using the
original label's exact 4×6 PDF through ordinary OS drivers. At the initial check
this Mac was macOS 15.7.3 arm64, with no connected USB DYMO or configured DYMO
queue. Existing Brother/HP queues do not prove physical print compatibility.

DYMO's official download page lists Connect for Desktop 1.6.3 for macOS 14+
and Windows 10+, including 4XL support. Verify the current supported download
when installing. Use the no-postage calibration page first, actual size/100%,
correct media and orientation; then measure the output and scan a real label's
barcode. A browser dialog closing is not print-success evidence. Record manual
confirmation or failure; a reprint never purchases postage.

Also verify another exact thermal model and Letter/A4 office printing. Standard
PDF support does not establish support for every device in a printer family.
Direct/silent/ZPL/EPL adapters remain separate work.

References: [DYMO downloads](https://www.dymo.com/support?cfid=user-guide),
[4XL user guide](https://download.dymo.com/UserManuals/labelwriter%20user%20guides/LabelWriter4XL_UserGuide.pdf),
and `website-label-printing.md`.
