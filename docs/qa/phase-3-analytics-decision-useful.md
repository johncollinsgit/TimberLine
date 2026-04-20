# Phase 3 - Decision-Useful Analytics (2026-04-20)

## Scope
- Phase 3 only: convert Messaging Analytics home from passive message stats into a decision surface for ecommerce operators.
- Used Phase 2 event/linkage model as source of truth.
- Did not start lifecycle workflow rollout.

## Implemented Panels (Home tab)

### 1) Attribution Quality
- Metrics now exposed:
  - UTM coverage %
  - self-referral %
  - unattributed purchase %
  - purchase linkage match rate
  - linkage confidence distribution (high/medium/low/unlinked)
  - Meta continuity for fbclid/fbc/fbp where Meta relevance is detected
- Data source:
  - `orders` (`attribution_meta`, `storefront_*` linkage fields)

### 2) Acquisition Funnel
- Metrics now exposed:
  - sessions
  - landing page views
  - product views
  - add to cart
  - checkout started
  - purchases
  - step-to-step conversion rates
- Source split now exposed:
  - source / medium / campaign rows with session and purchase context
- Data source:
  - `marketing_storefront_events` (`source_type` funnel + purchase lineage events)

### 3) Retention
- Metrics now exposed:
  - first-time vs returning revenue
  - repeat order share
  - returning revenue share
  - time-to-second-purchase median and p75
  - simple month cohorts with 30d/60d repeat rates
- Data source:
  - `orders`
  - canonical identity link via `marketing_profile_links` (`source_type=order`)
  - fallback identity keys from Shopify customer id/email only when canonical link is absent

### 4) Action Queue
- Generated operator actions now map directly to measured failures:
  - low UTM coverage
  - high self-referrals
  - high unattributed purchase %
  - weak checkout/purchase linkage
  - weak Meta continuity
  - checkout drop-off warning
  - retention-priority recommendation when returning revenue dominates
- Owner labels included (`engineering`, `marketing`, `operator`).

## Clutter Reduction
- Kept legacy message-only cards but downgraded them to secondary:
  - `Engagement Trend`
  - `Message Filters`
  - `Message Summary`
- Downgraded setup diagnostics prominence:
  - `Storefront tracking health` moved to secondary visual tone.
  - raw payload section relabeled as debug diagnostics.

## Core Files Changed
- `app/Services/Marketing/MessageAnalyticsService.php`
- `app/Http/Controllers/ShopifyEmbeddedMessagingController.php`
- `resources/views/shopify/messaging-analytics.blade.php`
- `tests/Feature/Marketing/MessageAnalyticsDecisionPanelsTest.php`
- `tests/Feature/ShopifyEmbeddedMessagingTest.php`

## Production QA Checklist (post-deploy)
1. Open `/shopify/app/messaging/analytics?analytics_tab=home` in Shopify Admin and verify no runtime crash.
2. Confirm Attribution Quality panel is non-empty for active date range:
   - UTM coverage > 0 or explicitly 0 with non-zero purchase count.
   - linkage confidence buckets sum to purchase count.
3. Confirm Acquisition Funnel panel has live counts and conversion rates for active traffic windows.
4. Confirm source/medium/campaign breakdown is not entirely `(unattributed)` for tagged campaigns.
5. Confirm Retention panel first-time + returning + unknown order counts reconcile with sampled orders.
6. Spot-check 10 recent orders:
   - compare panel linkage rate vs `orders.storefront_link_confidence` / `orders.storefront_linked_event_id`.
7. Spot-check Meta continuity:
   - for Meta-tagged orders, confirm fbclid/fbc/fbp surfaced in `orders.attribution_meta`.
8. Confirm Action Queue items are generated from panel metrics (not static copy).
9. Re-run click-path harness on authenticated routes and verify analytics route remains clean.

