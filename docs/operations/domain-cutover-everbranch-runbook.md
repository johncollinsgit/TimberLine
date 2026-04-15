# Everbranch Domain Cutover Runbook

## 1) Canonical Hosts (Target Contract)
- Public canonical host: `https://theeverbranch.com`
- Landlord/app canonical host: `https://app.theeverbranch.com`
- Tenant canonical host pattern: `https://<slug>.theeverbranch.com`

## 2) Hard-Cutover Runtime Model
- Laravel runtime accepts only Everbranch canonical hosts.
- Legacy hosts are not accepted by app runtime after cutover.
- Unknown/unexpected hosts are rejected safely (`404`) by runtime host guard.
- Legacy host migration is edge-managed (Cloudflare), not app-managed.

## 3) Required Runtime Env Contract
Set and deploy with these values:
- `APP_URL=https://app.theeverbranch.com`
- `TENANCY_CANONICAL_BASE_DOMAIN=theeverbranch.com`
- `TENANCY_CANONICAL_PUBLIC_HOST=theeverbranch.com`
- `TENANCY_CANONICAL_LANDLORD_HOST=app.theeverbranch.com`
- `TENANCY_TENANT_BASE_DOMAINS=theeverbranch.com`
- `TENANCY_LEGACY_BASE_DOMAINS=`
- `TENANCY_LEGACY_PUBLIC_HOSTS=`
- `TENANCY_LEGACY_LANDLORD_HOSTS=`
- `TENANCY_LANDLORD_PRIMARY_HOST=app.theeverbranch.com`
- `TENANCY_LANDLORD_HOSTS=app.theeverbranch.com`
- `AUTH_FLAGSHIP_HOSTS=app.theeverbranch.com,theeverbranch.com`
- `GOOGLE_REDIRECT_URI=https://app.theeverbranch.com/auth/google/callback`
- `GOOGLE_GBP_REDIRECT_URI=https://app.theeverbranch.com/marketing/candle-cash/google-business/callback`

## 4) Edge Redirect Requirements (Cloudflare)
Use path/query-preserving host redirects.

Start in validation mode:
- redirect status: `302`

After production sign-off:
- change redirect status to `301`

Rules:
- `https://grovebud.com/*` -> `https://theeverbranch.com/<same-path>`
- `https://app.grovebud.com/*` -> `https://app.theeverbranch.com/<same-path>`
- `https://*.grovebud.com/*` -> `https://<same-slug>.theeverbranch.com/<same-path>`
- `https://forestrybackstage.com/*` -> `https://theeverbranch.com/<same-path>`
- `https://app.forestrybackstage.com/*` -> `https://app.theeverbranch.com/<same-path>`
- `https://*.forestrybackstage.com/*` -> `https://<same-slug>.theeverbranch.com/<same-path>`

All rules must preserve query strings.

## 5) DNS/TLS Prerequisites
Provision and validate before deploy:
- `theeverbranch.com`
- `app.theeverbranch.com`
- `*.theeverbranch.com`

Operational requirements:
- Forge/Nginx vhost coverage must include canonical landlord + public + wildcard tenant hostnames.
- Confirm cert issuance/renewal for apex, app subdomain, and wildcard.

## 6) Forge/App Deployment Order
1. Update Forge environment variables to canonical Everbranch-only values.
2. Deploy application code.
3. Run:
   - `php artisan config:clear`
   - `php artisan config:cache`
   - `php artisan route:clear`
   - `php artisan route:cache` (if your environment uses route cache)
   - `php artisan queue:restart`
4. Confirm health endpoint on canonical landlord host (`/up`).

## 7) External System Updates (Required)
- Shopify Partner Dashboard:
  - App URL: `https://app.theeverbranch.com/shopify/app`
  - Allowed redirect URIs:
    - `https://app.theeverbranch.com/shopify/callback/retail`
    - `https://app.theeverbranch.com/shopify/callback/wholesale`
  - App proxy URL: `https://app.theeverbranch.com/shopify/marketing/v1`
- Google OAuth redirect URI:
  - `https://app.theeverbranch.com/auth/google/callback`
- Google GBP redirect URI (if enabled):
  - `https://app.theeverbranch.com/marketing/candle-cash/google-business/callback`
- Twilio callback URLs (if configured): update any hardcoded landlord host callbacks to `app.theeverbranch.com`.
- Shopify webhook verification command:
  - verify required stores: `php artisan shopify:webhooks:verify --required-only`
  - repair drift if needed: `php artisan shopify:webhooks:verify --required-only --repair`

## 8) Staging Smoke Steps
Run `docs/operations/domain-cutover-everbranch-smoke-checklist.md` completely before production.

## 9) Production Cutover Sequence
1. Deploy app + config (canonical-only runtime contract).
2. Validate canonical hosts (`theeverbranch.com`, `app.theeverbranch.com`, `<slug>.theeverbranch.com`).
3. Apply Cloudflare redirect rules in `302` mode.
4. Reauthorize required Shopify stores from canonical auth endpoints.
5. Verify webhook registrations on canonical callbacks.
6. Validate Google OAuth + GBP callbacks.
7. Run smoke checklist end-to-end.
8. After sign-off, change edge redirect status from `302` -> `301`.

## 10) Post-Cutover Cleanup
- Remove stale deployment artifacts and verify `shopify.app.toml` was the config source of truth at release time.
- Confirm no production env vars reference Grovebud/Forestry Backstage hosts.
- Confirm no runtime route/domain acceptance for legacy hosts.
- Keep redirect telemetry/monitoring active for at least one stabilization window.

## Rollback Reference
If critical issues occur, follow:
- `docs/operations/domain-cutover-everbranch-rollback.md`
