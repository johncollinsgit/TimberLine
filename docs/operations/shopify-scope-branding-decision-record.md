# Shopify Scope And Branding Decision Record

Date: 2026-05-21.
Status: Current internal/alpha Shopify app identity confirmed; PR 18 captured read-only CLI app-info and initial scope evidence. Public Everbranch branding and final scope decisions remain pending.

## Purpose

Document the Shopify App Store scope and branding decisions that must be made before public Everbranch submission. This record is intentionally evidence-first: it does not change OAuth behavior, scopes, app identity, billing, tenant resolution, modules, or privacy deletion behavior.

## Current Shopify App Identity

Source: `shopify.app.toml`.

| Field | Current value | Decision status |
| --- | --- | --- |
| Client ID | `197d01d6597c938c96b3b35fae6a087c` | Existing app reference; do not rotate casually. |
| App name | `Modern Forestry Backstage` | Confirmed current target app for internal/alpha evidence. |
| Handle | `modernforestrybackstage` | Confirmed current TOML/Partner app handle for internal/alpha evidence. |
| App URL | `https://app.theeverbranch.com/shopify/app` | Locally verified; external evidence pending. |
| Embedded app | `true` | Locally verified; external evidence pending. |
| Dev store | `modernforestry.myshopify.com` | Confirmed current dev store by TOML and PR 18 `shopify app info`; external install/reinstall evidence pending. |

Current posture:
- Use `Modern Forestry Backstage` when looking for the app in the Shopify Partner/dev dashboard.
- Use `modernforestrybackstage` as the current TOML handle.
- Use `modernforestry.myshopify.com` for the current dev-store evidence path.
- This is the internal/alpha Shopify app for the Modern Forestry / Backstage context connected to this repo.
- Everbranch remains the platform/product direction, but the public Shopify App Store app branding is not decided yet.
- Do not rename the app or handle until the public App Store strategy is decided.
- Do not deploy if the Partner Dashboard app identity does not match `Modern Forestry Backstage` / `modernforestrybackstage`.

## Current TOML Scopes

Source: `shopify.app.toml`.

```text
customer_read_customers
customer_read_draft_orders
customer_read_metaobjects
customer_read_orders
customer_write_customers
customer_write_orders
read_all_orders
read_analytics
read_assigned_fulfillment_orders
read_content
read_custom_pixels
read_customer_events
read_customers
read_discounts
read_discounts_allocator_functions
read_discovery
read_draft_orders
read_merchant_managed_fulfillment_orders
read_metaobject_definitions
read_metaobjects
read_order_edits
read_orders
read_pixels
read_price_rules
read_products
read_reports
read_themes
read_third_party_fulfillment_orders
write_assigned_fulfillment_orders
write_content
write_custom_pixels
write_customers
write_discounts
write_discounts_allocator_functions
write_discovery
write_draft_orders
write_merchant_managed_fulfillment_orders
write_metaobject_definitions
write_metaobjects
write_order_edits
write_orders
write_pixels
write_price_rules
write_products
write_themes
write_third_party_fulfillment_orders
```

## Runtime Defaults And Known Runtime Needs

Runtime default from `config/services.php`:

```text
read_products,read_orders,read_all_orders,read_reports,read_analytics,read_customers,write_customers,read_discounts,write_discounts,read_pixels,write_pixels,read_customer_events
```

Known runtime usage from code search:

| Scope family | Known code path | Current recommendation |
| --- | --- | --- |
| `read_products`, `write_products` | Product lookups, Modern Forestry mobile catalog, embedded messaging product lookup, and alpha/internal mobile variant-media sync for Shopify variant images | Keep for Modern Forestry alpha while the app owns product/variant media repair; review before public App Store submission. |
| `read_orders`, `read_all_orders` | Order import, audit, backfill, marketing/customer analytics | Keep or justify with clear retention/privacy language. |
| `read_customers`, `write_customers` | Customer sync and customer metafield/provisioning services | Keep only if customer sync/metafield write path is part of App Store scope; otherwise separate internal app may be safer. |
| `read_pixels`, `write_pixels`, `read_customer_events` | Web pixel connection/bootstrap/setup services | Keep if storefront tracking/pixel is part of the submitted app. |
| `read_reports`, `read_analytics` | Runtime default includes analytics/reporting; exact App Store need needs final review | Pending justification. |
| `read_discounts`, `write_discounts` | Runtime default includes discounts; final submitted module scope needs review | Pending justification. |
| `read_content`, `write_content`, `read_themes`, `write_themes` | Content/theme access appears broad relative to current flagship readiness | Reduce or justify later. |
| Fulfillment/order edit/draft order/metaobject/discovery/customer account API scopes | Present in TOML but not clearly proven as required by current Everbranch App Store path | Reduce or justify later. |

## Scopes That Appear Broad Or Unproven

These should not be kept for public App Store submission unless a concrete runtime path, customer value, and privacy/listing explanation is approved:

- `customer_read_draft_orders`
- `customer_read_metaobjects`
- `customer_read_orders`
- `customer_write_orders`
- `read_assigned_fulfillment_orders`
- `read_content`
- `read_custom_pixels`
- `read_discounts_allocator_functions`
- `read_discovery`
- `read_draft_orders`
- `read_merchant_managed_fulfillment_orders`
- `read_metaobject_definitions`
- `read_metaobjects`
- `read_order_edits`
- `read_price_rules`
- `read_themes`
- `read_third_party_fulfillment_orders`
- `write_assigned_fulfillment_orders`
- `write_content`
- `write_custom_pixels`
- `write_discounts_allocator_functions`
- `write_discovery`
- `write_draft_orders`
- `write_merchant_managed_fulfillment_orders`
- `write_metaobject_definitions`
- `write_metaobjects`
- `write_order_edits`
- `write_orders`
- `write_price_rules`
- `write_themes`
- `write_third_party_fulfillment_orders`

## PR 18 Scope Evidence Captured

Read-only PR 18 evidence:
- `shopify app info --path . --client-id 197d01d6597c938c96b3b35fae6a087c --no-color` confirmed the current app name, client ID, dev store, and broad access scope set.
- `docs/operations/evidence/shopify/2026-05-21/scope-review-evidence.md` records the initial code-search comparison between TOML scopes and runtime usage.
- No scopes were changed.
- No Shopify app deploy/release was run.

## Scope Decision Status

Decision: pending for public App Store submission. Approved alpha/internal exception on 2026-06-23: add `write_products` so the Modern Forestry app can attach canonical 4oz, 8oz, 16oz, wood-wick, and wax-melt images to Shopify product variants used by the native mobile product detail page.

Recommendation:
1. Do not change scopes in PR 18.
2. Build a scope matrix before App Store submission.
3. Prefer reducing TOML scopes to the runtime defaults or a smaller approved set unless a Shopify App Store feature requires the broader scope.
4. Run OAuth, install/reinstall, webhook, embedded app, pixel, customer sync, order import, and App Store readiness tests before any scope reduction is deployed.
5. Capture Partner Dashboard screenshots after the approved scope set is deployed.

Risk of changing scopes:
- Existing installs may need reauthorization.
- Pixel/customer/order import paths may break if required scopes are removed.
- Partner Dashboard and CLI app version state may drift from Laravel runtime config.
- Broad scopes can trigger App Store review rejection or privacy policy gaps if not justified.

2026-06-23 implementation note:
- `write_products` is required by Shopify Admin GraphQL for `stagedUploadsCreate`, product media creation, and product variant media attachment.
- The existing installed Modern Forestry token only had `read_orders,read_all_orders,read_customers`, so product media writes failed with `Access denied for stagedUploadsCreate field`.
- After changing TOML/runtime scopes, deploy the Shopify app config and reauthorize `/shopify/reinstall/retail` so Laravel stores a token with `write_products` before running `php artisan shopify:sync-modern-forestry-variant-media --store=modernforestry.myshopify.com --transport=admin-token --apply`.

## App Name And Handle Options

Everbranch product direction:
- Everbranch is the public platform/product brand.
- Modern Forestry is the flagship tenant and alpha/internal customer context.

Current app identity:
- `Modern Forestry Backstage`
- `modernforestrybackstage`

Options:

| Option | Description | Pros | Risks |
| --- | --- | --- | --- |
| A. Keep Modern Forestry Backstage for alpha/internal app | Preserve current Shopify app identity while readiness remains internal. | Lowest immediate operational risk; avoids install/review continuity changes. | Confusing for public Everbranch App Store submission. |
| B. Rename to Everbranch before public App Store submission | Update name/handle/listing to match platform brand. | Best public brand continuity. | May affect Partner Dashboard continuity, extension state, review artifacts, and installed-store expectations; needs deliberate timing. |
| C. Create separate Shopify apps later | Keep Modern Forestry/internal app separate and create public Everbranch app for App Store path. | Clean separation between tenant-specific alpha and public SaaS app. | More operational overhead, duplicated configuration, and migration/install planning. |

## Branding Decision Status

Current internal/alpha identity: confirmed.

Public Everbranch App Store branding decision: pending.

Recommendation:
- Keep the current TOML app name/handle unchanged in PR 18.
- Capture evidence against `Modern Forestry Backstage` and `modernforestry.myshopify.com` for now.
- Decide between Option B and Option C before public App Store submission.
- Capture Partner Dashboard implications before changing handle/name.
- If the app remains internal/alpha, document that `Modern Forestry Backstage` is intentional and not public Everbranch branding.

## Required Evidence Before Scope Or Branding Changes

- Shopify Partner Dashboard screenshot of current app identity and scopes.
- Shopify CLI app deploy/diff/status output for the current TOML.
- Dev-store install/reinstall evidence.
- Privacy webhook delivery evidence in `shopify_privacy_webhook_events`.
- App proxy evidence from a dev storefront.
- Local tests:
  - `tests/Feature/Everbranch/ShopifyAppStoreReadinessTest.php`
  - `tests/Feature/Everbranch/ShopifyPrivacyWebhookReadinessTest.php`
  - `tests/Feature/ShopifyAuthDomainMigrationTest.php`
  - `tests/Feature/ShopifyCommercializationPagesTest.php`
  - `tests/Feature/ShopifyWebhookSubscriptionEnforcementTest.php`

## Non-Goals For PR 18

- No Shopify OAuth behavior changes.
- No Shopify scope changes.
- No app name or handle changes.
- No Shopify Billing, Stripe billing, checkout, charges, or subscriptions.
- No module entitlement changes.
- No automated privacy deletion/redaction.
