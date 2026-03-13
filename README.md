# Modern Forestry Backstage

## Shopify (Phase 1)
Required environment keys:
- `SHOPIFY_RETAIL_SHOP`
- `SHOPIFY_RETAIL_CLIENT_ID`
- `SHOPIFY_RETAIL_CLIENT_SECRET`
- `SHOPIFY_WHOLESALE_SHOP`
- `SHOPIFY_WHOLESALE_CLIENT_ID`
- `SHOPIFY_WHOLESALE_CLIENT_SECRET`
- `SHOPIFY_API_VERSION` (default `2026-01`)
- `SHOPIFY_SCOPES` (default `read_orders,read_products,read_customers`)
- `SHOPIFY_ALLOW_ENV_TOKEN_FALLBACK` (default `false`, legacy only)

OAuth (Admin) routes:
- `/shopify/auth/{store}`
- `/shopify/callback/{store}`
- `/shopify/reinstall/{store}`

CLI helper:
- `php artisan shopify:auth retail`
- `php artisan shopify:auth wholesale`

Notes:
- OAuth access tokens are stored in `shopify_stores` and encrypted at rest.
- CLI imports/sync use DB-installed OAuth tokens as primary source of truth.
- Static env access tokens are legacy fallback only when `SHOPIFY_ALLOW_ENV_TOKEN_FALLBACK=true`.
- `shopify:sync-customer-metafields` requires Admin API `read_customers` or `write_customers` scope; Customer Account `customer_*` scopes are not sufficient for Admin `customers` queries.
- Webhooks are verified with HMAC and dispatched to a sync queue (Phase 1).

## Deployment (Forge / Production)
This app uses Vite. Production must build assets and remove any dev hot file.

Required in deploy script:
- `npm ci`
- `npm run build`
- `php artisan view:clear`

If `public/hot` exists on the server, delete it so `@vite` uses the manifest:
- `rm -f public/hot`
Deploy proof: Mon Feb 23 14:03:40 EST 2026
Deploy proof: Mon Feb 23 14:04:21 EST 2026

## Twilio SMS Configuration
- Set `TWILIO_ACCOUNT_SID` and `TWILIO_AUTH_TOKEN`.
- Configure one sender identity before live sends:
  - `TWILIO_MESSAGING_SERVICE_SID` (recommended, must start with `MG`), or
  - `TWILIO_FROM_NUMBER` (E.164 format like `+18339625949`).
- Enable provider flags:
  - `MARKETING_SMS_ENABLED=true`
  - `MARKETING_TWILIO_ENABLED=true`
