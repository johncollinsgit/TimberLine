# Forge Atomic Release Runbook

## Purpose

Everbranch production uses Laravel Forge zero-downtime release directories.
Each candidate is built outside the active release and becomes `current` only
after preparation succeeds. A failed build or migration must leave the prior
release serving users.

## Current production configuration

- Forge server: `129.212.138.111` (site record
  `backstage.theforestrystudio.com`).
- Production application host: `https://app.theeverbranch.com`.
- Deployment branch: `main`.
- Shared release paths: Forge-managed `.env` and `storage`.
- Release retention: four previous releases.
- Forge direct push-to-deploy: **off**. GitHub's test/build gate must remain
  the authority for automatic releases.
- Readiness health check: `https://app.theeverbranch.com/ready`.

Do not create a second production site or cut over DNS unless this site can no
longer be recovered. The current Forge site already has zero-downtime releases
enabled, so using it avoids an unnecessary domain cutover.

## Forge deployment script

```bash
$CREATE_RELEASE()
cd "$FORGE_RELEASE_DIRECTORY"

export RELEASE_ID="${FORGE_DEPLOY_COMMIT:-$(git rev-parse --short HEAD)}"

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --no-audit --no-fund
npm run build

php artisan config:cache
php artisan view:cache
php artisan migrate --force

$ACTIVATE_RELEASE()
$RESTART_QUEUES()
```

Keep the release macros in this order. Do not run `git reset`, `git clean`,
`optimize:clear`, `route:cache`, or remove `public/build` from the active
release. This application needs dynamic routes available; `route:cache` is not
part of the release process.

## GitHub Actions handoff

1. The `main` GitHub Actions workflow runs Composer install, frontend build,
   and Pest before production deployment.
2. Store the existing Forge deploy-hook URL as the protected GitHub production
   secret `FORGE_DEPLOY_HOOK_URL`. Never commit or print that URL.
3. Merge the hook-based deploy workflow. Its only production action after a
   successful gate is a POST to that secret URL.
4. Trigger a harmless release and verify the Forge deployment record,
   `/ready`, `/up`, authenticated landlord Home, a tenant switch, and a
   read-only tenant page.
5. Retain the legacy SSH job only as an explicitly approved, audited emergency
   recovery path. It must not be the routine deployment mechanism.

Until step 3 is complete, the existing GitHub Actions SSH deployment remains
the automated transition path. Do not turn Forge direct push-to-deploy on; it
would bypass the test/build gate.

## Readiness and rollback

- `/up` answers lightweight application liveness.
- `/ready` verifies Laravel boot, required config, MySQL, cache, and returns
  the active release ID. It returns HTTP 503 without internal error detail if
  any check fails.
- Forge checks `/ready` after activation. If the release is unhealthy, use
  Forge's prior retained release for rollback, then investigate the failed
  candidate without changing business data.
- Before a major change, confirm a database backup and use a low-traffic
  release window. Automatic releases may use only additive,
  backward-compatible migrations; backfills and destructive schema work are
  separate, planned releases.

## Smoke checks

```bash
curl -fsS https://app.theeverbranch.com/up
curl -fsS https://app.theeverbranch.com/ready
```

Then use the signed-in Safari session to check `/landlord`, Transactions,
tenant switching, agreements, and a safe billing view. Do not create charges,
refunds, agreements, or customer messages as part of a release smoke test.
