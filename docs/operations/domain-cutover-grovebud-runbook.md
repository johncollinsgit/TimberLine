# Grovebud Domain Cutover Runbook

Date: 2026-04-14
Status: Active

## Scope
- Canonical public host: `grovebud.com`
- Canonical landlord host: `app.grovebud.com`
- Canonical tenant host pattern: `<slug>.grovebud.com`
- Legacy compatibility during transition:
  - `forestrybackstage.com` (public redirect-only)
  - `app.forestrybackstage.com`
  - `<slug>.forestrybackstage.com`

## Required App Env (Production)
- `APP_URL=https://app.grovebud.com`
- `TENANCY_CANONICAL_SCHEME=https`
- `TENANCY_CANONICAL_BASE_DOMAIN=grovebud.com`
- `TENANCY_CANONICAL_PUBLIC_HOST=grovebud.com`
- `TENANCY_CANONICAL_LANDLORD_HOST=app.grovebud.com`
- `TENANCY_TENANT_BASE_DOMAINS=grovebud.com,forestrybackstage.com`
- `TENANCY_LEGACY_BASE_DOMAINS=forestrybackstage.com`
- `TENANCY_LEGACY_PUBLIC_HOSTS=forestrybackstage.com`
- `TENANCY_LEGACY_LANDLORD_HOSTS=app.forestrybackstage.com`
- `TENANCY_LEGACY_PUBLIC_REDIRECT_ENABLED=true`
- `TENANCY_LEGACY_PUBLIC_REDIRECT_STATUS=301`
- `TENANCY_LANDLORD_PRIMARY_HOST=app.grovebud.com`
- `TENANCY_LANDLORD_HOSTS=app.grovebud.com,app.forestrybackstage.com`
- `AUTH_FLAGSHIP_HOSTS=app.grovebud.com,grovebud.com,app.forestrybackstage.com,forestrybackstage.com`
- `GOOGLE_REDIRECT_URI=https://app.grovebud.com/auth/google/callback`
- `GOOGLE_GBP_REDIRECT_URI=https://app.grovebud.com/marketing/candle-cash/google-business/callback` (if GBP is enabled)
- `SESSION_DOMAIN=null` during dual-domain transition (host-only cookies)
- `SESSION_SECURE_COOKIE=true`

Optional for Shopify embedded admin cookie behavior (only if required by your browser/session policy):
- `SESSION_SAME_SITE=none`
- `SESSION_PARTITIONED_COOKIE=true`

Notes:
- Sanctum stateful-domain config is not currently used in this repo (`config/sanctum.php` is absent).
- Keep legacy app/tenant host support enabled until rollback window is closed.

## DNS + TLS
1. Canonical records (create first):
- `A grovebud.com -> <app origin IP>` (confirm current Forge origin/IP before change)
- `A app.grovebud.com -> <app origin IP>`
- `A *.grovebud.com -> <app origin IP>` (tenant wildcard)

2. Legacy records (keep during transition):
- `A forestrybackstage.com -> <app origin IP>`
- `A app.forestrybackstage.com -> <app origin IP>`
- `A *.forestrybackstage.com -> <app origin IP>`

3. ACME DNS-01 challenge (Forge-managed certs):
- `CNAME _acme-challenge -> verify-<forge-token>.ssl.on-forge.com`
- Keep this record `DNS only` (not proxied) in Cloudflare.
- If the target no longer resolves, regenerate challenge in Forge and update the CNAME target only.

4. Certificates:
- Ensure active certs for `grovebud.com`, `app.grovebud.com`, `*.grovebud.com`.
- Keep `forestrybackstage.com`, `app.forestrybackstage.com`, `*.forestrybackstage.com` certs active until final decommission.

## Cloudflare / Edge Rules
- Enable apex-only redirect rule:
  - `forestrybackstage.com/*` -> `https://grovebud.com/$1`
  - Preserve query string
  - Status `301` (or `302` for reversible staged checks)
- Do **not** redirect `app.forestrybackstage.com` or `*.forestrybackstage.com` yet.
- Cloudflare proxy expectations:
  - `grovebud.com`, `app.grovebud.com`, and wildcard tenant records may be proxied.
  - `_acme-challenge` CNAME must stay `DNS only`.

## Forge / Host Mapping
1. Ensure site/vhost accepts both canonical and legacy app hostnames.
2. Ensure wildcard tenant hostnames resolve at web server/app layer for both base domains.
3. Verify Forge site domain list includes:
- `app.grovebud.com`
- `app.forestrybackstage.com`
- wildcard mappings for `*.grovebud.com` and `*.forestrybackstage.com` (or equivalent Nginx vhost handling)
4. Deploy and cache refresh:
- `php artisan config:cache`
- `php artisan route:cache` (if used)
- `php artisan view:cache` (if used)

## External Dashboard Changes (Manual)
These are outside repo state and must be updated manually.

1. Shopify Partner Dashboard / app settings:
- App URL -> `https://app.grovebud.com/shopify/app`
- Redirect URLs (minimum):
  - `https://app.grovebud.com/shopify/callback/retail`
  - `https://app.grovebud.com/shopify/callback/wholesale`
- App proxy URL -> `https://app.grovebud.com/shopify/marketing/v1`
- During transition, keep legacy callback/app-proxy entries if legacy-host launch traffic is still expected.

2. Google OAuth console:
- Authorized redirect URI for login -> `https://app.grovebud.com/auth/google/callback`

3. Google Business Profile OAuth (if enabled):
- Redirect URI -> `https://app.grovebud.com/marketing/candle-cash/google-business/callback`

## Shopify OAuth + Candle Cash Hardening
Canonical Shopify settings:
- App URL: `https://app.grovebud.com/shopify/app`
- Redirect URLs:
  - `https://app.grovebud.com/shopify/callback/retail`
  - `https://app.grovebud.com/shopify/callback/wholesale`
- App proxy URL: `https://app.grovebud.com/shopify/marketing/v1`

Required Admin API scopes (minimum app/runtime parity):
- `read_products`
- `read_orders`
- `read_all_orders`
- `read_reports`
- `read_analytics`
- `read_customers`
- `write_customers`
- `read_discounts`
- `write_discounts`
- `read_webhooks`
- `write_webhooks`
- `read_pixels`
- `write_pixels`
- `read_customer_events`

Required runtime store-role config:
- `SHOPIFY_ACTIVE_STORE_KEYS=retail` (keeps launch commands retail-first by default)
- `SHOPIFY_REQUIRED_STORE_KEYS=retail` (retail is launch-critical; wholesale non-blocking)

Release + reauthorization requirements:
1. Release updated app config from current source (`shopify.app.toml`) in Shopify Partner Dashboard.
2. Do not reuse stale generated bundles from old domain builds. Regenerate deploy bundle before release if `.shopify/deploy-bundle/manifest.json` contains legacy Forestry hosts/scopes.
3. Reauthorize required launch store after release from canonical host:
   - `https://app.grovebud.com/shopify/auth/retail`
4. Reauthorize wholesale only when wholesale is actively being re-enabled:
   - `https://app.grovebud.com/shopify/auth/wholesale`

Post-reauthorization validation:
1. Confirm generated Shopify authorize URL `redirect_uri` host is `app.grovebud.com` (even if flow started on legacy landlord host).
2. Confirm `shopify_stores.scopes` for `retail` includes `read_discounts` and `write_discounts`.
3. Run required-store webhook verification first and confirm callbacks resolve to canonical landlord host:
   - `php artisan shopify:webhooks:verify --required-only`
   - `php artisan shopify:webhooks:verify retail`
4. (Optional) Audit wholesale as non-blocking:
   - `php artisan shopify:webhooks:verify wholesale`
5. Validate Candle Cash redemption in storefront/account and confirm no discount provisioning failure in logs.

## Right Now (Launch Path)
1. Release Shopify app version from current `shopify.app.toml`.
2. Confirm no stale `.shopify/deploy-bundle/manifest.json` from legacy Forestry hosts is reused.
3. Reauthorize retail from `https://app.grovebud.com/shopify/auth/retail`.
4. Verify persisted scopes in DB for retail include `read_discounts,write_discounts`.
5. Run retail-first webhook checks:
- `php artisan shopify:webhooks:verify --required-only`
- `php artisan shopify:webhooks:verify retail`
6. Complete one real Candle Cash redemption and confirm no Shopify discount provisioning failure logs.
7. Treat wholesale auth/webhook recovery as deferred unless `SHOPIFY_REQUIRED_STORE_KEYS` is expanded.

## Cutover Sequence
1. Deploy code + env to staging.
2. Validate smoke checklist in `docs/operations/domain-cutover-grovebud-smoke-checklist.md`.
3. Update staging external callbacks (Shopify/Google), retest.
4. Repeat on production in this order:
- Add canonical DNS/TLS
- Deploy app env + code
- Update external callbacks
- Enable public apex redirect
- Run production smoke checklist
5. Keep legacy app/tenant hosts active for transition window.

## Failure Handling During Cutover
1. If Grovebud hosts work but legacy app/tenant compatibility breaks:
- Confirm `TENANCY_LANDLORD_HOSTS` still includes `app.forestrybackstage.com`.
- Confirm `TENANCY_TENANT_BASE_DOMAINS` still includes `forestrybackstage.com`.
- Confirm legacy DNS + certs are still active.
- Confirm no Cloudflare rule redirects `app.forestrybackstage.com` or `*.forestrybackstage.com`.

2. If Shopify OAuth/callback fails after cutover:
- Verify Partner Dashboard App URL and redirect URLs exactly match canonical values above.
- Hit `GET https://app.grovebud.com/shopify/auth/retail` and verify outbound `redirect_uri` host is `app.grovebud.com`.
- If emergency rollback is needed, temporarily restore legacy callback URLs in Partner Dashboard and keep both hosts active.

3. If Google callback fails (`redirect_uri_mismatch`):
- Confirm OAuth console redirect URI values exactly match canonical URLs above.
- Run `php artisan auth:doctor-google` on deployed env and resolve reported mismatches.

## Exit Criteria
- Canonical Grovebud hosts pass smoke checks.
- Legacy public host redirects correctly with path/query preservation.
- Legacy app/tenant hosts remain functional (no forced redirect).
- Landlord routes remain host-locked and unreachable from tenant hosts.
- Unknown hosts do not resolve tenant context.

## Future Decommission Plan (Post-Transition)
1. Announce legacy host shutdown window and freeze new legacy-host callback registrations.
2. Remove legacy app/tenant host entries from:
- `TENANCY_LANDLORD_HOSTS`
- `TENANCY_LEGACY_*`
- `TENANCY_TENANT_BASE_DOMAINS` (remove `forestrybackstage.com`)
- `AUTH_FLAGSHIP_HOSTS` (remove Forestry Backstage hosts)
3. Update Shopify/Google dashboards to canonical-only callback hosts.
4. Replace compatibility smoke checks with canonical-only checks.
5. Remove legacy DNS/certs only after at least one full release cycle of canonical-only traffic validation.
