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

The handoff is complete.

1. The `main` GitHub Actions workflow runs Composer install, frontend build,
   and Pest before production deployment.
2. After the gate succeeds, it POSTs to the protected GitHub production secret
   `FORGE_DEPLOY_HOOK_URL`. Never commit or print that URL.
3. Forge creates the release and runs the deployment script above. The first
   fully automatic run activated Forge release `73789933` for commit
   `c272464230f4c83366f8d57a635ac4c38876c5c8` on 2026-07-21; `/ready` returned
   HTTP 200 with that commit as its active release ID.
4. The legacy SSH job is retired to an explicitly approved, audited emergency
   recovery path. It is not a routine deployment mechanism.

Do not turn Forge direct push-to-deploy on: it would bypass the GitHub
test/build gate.

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
