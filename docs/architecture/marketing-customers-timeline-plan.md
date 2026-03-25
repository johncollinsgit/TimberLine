# Marketing Customers Timeline Plan (2026-03-11)

## Goal
Define the next-stage architecture for a unified customer timeline in the customer detail page, combining operational and marketing events under canonical `marketing_profiles` identity.

## Current detail-page sources
`MarketingCustomersController::show` already loads these streams per profile:

- Shopify orders: `orders` linked through `marketing_profile_links` (`shopify_order`, `shopify_customer`, plus generic order links).
- Square activity: `square_orders` and `square_payments` linked through `marketing_profile_links` (`square_order`, `square_payment`, `square_customer`).
- Growave enrichment snapshots: `customer_external_profiles` where `integration = growave`.
- Reviews: currently planned; inferred only from source-link signals.
- Marketing activity: `messageDeliveries`, `campaignConversions`, and `consentEvents` relationships.
- Email delivery diagnostics: `emailDeliveries` (`marketing_email_deliveries`) with provider-context derivation via `MarketingEmailDeliveryProviderContext`.

The detail page now exposes a `Unified Customer Timeline Plan` section that reports stream readiness and current counts.

## Current implemented provider-context diagnostics (email timeline)
The customer detail page `Email Delivery Timeline` now renders canonical provider-context diagnostics per row using persisted `marketing_email_deliveries.metadata` fields:
- `provider_resolution_source`
- `provider_readiness_status`
- `provider_config_status`
- `provider_using_fallback_config`

Implemented row-level operator labels include:
- sent via tenant-configured provider
- sent via fallback provider config
- attempted with unsupported provider runtime
- blocked by incomplete provider setup
- provider context unavailable for legacy row

The surface also includes compact summary counts for:
- tenant vs fallback resolution paths
- unsupported/incomplete attempts
- legacy/unknown-context rows
- resolution/readiness mix chips

Legacy rows are explicitly labeled as unavailable/unknown context and are never backfilled from current runtime config.

## Canonical event envelope (planned)
Future timeline items should be rendered from a normalized event envelope:

- `marketing_profile_id` (required canonical owner)
- `event_type` (e.g. `shopify_order_placed`, `square_payment_completed`, `growave_points_earned`, `review_submitted`, `sms_delivered`)
- `occurred_at` (source event time)
- `source_system` (`shopify`, `square`, `growave`, `reviews`, `marketing`)
- `source_type` and `source_id` (pointer to original row/id)
- `summary` (human-readable line for UI)
- `payload` (small JSON details for expansion)

## Stream mapping plan
1. Shopify orders
- Source: `orders` + `marketing_profile_links`.
- Key event types: placed, fulfilled, refunded, canceled.
- Ordering key: `orders.ordered_at` fallback `orders.created_at`.

2. Square purchases/payments
- Source: `square_orders`, `square_payments`, `marketing_profile_links`.
- Key event types: order closed, payment captured, payment refunded/voided.
- Ordering key: `closed_at` or source-created timestamps.

3. Growave loyalty activity
- Current source: `customer_external_profiles` snapshots.
- Next source: immutable rewards ledger events once Candle Cash ledger rollout is complete.
- Key event types: points earned, points redeemed, reward issued, reward expired.

4. Reviews
- Future source: provider review events linked through canonical profile links.
- Key event types: review request sent, review submitted, review reward granted.

5. Messages/marketing
- Source: `messageDeliveries`, `campaignConversions`, `consentEvents`.
- Key event types: message sent/delivered/failed, conversion attributed, consent updated.

## Implementation stages
1. Keep current per-stream sections as operational truth.
2. Add a read-only timeline composer service that merges these sources in-memory.
3. Introduce pagination and date-range filtering on the merged stream.
4. Persist normalized timeline rows only if query cost becomes material.

## Identity rule
Timeline rows must always resolve through `marketing_profile_id`. External providers decorate timeline context but do not define profile existence.
