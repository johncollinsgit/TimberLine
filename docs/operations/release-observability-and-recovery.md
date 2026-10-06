# Release Observability and Recovery

This is the operating contract for Everbranch production release visibility,
schema-drift detection, and database recovery drills. It applies to every
tenant. It does not alter Modern Forestry's Shopify credentials, routes,
checkout, customer data, rewards, or fulfillment behavior.

## What is automatic

- GitHub releases only after the protected test/build and MySQL migration gates
  pass. Forge activates a fresh release atomically, and GitHub accepts it only
  when `/ready` reports the exact candidate commit.
- Every release reports whether the optional Forge failure observer is fully
  configured. On an exact-SHA readiness failure, it makes one GET-only Forge
  API request and writes an allowlisted deployment summary to GitHub Actions.
- A failed `Deploy Production` workflow sends a concise alert to the configured
  operations webhook. The original GitHub run remains the source of detail.
- The existing Forge scheduler runs `schema:fingerprint` daily at 03:10. It
  reads MySQL metadata only, compares it with the committed data-free schema
  signature, and exits non-zero on unexpected DDL drift.

No automatic check runs a production backup restore, posts a deployment,
changes Forge settings, retries a release, or reads customer rows.

## One-time GitHub production configuration

These values must be configured in the `production` GitHub environment, not in
source control or a local `.env` file:

| Setting | Purpose | Status |
| --- | --- | --- |
| Secret `FORGE_DEPLOY_HOOK_URL` | Required atomic-release trigger | already required |
| Secret `FORGE_API_TOKEN` | Optional, least-privilege read-only failure diagnosis | configured (`server:view` only); review before 2027-08-08 |
| Variable `FORGE_ORGANIZATION_SLUG` | Forge organization identifier | configured |
| Variable `FORGE_SERVER_ID` | Forge server identifier | configured |
| Variable `FORGE_SITE_ID` | Forge site identifier | configured |
| Secret `RELEASE_ALERT_WEBHOOK_URL` | Optional Slack/Teams/Discord-compatible operations alert endpoint | operator must choose and add |

Create the Forge token with `server:view` only, an owner-visible
expiration/review date, and no write/deploy/reset permission. Creating a
credential is an operator action because it creates persistent access. Once
the secret is present, the next failed readiness check
will confirm the observer path without exposing the token.

The alert secret receives a minimal JSON body shaped as `{ "text": "..." }`.
Use an incoming webhook that accepts that shape, or place a small internal relay
behind the secret. The message contains only release outcome, commit SHA, and
the GitHub run URL.

## Approved MySQL schema baseline

`database/schema/mysql-schema.sql` and
`database/schema/mysql-schema.sha256` are data-free artifacts created from a
disposable MySQL 8.4 database. The SQL dump is not created with `--prune`; no
migration files are removed. The companion SHA-256 represents table, column,
index, and foreign-key metadata, not data or auto-increment values.

To intentionally refresh the baseline after an approved migration:

1. Run **Build MySQL Schema Baseline** from GitHub Actions against the reviewed
   branch.
2. Download the `mysql-schema-baseline` artifact.
3. Review the SQL and fingerprint diff. A non-migration change should not alter
   either file.
4. Commit both files in the same reviewed change, then let protected CI prove a
   clean migration tree and a blank schema-dump boot produce the same hash.

Never regenerate this baseline against production and never commit a database
backup or customer data.

## Production schema-drift response

If `schema:fingerprint` fails:

1. Do not run `migrate:fresh`, `schema:dump`, or any destructive command on
   production.
2. Inspect the scheduled-command output and the last release SHA.
3. Compare the intended migration history against the committed schema baseline
   in a disposable MySQL 8.4 database.
4. If the schema change is intentional, add a new restart-safe migration and
   regenerate the reviewed baseline. If it is not intentional, stop further
   releases and investigate the out-of-band DDL.
5. Record the conclusion in the release evidence before retrying a deployment.

## Staging backup-and-restore drill

Run a drill at least quarterly and after a material database-provider or backup
policy change. The input must be a provider-generated backup stored in an
approved secure location; do not upload it to GitHub Actions or add it to this
repository.

1. Create an isolated temporary MySQL database named with `restore`, `test`,
   `testing`, `ci`, or `sandbox` (for example `everbranch_restore_test`).
2. Use credentials that can access only that drill target where possible.
3. In an isolated checkout with the matching application release, run:

   ```bash
   DB_CONNECTION=mysql \
   DB_DATABASE=everbranch_restore_test \
   DB_HOST=127.0.0.1 \
   DB_USERNAME=restore_operator \
   DB_PASSWORD='…' \
   scripts/ops/verify-mysql-backup-restore.sh /secure/path/backup.sql.gz
   ```

4. Confirm the script reports tables restored and a valid migration status.
5. Record backup timestamp, source release, restore duration, row-count spot
   checks performed by the designated operator, and the cleanup confirmation.
6. Delete the isolated drill database and securely dispose of the copied backup
   under the provider's retention policy.

The script refuses non-test-like names before it drops anything. That guard is
deliberate; do not weaken it. A real production restore remains a separately
authorized incident procedure.
