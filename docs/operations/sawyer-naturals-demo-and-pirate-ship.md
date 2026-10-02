# Sawyer Naturals demo and Pirate Ship bridge

## What this branch prepares

`feature/sawyer-naturals-demo-2026-10-01` adds a tenant-owned Sawyer Naturals site, a 62-product source catalog, 91 locally bundled and lightly enhanced source images, a responsive storefront, cart, passwordless shopper account, editable contact details, and private order history. The seed command makes `johncollinemail@gmail.com` a Sawyer tenant admin. It does not invent Sawyer's personal email or create his user yet. The public website remains private until the normal publishing and rendering gates pass.

The source catalog is `resources/data/sawyer-naturals-catalog.json`. Its source URLs and retrieval date are included there. Product names, categories and displayed prices came from the public Sawyer site on 2026-10-01 and need merchant review before a public launch. Gift card redemption and apparel size availability are unverified, so those catalog entries are visible but unavailable to buy. Do not infer inventory quantities from source pages.

## Prepare and review

1. Run migrations and the existing Managed Website release checks in the target environment.
2. Run `php artisan everbranch:prepare-sawyer-naturals --grant-demo-access` to create the tenant, the requested admin membership, the draft theme, and catalog. The command is idempotent. The trial entitlement is an audited demo grant with no billing.
3. Review the draft site, photos, copy, product prices, availability, returns terms, shipping threshold, and image rights with Sawyer. Correct any catalog details through the tenant Website/Product editors. Add Sawyer's account only after receiving his email.
4. Configure a verified Sawyer host and the tenant's DNS. Publishing uses `--publish` only after review, with the existing editor, publishing, rollout, entitlement, and public-render gates. `MANAGED_WEBSITE_COMMERCE_PREVIEW_TENANT_IDS` can permit only Sawyer's catalog/cart while `MANAGED_WEBSITE_COMMERCE_ENABLED=false` keeps checkout closed. Do not put unrelated tenants in the preview allowlist. Keep checkout closed until Stripe Connect, tax, signed webhook, and shipping readiness checks pass.
5. Smoke test home, shop/category/search, product, bag quantity updates, shopper email-link sign-in, private order history, checkout address/rates, payment webhook, staff order operations, and rollback on the verified host.

Do not bypass GitHub Actions' test/build gate or Forge's release mechanism. No Sawyer production host, payment credentials, merchant approval, or DNS change is supplied by this branch.

## Shipping options

Native Website shipping already supports tenant-owned EasyPost USPS/UPS rates, label purchase, and tracking behind its separate gate. This branch adds an optional Pirate Ship **spreadsheet bridge** for paid, unfulfilled, shippable Website orders. Pirate Ship says it has no public API, so the bridge makes no API or browser-automation claim. It does not purchase labels or fetch rates. Its export is a Pirate Ship address upload CSV; staff purchase labels in their own Pirate Ship account, export shipment history, then import a CSV with `Order ID`, `Tracking Number`, `Carrier` (`USPS` or `UPS`), and optional `Service` headers. Keep the Everbranch order ID in the Pirate Ship order/reference field so it survives export. The bridge validates the whole file, enforces tenant/site scope, and records native `website_*` fulfillment, shipment, and event rows once. It never writes Shopify or legacy orders.

Default-off controls are `MANAGED_WEBSITE_PIRATE_SHIP_BRIDGE_ENABLED` and `MANAGED_WEBSITE_PIRATE_SHIP_BRIDGE_TENANT_IDS`. Set both only for the reviewed Sawyer tenant. The bridge also needs the tenant's Website Commerce operations gate and tenant admin access. Export only paid orders; importing a tracking number is a staff assertion that a real label was purchased. Reconcile each import against Pirate Ship before using it as fulfillment evidence.

Pirate Ship account credentials must be created or supplied by the merchant in Pirate Ship; the bridge needs no Pirate Ship API key. If direct USPS APIs are later required, create a USPS Business Account and developer app for consumer credentials. USPS label APIs additionally require USPS Ship/payment approval. Store secrets in the protected environment, never in the repo. Do not request or fabricate credentials in code.

## Shop Pay preview

The Sawyer cart shows a clearly disabled Shop Pay feasibility preview. Shopify's Shop Pay Wallet documentation permits integration with an external ecommerce site, but it requires a Shopify store, completed Shopify Payments signup, Shop sales channel setup, allowed origins, Shop ID/client ID, Storefront/Admin API credentials, and end-to-end order reconciliation. Those merchant-owned steps are outstanding. Evergrove staff login and Sawyer shopper login do not authenticate a Shop Pay customer; a real integration would use Shopify's own account verification. The preview does not collect payments.

## Coverage and launch gaps

| Area | Current branch |
| --- | --- |
| Website pages, brand, photos, catalog, categories, search, cart | Demo ready for merchant review |
| Tenant admin, product editing, order operations, refunds, fulfillment, inventory primitives, native shipping | Existing Everbranch Website Commerce plus Sawyer admin seed; normal entitlements and gates apply |
| Shopper login, editable contact details, order list/detail | New passwordless, site-scoped flow; email delivery must be configured in target environment |
| Pirate Ship | CSV order export/tracking import; label purchase remains in Pirate Ship |
| Gift cards and apparel | Listed; selling blocked until redemption, denomination, size, and inventory behavior are verified |
| Payment, tax, live shipping, Shop Pay, Sawyer owner account, verified domain | Require merchant setup, credentials/approval, and release validation |

This is a focused retail demo, not a claim of parity with every Shopify feature. Evaluate any additional requested Shopify capability against the existing Website Commerce module before promising a cutover.

## Sources

- Sawyer home and catalog: https://www.sawyernaturals.com/ and https://www.sawyernaturals.com/shop-all-products
- Pirate Ship API status: https://support.pirateship.com/en/articles/2309246-does-pirate-ship-have-an-api
- Pirate Ship spreadsheet upload: https://support.pirateship.com/en/articles/1068428-how-do-i-upload-address-spreadsheets-into-pirate-ship
- Pirate Ship shipment export: https://support.pirateship.com/en/articles/4143612-can-i-export-a-report-of-my-shipment-history
- USPS developer setup: https://developers.usps.com/getting-started
- Shop Pay Wallet setup: https://shopify.dev/docs/api/commerce-components/pay
