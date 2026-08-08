# Migration Safety Gate

## Purpose and classification

This is a core platform release-safety capability. It is tenant-neutral, has no
entitlement or billing state, and does not read or mutate customer data. It
protects every Everbranch tenant, including Modern Forestry, by stopping an
unsafe database candidate before Forge receives a deployment request.

The gate addresses five recurring production risks:

1. MySQL rejects an automatically generated index or foreign-key identifier
   longer than 64 characters.
2. MySQL rejects a composite `utf8mb4` key wider than InnoDB's 3072-byte
   maximum.
3. MySQL commits an early DDL statement, the migration later fails, and Laravel
   never records the migration as complete.
4. A migration works on a new empty database but cannot upgrade the schema from
   the previously released commit.
5. GitHub sees a stale `/ready` release but has no Forge-side status to explain
   whether the candidate is queued, running, failed, or complete.

## Required CI sequence

Pull requests use MySQL 8.4 and run these checks in order:

1. `scripts/ci/lint-migrations.php` inspects migration files changed since the
   target release.
2. `tests/Integration/WorkflowStudioMySqlMigrationRecoveryTest.php` recreates
   durable partial schemas and proves each registered migration can resume.
3. `scripts/ci/rehearse-migrations.sh` builds the prior commit's schema in a
   disposable database, applies the current migrations, and runs the migrator
   again to prove the result is settled.
4. `scripts/ci/verify-mysql-schema-baseline.sh` proves that both the complete
   migration tree and a blank database booted from the committed MySQL schema
   dump match the approved, data-free schema fingerprint.

For a production release, GitHub reruns this gate whenever the merged release
changes migrations, the schema baseline, database configuration, migration
tooling, or the recovery contract. A verified non-schema merge may reuse its
already-passed PR MySQL gate only after GitHub proves its final tree is exactly
the reviewed PR tree and all named PR checks passed. Direct pushes,
unverifiable merges, and every manual emergency run execute the gate. A
manually approved emergency deployment may skip the full application test/build
job, but it may not skip migration safety.

## Linter contract

The migration linter fails when a changed migration:

- modifies, renames, or deletes a migration that may already be recorded in
  production;
- creates a table without a same-migration `Schema::hasTable()` recovery guard;
- declares or would generate an index or foreign-key name longer than MySQL's
  64-character identifier limit;
- declares a same-table composite character key whose maximum `utf8mb4` width
  exceeds MySQL's 3072-byte InnoDB limit; or
- contains more than one schema/DDL step without a registered interruption
  recovery scenario.

Use explicit names, preferably 60 characters or fewer, when a generated name
could approach the limit. Fix a released migration by adding a new idempotent
repair migration. Do not edit the released migration and do not delete a
production table to make Laravel retry it.

There is one deliberately narrow exception: a historical migration that cannot
create a clean database on the supported MySQL version cannot be repaired by a
later migration, because execution never reaches that later file. Such a
compatibility correction must:

- change only the blocking identifier or the minimum restart guard;
- pin the exact released and proposed SHA-256 checksums in
  `scripts/ci/legacy-migration-compatibility-manifest.php`;
- name a MySQL test that executes that exact migration from its durable retry
  boundary; and
- pass the full baseline-to-current MySQL rehearsal.

Any unlisted edit or one-byte deviation from an approved checksum still fails.
Do not use this exception for new columns, data changes, product behavior, or
ordinary schema evolution; those always require a new migration.

Run the changed-migration linter locally while editing with:

```bash
composer lint:migrations
```

Before publishing a branch containing committed work, compare against its real
base:

```bash
php scripts/ci/lint-migrations.php --base="$(git merge-base HEAD origin/main)"
```

## Interruption-recovery contract

Every new multi-step migration must be added to
`tests/Integration/migration-recovery-manifest.php`. Its referenced MySQL test
must name the migration and reconstruct at least one durable partial state,
such as:

- the first table exists but later tables do not;
- a table and early foreign keys exist but a trailing index does not;
- a new column exists but its supporting index does not; or
- an old index remains because replacement failed midway.

The test must call the real migration `up()` method from that state and prove
the complete intended schema exists. A clean migration test alone is not an
interruption test.

## Production-like schema rehearsal

The rehearsal script accepts a baseline Git commit and refuses to run unless:

- `DB_CONNECTION=mysql`; and
- `DB_DATABASE` clearly contains a `ci`, `test`, or `testing` segment.

It extracts only the baseline commit's migrations, builds that historical
schema using the current Laravel runtime, and then upgrades it with the current
migration directory. This catches dependencies and ordering problems that an
empty-database migration cannot reveal.

Before building, the rehearsal replaces only checksum-matched historical files
listed in `scripts/ci/legacy-migration-compatibility-manifest.php` inside the
temporary extraction. This is necessary when the released source itself cannot
create a clean MySQL schema. The helper refuses repository paths, verifies both
checksums and the named recovery test, and never changes production files or a
database. A mismatch stops the rehearsal.

Example against a disposable MySQL database:

```bash
DB_CONNECTION=mysql \
DB_DATABASE=everbranch_test \
DB_HOST=127.0.0.1 \
DB_USERNAME=root \
DB_PASSWORD=root \
scripts/ci/rehearse-migrations.sh origin/main
```

The script explicitly wipes the disposable schema before building the prior
release because interruption fixtures do not create Laravel's migration
bookkeeping table. It is intentionally hard-locked to a test-like database
name. Never weaken that name check or point it at production.

## Optional Forge deployment visibility

Exact activation proof still comes from `/ready`. When that check fails, the
production workflow can make one read-only Forge API request and place an
allowlisted summary in the GitHub job summary. The diagnostic reports only the
expected commit, latest Forge commit, match state, deployment status, and
timestamps. It does not fetch environment values or deployment scripts, and it
contains no POST, PUT, PATCH, DELETE, retry, deploy, or reset action.

Configure these in the GitHub `production` environment:

- secret `FORGE_API_TOKEN` with the narrowest site/deployment read scopes Forge
  offers and an expiration date;
- variable `FORGE_ORGANIZATION_SLUG`;
- variable `FORGE_SERVER_ID`; and
- variable `FORGE_SITE_ID`.

If any value is missing, the diagnostic safely reports that it is unconfigured
and exits without affecting the failed deployment. Token creation and scope
selection remain an explicit operator action. The code does not create or
rotate credentials.

The integration uses Forge's current `/api` JSON API and a
`latestDeployment` include. Do not reintroduce the discontinued legacy `/api/v1`
deployment-history endpoint.

## Failure response

When the gate fails:

1. Leave the active Forge release in place.
2. Read the exact lint, recovery-test, or rehearsal error in GitHub Actions.
3. Add a new restart-safe repair migration when a released schema needs repair.
4. If the released file itself prevents every clean MySQL install, use the
   checksum-pinned compatibility process above rather than weakening the gate.
5. Reproduce any new durable partial state in the MySQL recovery suite.
6. Re-run protected CI and release only after all three migration checks pass.

See `docs/operations/forge-atomic-release-runbook.md` for activation, readiness,
and rollback procedure.
