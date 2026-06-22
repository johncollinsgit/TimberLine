# Mobile Catalog Services

This folder owns the Laravel-side mobile catalog source of truth for the Modern Forestry iOS app.

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
- Customer Account OAuth token exchange lives behind Laravel at `/auth/token` so stores that require a confidential Customer Account client secret do not expose that secret in iOS.
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
  - best-selling featured product ordering
  - product-detail fallback lookup
  - Candle Club FAQ exposure
