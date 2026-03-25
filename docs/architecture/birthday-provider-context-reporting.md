# Birthday Provider-Context Reporting (Step)

Date: 2026-03-24  
Status: Implemented

## Goal
Expose tenant-aware provider resolution and readiness context directly in birthday analytics, campaign diagnostics, customer timeline diagnostics, and exports so operators do not need raw JSON metadata inspection.

## Canonical Source Of Truth
- Delivery lifecycle source: `marketing_email_deliveries`
- Provider context source: pre-dispatch metadata stamped on each delivery row
  - `provider_resolution_source`
  - `provider_readiness_status`
  - `provider_config_status`
  - `provider_using_fallback_config`
- Context derivation service:
  - `App\Services\Marketing\MarketingEmailDeliveryProviderContext`

Derived values are read from persisted delivery context. Reporting does **not** infer resolution/readiness from current runtime config.

## Derived Dimensions
- `provider_resolution_source`
  - `tenant`
  - `fallback`
  - `none`
  - `unknown` (legacy/unavailable)
- `provider_readiness_status`
  - `ready`
  - `unsupported`
  - `incomplete`
  - `error`
  - `not_configured`
  - `unknown` (legacy/unavailable)
- `provider_config_status`
- `provider_using_fallback_config`
- runtime-path label
  - tenant runtime ready
  - fallback runtime ready
  - unsupported runtime
  - blocked by readiness
  - legacy/unavailable

## Birthday Analytics Integration
Service:
- `App\Services\Marketing\BirthdayReportingService`

Added:
- filter support:
  - `provider_resolution_source`
  - `provider_readiness_status`
- breakdowns:
  - `provider_resolution_breakdown`
  - `provider_readiness_breakdown`
  - `top_failure_reasons_by_resolution_source`
- options payload:
  - `provider_resolution_sources`
  - `provider_readiness_statuses`
- export rows:
  - provider context fields per row
  - `provider_resolution_breakdown`
  - `provider_readiness_breakdown`
  - `failure_reason_by_resolution_source`

Legacy rows without provider-context metadata remain visible and are labeled `unknown` with explicit notes.

## Embedded Birthday UI Integration
Controller:
- `App\Http\Controllers\ShopifyEmbeddedRewardsController`

View:
- `resources/views/shopify/rewards-birthdays.blade.php`

Added:
- filter controls for resolution source and readiness status
- provider resolution table
- provider readiness table
- segmented failure reasons by resolution source

## Campaign Diagnostics Integration
Service:
- `App\Services\Marketing\MarketingCampaignDeliveryDiagnostics`

View:
- `resources/views/marketing/campaigns/show.blade.php`

Added:
- row-level provider context labels on delivery diagnostics
- grouped diagnostics summaries:
  - by resolution source
  - by readiness status
  - by runtime path

## Customer Timeline Integration
Controller:
- `App\Http\Controllers\Marketing\MarketingCustomersController`

View:
- `resources/views/marketing/customers/show.blade.php`

Added:
- canonical row enrichment using `MarketingEmailDeliveryProviderContext` for each email timeline row
- concise row-level labels:
  - sent via tenant-configured provider
  - sent via fallback provider config
  - attempted with unsupported provider runtime
  - blocked by incomplete provider setup
  - provider context unavailable for legacy row
- lightweight summary diagnostics above the timeline:
  - tenant vs fallback path attempts
  - unsupported/incomplete attempt count
  - legacy/unknown context row count
  - resolution/readiness mix chips
- provider-context-aware failure hints on failed timeline rows

## Guardrails
- No new analytics store/table was added.
- Reporting remains tenant-scoped and filter-aware.
- Unsupported/incomplete provider attempts remain visible.
- Unknown legacy rows are never fabricated into tenant/fallback states.

## Tests
- `tests/Feature/Marketing/BirthdayAnalyticsReportingTest.php`
- `tests/Feature/ShopifyEmbeddedBirthdayAnalyticsTest.php`
- `tests/Feature/Marketing/MarketingCampaignDeliveryDiagnosticsTest.php`
- `tests/Feature/Marketing/MarketingCustomersManagementExperienceTest.php`
