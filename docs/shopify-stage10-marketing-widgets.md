# Stage 10 Shopify Storefront Widgets

## Canonical conversion note (2026-04-03)

- Legacy Growave points convert with `candle_cash = legacy_points * 0.3`.
- Converted legacy balances are grandfathered opening credit and are earned-limit exempt.
- Canonical migration reference customer: `Rynda Baker <bakery25@gmail.com>`, `1494 * 0.3 = 448.200`.

## Extension structure

```text
extensions/forestry-marketing-embed/
  assets/
    marketing-storefront-tracker.css
    marketing-storefront-tracker.js
  blocks/
    marketing-app-embed.liquid
  locales/
    en.default.json
  shopify.extension.toml

extensions/forestry-marketing-pixel/
  src/
    index.js
  shopify.extension.toml
```

## App proxy paths (storefront)

All storefront requests must stay on Shopify domain and use app proxy root:

- `/apps/forestry/customer/status`
- `/apps/forestry/rewards/balance`
- `/apps/forestry/rewards/available`
- `/apps/forestry/rewards/history`
- `/apps/forestry/rewards/redeem`
- `/apps/forestry/consent/status`
- `/apps/forestry/consent/request`
- `/apps/forestry/consent/confirm`
- `/apps/forestry/health`
- `/apps/forestry/product-reviews/status`
- `/apps/forestry/product-reviews/submit`
- `/apps/forestry/wishlist/status`
- `/apps/forestry/wishlist/add`
- `/apps/forestry/wishlist/remove`
- `/apps/forestry/wishlist/lists/create`

Expected Shopify app proxy target:

- `/apps/forestry/*` -> `https://<laravel-host>/shopify/marketing/v1/*`

Current app proxy target in this repo:

- `shopify.app.toml` -> `https://app.theeverbranch.com/shopify/marketing/v1` (canonical)

## Backend route compatibility (minimal Stage 10 gap fixes)

Added to both `/shopify/marketing/*` and `/shopify/marketing/v1/*`:

- `GET /consent/status` (new)
- `POST /consent/request` (alias to existing consent opt-in flow)
- `GET /health` (signed transport + runtime config probe)
- identity normalization for Shopify proxy ids:
  - supports `shopify_customer_id`, `logged_in_customer_id`, `customer_id`
  - supports Shopify GID format (e.g. `gid://shopify/Customer/123`)

Existing routes remain unchanged (`/consent/optin`, `/consent/confirm`).

## Endpoint parity check

Widget JS endpoint -> Laravel v1 endpoint:

- `/apps/forestry/customer/status` -> `/shopify/marketing/v1/customer/status`
- `/apps/forestry/rewards/balance` -> `/shopify/marketing/v1/rewards/balance`
- `/apps/forestry/rewards/available` -> `/shopify/marketing/v1/rewards/available`
- `/apps/forestry/rewards/history` -> `/shopify/marketing/v1/rewards/history`
- `/apps/forestry/rewards/redeem` -> `/shopify/marketing/v1/rewards/redeem`
- `/apps/forestry/consent/status` -> `/shopify/marketing/v1/consent/status`
- `/apps/forestry/consent/request` -> `/shopify/marketing/v1/consent/request`
- `/apps/forestry/consent/confirm` -> `/shopify/marketing/v1/consent/confirm`
- `/apps/forestry/health` -> `/shopify/marketing/v1/health`
- `/apps/forestry/product-reviews/status` -> `/shopify/marketing/v1/product-reviews/status`
- `/apps/forestry/product-reviews/submit` -> `/shopify/marketing/v1/product-reviews/submit`
- `/apps/forestry/wishlist/status` -> `/shopify/marketing/v1/wishlist/status`
- `/apps/forestry/wishlist/add` -> `/shopify/marketing/v1/wishlist/add`
- `/apps/forestry/wishlist/remove` -> `/shopify/marketing/v1/wishlist/remove`
- `/apps/forestry/wishlist/lists/create` -> `/shopify/marketing/v1/wishlist/lists/create`

## Native reviews + wishlist contract notes

Reviews:
- `product-reviews/status` returns canonical Backstage review data for the requested product/store context.
- Native review payload always exposes `task.button_text = "Write a review"` regardless of legacy Candle Cash task text.
- `viewer.recent_order_candidates` is included for resolved customers.
- `viewer.eligibility` and submit responses expose reward eligibility/result details.
- Review publication mode is tenant-driven:
  - `pending_moderation`
  - `auto_publish`
- Modern Forestry default reward is `$1` (`100` cents), awarded only after a fulfilled/completed matching order-line passes anti-abuse checks.

Wishlist:
- `guest_token` is a supported storefront identity for status/add/remove/create-list.
- Guest wishlist rows persist in canonical Backstage wishlist tables and merge into the resolved profile on later authenticated status/add flows.
- `wishlist/status` returns:
  - `guest_token`
  - `active_list`
  - `default_list`
  - `lists`
  - `items`
  - `recent_items`
- Named lists are created through `POST /wishlist/lists/create`.

Tenant/runtime rules:
- Storefront review/wishlist requests depend on verified Shopify store context (`shop` or `store_key`) that resolves into `shopify_stores`.
- `shopify_stores.tenant_id` must be populated for the tenant-scoped Modern Forestry contract/defaults to apply cleanly.
- Growave is historical-import input only. Runtime storefront reads/writes must use Backstage-owned canonical tables.

## Modern Forestry cutover checklist

After deploying the app/backend and pushing the theme:
- Confirm `product-reviews/status` returns native CTA/reward values for Modern Forestry.
- Confirm guest wishlist add/status/remove succeeds with `guest_token`.
- Confirm the storefront no longer renders Growave helper markup or loads `ssw-empty.js`.
- If Growave runtime output still appears after theme push, remove remaining Shopify-side Growave ScriptTags/app embeds operationally.

Observed live state on 2026-03-31:
- The app/backend contract is live and aligned with the native storefront widgets:
  - `product-reviews/status` returns `button_text = "Write a review"` and `reward_amount = "1.00"`
  - guest wishlist add/status/remove succeeds through the live app proxy
- The Shopify live theme path at `modernforestry.myshopify.com` is serving the cutover theme (`159310446851`) and no longer renders or requests Growave runtime assets.
- The remaining storefront blocker is the custom domain `https://theforestrystudio.com`, which still serves stale HTML from the older `Prestige` theme (`136487764227`) and therefore still emits the Growave loader/app block.
- That stale body persists under cache-busting query params and `Cache-Control: no-cache`, and it also persists when the custom host is forced directly to Shopify's edge IP via `curl --resolve theforestrystudio.com:443:23.227.38.65`.
- Shopify response headers on that custom-domain path still report live theme `159310446851`, so the final cutover issue now looks like a custom-host Shopify render/cache/routing problem rather than a remaining app-proxy/backend mismatch.
- Exact next action: purge or bypass any custom-domain cache layer and re-test `theforestrystudio.com`; if the body still reports theme `136487764227`, escalate to Shopify support with the host-specific mismatch evidence.

## Runtime config validation

The app proxy verifier depends on:

- `MARKETING_SHOPIFY_SIGNING_SECRET`
- `MARKETING_SHOPIFY_APP_PROXY_ENABLED`
- `MARKETING_SHOPIFY_APP_PROXY_SECRET`

Local runtime probe command used:

- `php -r '... config(\"marketing.shopify\") ...'`

Expected/validated booleans:

- `app_proxy_enabled=true`
- `has_signing_secret=true`
- `has_app_proxy_secret=true`

## Health endpoint probe

Use signed app-proxy request:

- Storefront path: `/apps/forestry/health`
- Backend path: `/shopify/marketing/v1/health`

Expected success payload includes:

- `ok: true`
- `version: v1`
- `meta.auth_mode: app_proxy`
- `data.transport: ok`
- `data.runtime.*` booleans for proxy/signing config

## Live data test results

Validated in feature tests (`tests/Feature/Marketing/MarketingStage10ShopifyWidgetsContractTest.php`):

- Anonymous visitor (`customer/status` without identity):
  - `200 OK`
  - `data.state = unknown_customer`
- Logged-in customer simulation (`customer/status` with known email+phone):
  - `200 OK`
  - `data.state = linked_customer`
  - `data.profile_id` resolves correctly

Also validated all extension contract endpoints under signed app-proxy transport.

External transport checks run on **March 11, 2026**:

- Shopify CLI app binding check:
  - `shopify app info --verbose` reports dev store `modern-forestry-dev.myshopify.com`
- `https://modernforestry.myshopify.com/apps/forestry/health`:
  - redirects to `https://theforestrystudio.com/apps/forestry/health`
  - final response is `404` from Shopify (app proxy route not active on that store/domain)
- `https://modern-forestry-dev.myshopify.com/apps/forestry/health`:
  - responds `302` to `/password` (dev store password gate)
- `https://app.theeverbranch.com/shopify/marketing/v1/health` (direct signed probe, canonical):
  - currently returns Laravel `404` page in the deployed environment
  - local app has route; deployed Backstage host appears to be behind local code

Conclusion:

- Local/test transport and contract are valid.
- Live storefront transport is currently blocked by store/app environment state:
  - store mismatch (`modernforestry` vs `modern-forestry-dev`),
  - password-gated storefront,
  - and deployed Backstage route not yet live.

## Widget coverage

- `rewards-balance-block`: balance + threshold messaging.
- `available-rewards-block`: unlocked vs locked rewards.
- `reward-history-block`: transactions + redemptions.
- `redeem-reward-block`: issue/reuse code + copy interaction.
- `sms-consent-block`: status, request, confirm flow, incentive signaling.
- `compact-rewards-promo-block`: event-to-online bridge messaging.
- `marketing-app-embed`: shared asset loader + customer status bootstrap.

## Shared JS engine responsibilities

`assets/marketing-widgets.js` provides:

- proxy fetch helper (no direct Laravel URL calls)
- v1 contract parser (success/error envelope)
- state and recovery-state normalization
- development contract-mismatch logging
- per-widget mount/render lifecycle
- shared identity bootstrap from customer/app embed context
- clipboard helper for reward code copy

## State handling map

The storefront engine normalizes and renders these states:

- `unknown_customer`
- `linked_customer`
- `needs_verification`
- `known_customer_no_balance`
- `known_customer_has_balance`
- `reward_available`
- `reward_near_threshold`
- `reward_code_issued`
- `reward_code_redeemed`
- `reward_code_invalid`
- `reward_code_expired`
- `reward_code_already_used`
- `sms_not_consented`
- `sms_requested`
- `sms_confirmed`
- `email_not_consented`
- `email_confirmed`
- `incentive_available`
- `incentive_already_awarded`
- `eligible_for_winback`
- `eligible_for_reward_nudge`
- `online_only`
- `square_only`
- `recent_event_buyer`

Recovery states:

- `verification_required`
- `try_again_later`
- `already_redeemed`
- `contact_support`
- `unresolved_reconciliation_pending`

## Theme editor placement strategy

Recommended placements:

- Account page: balance, available rewards, history, redeem, consent.
- Cart page: balance, redeem, consent.
- Cart drawer: compact promo + consent.
- Product page: compact promo (light teaser mode).
- Homepage: compact promo and/or consent widget.

## Local preview instructions

1. Ensure app proxy is configured in Shopify app admin with subpath `apps/forestry`.
2. Start extension preview from app repo root:
   - `npm run shopify:app:dev -- --store modernforestry.myshopify.com`
3. In Theme Editor, enable `Forestry storefront tracking`.
4. In Shopify Customer Events, verify the Forestry marketing pixel is active after deploy.
5. Open a tagged email-style landing URL and confirm Message Analytics starts showing storefront funnel events.

## QA checklist

- unknown customer
- known customer with balance
- near threshold
- reward redemption success
- insufficient points
- active code reuse
- sms not consented
- sms requested
- sms confirmed
- verification required
- reconciliation pending
