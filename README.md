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

## Deployment (GitHub Actions -> Production)
This repository deploys with `.github/workflows/deploy.yml`.

Triggers:
- Push to `main` (automatic deploy)
- Manual run via `workflow_dispatch` in GitHub Actions (with optional `run_tests` toggle)

Owner workflow:
```bash
git add .
git commit -m "Describe change"
git push origin main
```

Required GitHub secrets (configure in the `production` environment):
- `DEPLOY_HOST`
- `DEPLOY_USER`
- `DEPLOY_PORT`
- `DEPLOY_PATH`
- `DEPLOY_SSH_KEY` (private key for SSH access to the server)

Optional test prerequisites:
- `FLUX_USERNAME`
- `FLUX_LICENSE_KEY`

These are only needed for CI test/build when private Flux packages are required.

Server prerequisites:
- Git with the app already cloned at `DEPLOY_PATH`
- PHP 8.2+ and required extensions
- Composer 2
- Node.js + npm (this app uses Vite)
- Writable Laravel directories (`storage`, `bootstrap/cache`)
- Database connectivity from the server
- Queue worker process manager (Supervisor/systemd) if queues are active

Server deploy command sequence:
- `git fetch origin main`
- `git checkout main`
- `git pull --ff-only origin main`
- `composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev`
- `npm ci`
- `npm run build`
- `rm -f public/hot`
- `php artisan migrate --force`
- `php artisan route:clear`
- `php artisan config:cache`
- `php artisan view:cache`
- `php artisan queue:restart`

Notes:
- `route:cache` is intentionally not used because the app currently has closure routes.
- Deploy is fail-fast and concurrency-guarded so only one production deploy runs at a time.

Manual deploy:
1. Go to GitHub -> Actions -> `Deploy Production`.
2. Click `Run workflow`.
3. Choose `main` and (optionally) set `run_tests` to false.

Temporarily disable deploy:
- In GitHub -> Actions -> `Deploy Production` -> `...` menu -> `Disable workflow`.

## Shopify Embedded Session Cookies
- Embedded Shopify Admin requests run inside an `admin.shopify.com` iframe, so production session cookies must allow secure cross-site usage.
- Production env should include:
  - `SESSION_SECURE_COOKIE=true`
  - `SESSION_SAME_SITE=none`
  - `SESSION_PARTITIONED_COOKIE=true`
- After changing those values, run:
  - `php artisan optimize:clear`
  - `php artisan config:clear`

## Twilio SMS Configuration
- Set `TWILIO_ACCOUNT_SID` and `TWILIO_AUTH_TOKEN`.
- Preferred: configure `MARKETING_TWILIO_SENDERS` as a JSON array of sender objects. All senders share the same `TWILIO_ACCOUNT_SID` and `TWILIO_AUTH_TOKEN`.
  - MG sender:
    - `[{"key":"toll_free","label":"Toll-free","type":"toll_free","status":"active","enabled":true,"default":true,"phone_number_sid":"PN...","messaging_service_sid":"MG..."}]`
  - Direct sender:
    - `[{"key":"local","label":"Local","type":"local","status":"active","enabled":true,"phone_number_sid":"PN...","from_number":"+15555550123"}]`
  - Mixed sender config:
    - `[{"key":"toll_free","label":"Toll-free","type":"toll_free","status":"active","enabled":true,"default":true,"phone_number_sid":"PN...","messaging_service_sid":"MG..."},{"key":"local","label":"Local","type":"local","status":"pending","enabled":false,"default":false,"phone_number_sid":"PN...","from_number":"+15555550123"}]`
  - `phone_number_sid` is metadata only.
- Optional: set `MARKETING_TWILIO_DEFAULT_SENDER` to force the default sender key.
- Backward-compatible migration fallback:
  - `TWILIO_MESSAGING_SERVICE_SID` (recommended, must start with `MG`), or
  - `TWILIO_FROM_NUMBER` (E.164 format like `+18339625949`).
- Enable provider flags:
  - `MARKETING_SMS_ENABLED=true`
  - `MARKETING_TWILIO_ENABLED=true`
- Operational verification:
  - `php artisan marketing:send-test-sms +15551234567 "Test message" --sender=toll_free`

## Candle Cash Gift Reporting
- Gift transactions now persist `gift_intent`, `gift_origin`, `notified_via`, `notification_status`, and `campaign_key` in `candle_cash_transactions`.
- Backstage surfaces a `Gift insights` tab under Candle Cash (`/marketing/candle-cash/gifts-report`) that summarizes total gifts, intent/origin/notification breakdowns, actor attribution, recent gift rows, and a simple post-gift conversion proxy.
- Use the date filters on that page to focus on a specific window and understand whether gifted customers later placed orders.
