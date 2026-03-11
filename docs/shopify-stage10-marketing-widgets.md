# Stage 10 Shopify Storefront Widgets

## Extension structure

```text
extensions/forestry-marketing/
  assets/
    marketing-widgets.css
    marketing-widgets.js
  blocks/
    marketing-app-embed.liquid
    rewards-balance-block.liquid
    available-rewards-block.liquid
    reward-history-block.liquid
    redeem-reward-block.liquid
    sms-consent-block.liquid
    compact-rewards-promo-block.liquid
  locales/
    en.default.json
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

Expected Shopify app proxy target:

- `/apps/forestry/*` -> `https://<laravel-host>/shopify/marketing/v1/*`

Current app proxy target in this repo:

- `forestry-marketing-app/shopify.app.toml` -> `https://backstage.theforestrystudio.com/shopify/marketing/v1`

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
- `https://backstage.theforestrystudio.com/shopify/marketing/v1/health` (direct signed probe):
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
   - `shopify app dev --store modern-forestry-dev.myshopify.com`
3. In Theme Editor, enable `Forestry marketing embed`.
4. Add desired app blocks to account/cart/home/product templates.
5. Validate state transitions against QA checklist below.

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
