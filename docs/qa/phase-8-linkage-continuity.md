# Phase 8 Linkage Continuity QA (2026-04-21)

## Scope

Phase 8 is limited to linkage continuity hardening plus live smoke verification.

Included:
- order-linkage input hardening (storefront -> order ingest continuity)
- controlled live smoke attempts with UTM/fbclid test params
- re-run production storefront diagnostics

Excluded:
- analytics redesign
- lifecycle workflow changes
- AI budget-control changes

## What changed

### 1) Storefront runtime continuity hardening

File:
- `extensions/forestry-marketing-embed/assets/marketing-storefront-tracker.js`

Shipped:
- add-to-cart forms now receive hidden linkage properties before submit:
  - `properties[_mf_session_key]`
  - `properties[_mf_client_id]`
  - `properties[_mf_cart_token]`
  - `properties[_mf_checkout_token]`
  - `properties[_mf_fbclid]`
  - `properties[_mf_fbc]`
  - `properties[_mf_fbp]`
  - `properties[_mf_utm_source]`
  - `properties[_mf_utm_medium]`
  - `properties[_mf_utm_campaign]`
  - `properties[_mf_utm_content]`
  - `properties[_mf_utm_term]`
- cart attribute sync now uses freshest attribution snapshot (`activeAttribution`) when available.

Why:
- cart attribute updates alone are not reliable enough in all checkout paths.
- line-item fallback gives order ingest another deterministic source for session/client continuity.

### 2) Order linkage service fallback expansion

File:
- `app/Services/Marketing/StorefrontOrderLinkageService.php`

Shipped:
- linkage signal extraction now includes Shopify order `line_items[].properties[]` fallback.
- normalized property prefixes (for `_mf_*`, `marketing_*`, `properties[...]` style names).
- supports session/client/cart/checkout/utm/fb* recovery from line-item properties.

Why:
- allows linkage even when note attributes are absent.

### 3) Attribution meta enrichment fallback expansion

File:
- `app/Services/Marketing/MarketingAttributionSourceMetaBuilder.php`

Shipped:
- `fromShopifyOrderPayload()` now ingests line-item property signals and persists:
  - normalized attribution + linkage fields
  - `line_item_property_signals` diagnostics snapshot

Why:
- keeps attribution/order diagnostics truthful when linkage originates from line-item properties.

## Test coverage updates

Files:
- `tests/Feature/Marketing/MarketingAttributionSourceMetaEnrichmentTest.php`
- `tests/Feature/Marketing/ShopifyStorefrontTrackingBootstrapTest.php`

Added coverage:
- builder reads `_mf_*` line-item property signals
- linkage service can match via line-item session/client fallback
- storefront bootstrap test checks form property injection contract strings

## Live smoke reality check

Observed:
- storefront challenge/rate-limit defenses intermittently blocked full automated flows.
- partial journeys still produced successful app-proxy funnel posts with 200 responses for baseline events.

Impact:
- full automated purchase-path verification remains environment-limited.
- deterministic continuity proof should rely on:
  - production diagnostics trend
  - recent order linkage fields on newly created orders
  - controlled human-run storefront smoke on a browser session that passes challenge gates

## Production verification commands

```bash
php artisan marketing:diagnose-storefront-tracking --tenant-id=1 --store=retail --days=30 --json
php artisan marketing:diagnose-storefront-tracking --tenant-id=1 --store=retail --days=1 --json
```

## Next gate

Phase 8 is complete only when a post-deploy production window shows:
- higher share of orders with `storefront_session_key` / `storefront_client_id`
- linkage rate moving out of low single digits toward double digits
- no regression in `checkout_started` volume
