# Everbranch Domain Cutover Smoke Checklist

## Runtime Host Acceptance
1. `https://app.theeverbranch.com/login` loads.
2. `https://<tenant>.theeverbranch.com/login` resolves tenant context.
3. `https://theeverbranch.com/platform/plans` loads.
4. `https://app.grovebud.com/login` returns `404` from app runtime (no direct acceptance).
5. `https://app.forestrybackstage.com/login` returns `404` from app runtime (no direct acceptance).

## Edge Redirect Validation (Cloudflare)
1. `https://grovebud.com/<path>?q=1` redirects to `https://theeverbranch.com/<path>?q=1`.
2. `https://app.grovebud.com/<path>?q=1` redirects to `https://app.theeverbranch.com/<path>?q=1`.
3. `https://<slug>.grovebud.com/<path>?q=1` redirects to `https://<slug>.theeverbranch.com/<path>?q=1`.
4. `https://forestrybackstage.com/<path>?q=1` redirects to `https://theeverbranch.com/<path>?q=1`.
5. `https://app.forestrybackstage.com/<path>?q=1` redirects to `https://app.theeverbranch.com/<path>?q=1`.
6. `https://<slug>.forestrybackstage.com/<path>?q=1` redirects to `https://<slug>.theeverbranch.com/<path>?q=1`.
7. Redirect status is `302` during validation; later changed to `301` after sign-off.

## URL Emission Checks
1. Password reset links use `app.theeverbranch.com` fallback host.
2. Email verification links use `app.theeverbranch.com`.
3. Shopify OAuth `redirect_uri` host is `app.theeverbranch.com`.
4. Shopify webhook callback URLs point to `https://app.theeverbranch.com/webhooks/shopify/...`.
5. Billing hosted handoff return/success/cancel URLs use `<slug>.theeverbranch.com` where tenant context applies.

## Route Boundary Checks
1. Tenant hosts cannot access landlord routes (`/landlord*` returns `404`).
2. Landlord host does not silently resolve tenant context.
3. Unknown host (for example `unknown.example`) is rejected (`404`).

## Shopify Post-Cutover
1. Reauthorize required stores from canonical auth endpoints.
2. Run `php artisan shopify:webhooks:verify --required-only` and confirm success.
3. If drift exists, run `php artisan shopify:webhooks:verify --required-only --repair` and re-verify.
4. Confirm no stale deploy artifact is overriding `shopify.app.toml` values.

## External Callback Validation
1. Google OAuth callback succeeds on `https://app.theeverbranch.com/auth/google/callback`.
2. Google GBP callback succeeds on `https://app.theeverbranch.com/marketing/candle-cash/google-business/callback` (if enabled).
3. Twilio callback URLs (if configured) resolve to canonical landlord host.
