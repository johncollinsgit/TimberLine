# Phase 1: Measurement Baseline Inspection

## Scope inspected

### Storefront funnel tracking

- `app/Http/Controllers/Marketing/MarketingShopifyIntegrationController.php:2694`
- `app/Services/Marketing/MarketingStorefrontFunnelService.php:15`
- `app/Services/Shopify/ShopifyStorefrontTrackingSetupService.php:503`

What matters:

- This is the top of the behavior signal chain needed for conversion visibility.

Observed in code:

- Event ingest endpoint exists and is signed.
- Event aliases include session, landing, product, wishlist, cart, checkout-start, checkout-complete.
- `purchase` currently aliases to `checkout_completed` (no independent `purchase` event record).

### UTM persistence and source signal capture

- `app/Services/Marketing/MarketingStorefrontFunnelService.php:98`
- `app/Services/Marketing/MarketingStorefrontFunnelService.php:178`

What matters:

- UTM/source fields are the minimum for channel-level decision-making.

Observed in code:

- UTM fields are parsed from URL and persisted in event `meta`.
- Message linkage params (`mf_*`) are also parsed and persisted.

### Checkout/purchase linkage

- `app/Services/Marketing/MessageAnalyticsService.php:2566`
- `app/Services/Marketing/MessageAnalyticsService.php:2645`
- `app/Services/Marketing/MessageOrderAttributionService.php:16`

What matters:

- Revenue attribution needs a durable bridge from click/session to order.

Observed in code:

- Funnel detail computes checkout-start vs checkout-complete candidate abandonment.
- Journey key falls back to `checkout_token`, then `session_key`, then `delivery_id`.
- Order attribution model is `last_click`; falls back to coupon/url inference when click proof is absent.

### Analytics page data sources

- `resources/views/analytics/index.blade.php:19`
- `app/Livewire/Analytics/AnalyticsWidgets.php:32`

What matters:

- Operator dashboard should support revenue decisions, not only operations context.

Observed in code:

- `/analytics` mounts dashboard + analytics widgets.
- Widget library is inventory/demand-heavy rather than ecommerce conversion-heavy.

### Messaging telemetry

- `app/Http/Controllers/ShopifyEmbeddedMessagingController.php:1545`
- `resources/views/shopify/messaging-analytics.blade.php:650`

What matters:

- Messaging ROI depends on delivery + click + attributed-order integrity.

Observed in code:

- Messaging setup guide explicitly checks module access, email analytics readiness, and tracked send seed state.
- Analytics UI includes warnings for inferred attribution and missing tracked click data.

### Recommendation/action surfaces

- `app/Http/Controllers/Marketing/MarketingRecommendationsController.php:24`
- `resources/views/marketing/recommendations/index.blade.php`

What matters:

- Revenue ops requires visible actions tied to measurable outcomes.

Observed in code:

- Recommendation surfaces are implemented, approval paths exist.
- Surface efficacy is setup/data-volume dependent.

## Current confidence classification (code + latest known runtime)

- **Working now:** signed storefront event endpoints, funnel ingestion path, messaging analytics UI, recommendation approval surfaces.
- **Partially working:** checkout/purchase linkage quality (fallback inference still present), messaging attribution certainty.
- **Setup-dependent:** theme app embed + web pixel coverage, messaging provider readiness.
- **Missing:** independent purchase event continuity proof in storefront event chain.
- **Cannot verify from this repo alone:** theme-rendered storefront widget behavior and checkout-domain completion signal consistency.
