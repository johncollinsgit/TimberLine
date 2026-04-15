# Everbranch Domain Cutover Rollback

## Rollback Scope
Use this only for critical production impact. Rollback includes:
- edge redirect behavior
- app/env canonical host contract
- external callback and Shopify config alignment

## Immediate Containment
1. Keep app runtime on canonical hosts; do not re-enable legacy runtime host acceptance ad hoc.
2. Set Cloudflare legacy redirect rules to `302` (if currently `301`) for reversible behavior while triaging.
3. If edge rule is causing breakage, disable only the specific failing rule first.

## App Rollback (If Required)
1. Redeploy last known-good release tag/commit.
2. Restore corresponding env snapshot for that release.
3. Run:
   - `php artisan config:clear`
   - `php artisan config:cache`
   - `php artisan route:clear`
   - `php artisan route:cache` (if used)
   - `php artisan queue:restart`
4. Validate canonical landlord host `/up` and `/login`.

## External System Rollback
1. Shopify Partner Dashboard: restore URLs to the release-compatible values.
2. Google OAuth/GBP redirect URIs: restore values that match the rolled-back release.
3. Re-run Shopify store authorization and webhook verification if callback hosts changed.

## DNS/TLS / Forge Checks
1. Confirm Forge vhost/cert still covers the active rollback host set.
2. Confirm DNS points to the expected origin for active hosts.

## Exit Criteria
Rollback is complete when:
1. App is healthy on intended active hosts.
2. Auth + Shopify + webhook callbacks are functioning.
3. Smoke checks pass for the active rollback host contract.

## Follow-Up
1. Document root cause.
2. Create corrected cutover plan.
3. Re-run staging smoke checklist before retrying production cutover.
