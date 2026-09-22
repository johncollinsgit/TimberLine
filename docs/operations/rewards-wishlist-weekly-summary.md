# Rewards + Wishlist Weekly Summary

## Purpose

The weekly summary gives an operator a low-volume check that the customer-facing Candle Cash and wishlist flows are being used and surfaces recorded failures before they become support reports. It reads the existing canonical records only; it does not change balances, reward codes, wishlist items, customers, or Shopify.

The Modern Forestry schedule sends the report to `info@theforestrystudio.com` every Monday at 8:30 AM America/New_York.

## Canonical inputs

- Candle Cash earnings: `candle_cash_transactions`, tenant-scoped through `marketing_profiles`.
- Issued and completed rewards: `candle_cash_redemptions`, tenant-scoped through `marketing_profiles`.
- Storefront checks, applies, failures, and wishlist interactions: `marketing_storefront_events.tenant_id`.
- Current wishlist state and most-saved products: `marketing_profile_wishlist_items.tenant_id`.

The email contains aggregate counts only. It intentionally omits customer names, email addresses, phone numbers, and reward codes.

## Health labels

- `Working`: at least one successful status check, reward apply, wishlist add, or wishlist removal was recorded, with no recorded interaction failures in the window.
- `Needs attention`: one or more reward apply failures, reward fallback cards, or wishlist errors were recorded.
- `No activity observed`: no positive activity and no failures were recorded. This is neutral; a quiet week alone is not treated as an outage.

The report separates storefront apply telemetry from completed redemption records. A customer can apply a Shopify discount before an order is completed, so those values should not be treated as interchangeable.

## Manual verification

Build and inspect a report without sending email:

```bash
php artisan marketing:send-weekly-rewards-wishlist-summary \
  --tenant=modern-forestry \
  --email=info@theforestrystudio.com \
  --days=7 \
  --dry-run
```

Send a real report after confirming production mail readiness:

```bash
php artisan marketing:send-weekly-rewards-wishlist-summary \
  --tenant=modern-forestry \
  --email=info@theforestrystudio.com \
  --days=7
```

Verify the scheduler registration with:

```bash
php artisan schedule:list
```

## Triage

When the report says `Needs attention`:

1. Compare `apply failures` with `successful applies` and `successful status checks`.
2. If fallback cards were shown, inspect `marketing_storefront_events` for `reward_status_fallback_rendered`, including `issue_type`, surface, and timestamp.
3. Inspect wishlist errors by `issue_type`; missing identity and missing store context are distinct from backend exceptions.
4. Re-run the report with a shorter `--days` window to isolate when the issue started.
5. Verify the signed app-proxy routes and storefront manually before changing rewards data.

Do not repair a reporting anomaly by editing Candle Cash ledgers or moving rewards between profiles. Follow the canonical Candle Cash audit and reconciliation runbook for actual balance drift.
