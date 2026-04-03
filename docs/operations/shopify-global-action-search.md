# Shopify Global Action Search

## Architecture
The Shopify embedded shell command menu remains registry-driven and layered:
1. Feature-owned explicit actions via `registerSearchActions`.
2. Auto-discovered page navigation actions from `ShopifyEmbeddedPageRegistry` payload (`commandSearchDocuments`).
3. Current-view actions from explicit control markup plus strict topbar fallback rules.

Primary files:
- `resources/js/shopify/search/ActionSearchProvider.js`
- `resources/js/shopify/search/registerSearchActions.js`
- `resources/js/shopify/search/defaultShopifySearchActions.js`
- `resources/js/shopify/search/useActionSearch.js`
- `resources/js/shopify/search/searchRanking.js`
- `resources/js/shopify/search/currentViewActions.js`
- `resources/js/shopify/search/GlobalCommandMenu.jsx`
- `app/Services/Shopify/ShopifyEmbeddedShellPayloadBuilder.php`

## Document Shape
Search documents support:
- `id`
- `title`
- `subtitle`
- `section` (`actions|pages|current-view|recent`)
- `keywords`
- `aliases`
- `synonyms`
- `breadcrumbs`
- `entityLabels`
- `intentPhrases`
- `source` (`explicit|current-view-explicit|route-discovery|current-view-harvested|recent`)
- `entityType`
- `execute()` callback
- `executeKey`
- `priority`

## Ranking Model
Ranking uses Fuse + post-ranking heuristics:
1. Fuse weighted keys:
   - `title` (highest)
   - `aliases`
   - `keywords`
   - `synonyms`
   - `intentPhrases`
   - `entityLabels`
   - `breadcrumbs`
   - `subtitle`
2. Post-Fuse boosts:
   - exact title
   - exact alias
   - exact keyword
   - title prefix
   - imperative intent (`create`, `open`, `go to`, `view`, `edit`, `manage`)
3. Demotions:
   - breadcrumb-only weak matches
   - long low-confidence fuzzy matches
   - generic `Settings` page for strong shipping-intent queries

Dedup preference (highest to lowest):
1. `explicit`
2. `current-view-explicit`
3. `route-discovery`
4. `current-view-harvested`
5. `recent`

## Query Normalization Rules
Normalization runs before ranking. Current defaults include:
- `new` -> `create`
- `add` -> `create`
- `navigate` -> `go to`
- `customers` -> `customer`
- `orders` -> `order`
- `discounts` -> `discount`
- `ship` / `delivery` -> `shipping`
- `prefs` / `preferences` -> `settings`

Implementation:
- `resources/js/shopify/search/queryNormalization.js`

Feature modules can add per-action synonyms by supplying `synonyms` in `registerSearchActions`.

## Current-View Markup Convention
Prefer explicit control opt-in:
- `data-search-action="1"`
- `data-search-id="..."` (stable identity)
- `data-search-title="..."`
- `data-search-subtitle="..."`
- `data-search-keywords="comma,separated"`
- `data-search-aliases="comma,separated"`
- `data-search-synonyms="comma,separated"`
- `data-search-entity="comma,separated"`
- `data-search-intent="create,open,go to,..."`
- `data-search-event="event.name"` (optional event execution)

Guardrails:
- hidden, disabled, or decorative (`role=presentation/none`) controls are excluded
- fallback harvesting is limited to strict topbar action/subnav links
- dedupe is identity- and destination-aware

## Recents + Suggestions
Recents are stored in local storage key:
- `fb:shopify:command-menu:recent`

Rules:
- recents show when query is empty
- stale recents are dropped when action ids no longer exist
- noisy harvested button actions are excluded from persistence
- pinned high-value suggestions fill empty-query results when needed

Implementation:
- `resources/js/shopify/search/recentActions.js`
- `resources/js/shopify/search/useActionSearch.js`

## Telemetry Hook Contract
Telemetry is adapter-based and vendor-neutral.

Implementation:
- `resources/js/shopify/search/commandMenuTelemetry.js`

Default behavior:
- dispatches browser event `fb:command-menu:event` with `{ eventName, payload }`

Events currently emitted:
- `command_menu_opened`
- `command_menu_query_changed`
- `command_menu_result_selected`
- `command_menu_zero_result_query`
- `command_menu_query_abandoned`
- `command_menu_action_execution_failed`

Payload guardrails:
- includes query length + normalized/redacted query metadata
- redacts likely identifiers (for example long numeric ids, emails)
- never blocks UI when telemetry fails

## Adding Actions for New Views
1. Add page metadata to `ShopifyEmbeddedPageRegistry` for automatic route discovery.
2. Add explicit feature actions with `registerSearchActions(scope, actions)`.
3. Add aliases/synonyms/intent phrases for strong intent matching.
4. Mark current-view controls with `data-search-*` attributes when needed.
5. Run JS + PHP tests before deploy.

