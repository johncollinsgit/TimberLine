# Shopify Customer Sync Health (Step 6)

This internal operational page lives at:

- `marketing.providers-integrations.shopify-customer-sync-health`

It is intentionally read-only and does **not** change customer identity state.

## Signals Aggregated

Per installed Shopify store (`shopify_stores`):

- webhook subscription verification summary (via `ShopifyWebhookSubscriptionService`)
- last successful customer webhook ingestion timestamp (from `customer_external_profiles.raw_metafields.shopify_customer_webhook.received_at`)
- recent provisioning and webhook-ingestion failures (from `failed_jobs`, when payload context is parseable)
- unresolved Shopify customer identity conflicts (from pending `marketing_identity_reviews`)
- auth/token health indicator (token + install metadata + verification failures)

## Status Buckets

- `healthy`: webhooks aligned, auth healthy, no recent failure/conflict signals, and ingestion observed
- `warning`: webhook drift and/or recent failures/conflicts detected
- `failing`: auth invalid/missing or webhook verification failures indicate broken sync rail
- `unknown`: insufficient ingestion signal yet

## Launch Gate Semantics

- Required store keys come from `SHOPIFY_REQUIRED_STORE_KEYS` (runtime config).
- Current launch model: `retail` required, `wholesale` optional.
- Optional store failures still appear in diagnostics, but launch gate readiness is computed from required stores only.

## Operational Use

Use this page for first-pass diagnosis before log deep-dives.  
For launch-critical webhook checks, run:

- `php artisan shopify:webhooks:verify --required-only`
- `php artisan shopify:webhooks:verify retail`

For webhook drift remediation, run:

- `php artisan shopify:webhooks:verify --repair`
