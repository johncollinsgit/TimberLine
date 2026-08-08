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

1. The `main` GitHub Actions workflow runs the full application test/build job
   and an independent MySQL 8.4 Migration Safety Gate. Migration safety cannot
   be skipped by the emergency dispatch. It lints changed migrations, runs the
   partial-state recovery suite, and rehearses the schema upgrade from the
   prior released commit.
2. After the gate succeeds, it POSTs to the protected GitHub production secret
   `FORGE_DEPLOY_HOOK_URL`. Never commit or print that URL. The workflow then
   polls `/ready` for the exact GitHub commit for up to three minutes. A hook
   acknowledgment alone is not deployment evidence; the workflow fails if the
   active release identifier remains stale.
   If optional Forge observer credentials are configured, a failed verification
   makes one GET request to Forge's current API and prints an allowlisted latest
   deployment summary in the GitHub job. It does not retry, reset, or alter a
   deployment and does not replace the exact-SHA `/ready` check.
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
- Additive migrations must also be restart-safe. A release can create one
  table and fail before Laravel writes its migration record. Each independent
  table/index step must detect the durable partial state and resume safely.
  Reproduce that state in the MySQL migration-recovery suite before release;
  never manually delete a production table simply to retry a migration.
- MySQL identifiers are limited to 64 characters. Do not rely on Laravel's
  generated index or foreign-key names when a table/column combination can
  approach that limit. The 2026-08-07 Customer Loop/Commerce incident was
  caused by a 65-character generated action index and exposed a 68-character
  generated shipping-rate foreign key. Both migrations now use explicit short
  names and repair the durable partial schema on retry.

The enforced checks and developer workflow are documented in
`docs/operations/migration-safety-gate.md`. In particular, do not edit or delete
a migration that may have shipped. Add a new idempotent repair migration and a
durable partial-state test. A historical migration that cannot execute on a
clean supported MySQL database is the sole exception: its minimal compatibility
change must have exact before/after checksums in
`scripts/ci/legacy-migration-compatibility-manifest.php` and a named MySQL
restart test. Any later edit fails closed.

## Optional Forge observer setup

The production workflow can add Forge-side context after a failed `/ready`
exact-release check. Configure the following in GitHub's `production`
environment:

- secret `FORGE_API_TOKEN`, created with the narrowest site/deployment read
  scopes Forge offers and an expiration date;
- variable `FORGE_ORGANIZATION_SLUG`;
- variable `FORGE_SERVER_ID`;
- variable `FORGE_SITE_ID`.

The reporter at `scripts/ci/report-forge-deployment.php` uses the current Forge
`/api` endpoint with `include=latestDeployment`. It contains one GET operation
and prints only commit, match, status, and timestamps. Missing configuration is
non-fatal. Never give this diagnostic write scopes merely for convenience, and
never replace exact-SHA `/ready` verification with Forge's status alone.

## Smoke checks

```bash
curl -fsS https://app.theeverbranch.com/up
curl -fsS https://app.theeverbranch.com/ready
```

Then use the signed-in Safari session to check `/landlord`, Transactions,
tenant switching, agreements, and a safe billing view. Do not create charges,
refunds, agreements, or customer messages as part of a release smoke test.
