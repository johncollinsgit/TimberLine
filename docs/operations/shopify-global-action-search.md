# Shopify Global Action Search

## Overview
The Shopify embedded shell now uses a command menu that is action-driven and registry-backed.

Core layers:
1. Primary layer: feature action registration (`registerSearchActions`).
2. Secondary layer: automatic route/page discovery from `ShopifyEmbeddedPageRegistry` (server payload).
3. Optional tertiary layer: safe current-view action harvesting from known actionable UI controls.

## How Feature Actions Register
Use `registerSearchActions(scope, actions)` from:
- `resources/js/shopify/search/registerSearchActions.js`

Each action document supports:
- `id`
- `title`
- `subtitle`
- `section` (`actions|pages|current-view|recent`)
- `keywords`
- `aliases`
- `breadcrumbs`
- `entityType`
- `execute()` callback

Default Shopify actions are registered in:
- `resources/js/shopify/search/defaultShopifySearchActions.js`

## Automatic Route Discovery
Route discovery documents are generated server-side from:
- `app/Services/Shopify/ShopifyEmbeddedPageRegistry.php`
- `app/Services/Shopify/ShopifyEmbeddedShellPayloadBuilder.php` (`commandSearchDocuments`)

Those docs are injected into the Shopify command menu bootstrap and converted to executable navigation actions in the frontend.

When a new Shopify page is added to `ShopifyEmbeddedPageRegistry`, it is automatically eligible for command search without changing command-menu core code.

## Adding Searchable Actions For New Shopify Views
1. Add/adjust page metadata in `ShopifyEmbeddedPageRegistry` (`label`, `search_keywords`, `search_subtitle`, `searchable`, `group`, `route_name`).
2. If needed, add view-specific actions in `defaultShopifySearchActions.js` using `registerSearchActions`.
3. Optionally add safe current-view controls with `data-search-action-*` attributes for harvestable actions.
4. Run tests.

## Ranking Behavior
Ranking is Fuse-powered with explicit priority rules:
1. exact title match
2. exact keyword/alias match
3. title prefix match
4. breadcrumb/subtitle match
5. fuzzy match

Implementation:
- `resources/js/shopify/search/searchRanking.js`
