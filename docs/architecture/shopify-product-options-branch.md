# Shopify Product Options Branch

Status: implemented as a Shopify-only Everbranch module, initially entitled
for the Modern Forestry tenant.

## Capability classification

- Classification: reusable Shopify-only Everbranch module.
- Tenant scope: rulesets, assignments, installed stores, and product metafield
  writes resolve through the current tenant.
- Entitlement/billing: the existing `shopify_product_options` entitlement is
  required; Modern Forestry's current entitlement is included.
- Canonical contracts: `TenantModuleAccessResolver`, `ShopifyStore`,
  `ShopifyProductOptionsService`, signed app-proxy resolution, Shopify
  line-item properties, and Shopify Functions.
- Non-Forestry use: any entitled Shopify tenant can configure rulesets and
  assigned product handles without code changes.

## Purpose

This branch replaces the current Infinite Options bundle-scent workflow. A ruleset:

- contains the scents available to the customer;
- sets how many required scent selectors appear (`Scent 1` through `Scent N`);
- can require each selected scent to be different; and
- is assigned to one or more Shopify product handles.

The storefront block writes the selections as Shopify line-item properties. Existing order ingestion already parses scent/fragrance line-item properties through `InfiniteOptionsParser`, so the replacement preserves the downstream bundle expansion contract.

## Shopify Admin surface

- Route: `/shopify/app/product-options`
- Navigation label: `Product Options`
- Classification shown in the UI: `Shopify only`
- Initial entitlement: enabled only for tenant slug `modern-forestry`
- Ruleset mutations require a verified Shopify App Bridge session token.

## Storefront surface

- Theme app extension block: `Everbranch scent options`
- Active theme app embed: automatically mounts the same option UI on assigned
  product pages, protecting stores where the optional product block was never
  placed.
- App proxy request: `/apps/forestry/product-options`
- Backing route: `/shopify/marketing/v1/product-options`
- Cart properties: `properties[Scent 1]`, `properties[Scent 2]`, and so on.

The optional product block controls exact template placement. If it is absent,
the active app embed moves the option UI into the visible product form before
its Add to cart / accelerated checkout controls. Rulesets with no matching
product handle stay hidden on the storefront.

## Checkout enforcement

- `everbranch-bundle-scent-validation` is a Cart and Checkout Validation
  Shopify Function. Shopify runs it for cart, standard checkout, Shop Pay,
  PayPal, Google Pay, Apple Pay, and other express checkout paths.
- Each assigned product stores its tenant-owned rule in the JSON metafield
  `everbranch.bundle_scent_rule`. Ruleset saves synchronize the selection
  count, distinct-value requirement, enabled state, and allowed values.
- The initial migrated handles are also compiled into the Function as a
  rollout fallback so the known bundles fail closed before their first
  metafield backfill.
- Preview/backfill with
  `php artisan shopify:sync-product-option-validation --tenant-id={id}` and
  add `--apply` only when the target tenant/store has been verified.

## Initial Infinite Options migration

The migration seeds the seven visible rulesets from the supplied screenshots:

1. Room Spray Bundle — 3 selections
2. Buy 2 Get 1 Free — 3 selections
3. Teacher Candles — 2 selections
4. Build Your Own Flight — 6 selections
5. Bulk Discount Bundles — 12 selections
6. Wax Melt Bundle — 5 selections
7. Bundles with 3 options — 3 selections

The Room Spray Bundle and 4oz three-candle bundle receive the product handles visible in the screenshots. The remaining rulesets are intentionally marked as needing product assignments; their source URLs were truncated in the screenshots.

The initial allowed scent values are the 31 values visible across the supplied Room Spray Bundle dropdown screenshots, including `Room Refresh` and `Violet Spice`. They can be replaced or expanded from the embedded editor without a deployment.

The Modern Forestry mobile product-detail API reads the same assigned ruleset. Existing iOS bundle selectors therefore receive the ruleset count and filtered scents, plus `requireDistinctValues` for rules that require a different scent in every slot.

## Order normalization

`ShopifyOrderIngestor` expands each recognized option-set order line into one
Everbranch order line per selected scent. The normalized line carries the
canonical scent and size IDs, multiplied bundle quantity, and a stable
parent-line/slot external key so re-import is idempotent.

The migrated mappings cover:

- 4oz and 8oz three-candle bundles;
- Teacher Candles in 4oz, 8oz, 16oz, and wax-melt variants;
- five-wax-melt bundles; and
- three-room-spray bundles.

Unexpected selection counts, unknown scents, and unresolved sizes create
Shopify import exceptions instead of silently producing incomplete demand.

## Live activation

- Shopify app version `modernforestrybackstage-35` is released.
- The retail installation has approved `read_validations` and
  `write_validations`.
- Validation `gid://shopify/Validation/121798915` is enabled with
  `blockOnFailure: true`.
- Live Cart AJAX checks block missing selections for active 4oz, 8oz, and
  Teacher products, block duplicates where configured, and accept complete
  valid selections.
- The room-spray product is currently draft and cannot receive a customer-cart
  smoke test until reactivated.
- Full external evidence is stored under
  `docs/operations/evidence/shopify/2026-07-30/`.
