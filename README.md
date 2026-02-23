# Modern Forestry Backstage

## Shopify (Phase 1)
Required environment keys:
- `SHOPIFY_RETAIL_SHOP`
- `SHOPIFY_RETAIL_CLIENT_ID`
- `SHOPIFY_RETAIL_CLIENT_SECRET`
- `SHOPIFY_RETAIL_ACCESS_TOKEN` (optional if OAuth is used)
- `SHOPIFY_WHOLESALE_SHOP`
- `SHOPIFY_WHOLESALE_CLIENT_ID`
- `SHOPIFY_WHOLESALE_CLIENT_SECRET`
- `SHOPIFY_WHOLESALE_ACCESS_TOKEN` (optional if OAuth is used)
- `SHOPIFY_API_VERSION` (default `2026-01`)
- `SHOPIFY_SCOPES` (default `read_orders,read_products`)

OAuth (Admin) routes:
- `/shopify/auth/{store}`
- `/shopify/callback/{store}`
- `/shopify/reinstall/{store}`

CLI helper:
- `php artisan shopify:auth retail`
- `php artisan shopify:auth wholesale`

Notes:
- Access tokens are stored in `shopify_stores` and encrypted at rest.
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
