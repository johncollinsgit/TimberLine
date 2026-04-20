# Phase 2 Event Integrity Findings (2026-04-20)

Scope: instrumentation integrity + attribution continuity only. No dashboard redesign.

## 1) Messaging analytics runtime crash

- Route: `/shopify/app/messaging/analytics?analytics_tab=home`
- Root cause (repo-local): `useSyncExternalStore` snapshot instability in `resources/js/shopify/search/ActionSearchProvider.js`
  - `snapshot()` returned a new array on every render pass.
  - This can trigger React max update depth (`Minified React error #185`) in consumers.
- Fix:
  - Added cached snapshot semantics so store snapshots remain referentially stable until registry mutation.
  - Added regression test in `resources/js/shopify/search/__tests__/action-search-provider.test.js`.
- QA proof:
  - Single-route click-path run completed clean after rebuild.
  - No console/page runtime errors on the analytics route.

## 2) Authenticated click-path coverage

- Added authenticated operator route config: `tests/e2e/click-path-routes-auth.json`
- Added script alias: `npm run qa:click-path:auth`
- Local authenticated run summary:
  - routes tested: 13
  - routes with issues: 8
  - broken anchors: 22
  - dead buttons: 6
  - key result: all Shopify embedded messaging routes are now runtime-clean (`/shopify/app`, `/shopify/app/messaging`, `/shopify/app/messaging/setup`, `/shopify/app/messaging/analytics`, `/shopify/app/messaging/responses`)
- Remaining failures were concentrated on legacy `/marketing/*` pages returning `403` in local role context plus anchor/no-op issues on `/analytics`.

## 3) Baseline funnel gating removal

- Theme embed and web pixel trackers now emit baseline funnel events without campaign-only gating:
  - `session_started`
  - `landing_page_viewed`
  - `product_viewed`
  - `add_to_cart`
- Explicit payload attribution values are now accepted server-side and persisted in event `meta` even when URL params are absent.

## 4) Durable purchase linkage

- Added distinct `purchase` event type (no alias to `checkout_completed`).
- Added deterministic order linkage service:
  - `app/Services/Marketing/StorefrontOrderLinkageService.php`
  - matches Shopify orders to storefront events using deterministic keys (`checkout_token`, `cart_token`, `session_key`, `client_id`, `mf_delivery_id`) with confidence scoring.
  - records durable purchase lineage event (`source_type=shopify_storefront_purchase`) during order ingest.
- Added order linkage persistence columns:
  - migration: `2026_04_20_150000_add_storefront_linkage_columns_to_orders_table.php`

## 5) Meta continuity

- Added end-to-end capture and persistence for:
  - `fbclid`
  - `fbc`
  - `fbp`
- Captured in storefront trackers and persisted into funnel `meta` + order attribution enrichment.

## 6) Verified tests

- `php artisan test tests/Feature/Marketing/StorefrontFunnelTrackingTest.php tests/Feature/Marketing/MarketingAttributionSourceMetaEnrichmentTest.php`
- `node --test resources/js/shopify/search/__tests__/action-search-provider.test.js resources/js/shopify/search/__tests__/route-discovery.test.js resources/js/shopify/search/__tests__/command-menu-flow.test.js`

## 7) Remaining blockers before Phase 3

- Theme-side storefront pages still need separate E2E coverage for true browser checkout flow (theme repo is separate).
- Production deployment + extension publish needed to confirm tracker changes are live in Shopify storefront runtime.
- Attribution QA still requires live order samples to validate checkout/session match rates and confidence distribution under real traffic.
