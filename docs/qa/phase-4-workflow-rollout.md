# Phase 4 Workflow Rollout QA (2026-04-20)

## Scope

Shipped and validated in this phase:
- welcome workflow staging
- winback workflow staging
- post-purchase cross-sell workflow staging
- workflow status surface at `/marketing/automations`
- explicit blocker visibility for cart/checkout abandonment

Out of scope:
- browse abandonment rollout
- paid retargeting sync
- AI budget controls

## Workflow Status Snapshot

1. `welcome` -> can ship now (manual-first)
2. `winback` -> can ship now (manual-first)
3. `post_purchase_cross_sell` -> can ship now (manual-first)
4. `wishlist_triggered_offer` -> can ship now (existing manual queue)
5. `cart_abandonment` -> needs small build (token/profile continuity blockers)
6. `checkout_abandonment` -> needs small build (checkout token + purchase continuity blockers)

## Launch QA Checklist (for each staged workflow)

### Welcome
- [ ] Open `/marketing/automations` and click **Prepare approval queue** on Welcome.
- [ ] Verify redirect to campaign detail and queued recipient count is non-zero.
- [ ] Validate queued recipients have `reason_codes` including `workflow_welcome`.
- [ ] Confirm consent-only profiles with recent purchases are suppressed.

### Winback
- [ ] Prepare Winback from `/marketing/automations`.
- [ ] Verify recipients are repeat buyers and lapsed beyond stale threshold.
- [ ] Verify recipients are queued for approval (not auto-sent).
- [ ] Confirm suppression rows are logged in `marketing_automation_events` with clear reason.

### Post-purchase Cross-sell
- [ ] Prepare Post-purchase Cross-sell from `/marketing/automations`.
- [ ] Verify recipients are first-time buyers in the configured post-order window.
- [ ] Verify `segment_snapshot.workflow_context.product_family` is populated when order lines permit inference.
- [ ] Confirm second-order customers are suppressed/excluded.

## Data Truth Checks

- [ ] `marketing_automation_events` receives `campaign_queued`, `skipped`, and `suppressed` statuses with reasons.
- [ ] `marketing_campaign_recipients` are created/updated with `queued_for_approval` or `skipped` (no automatic sends).
- [ ] Campaign objectives persist as lifecycle objective keys without validation errors.

## Blockers Before Cart/Checkout Abandonment Launch

- `add_to_cart` cart-token coverage must exceed target threshold.
- `checkout_started` checkout-token coverage must exceed target threshold.
- Purchase linkage continuity (checkout token + linked storefront event) must be reliable enough to avoid post-purchase spam.
- Profile linkage on funnel events must be adequate for suppression after conversion.
