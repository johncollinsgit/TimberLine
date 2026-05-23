# Scope Review Evidence

Date: 2026-05-21.
Status: captured initial code-search evidence; final scope decision remains pending.

Related operator docs:
- `screenshot-manifest.md`
- `operator-checklist.md`

## Current TOML Scope Posture

`shopify.app.toml` currently requests a broad scope set including Admin API, customer account, content/theme, fulfillment, metaobject, order edit, price rule, discount, pixel, and analytics/reporting scopes.

Decision status: pending.

No scopes were changed in PR 18.

No scopes were changed in PR 19.

## Runtime Defaults

`config/services.php` defaults to:

```text
read_products,read_orders,read_all_orders,read_reports,read_analytics,read_customers,write_customers,read_discounts,write_discounts,read_pixels,write_pixels,read_customer_events
```

`ShopifyOAuth` also ensures these important scopes are present when building runtime OAuth scopes:

- `read_customers` unless `write_customers` is already present.
- `read_pixels`
- `write_pixels`
- `read_customer_events`

## Code-Search Evidence

Captured with:

```bash
rg -n "SHOPIFY_SCOPES|services\\.shopify\\.scopes|read_products|read_orders|read_customers|write_customers|read_pixels|write_pixels|read_customer_events|read_themes|write_themes|read_content|write_content" config app tests --glob '!vendor'
```

Observed runtime usage includes:

| Scope family | Evidence found | Initial recommendation |
| --- | --- | --- |
| `read_products` | Product lookup, mobile catalog, and Shopify embedded messaging/product surfaces. | likely keep if product/catalog surfaces remain submitted. |
| `read_orders`, `read_all_orders` | Order import, audit, backfill, customer analytics, and birthday/customer sync paths. | keep or justify with privacy/listing language. |
| `read_customers`, `write_customers` | Customer sync, customer metafield sync, provisioning, webhook ingestion. | keep only if customer sync/provisioning is part of submitted app. |
| `read_pixels`, `write_pixels`, `read_customer_events` | Storefront tracking and web pixel setup services/tests. | likely keep if storefront tracking/pixel is submitted. |
| `read_discounts`, `write_discounts` | Runtime default and OAuth tests mention discount scopes. | justify or reduce before public review. |
| `read_reports`, `read_analytics` | Runtime default includes reporting/analytics. | justify or reduce before public review. |
| `read_content`, `write_content` | Marketing page cache command test mentions content scopes. | likely separate/internal unless public app needs content writes. |
| `read_themes`, `write_themes` | Present in TOML; no clear readiness-path runtime evidence in the focused search. | reduce or justify later. |
| Fulfillment/order edit/draft order/metaobject/discovery/customer account scopes | Present in TOML; not clearly proven by focused readiness path. | reduce or justify later. |

## Decision Gate

Before public App Store submission:

1. Build a final scope matrix from code paths and product surfaces.
2. Decide whether public Everbranch Shopify app should be separate from this Modern Forestry internal/alpha app.
3. Reduce or justify broad scopes.
4. Capture Partner Dashboard scope screenshot after the approved scope set is deployed.
5. Run OAuth, install/reinstall, webhook, embedded app, pixel, customer sync, order import, and App Store readiness tests before any scope reduction is released.

PR 19 screenshot slot:
- `12-scope-review-notes.png`

Use this slot for a Partner Dashboard scope screenshot, exported scope notes, or the final signed-off scope matrix when available.
