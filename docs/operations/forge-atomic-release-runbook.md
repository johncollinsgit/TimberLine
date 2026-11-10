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

Install the complete contents of `scripts/ops/forge-pinned-deploy.sh` into this
site's Forge Deploy Script editor. Keep a private copy of the previous script
before saving. The reviewed script is inline in Forge: do not source a helper
from a freshly cloned, not-yet-pinned branch head.

GitHub sends the approved full SHA as `release_sha`, which Forge exposes as
`FORGE_VAR_RELEASE_SHA`. The script rejects missing/malformed pins, creates an
inactive release, verifies its physical path is under this site's `releases/`
and is not the active `current` target, fetches the exact commit and checks it
out detached. Build and migration commands run only after that check. It checks
HEAD and tracked source again before migration and activation. `RELEASE_ID` is
the actual full Git HEAD, never a webhook label.

Preserve the installed server-owned Enterprise preparation hook at
`$FORGE_SITE_ROOT/shared/enterprise-owner/ops/prepare-release.sh`. It runs after
migrations and must succeed before activation. Missing/failed preparation stops
the release; a source check also follows it. Keep that shared hook maintained
through its existing Enterprise operations process rather than replacing it
with an unreviewed branch helper.

Forge's `forge_deploy_commit` parameter is a display label and **does not pin
source**. See [Forge's deployment parameter documentation](https://laravel.com/forge/docs/sites/deployments#using-query-parameters).
The separate custom pin and inactive-checkout verification are both required.
The test `tests/ci/test_forge_pinning.py` exercises branch advancement, invalid
pins, wrong/active paths and source mutation with disposable Git repositories.

Keep the release macros in this order. Do not run `git reset`, `git clean`,
`optimize:clear`, `route:cache`, or remove `public/build` from the active
release. This application needs dynamic routes available; `route:cache` is not
part of the release process.

Composer's `post-autoload-dump` hook runs Laravel's `storage:link` in each
candidate release. Forge shares `storage` but does not create `public/storage`
automatically; without this link uploaded marketing photos return 404 after an
otherwise healthy deployment. Existing links are preserved. Never replace an
existing public directory or copy uploads into a release's tracked assets.

## GitHub Actions handoff

The handoff is complete.

1. Pull requests run the full application test/build job and an independent
   MySQL 8.4 Migration Safety Gate. A `main` deployment reuses those exact PR
   checks only when GitHub verifies all of the following: the commit came from
   a merged PR targeting `main`, the merged release tree equals that PR head
   tree, and `quality`, `ci (8.4)`, and `mysql migration recovery` all passed.
   Direct pushes, unverifiable merges, and ordinary manual runs execute the
   full gates again. Any schema, migration, database-config, or migration
   tooling change repeats the MySQL gate on the merged release. Migration
   safety cannot be skipped by the emergency dispatch; it lints changed
   migrations, runs the partial-state recovery suite, and rehearses the schema
   upgrade from the prior released commit.
2. After the required gate succeeds, it checks that this is still the current
   `main` SHA. Superseded runs explicitly report that no deployment was requested;
   the newer candidate must pass its own protected workflow. Admitted runs POST
   the exact approved SHA to the protected production `FORGE_DEPLOY_HOOK_URL`.
   Never commit or print that URL. The workflow then polls `/ready` every ten
   seconds for up to twenty minutes, requiring JSON status `ok` and the exact
   SHA. A hook
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

### Pinning rollout and maintenance

Install the reviewed script before the first run of the updated hook workflow.
The old workflow does not supply the mandatory custom pin, so its ordinary
Deploy button/hook must not be used during that short configuration transition.
Saving the script does not deploy. Let the reviewed PR's protected workflow send
the first pinned request; verify its build log names the approved SHA and `/ready`
returns that same SHA. Keep push-to-deploy disabled. Use GitHub's normal manual
dispatch (tests enabled) for redeploys; an unpinned Forge Deploy Now is rejected.

Retain Forge's atomic-release macros, shared storage and release retention.
Never fix a failed pin by resetting an active checkout or overriding RELEASE_ID.
Investigate the original attempt, then release a currently approved candidate.
After Forge upgrades or deploy-script edits, run the pinning contract test and
verify the installed script still matches the reviewed repository version.
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
- InnoDB keys are limited to 3072 bytes under the production MySQL contract.
  Four default-width `utf8mb4` strings can exceed that even when the index name
  is short. Declare bounded lengths and let the migration linter calculate the
  worst-case byte width before release.

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

- secret `FORGE_API_TOKEN`, created with Forge's `server:view` scope only and
  an owner-visible expiration or review date;
- variable `FORGE_ORGANIZATION_SLUG`;
- variable `FORGE_SERVER_ID`;
- variable `FORGE_SITE_ID`.

The reporter at `scripts/ci/report-forge-deployment.php` uses Forge's current
deployment-list endpoint, requests one newest deployment, and performs one GET
operation only. It prints only commit, match, status, and timestamps. Missing
configuration is non-fatal. Never give this diagnostic write scopes merely for
convenience, and never replace exact-SHA `/ready` verification with Forge's
status alone.

## Smoke checks

```bash
curl -fsS https://app.theeverbranch.com/up
curl -fsS https://app.theeverbranch.com/ready
```

Then use the signed-in Safari session to check `/landlord`, Transactions,
tenant switching, agreements, and a safe billing view. Do not create charges,
refunds, agreements, or customer messages as part of a release smoke test.
