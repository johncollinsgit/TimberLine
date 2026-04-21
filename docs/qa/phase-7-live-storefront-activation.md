# Phase 7 Live Storefront Activation QA (2026-04-21)

## Scope

Phase 7 is strictly storefront activation + continuity verification.

Included:
- proving live activation state (theme embed, customer-events pixel, app proxy)
- tracing checkout-start continuity gaps in production
- validating live runtime emissions with safe smoke flows
- hardening checkout-start emission in theme runtime (submit path support)
- re-running production storefront diagnostics

Excluded:
- analytics redesign
- lifecycle workflow changes
- AI budget control changes

## Live status snapshot (tenant=1, store=retail)

- Theme app embed on published theme: **inactive**
  - Shopify main theme (`modernforestry.myshopify.com`) has no Forestry app-embed block in `config/settings_data.json`.
  - Storefront DOM does not contain `#forestry-storefront-tracking-config`.
  - Storefront does not load `marketing-storefront-tracker.js`.
- Web pixel/customer events extension: **connected but not verified emitting**
  - Pixel connection status: `connected=true`, `pixel_id=gid://shopify/WebPixel/2117271811`.
  - Recent tracker flow for `web_pixel`: zero events in recent production window.
- App proxy runtime: **active and verified**
  - `https://theforestrystudio.com/apps/forestry/health` returns HTTP 200 + `ok=true`.

## Chain break diagnosis

Primary production break is emission, not ingest:
- backend accepts and persists funnel events when posted
- app proxy signatures verify
- verification failures are not the dominant issue

Current breakpoints:
1. theme app embed is not active on the published theme
2. connected web pixel is not currently emitting usable funnel events
3. checkout_started remains near-zero organically because upstream runtime signal is mostly absent

## Runtime hardening shipped in this pass

File:
- `extensions/forestry-marketing-embed/assets/marketing-storefront-tracker.js`

Changes:
- checkout_started now emits on checkout **form submit** events
- checkout token extraction now supports:
  - checkout path token (`/checkouts/{token}`)
  - query token fallback (`token` / `checkout_token`)
- payload builder accepts explicit checkout token override

Why:
- many storefront themes initiate checkout via form submit rather than simple click navigation
- click-only capture undercounts true checkout starts

## Shopify CLI config correction required for extension deploy

File:
- `shopify.app.toml`

Change:
- removed invalid app scopes (`read_webhooks`, `write_webhooks`) that blocked extension deploy

Related test update:
- `tests/Feature/Marketing/ShopifyStorefrontTrackingBootstrapTest.php`

## Smoke evidence (safe)

### Organic browser flow (no manual event posts)
- Home/PDP/Add-to-cart/Checkout navigation succeeds.
- No automatic `POST /apps/forestry/funnel/event` observed during flow.
- Confirms runtime emission is still not active in the live published theme path.

### Controlled probe (safe manual post from storefront context)
- Posting to `/apps/forestry/funnel/event` succeeds and records identifiers (`checkout_token`, `cart_token`, `session_key`, `client_id`, `fbclid`, `fbc`, `fbp`).
- Confirms ingest pipeline works when storefront emits.

## Production diagnostics (30-day window at verification time)

Command:

```bash
php artisan marketing:diagnose-storefront-tracking --tenant-id=1 --store=retail --days=30 --json
```

Observed:
- orders: `189`
- funnel events: `15`
- checkout_started: `2`
- purchase events: `3`
- linkage rate: `1.6%`
- tracker mix: mostly historic `theme_app_embed` + explicit probe events, no recent organic volume

Interpretation:
- production remains blocked by live storefront runtime activation/continuity, not by backend ingestion.

## Required manual Shopify admin actions

1. Enable app embed on the **published** theme:
   - `Online Store -> Themes -> Customize (published theme) -> Theme settings -> App embeds`
   - turn on **Forestry tracking**
   - click **Save**
2. Confirm Customer Events pixel remains active:
   - `Settings -> Customer events`
   - verify Forestry app pixel is active for the live store
3. Run live smoke flow after save:
   - home -> product -> add to cart -> begin checkout
   - verify network posts to `/apps/forestry/funnel/event`
   - re-run diagnostics command above and verify event volume + checkout_started lift

## Gate statement

Status after this pass: **materially improved but still blocked**.

Reason:
- instrumentation code paths are hardened and backend ingest is proven,
- but live published theme activation/runtime emission remains incomplete.

