# Shopify Product Options Branch

Status: implemented for the Modern Forestry tenant as a Shopify-only Everbranch module.

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
- App proxy request: `/apps/forestry/product-options`
- Backing route: `/shopify/marketing/v1/product-options`
- Cart properties: `properties[Scent 1]`, `properties[Scent 2]`, and so on.

Add the app block to the product template after deploying the Shopify app extension. Rulesets with no matching product handle stay hidden on the storefront.

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

## Live activation dependency

The locally stored `retail` OAuth token belongs to the retired `modernforestry-test.myshopify.com` shop and returns HTTP 404. The live `modernforestry.myshopify.com` store returns HTTP 401 for that token, confirming that the app must be reauthorized against the live store before Admin API product discovery can run.

After the web app and Shopify extension are deployed:

1. Open `/shopify/reinstall/retail` and complete OAuth for `modernforestry.myshopify.com`.
2. Open Everbranch from Shopify Admin and select `Product Options`.
3. Paste or confirm the product handles for the five unassigned rulesets.
4. Add the `Everbranch scent options` app block to the relevant product template(s).
5. Test a bundle add-to-cart and confirm `Scent 1...N` appear on the Shopify cart line and order.
