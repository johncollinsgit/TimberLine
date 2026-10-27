# Modern Forestry mobile catalog and presentation

The retail mobile catalog includes canonical Winter, Room Sprays and Sale shelves. Room Spray scent variants remain browsable even when Shopify has no dedicated collection. Search uses bounded title, handle, tag and variant-title prefix tokens; existing active retail visibility rules remain authoritative.

The public retail homepage at https://theforestrystudio.com/ supplies banner order and collection order through ModernForestryStorefrontPresentationService. It reads current Prestige Slideshow__Slide and CollectionItem markup. The two-minute cache and Eastern-calendar quarter key pick up seasonal website changes; a one-day last-good snapshot handles brief source outages. If the theme markup changes, update the parser and its fixture tests.

Release the catalog service, controller, presentation service and mobile_catalog configuration together through the protected GitHub/Forge pipeline. No migration is required. Deploy the API before iOS 1.0.4/build 17, then verify /ready, /api/mobile/v1/modern-forestry/collections, /home, /products?q=Coffeehouse and /collections/room-sprays/products.

Rollback the release using the normal Forge atomic-release path. MOBILE_CATALOG_WEBSITE_SYNC_ENABLED=false disables website presentation sync while retaining catalog browsing. Preserve customer, checkout, reward and Shopify catalog state. Never clear broad production caches for this change.

The iOS update revalidates metadata after bounded freshness, supports pull-to-refresh, rejects stale Home overwrites of newer collection data, renders website order, and adds visible scrolling cues. Website search needs sections/main-search.liquid on Shopify theme 159737250051; it is a separate one-file theme upload.
