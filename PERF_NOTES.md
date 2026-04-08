# Embedded Shopify Admin Performance Notes (2026-04-08)

## What Changed

### 1) Dashboard “Today” Initial Fetch Bug
- Fixed the embedded React dashboard hook so the initial query state is derived from the URL query string when server `initialData` is absent.
- Guarded the “skip first fetch when `initialData` exists” logic so it only skips when `initialData.query` matches the current query state (prevents stale/incorrect first paint).

File:
- [/Users/johncollins/Code/myapp/resources/js/shopify/dashboard/hooks/useDashboardData.ts](/Users/johncollins/Code/myapp/resources/js/shopify/dashboard/hooks/useDashboardData.ts)

### 2) Make Embedded Navigation Feel Immediate
- Added short shared-cache for the embedded shell payload pieces that were being recomputed on every embedded page request:
  - display labels
  - module states
  - experience profile (per user)
- Cache TTL uses `SHOPIFY_EMBEDDED_JOURNEY_CACHE_TTL_SECONDS` (default `60s`) so it stays fresh but avoids needless repeated reads/joins.

File:
- [/Users/johncollins/Code/myapp/app/Services/Shopify/ShopifyEmbeddedShellPayloadBuilder.php](/Users/johncollins/Code/myapp/app/Services/Shopify/ShopifyEmbeddedShellPayloadBuilder.php)

### 3) Fix Rewards Not Loading (Remove Heavy Analytics From First Paint)
- Rewards overview (`/shopify/app/rewards`) no longer blocks first paint by computing the full embedded dashboard analytics payload on the server.
- Analytics can still be explicitly requested with `?analytics=1` (opt-in).
- The view now avoids loading ApexCharts + chart JS when there is no chart payload.
- Added an “Open full analytics” CTA (links to `/shopify/app?full=1`) when analytics is not enabled.

Files:
- [/Users/johncollins/Code/myapp/app/Http/Controllers/ShopifyEmbeddedRewardsController.php](/Users/johncollins/Code/myapp/app/Http/Controllers/ShopifyEmbeddedRewardsController.php)
- [/Users/johncollins/Code/myapp/resources/views/shopify/rewards-overview.blade.php](/Users/johncollins/Code/myapp/resources/views/shopify/rewards-overview.blade.php)

### 4) Reduce Settings + Messaging Latency (Remove Alpha Upserts From Critical Path)
- The embedded `Settings` and `Messaging` pages no longer block first paint by running `ModernForestryAlphaBootstrapService::ensureForTenant()` during the request.
- Alpha defaults are now scheduled as best-effort work **after** the response is sent.
- Added a best-effort cache lock to `ensureForTenant()` to prevent stampedes when caches are cold (avoids multiple concurrent upsert bursts).
- Removed the Messaging page “warm caches” terminating hook that was computing the full embedded dashboard analytics payload (this work was expensive and unnecessary for Messaging first paint).

Files:
- [/Users/johncollins/Code/myapp/app/Http/Controllers/ShopifyEmbeddedSettingsController.php](/Users/johncollins/Code/myapp/app/Http/Controllers/ShopifyEmbeddedSettingsController.php)
- [/Users/johncollins/Code/myapp/app/Http/Controllers/ShopifyEmbeddedMessagingController.php](/Users/johncollins/Code/myapp/app/Http/Controllers/ShopifyEmbeddedMessagingController.php)
- [/Users/johncollins/Code/myapp/app/Services/Tenancy/ModernForestryAlphaBootstrapService.php](/Users/johncollins/Code/myapp/app/Services/Tenancy/ModernForestryAlphaBootstrapService.php)

### 5) Reduce `/shopify/app` First Paint Work (Skip Full Dashboard Config Unless Needed)
- `/shopify/app` no longer computes the full dashboard config payload unless `?full=1` (or the request is in an unauthenticated fallback state).

File:
- [/Users/johncollins/Code/myapp/app/Http/Controllers/ShopifyEmbeddedAppController.php](/Users/johncollins/Code/myapp/app/Http/Controllers/ShopifyEmbeddedAppController.php)

## Instrumentation / Debugging

### Server Timing + Logs (per-request opt-in)
Add `?perf=1` (or `?debug_perf=1`) to any embedded route (Dashboard, Customers, Messaging, Rewards, Settings).  
This enables `ShopifyEmbeddedPerformanceProbe` for that request and adds a `Server-Timing` response header plus a `shopify.embedded.perf` log entry.

File:
- [/Users/johncollins/Code/myapp/app/Services/Shopify/ShopifyEmbeddedPerformanceProbe.php](/Users/johncollins/Code/myapp/app/Services/Shopify/ShopifyEmbeddedPerformanceProbe.php)

### Client Debug Logs (Dashboard)
Add `?dashboard_debug=1` (or `?debug=dashboard`) to the full embedded dashboard to log:
- initial query resolution
- fetch start/end + query string
- response payload summary (when enabled previously)

## Expected Impact
- Dashboard deep links like `?timeframe=today` load the correct window immediately (no “click away and back” to trigger a fetch).
- Embedded top-level nav/subnav becomes much less “janky” because label + module-access lookups don’t re-run on every click.
- Rewards page should render even if the full analytics payload is slow/unavailable; merchants get a stable page and a clear path to deeper analytics.

## Metrics Intentionally Excluded (To Keep First Paint Fast)
- Rewards overview no longer includes the full dashboard analytics payload by default (charts/attribution/financial summary are opt-in via `?analytics=1` or via “Open full analytics”).

## Benchmark Notes
Run: `php -d memory_limit=512M ./vendor/bin/pest tests/Feature/ShopifyEmbeddedPerformanceBenchmarkTest.php`  
Record cold + warm medians here after running locally:
- (pending)
