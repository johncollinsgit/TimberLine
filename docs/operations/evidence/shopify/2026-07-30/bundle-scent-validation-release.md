# Bundle Scent Validation Release Evidence

Date: 2026-07-30  
Store: `modernforestry.myshopify.com`  
Primary storefront: `https://theforestrystudio.com`  
App: `Modern Forestry Backstage` (`modernforestrybackstage`)

## Incident evidence

- Orders `#32775` and `#32793` each contained the `3 (4oz) Soy Candle Bundle`.
- Shopify Admin GraphQL returned no custom attributes for either bundle line.
- The Everbranch app proxy returned enabled ruleset `7`, `Bundles with 3 options`, with three required distinct scent selections and 31 allowed values.
- Before this release, the live product page did not render the Everbranch option selectors even though the proxy assignment existed.

## Shopify CLI release

The following command was run from the canonical repository:

```text
shopify app deploy --no-color
```

Released app versions:

| Version | Dashboard version ID | Result |
| --- | --- | --- |
| `modernforestrybackstage-34` | `1070468661249` | Released successfully; added `everbranch-bundle-scent-validation` and updated the existing theme/pixel extensions. |
| `modernforestrybackstage-35` | `1070469578753` | Released successfully; added the validation access scopes to the app configuration. |

Version 35 dashboard:

`https://dev.shopify.com/dashboard/2353831/apps/324577984513/versions/1070469578753`

## Live storefront smoke evidence

The live 4oz bundle HTML now includes:

- `everbranch-product-options`
- `Choose your scents`
- `product-scent-options.css`
- `product-scent-options.js`

The live app proxy request:

```text
GET https://theforestrystudio.com/apps/forestry/product-options?handle=4oz-3-soy-candle-bundle-save-on-three-soy-candle-by-modern-forestry
```

returned HTTP 200 with ruleset `7`, `option_count: 3`, `require_distinct_values: true`, and the current allowed scent values.

## Checkout validation activation

Status: `active_and_verified`.

- The retail app installation was reauthorized with `read_validations` and `write_validations`.
- Shopify validation `gid://shopify/Validation/121798915` was created with title `Everbranch required bundle scents`.
- Shopify reports `enabled: true`, `blockOnFailure: true`, function title `everbranch-bundle-scent-validation`, and API type `cart_checkout_validation`.

## Live server-side smoke evidence

Anonymous storefront Cart AJAX requests were made against variant `32171680530506`:

| Attempt | Result |
| --- | --- |
| No line-item scent properties | HTTP 422: bundle requires three scent selections. |
| `Lavender` in all three positions | HTTP 422: bundle requires a different scent for each selection. |
| `Lavender`, `River Birch`, and `Lava Rock` | HTTP 200 with all three `Scent` properties present on the returned cart line. |

The rejected attempt creates no cart line. This is server-side Shopify Function enforcement rather than a theme-only browser check, so accelerated checkout cannot bypass the required selections.

No Shopify client secret or access token is stored in this evidence.
