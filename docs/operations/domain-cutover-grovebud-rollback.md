# Grovebud Domain Rollback Runbook

Date: 2026-04-14
Status: Active

## Trigger Conditions
- Canonical host auth/session failures that block operator access
- Shopify OAuth/app-proxy failures after callback update
- Landlord route lock regressions
- Tenant host resolution regressions under canonical hosts

## Rollback Goals
- Restore stable login + tenant routing immediately
- Keep data migrations intact (no destructive DB rollback)
- Re-enable legacy Forestry Backstage app/tenant entry paths

## Immediate Actions (Order)
1. Disable/relax apex redirect rule if public redirect is implicated:
- change `301` to `302` or disable redirect on `forestrybackstage.com/*`

2. If only legacy compatibility is broken (canonical Grovebud still healthy), run targeted rollback first:
- confirm `TENANCY_LANDLORD_HOSTS` includes `app.forestrybackstage.com`
- confirm `TENANCY_TENANT_BASE_DOMAINS` includes `forestrybackstage.com`
- confirm no Cloudflare redirect affects `app.forestrybackstage.com` or `*.forestrybackstage.com`
- confirm legacy DNS + certs remain active
- run cache rebuild commands, then retest legacy smoke checks

3. Revert production env to previous host profile (full rollback path):
- `APP_URL` back to prior stable value
- previous `TENANCY_CANONICAL_*` values
- previous `TENANCY_LANDLORD_PRIMARY_HOST` / `TENANCY_LANDLORD_HOSTS`
- keep `TENANCY_LEGACY_*` populated so legacy hosts remain accepted

4. Rebuild caches:
- `php artisan config:cache`
- `php artisan route:cache` (if used)
- `php artisan view:cache` (if used)

5. Revert external callbacks (manual):
- Shopify app URL / redirect URLs / app proxy URL
- Google OAuth redirect URI
- Google GBP redirect URI (if used)

6. Re-run smoke checks on restored host model.

## Callback-Specific Rollback
- Shopify failures:
  - restore Partner Dashboard App URL/redirects/app-proxy values to last known good set
  - verify `/shopify/auth/retail` emits expected host in `redirect_uri`
- Google failures:
  - restore OAuth redirect URIs in Google console
  - run `php artisan auth:doctor-google` and resolve any mismatch output

## DNS/TLS Rollback
- Keep both canonical and legacy DNS/certs active during rollback.
- Do not remove Grovebud records/certs during first rollback wave; only restore traffic behavior.

## Data + Safety Notes
- This rollback is host/config routing rollback only.
- Do not run destructive tenant/domain data mutations.
- Preserve logs for failed requests during incident window.

## Recovery Exit Criteria
- Legacy landlord host (`app.forestrybackstage.com`) login + landlord pages reachable.
- Legacy tenant host (`<slug>.forestrybackstage.com`) login + tenant pages reachable.
- Public host behavior is stable per rollback decision (redirect disabled or temporary).
- Shopify OAuth callback and app-proxy calls succeed again.
