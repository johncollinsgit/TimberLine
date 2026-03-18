# Candle Cash Lifecycle Automation Foundation (Step 9B)

## What This Adds
- A tenant-safe lifecycle evaluation service: `App\Services\Marketing\CandleCashLifecycleService`
- A minimal suppression/intent tracking table: `marketing_automation_events`
- An operator preview command:
  - `php artisan marketing:candle-cash-lifecycle-preview`

## Implemented Trigger Keys
- `candle_cash_earned_not_used`
- `candle_cash_reminder`
- `candle_cash_lapsed_with_value`

## Tenant Safety
- Lifecycle cohort evaluation scopes canonical profiles by `marketing_profiles.tenant_id`.
- Candle Cash event/redeem reads are tenant-scoped through profile joins.
- Store-specific lapsed checks use Shopify customer links (`marketing_profile_links`) and store-filtered orders.

## Contactability + Consent Boundaries
- `email` channel requires:
  - non-empty email on the canonical profile
  - `accepts_email_marketing = true`
  - `email_opted_out_at` is null
- `sms` channel requires:
  - non-empty phone on the canonical profile
  - `accepts_sms_marketing = true`
  - `sms_opted_out_at` is null

## Cooldown / Suppression
- Reminder suppression reads `marketing_automation_events` for recent open/sent statuses.
- Reminder events inside the configured cooldown window suppress re-eligibility.
- Optional intent recording uses `queued_intent` events and skips duplicate queue intent rows within a 24-hour dedupe window.

## Operator Preview
Run:

```bash
php artisan marketing:candle-cash-lifecycle-preview --tenant-id=1 --trigger=candle_cash_reminder --channel=email
```

Optional intent recording:

```bash
php artisan marketing:candle-cash-lifecycle-preview --tenant-id=1 --trigger=candle_cash_reminder --channel=email --record-intents
```

Use `--dry-run` to preview without writes, even when `--record-intents` is set.

