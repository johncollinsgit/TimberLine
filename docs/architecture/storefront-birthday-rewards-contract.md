# Storefront Birthday Rewards Contract

This contract defines how `www.theforestrystudio.com` talks to Backstage for birthday rewards and other usable reward codes.

## Source of Truth

Backstage is authoritative for:

- birthday reward state
- activation state
- redemption state
- Shopify discount sync status
- reward history and audit events

The storefront does not own parallel reward state.

## Transport

Storefront requests are expected to use the Shopify app proxy surface:

- `GET /apps/forestry/customer/status`
- `GET /apps/forestry/rewards/history`
- `GET /apps/forestry/birthday/status`
- `POST /apps/forestry/birthday/capture`
- `POST /apps/forestry/birthday/claim`
- `POST /apps/forestry/rewards/event`

Equivalent `/shopify/marketing/v1/*` routes exist in Laravel, but theme code should use the proxied `/apps/forestry/*` paths.

Canonical Shopify app identity:

- Shopify app: `ModernForestryBackstage`
- client id: `197d01d6597c938c96b3b35fae6a087c`

The storefront proxy and signed storefront requests should verify against the canonical retail Shopify app secret. Do not point rewards traffic at the legacy split-out theme app.

## Identity Inputs

The storefront may send any stable subset of:

- `marketing_profile_id`
- `shopify_customer_id`
- `email`
- `phone`
- `first_name`
- `last_name`

Backstage resolves identity through the existing canonical marketing profile pipeline.

## Birthday Reward Payload

`GET /apps/forestry/customer/status` returns birthday state under:

- `data.birthday.state`
- `data.birthday.birthday`
- `data.birthday.issuance`

The issuance payload is expected to include:

- `id`
- `status`
- `reward_name`
- `reward_value`
- `reward_code`
- `expires_at`
- `claimed_at`
- `activated_at`
- `redeemed_at`
- `shopify_store_key`
- `shopify_discount_id`
- `shopify_discount_node_id`
- `discount_sync_status`
- `discount_sync_error`
- `discount_title`
- `apply_path`
- `is_activated`
- `is_redeemed`
- `is_usable`
- `order_number`
- `order_total`

`apply_path` is the storefront-safe way to carry the birthday discount into Shopify cart/checkout.

## Other Reward Codes

`GET /apps/forestry/rewards/history` returns active non-birthday codes in:

- `data.redemptions`

Current contract assumption:

- birthday rewards are Shopify-backed and safe to apply via `apply_path`
- other Candle Cash redemptions are displayed as reusable codes
- storefront may offer copy-to-clipboard for those codes

## Storefront Event Logging

The storefront should log meaningful interactions through:

- `POST /apps/forestry/rewards/event`

Approved event types:

- `reward_view`
- `reward_activate_click`
- `reward_activation_success`
- `reward_activation_failure`
- `reward_apply_click`
- `reward_apply_success`
- `reward_apply_failure`
- `reward_confetti_shown`

Payload fields:

- `event_type`
- `request_key`
- `reward_code`
- `reward_kind`
- `surface`
- `state`
- `message`
- `meta`

`request_key` is idempotent for that event type. Replayed requests with the same key should not create duplicate event rows.

## Storefront State Rules

The theme should treat these birthday card states as canonical projections:

- `missing_birthday`
- `available`
- `activated`
- `applied`
- `redeemed`
- `expired`
- `sync_failed`
- `unavailable`

The storefront may show optimistic loading states, but it should rehydrate from Backstage after any mutation.

## Apply Flow

Birthday reward apply flow:

1. customer clicks `Use My Birthday Coupon!`
2. theme logs `reward_apply_click`
3. theme redirects to `issuance.apply_path`
4. cart reloads
5. theme rehydrates
6. if the cart discount title matches the issuance discount title:
   - show success state
   - log `reward_apply_success`
   - log `reward_confetti_shown`

If the birthday code does not appear applied after redirect, the theme should:

- restore UI usability
- show a calm failure message
- log `reward_apply_failure`

## Dependency Note

If the Shopify app is not installed on the store, `/apps/forestry/*` will 404 at the storefront layer even if Backstage is healthy.

In that case:

- the theme should fail gracefully
- the rewards surface should not assume live proxy availability
