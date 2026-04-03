# Marketing Customers Debug Notes (2026-03-11)

## Canonical conversion note (2026-04-03)
- Legacy Growave points now convert with `candle_cash = legacy_points * 0.3`.
- Converted legacy balances are grandfathered opening credit and excluded from earned-limit scope.
- Canonical reference customer for migration reporting: `Rynda Baker <bakery25@gmail.com>` with `1494 * 0.3 = 448.200` Candle Cash.

## Root cause (empty customer table)
- `Marketing Customers` reads from `marketing_profiles` (`app/Http/Controllers/Marketing/MarketingCustomersController.php`).
- Existing backfill command `marketing:sync-profiles` can skip orders when no normalized email/phone is available.
- Shopify-derived orders without persisted contact fields were being counted as `missing_email_phone`, so no profile rows were created.

## What is now wired
- Shopify order sync path:
  - `app/Services/Shopify/ShopifyOrderIngestor.php` dispatches `SyncMarketingProfileFromOrder`.
  - `app/Jobs/SyncMarketingProfileFromOrder.php` calls `MarketingProfileSyncService::syncOrder`.
- Backfill path:
  - `app/Console/Commands/MarketingSyncProfiles.php` (`marketing:sync-profiles`).
  - New wrapper command `marketing:rebuild-customers`.
- Source-only Shopify fallback in sync service:
  - `app/Services/Marketing/MarketingProfileSyncService.php` now allows profile creation from Shopify source links even when email/phone are missing.

## Growave status
- Growave ingestion is present:
  - command: `shopify:sync-customer-metafields`
  - service: `App\Services\Marketing\ShopifyCustomerMetafieldSyncService`
  - parser: `App\Services\Marketing\GrowaveCustomerMetafieldParser`
  - storage: `customer_external_profiles` table
- Marketing customer index page now shows an admin-facing status panel for Growave snapshot state and actionable commands.

## TODO (safe next steps)
- Add scheduler entries for:
  - `marketing:rebuild-customers --shopify-only`
  - `shopify:sync-customer-metafields retail --limit=200` (and wholesale).
- Add alerting on repeated `reason_missing_email_phone` spikes in `marketing:sync-profiles` output.
