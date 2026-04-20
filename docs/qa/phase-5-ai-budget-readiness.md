# Phase 5 AI Budget Readiness QA (2026-04-20)

## Scope

Phase 5 ships **advisory-only** AI budget readiness inside existing messaging analytics home.

Included:
- readiness scorecard with metric formulas, thresholds, and pass/warn/fail states
- Meta paid spend ingestion path (minimal, rerunnable)
- recommendation-only budget guidance (no autonomous spend mutation)
- hard guardrail policy output that blocks autonomous budget control

Not included:
- autonomous budget changes
- automatic campaign pausing
- automatic channel reallocation

## Core implementation

- Readiness + policy + advisory recommendations:
  - `app/Services/Marketing/AiBudgetReadinessService.php`
  - `app/Services/Marketing/AiBudgetRecommendationService.php`
- Meta spend ingestion:
  - `app/Services/Marketing/MetaAdsSpendSyncService.php`
  - `app/Console/Commands/MarketingSyncMetaAdsSpend.php`
  - `app/Models/MarketingPaidMediaDailyStat.php`
  - `database/migrations/2026_04_20_200000_create_marketing_paid_media_daily_stats_table.php`
- Analytics payload wiring:
  - `app/Services/Marketing/MessageAnalyticsService.php`
- Operator surface:
  - `resources/views/shopify/messaging-analytics.blade.php`

## Manual sync command

```bash
php artisan marketing:sync-meta-ads-spend \
  --tenant-id=<TENANT_ID> \
  --store-key=retail \
  --account-id=<META_ACCOUNT_ID> \
  --since=2026-01-01 \
  --until=2026-12-31
```

Dry-run:

```bash
php artisan marketing:sync-meta-ads-spend --tenant-id=<TENANT_ID> --dry-run
```

## Env/config required

- `MARKETING_META_ADS_ENABLED=true`
- `MARKETING_META_ADS_ACCESS_TOKEN=<token>`
- `MARKETING_META_ADS_ACCOUNT_ID=<account_id_without_act_prefix>`

Optional tuning:
- `MARKETING_META_ADS_API_VERSION`
- `MARKETING_META_ADS_DEFAULT_LOOKBACK_DAYS`
- `MARKETING_META_ADS_TIMEOUT_SECONDS`

## Readiness guardrails (current behavior)

Allowed now:
- advisory recommendations only (human review)
- audience suggestions
- creative/copy suggestions

Blocked now:
- automatic budget mutation
- automatic campaign pausing
- automatic channel reallocation

## QA checklist (production after deploy)

1. Run Meta sync for tenant/store and confirm `status=ok`.
2. Verify `marketing_paid_media_daily_stats` has rows for expected dates/campaigns.
3. Open `/shopify/app/messaging/analytics?analytics_tab=home` and confirm **AI Budget Readiness** panel renders.
4. Confirm metric table is populated (not all `Insufficient data`) after spend sync.
5. Confirm policy table shows advisory action state and autonomous actions blocked.
6. Confirm recommendation queue lists human-review actions only.
7. Validate blockers clear/persist correctly when data quality changes (UTM/self-referral/linkage/spend freshness).

## Automated tests

```bash
php -d memory_limit=512M ./vendor/bin/pest \
  tests/Feature/Marketing/MetaAdsSpendSyncServiceTest.php \
  tests/Feature/Marketing/AiBudgetReadinessServiceTest.php \
  tests/Feature/Marketing/MessageAnalyticsDecisionPanelsTest.php

php -d memory_limit=512M ./vendor/bin/pest tests/Feature/ShopifyEmbeddedMessagingTest.php --filter="analytics"
```
