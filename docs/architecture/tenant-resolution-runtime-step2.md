# Tenant Resolution Runtime Wiring (Step 2)

This step keeps `marketing_profiles` as the canonical customer model and layers tenant scoping onto existing store-aware flows.

## Resolution Rule

1. Resolve store context (`store_key`) from the ingress payload.
2. Resolve `tenant_id` through `shopify_stores.tenant_id` using `App\Services\Tenancy\TenantResolver`.
3. Carry `tenant_id` through service/job context.
4. Stamp tenant-owned writes with `tenant_id` when resolved.

## Flows Now Tenant-Aware

- Shopify webhook ingress:
  - `ShopifyWebhookController` resolves tenant from store context.
  - `ShopifyUpsertOrder` carries `tenant_id` into ingestion.
  - `ShopifyOrderIngestor` stamps `orders.tenant_id` and forwards tenant context to marketing sync jobs.
- Shopify app-proxy/storefront widget ingress:
  - `MarketingShopifyIntegrationController` resolves `{store_key, tenant_id}` from request (`store_key`, `store`, or `shop` domain).
  - Profile resolution, identity linking, and consent capture context now include tenant metadata.
- Public consent/event capture:
  - `MarketingConsentCaptureController` and `MarketingPublicEventController` accept optional store context and resolve tenant where available.

## Tenant-Stamped Tables in This Step

- `shopify_stores`
- `marketing_profiles`
- `marketing_profile_links`
- `marketing_consent_requests`
- `marketing_consent_events`
- `customer_external_profiles`
- `marketing_storefront_events`
- `orders` (already present; now actively stamped at runtime)

## Matching Isolation

- `MarketingProfileMatcher` now scopes normalized email/phone matching by tenant when `tenant_id` is resolved.
- `MarketingProfileSyncService` scopes source-link lookups and link upserts by tenant.

## Transitional Behavior

- If tenant cannot be resolved, flows keep legacy behavior and write nullable `tenant_id`.
- This avoids unsafe tenant guessing while keeping existing ingress endpoints operational.
- Remaining follow-up is a backfill to reduce nullable tenant rows and tighten enforcement per ingress path.
