# Modern Forestry Backstage

## Current Release State (2026-03-25)

This branch now includes the first commercialization/operator shell on top of tenant entitlements.

Implemented and navigable now:
- Embedded product shell:
  - `/shopify/app` (overview/dashboard)
  - `/shopify/app/start` (Start Here)
  - `/shopify/app/plans` (Plans & Add-ons informational)
  - `/shopify/app/integrations` (integrations placeholder surface)
- Public product surfaces:
  - `/platform/promo`
  - `/platform/contact`
- Operator diagnostics surfaces:
  - customer email timeline provider-context filters + CSV export parity
  - birthday analytics/reporting/export/comparison flows
  - campaign delivery diagnostics/provider-context sections

Integrations surface behavior (intentional in this release):
- placeholder-first, entitlement-aware cards
- setup detail drawer per integration
- deterministic read-only status registry context per card
- fallback-first guidance (manual/CSV/continue without connector)
- no live connector sync/OAuth/jobs/webhooks/API writes from this page

Commercialization/access state:
- product shell and entitlement-aware UI are in place
- billing/checkout/activation writes are not implemented yet
- upgrade prompts are informational routing only

Multi-tenant state:
- tenant-aware semantics are now established in email/birthday/provider diagnostics and shell module-state presentation
- full domain tenant isolation is still in progress and should not be overclaimed
- internal ops/inventory/pouring boundaries remain intentionally cautious and partially candle-shaped

Recommended next step after this push:
- deploy and run manual production verification of shell navigation, diagnostics filters/export parity, and integrations placeholder/drawer behavior before adding new scope

## Production Auth Findings (2026-03-25)

Observed during live verification at `https://backstage.theforestrystudio.com/login`:
- Password resets run locally do not affect production.
- Production user `johncollinsemail@gmail.com` exists, is active/approved, and password reset was successfully applied on production (`PASSWORD_MATCH=1`).
- Google login failure is currently external-credential based, not route/UI based:
  - production log shows `POST https://www.googleapis.com/oauth2/v4/token` returning `401 invalid_client`
  - message: `The provided client secret is invalid.`

What was verified on production:
- `services.google.client_secret` loaded by Laravel matches the `.env` value fingerprint (same length/hash), so this is not a runtime config drift in the app process at verification time.
- Login Google credentials are distinct from `GOOGLE_GBP_*` credentials (no accidental key collision in current config).

Google login runbook:
1. Run local/production diagnostics (masked output only):
   - `php artisan auth:doctor-google`
   - `php artisan auth:doctor-google --token-smoke`
2. In Google Cloud Console, confirm the OAuth client ID + client secret pair are from the same OAuth credential entry.
3. Update production `.env` keys:
   - `GOOGLE_CLIENT_ID`
   - `GOOGLE_CLIENT_SECRET`
   - `GOOGLE_REDIRECT_URI`
4. Rebuild Laravel runtime config on production:
   - `php artisan config:clear`
   - `php artisan config:cache`
   - `php artisan queue:restart`
5. Retry in an incognito window and check `storage/logs/laravel.log`:
   - `invalid_client` => wrong/revoked/mismatched OAuth pair
   - `invalid_grant` => credentials accepted; test/code is intentionally invalid or expired
   - `redirect_uri_mismatch` => callback URL mismatch in Google Console

Interpretation of smoke test results:
- `invalid_client` = broken client ID/secret pair
- `invalid_grant` = credentials accepted by Google

Important:
- Do not mix login keys (`GOOGLE_CLIENT_*`) with Google Business Profile keys (`GOOGLE_GBP_*`); they are separate integrations.

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

## Email Provider-Context Reporting
- Birthday analytics and exports expose provider context directly from canonical delivery metadata in `marketing_email_deliveries`.
- Supported reporting dimensions include:
  - `provider_resolution_source` (`tenant`, `fallback`, `none`, `unknown`)
  - `provider_readiness_status` (`ready`, `unsupported`, `incomplete`, `error`, `not_configured`, `unknown`)
- Embedded birthday analytics filters now include provider resolution/readiness context, and exports include matching breakdown rows.
- Campaign delivery diagnostics now show provider resolution/readiness/runtime-path summaries per campaign.
- Customer email timeline now shows row-level provider-context labels plus summary chips (tenant vs fallback paths, unsupported/incomplete attempts, and legacy/unknown rows) on `marketing.customers.show`.
- Customer email timeline now supports operator filters for `provider_resolution_source` and `provider_readiness_status`.
- Customer email timeline CSV export now has filter parity via `marketing.customers.email-deliveries.export` (`/marketing/customers/{marketingProfile}/email-deliveries/export`) and includes provider-context labels for legacy/unknown rows.
- Architecture details: `docs/architecture/birthday-provider-context-reporting.md`.

## Operational Architecture Guidance
- For cross-domain boundary decisions (tenant/platform vs reusable ops primitives vs candle-specific logic), use:
  - `docs/architecture/operational-multi-tenant-direction.md`
- This guidance is intended for future implementation runs touching customers, inventory/internal ops, order workflows, and lifecycle communications.

## Storefront Rewards Sidecar (Theme Repo)
- The current `/pages/rewards` UI sidecar lives in the separate Shopify theme repository:
  - `/Users/johncollins/projects/modernforestry-live-theme`
  - implemented in `assets/forestry-rewards.js` + `assets/forestry-rewards.css`
- Recent sidecar update delivered:
  - compact theme toggle/dropdown
  - removed top rewards-summary clutter
  - unified birthday reward + intake into one expandable card
  - collapsible opportunity cards for compact default scan
  - removed task/reward history blocks from page layout
  - mobile/desktop spacing and hierarchy polish
- Backend remains canonical for identity/rewards state; sidecar only presents and invokes existing contracts.

## Future Purchasable Add-Ons (Tenant-Scoped)
- Build future apps/modules as tenant-scoped add-ons attached to the shared platform shell.
- Reuse canonical identity and marketing architecture:
  - `marketing_profiles`
  - `customer_external_profiles`
  - `marketing_profile_links`
  - existing sync/service pipelines
- Prefer extending existing signed storefront/API contracts before adding new surfaces.
- Feature access must be tenant-scoped and billing-aware (no global hardcoded availability).
- Do not fork per-tenant architecture; use one reusable module with tenant-level configuration.
