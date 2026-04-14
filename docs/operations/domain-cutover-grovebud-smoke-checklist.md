# Grovebud Domain Smoke Checklist

Date: 2026-04-14

## Host Routing
1. `https://app.grovebud.com/login` loads.
2. `https://<tenant>.grovebud.com/login` resolves tenant branding/context.
3. `https://app.forestrybackstage.com/login` still loads (transition compatibility).
4. `https://<tenant>.forestrybackstage.com/login` still resolves tenant context.
5. `https://forestrybackstage.com/<path>?q=1` redirects to `https://grovebud.com/<path>?q=1`.
6. `https://app.forestrybackstage.com/<path>` is **not** auto-redirected to Grovebud during transition.
7. `https://<tenant>.forestrybackstage.com/<path>` is **not** auto-redirected to Grovebud during transition.

## Safety Guards
1. `https://<tenant>.grovebud.com/landlord` returns `404`.
2. Unknown host with known slug (for example `https://acme.unknown.example/login`) does not resolve tenant context.
3. Landlord host pages only accessible on landlord hosts.

## Auth + URL Emission
1. Password reset email link host is canonical (`app.grovebud.com`) when generated without request host context.
2. Approval/onboarding password setup links use canonical tenant host (`<slug>.grovebud.com`).
3. Email verification signed URLs resolve to canonical host when generated from background context.
4. Signed export URLs (rewards) resolve to canonical landlord host when generated out of request context.

## Shopify
1. `/shopify/auth/retail` from `app.grovebud.com` emits callback redirect URI on `app.grovebud.com`.
2. `/shopify/auth/retail` from `app.forestrybackstage.com` still works, but emits callback redirect URI on canonical host `app.grovebud.com`.
3. Shopify Partner Dashboard settings match expected canonical values.
4. App proxy health endpoint succeeds via canonical host.
5. Required-store webhook checks pass:
   - `php artisan shopify:webhooks:verify --required-only`
   - `php artisan shopify:webhooks:verify retail`
6. Webhook callbacks in verification output resolve to canonical landlord host (`https://app.grovebud.com/webhooks/shopify/...`).
7. Wholesale webhook/auth drift may be logged as warning only when wholesale is optional (`SHOPIFY_REQUIRED_STORE_KEYS=retail`).

## Session / Browser
1. Confirm production `SESSION_DOMAIN=null` during transition.
2. Confirm secure cookies are enabled in production (`SESSION_SECURE_COOKIE=true`).
3. Validate login and subsequent protected navigation on canonical + legacy app/tenant hosts.

## Callback Recovery Quick Checks
1. If Shopify OAuth fails, re-open Partner Dashboard and confirm App URL/redirect URLs/app-proxy URL values exactly match the cutover runbook.
2. If Google OAuth fails with redirect mismatch, confirm Google console redirect URIs exactly match canonical Grovebud callback URLs.

## Sign-off
- Staging sign-off owner:
- Production sign-off owner:
- Timestamp:
