# Trajectory source coverage reconciliation

Live Plaid data is authoritative for a connected account from its first available
transaction onward. A Monarch transaction export fills only the earlier history
gap. This reconciliation pairs a Monarch account with one uniquely matching
Plaid account in the same household, ignoring a masked Monarch suffix such as
`(...3129)`.

Superseded Monarch rows are excluded through the existing ledger state, but are
not deleted. They keep encrypted source evidence and receive an encrypted audit
marker. Unmatched names, statements, transfers, and live Plaid rows are not
changed.

Preview before applying:

```sh
php artisan trajectory:reconcile-import-sources --owner=RECIPIENT_EMAIL --space=HOUSEHOLD_SPACE_ID --json
```

Use `--apply` to exclude overlap and `--restore` to restore only rows excluded
by this process. Both require current finance access. Monarch imports run the
same idempotent policy after import, so future files fill only uncovered time.

## Exact provider source identity

MySQL Trajectory account and transaction source keys, including the nullable
pending source key, use `utf8mb4_0900_bin`. Provider IDs are case-sensitive;
case-insensitive SQL equality can otherwise select a different reviewed source
before any application-level comparison. Binary, accent-sensitive and NO PAD
comparison preserves exact IDs through account lookups, transaction ingest,
pending-to-posted conversion and removal matching. SQLite's existing binary
comparison is unchanged.

The repair migration validates all three existing column shapes and both unique
indexes before its first DDL. It retains key lengths, nullability, source bytes,
financial rows, classifications, allocations and balances. A retry skips any
completed table after an interrupted MySQL DDL operation. Code rollback retains
the exact schema; folding the keys again could collide with newly coexisting
provider IDs. MySQL may rebuild indexes and briefly acquire metadata/write locks;
use the normal serialized migration/deployment rehearsal and exact release gate.

This schema change does not import missing transactions, repair prior merged
source history, recategorize data, change connection cursors or prove complete
provider coverage. Any source-backed backfill remains a separate guarded owner
operation. The MySQL recovery regression proves distinct case-only accounts and
transactions coexist, updates/removals target only the exact ID, pending posting
uses the exact pending ID, and reviewed split allocations survive retry/rollback.
The data-free schema dump records the column collations. The existing generic
schema fingerprint does not include character-collation metadata, so explicit
metadata assertions accompany the MySQL regression and dump verification.
