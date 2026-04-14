# Shopify Webhook Subscription Enforcement (Step 5)

## Required Topics

Configured in [`config/shopify_webhooks.php`](/Users/johncollins/Code/myapp/config/shopify_webhooks.php):

- `orders/create`
- `orders/updated`
- `orders/cancelled`
- `refunds/create`
- `customers/create`
- `customers/update`

Each topic maps to an app route name and resolves to an absolute callback URL on the canonical landlord host (`TENANCY_CANONICAL_LANDLORD_HOST` via tenancy host builder), with `APP_URL` only as a final fallback.

## Runtime Trigger

Webhook verification/repair runs automatically at Shopify OAuth callback in:

- [`ShopifyAuthController::callback()`](/Users/johncollins/Code/myapp/app/Http/Controllers/ShopifyAuthController.php)

After access token persistence, the app enforces required subscriptions for the connected store.

## Manual Verify / Repair

Use:

```bash
php artisan shopify:webhooks:verify
php artisan shopify:webhooks:verify --required-only
php artisan shopify:webhooks:verify retail
php artisan shopify:webhooks:verify --repair
php artisan shopify:webhooks:verify retail --repair
```

Behavior:

- `verify`: detect drift (`missing` / `mismatch`) and fail exit code when drift exists.
- `verify --required-only`: launch-gate mode (checks only required store keys from config).
- `--repair`: create missing subscriptions and repair mismatched callbacks idempotently.
- Optional-store failures remain visible in output but are non-fatal unless that store is marked required.

## Safety Rules

- Per-store verification only (uses each store's own token and domain).
- No deletion of unrelated webhook subscriptions.
- Logs include `store_key`, `shop`, `topic`, intended callback, and failure reason.

## Scope Requirement

Webhook enforcement requires Shopify webhook scopes (`read_webhooks`, `write_webhooks`).
Existing stores without these scopes may need reinstall:

- `/shopify/reinstall/{store}`
