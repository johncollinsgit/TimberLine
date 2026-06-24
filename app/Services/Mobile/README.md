# Mobile Catalog Services

This folder owns the Laravel-side mobile catalog source of truth for the Modern Forestry iOS app.

## Variant Media Sync Notes (2026-06-23)

- The canonical Shopify media sync is `php artisan shopify:sync-modern-forestry-variant-media`.
- Use `--transport=cli` when you want the Shopify CLI-backed admin session, or keep the default `--transport=admin-token` for the installed-store Admin token path.
- The command now knows about `wood_wick_8oz` and `wood_wick_16oz`, and it maps those to `/Users/johncollins/Downloads/Wood Wick.png`.
- `wax melt`, `wax melts`, `melt`, `melts`, `wax tart`, `wax tarts`, `soy tart`, and `soy tarts` all normalize to `wax_melt`.
- If Shopify says media are not ready yet, the command polls for readiness before attaching them to variants and skips variants that are already linked.
- The live catalog response for Coffeehouse now proves the canonical image attachments exist on Shopify, including the wood-wick assets and wax-melt assets.
- Future edits should keep the variant payload fields stable because the iOS product page now depends on `imageUrl`, `compareAtPrice`, `available`, and `selectedOptions` to feel like a real shopping app.

## Home Payload + Session Contract (2026-06-21)

- `ModernForestryMobileProductCatalogService.php` now extends the mobile home payload with:
  - a `brand` block
  - hero slideshow metadata resolved from the live theme snapshot in `modernforestry-live-theme/templates/index.json`
  - the same featured collections and featured products contract used by the native app
- Collection detail queries now include collection image data so Shop headers and collection cards can stay in sync more reliably.
- `ModernForestryProductCatalogController.php` now exposes a lightweight `/api/mobile/v1/modern-forestry/session-status` endpoint for future mobile session checks.
- The fake-catalog tests were updated so the new slideshow/session contract stays covered.

## Native Hero + Routing Follow-up (2026-06-21)

- No new Laravel contract was required for the native-home portrait/routing pass in the iOS app.
- The existing slide URL fields remain the routing source of truth:
  - `ctaUrl`
  - `secondaryCtaUrl`
- The Swift client now interprets those URLs into native destinations on-device, so changes to hero behavior should usually happen in `modernforestry-build/` unless the slide content itself needs to change here.
- Keep this service focused on payload quality and stable storefront URLs; do not move native tab-routing logic into Laravel.

## What lives here

- `ModernForestryMobileProductCatalogService.php`: builds the collection-first catalog payload for the phone app.
- Related mobile catalog helpers and tests that keep the iOS Shop tab fed with curated collection data.

## What this folder does

- Resolves collections for the iOS app.
- Prefers collection artwork first, then best-selling product imagery when Shopify omits collection art.
- Keeps the mobile app focused on rendering and navigation instead of catalog logic.

## What this folder does not do

- It does not own the iOS UI.
- It does not store customer records or Candle Club points.
- It does not replace Shopify as the commerce platform.
- It does not implement messaging, checkout, or account login persistence.

## Current mobile-pass notes

- The latest pass tightened image fallback so phone shelves stay polished even when Shopify collection art is missing.
- The mobile catalog remains collection-first so the app can open into the seasonal shop experience instead of a raw product feed.
- Home is now the first mobile surface that should be extended when brand, slideshow, or featured shelf content changes.
- Future catalog changes should start here before duplicating logic in the app.

## Catalog + Rewards Cleanup (2026-06-22)

- `ModernForestryMobileProductCatalogService.php` now returns canonical seasonal collections explicitly instead of relying on “first 4 collections.”
- Seasonal collection payloads now resolve in this order:
  - collection image
  - first active product image inside that collection
  - stable seasonal fallback image
- Collection-product payloads now:
  - filter inactive products out of the mobile response
  - accept a `sort` query parameter
  - support exactly `best_selling`, `newest`, `price_low_to_high`, and `price_high_to_low`
- Home featured products now rank by imported Modern Forestry order-line purchase quantity first, then use Shopify best-selling only as a fallback if fewer than six active, published, available products survive.
- Product summary payloads now expose `variantId` for the first available Shopify variant so Home, Shop, and collection cards can feed native bag/checkout flows safely.
- Mobile checkout is guest-capable; signed-in customer identity is optional and is attached only when the app has a valid Customer Account token.
- Customer Account OAuth public config lives at `/auth/config`, and token exchange lives behind Laravel at `/auth/token` so stores that require a confidential Customer Account client secret do not expose that secret in iOS. `/auth/token` validates the exchanged token against Customer Account GraphQL before the app treats the customer as signed in.
- The Customer Account flow now prefers Shopify discovery documents from `https://theforestrystudio.com/.well-known/openid-configuration` and `/.well-known/customer-account-api` when resolving auth/token/graphql endpoints in live environments.
- Keep the env values in place as fallbacks, but treat discovery drift first when login says Shopify sign-in completed and session verification failed.
- If this error shows up again, inspect:
  - the live `.well-known` token + graph endpoints
  - whether Laravel has `SHOPIFY_CUSTOMER_ACCOUNT_CLIENT_SECRET`
  - whether the returned Shopify customer identity can resolve to a tenant-1 `MarketingProfile`
- Product-detail lookups now fall back to a paginated active-catalog search when the exact handle misses or resolves to a non-customer-visible node.
- Product-detail payloads now include:
  - `mobileSummary`
  - `faq`
- Candle Club-style subscription FAQ content is currently derived in Laravel so the iOS app can place it directly below the selector without rebuilding reward logic client-side.
- Storefront URLs in this service now normalize to `https://theforestrystudio.com`, which is the resolvable customer storefront host the iOS app should use for auth, rewards, product links, and collection links.
- The mobile feature test file now covers:
  - explicit seasonal collections
  - active-only collection products
  - sorting
  - order-history featured product ordering
  - product-detail fallback lookup
  - Candle Club FAQ exposure

## Shopify Embedded App Content Bridge (2026-06-23)

- The native Home payload now reads published App Content from the Shopify embedded `Edit App` page before falling back to defaults.
- The `Edit App` page includes a Shopify-admin-side phone preview that uses draft Mobile Home fields plus live mobile Home API shelves for visual editing.
- Editable mobile fields currently include:
  - Home hero eyebrow, title, and subtitle
  - three hero slide titles, subtitles, image URLs, phone-crop URLs, button labels, and button URLs
- Draft content stays private until published; the native app only reads the published/effective snapshot.
- Product images, collection images, product availability, variants, sorting, and checkout purchaseability still come from Shopify-backed catalog/checkout services.
- Headless is not just an order route: Customer Account OAuth and Storefront checkout use Shopify customer-facing APIs, while catalog/media curation remains Laravel-owned via Shopify Admin GraphQL.

## Product Detail Variant Media + Pricing (2026-06-23)

- Product-detail variant payloads now include:
  - `imageUrl`
  - `compareAtPrice`
  - `available`
  - `selectedOptions`
- The mobile catalog queries Shopify Admin variant `media`, `selectedOptions`, `price`, `compareAtPrice`, and `availableForSale` so the iOS product page can update image, price, and purchaseability when a customer changes variants.
- `php artisan shopify:sync-modern-forestry-variant-media` audits and optionally attaches canonical size/form imagery to matching variants:
  - default mode is dry-run
  - `--apply` performs live Shopify media uploads and variant-media appends
  - `--image-dir` defaults to `/Users/johncollins/Downloads`
  - canonical files are `4oz.png`, `8oz.png`, `16oz.png`, and `Wax Melt.png`
- Variant title normalization treats `4oz`/`4 oz`, `8oz`/`8 oz`, `16oz`/`16 oz`, and `wax melt`/`wax melts`/`melts`/`wax tarts`/`soy tarts` as canonical matches.
- The command only adds missing canonical media. It does not delete or replace existing Shopify product or variant media.
- Local Shopify CLI was repaired by reinstalling Homebrew Node; `shopify app info` now works for `info@theforestrystudio.com`. The local Laravel retail Admin token currently returns Shopify `401 Invalid API key or access token`, so live command dry-runs require a refreshed `SHOPIFY_RETAIL_ACCESS_TOKEN`/installed-store token before using the Laravel command against Shopify.
- `shopify app execute --store modernforestry.myshopify.com --version 2026-01` was verified with a read-only query against live products. It confirmed live variants such as `16oz Cotton Wick`, `8oz Cotton Wick`, and `4oz Cotton Wick` currently have empty `variant.media.nodes`, so the media attachment problem is real on Shopify data, not an iOS rendering issue.
- Future agents should not spend time re-proving the GraphQL shape: Admin variant media reads work with `variants { nodes { selectedOptions { name value } media(first: 1) { nodes { ... on MediaImage { image { url altText } } } } } }`.
- The Laravel sync command is the repeatable production path once the app's stored Admin token is refreshed. Shopify CLI can prove/store access, but CLI mutations are dev-store only in this version, so do not depend on `shopify app execute` for production media writes.
