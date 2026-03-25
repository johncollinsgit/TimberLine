# Multi-Tenant Viability + Customer Ingestion Reverse-Engineering (2026-03-18)

> Historical reverse-engineering snapshot from 2026-03-18.
> For current cross-domain boundary guidance (including customer, inventory/ops, and candle-specific vs reusable layering), see:
> `docs/architecture/operational-multi-tenant-direction.md`.

## Purpose
Document the current Backstage/Shopify marketing architecture, assess viability for true multi-tenant operation, and define the best current workflow for ingesting new customers and rewards signups (email + SMS).

## System Architecture (As Implemented)

### 1) Core bounded contexts
- Operations/orders:
  - `orders`, `order_lines`, fulfillment/workflow tables
  - Shopify order ingest enters here first.
- Canonical marketing identity:
  - `marketing_profiles` (canonical person record)
  - `marketing_profile_links` (external/source identity links)
  - `customer_external_profiles` (provider snapshots like Shopify/Growave/Square)
- Campaign and messaging:
  - `marketing_campaigns`, `marketing_campaign_recipients`, `marketing_campaign_conversions`
  - `marketing_email_deliveries`, SMS delivery/consent event tables
- Rewards:
  - `candle_cash_balances`, `candle_cash_transactions`, `candle_cash_redemptions`, referrals/tasks
  - birthday/review extensions keyed to `marketing_profile_id`

### 2) Integration boundaries
- Shopify store registry + OAuth tokens:
  - `shopify_stores` table, model `App\Models\ShopifyStore`
  - resolver `App\Services\Shopify\ShopifyStores`
- Shopify webhook entry:
  - `webhooks/shopify/orders/*` in `routes/web.php`
  - controller `App\Http\Controllers\ShopifyWebhookController`
- Shopify storefront/app-proxy API:
  - `shopify/marketing/*` and `shopify/marketing/v1/*`
  - signature middleware `App\Http\Middleware\VerifyMarketingStorefrontRequest`

### 3) Runtime data flow (high-level)
1. Ingest source events (webhook/command/public endpoint)
2. Normalize + sync identity into `marketing_profiles` via `MarketingProfileSyncService`
3. Persist source links in `marketing_profile_links`
4. Persist provider snapshots in `customer_external_profiles` (where applicable)
5. Derive rewards, attribution, and campaign conversions from canonical profile + orders
6. Render embedded and Backstage UIs from canonical + derived tables

## Current Workflow: Ingesting New Customers

### A) Real-time from Shopify order activity (live today)
Path:
1. Shopify sends order webhook (`orders/create|updated|cancelled|refunds/create`)
2. `ShopifyWebhookController` verifies HMAC and dispatches `ShopifyUpsertOrder`
3. `ShopifyOrderIngestor` upserts order + identity fields + attribution
4. Ingestor dispatches `SyncMarketingProfileFromOrder`
5. `MarketingProfileSyncService::syncOrder` creates/updates canonical `marketing_profiles` and links:
   - `order:{id}`
   - `shopify_order:{store_key}:{shopify_order_id}`
   - `shopify_customer:{store_key}:{shopify_customer_id}` when available

Result:
- New customers are ingested automatically when they generate Shopify order events.

### B) Batch/backfill from Shopify customer graph (implemented, not webhook-driven)
Path:
- `shopify:sync-customer-metafields` command/job
- Reads Shopify customers via GraphQL (`ShopifyCustomerMetafieldFetcher`)
- Upserts `customer_external_profiles`
- Calls `MarketingProfileSyncService::syncExternalIdentity` to link/create canonical profiles

Important:
- This is command/job driven.
- No `customers/create` Shopify webhook ingestion path currently exists in routes.

### C) Public/event opt-in ingestion (implemented)
Path:
- `MarketingPublicEventController::storeOptin`
- Calls `MarketingProfileSyncService::syncExternalIdentity` with `allow_create` default true
- Applies consent via `MarketingConsentService`
- Optionally awards Candle Cash signup/consent tasks

### D) Shopify storefront/widget opt-in ingestion (implemented)
Path:
- `MarketingShopifyIntegrationController::requestConsentOptin`
- Uses `MarketingConsentCaptureService` + `MarketingProfileSyncService` with `allow_create => true`
- Can capture SMS/email consent and bonuses
- Stores canonical identity + consent events

Important:
- This creates/links in Backstage canonical profile space.
- It does **not** automatically create a Shopify customer record when one does not exist.

## Multi-Tenant Viability Assessment

### Verdict
Current state is **multi-store capable inside one business context**, but **not yet true SaaS multi-tenant ready**.

### What is already helpful
- `shopify_stores` + `store_key` concept is established.
- Many ingestion records include `store_key` or store-aware source IDs.
- Customer external uniqueness already includes `(provider, integration, store_key, external_customer_id)`.
- Embedded context tokens include `store_key`.

### Major blockers for true tenant isolation
1. Canonical marketing entities are global (no tenant key)
- `marketing_profiles`, campaign tables, rewards tables, deliveries, consent, etc. are keyed by profile/campaign IDs but not tenant IDs.

2. Query layers are mostly global
- Example: embedded dashboard service computes from broad tables without hard tenant/store isolation in the query contract.
- Backstage customer pages are canonical/global by default.

3. Tenant model exists but is not wired
- `tenants` table exists.
- `tenant_id` appears on orders schema history, but tenant scoping is not enforced across the marketing domain.

4. Middleware/auth are not tenant-bound
- App auth/roles are not partitioning by tenant.
- Storefront signing secret defaults are single-config style, not per-tenant secret governance.

5. Job/scheduler isolation is partial
- Scheduled jobs are configured globally.
- No explicit tenant partition orchestration in scheduler/queue topology.

### Practical readiness score
- Multi-store within one operator org: **High**
- True tenant-safe SaaS (data + auth + billing + ops isolation): **Low-to-Medium** until schema + query scoping are completed.

## Recommended Multi-Tenant Target Shape

### Minimum viable model
- Add `tenant_id` to all canonical marketing/rewards/campaign tables.
- Keep existing IDs, but enforce tenant-scoped uniqueness and filtering.
- Make all reads/writes go through tenant-resolving context middleware/service.

### Key technical guardrails
- Global scopes or repository layer requiring tenant context for canonical reads.
- Queue payloads include tenant/store context.
- Cache keys include tenant/store context.
- Webhook/storefront handlers resolve tenant from store mapping before any write.
- Per-tenant secrets/config (or tenant->store secret mapping) for storefront verification.

## Recommendation: Rewards Signup (Email + SMS) + Customer Creation

### Best operational flow (now)
Use the existing Shopify storefront consent endpoint as primary rail:
- `POST /shopify/marketing/v1/consent/request`
- Keep `allow_create => true` for canonical Backstage profile creation.
- Continue explicit checkbox-based SMS/email consent capture.

This gives:
- Immediate canonical customer creation in Backstage (`marketing_profiles`)
- Consent auditability (`marketing_consent_*`)
- Rewards eligibility linkage (`marketing_profile_id`)

### Gap to close
If a person signs up for rewards but is not a Shopify customer yet, there is no current automatic Shopify customer creation call.

### Recommended enhancement
Add a dedicated provisioning service + job:
- `ShopifyCustomerProvisioningService`
- Trigger after successful signup when:
  - no `shopify_customer` link exists for store
  - email (required) is present
- Behavior:
  1. Create/find Shopify customer via Admin API
  2. Upsert `customer_external_profiles` (`integration=shopify_customer`)
  3. Upsert canonical `marketing_profile_links` `shopify_customer:{store_key}:{customer_id}`
  4. Idempotent by `(store_key, normalized_email)` and existing link checks

### Consent and deliverability rules
- Require explicit SMS checkbox (unchecked by default).
- Keep SMS double opt-in where required by compliance policy.
- Require email for rewards signup if email channel is required operationally.
- Persist consent source metadata so downstream campaign eligibility is deterministic.

## Concrete Next Steps (ordered)
1. Add tenant context contract and table migration plan (`tenant_id` across canonical marketing tables).
2. Introduce tenant-aware query scopes in marketing/dashboard services.
3. Implement Shopify customer provisioning on rewards signup (idempotent job).
4. Add Shopify `customers/create` webhook ingestion path (optional but high value for near-real-time customer graph completeness).
5. Add tenant/store dimensions to reporting and cache keys.

## Evidence Files Reviewed
- `routes/web.php`
- `routes/console.php`
- `app/Http/Controllers/ShopifyWebhookController.php`
- `app/Http/Controllers/ShopifyAuthController.php`
- `app/Http/Controllers/Marketing/MarketingShopifyIntegrationController.php`
- `app/Http/Controllers/Marketing/MarketingConsentCaptureController.php`
- `app/Http/Controllers/Marketing/MarketingPublicEventController.php`
- `app/Services/Shopify/ShopifyOrderIngestor.php`
- `app/Jobs/ShopifyUpsertOrder.php`
- `app/Jobs/SyncMarketingProfileFromOrder.php`
- `app/Services/Marketing/MarketingProfileSyncService.php`
- `app/Services/Marketing/MarketingIdentityExtractor.php`
- `app/Services/Marketing/ShopifyCustomerMetafieldSyncService.php`
- `app/Services/Shopify/ShopifyCustomerMetafieldFetcher.php`
- `app/Services/Shopify/ShopifyStores.php`
- `app/Services/Shopify/Dashboard/ShopifyEmbeddedDashboardDataService.php`
- `app/Services/Marketing/MarketingStorefrontRequestVerifier.php`
- `database/migrations/2026_02_04_031802_create_tenants_table.php`
- `database/migrations/2026_02_18_130000_create_shopify_stores_table.php`
- `database/migrations/2026_03_10_130000_create_marketing_foundation_tables.php`
- `database/migrations/2026_03_10_210000_create_marketing_campaign_domain_tables.php`
- `database/migrations/2026_03_12_100000_create_marketing_email_and_candle_cash_tables.php`
- `database/migrations/2026_03_16_090000_create_customer_external_profiles_table.php`
