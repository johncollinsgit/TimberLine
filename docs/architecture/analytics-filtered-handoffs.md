# Analytics Filtered Handoffs (Block 12)

Date: 2026-03-08  
Scope: analytics drilldown -> operational/admin destination routing with pre-applied filters

## Goal
Turn analytics drilldowns into reliable operational handoffs so users land on the right page with useful prefilter context already applied.

## Query Param Contract
Analytics drill-through links now use a shared parameter set:

- `analytics_mode`: `rolling|fixed`
- `analytics_preset`: timeframe preset key (for example `last_30_days`)
- `analytics_compare`: comparison mode key
- `analytics_start`: primary window start date (`Y-m-d`)
- `analytics_end`: primary window end date (`Y-m-d`)
- `analytics_state`: demand state context (`forecast|current|actual|mixed`)
- `channel`: channel context (`all|retail|wholesale|event`)
- `source_widget`: originating analytics widget id
- `return_to`: source analytics page URL

Entity-specific filters piggyback on the same URL:

- Scent handoff: `scent`, `search`
- Oil handoff: `oil`, `materialSearch`
- Unmapped handoff: `filter`, `raw`, `store`, `account`, `search`

## Implemented Handoffs

### 1) Unmapped rows -> Scent Intake
- Source: `unmapped_exceptions` drilldown row
- Destination: `route('admin.scent-intake')`
- Prefilters: unresolved raw name + account/store + channel filter
- Note: `/admin/scent-intake` redirect now preserves query params into `/admin?tab=scent-intake`.

### 2) Oil rows -> Inventory
- Sources:
  - `oil_reorder_risk` drilldown rows
  - `top_oils_forecast` drilldown rows
- Destination: `route('inventory.index')`
- Prefilters: `oil` (id) + `materialSearch` (name)
- UX: inventory page highlights the matched oil row when `oil` resolves.

### 3) Scent rows -> Catalog Scents
- Source: `top_scents_forecast|current|actual` drilldown rows
- Destination: `route('admin.catalog.scents')`
- Prefilters: `scent` + `search`
- Behavior: catalog initializes `search` from `scent` when search is empty.

### 4) Demand overview rows -> Queue pages
- Source: `demand_state_overview` drilldown rows
- Destinations:
  - `forecast` -> `route('retail.plan', ['queue' => best-fit channel])`
  - `current|actual` -> pouring queue:
    - channel-specific: `route('pouring.stack', ['channel' => ...])`
    - all-channel: `route('pouring.all-candles')`
- Prefilters: `state` + analytics context
- Queue behavior:
  - `StackOrders` supports `state=current|actual`
  - `AllCandles` supports `state=all|current|actual`

## Destination Filter Initialization
- `App\Livewire\Admin\Catalog\ScentsCrud`
  - reads `scent`; seeds `search` if empty
- `App\Livewire\Inventory\Index`
  - reads `oil` + `materialSearch`; resolves focus oil and highlights row
- `App\Livewire\Admin\MappingExceptions`
  - reads `channel`, `raw`, `store`, `account`; seeds search and query filters
- `App\Livewire\PouringRoom\StackOrders`
  - reads `state`
- `App\Livewire\PouringRoom\AllCandles`
  - reads `channel` + `state`

## Known Limitations
- Not every destination currently interprets every analytics context param; unused params are intentionally tolerated for forward compatibility.
- `forecast -> retail.plan` uses best-fit queue mapping (`retail|wholesale|markets`) and does not yet enforce every advanced analytics state nuance.
- Back-navigation is currently URL-based (`return_to` preserved), not a full navigation session manager.
