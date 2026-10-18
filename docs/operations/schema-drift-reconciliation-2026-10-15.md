# Reviewed schema reconciliation — 2026-10-15

## Trigger and scope

The shipping Phase 4 preflight compared active release `88bda81a` with its
approved MySQL baseline. Read-only metadata showed 402 tables versus 400:
two historical tables and 33 changed existing tables. Twenty tables had
substantive differences; twelve had constraint-name differences only and one
had column-order differences only. A substantive table can also have harmless
name/order differences.

This separate platform repair precedes shipping deployment. It does not
activate commerce, shipping or notifications, change Shopify connections,
checkout, credentials, rewards or consent, or call messaging/provider services.
The original release stays active until protected checks pass.

## Historical causes and retained records

- `collections` and `seasonal_scents` match the March migration added in
  `48cc6483` and removed from source in `65d5e7af`. Preserve both tables and
  declare their reviewed shapes on clean installations, with restart-safe
  creation and independent index/FK repair. Do not drop them to silence drift.
- Development tables match the global-admin schema from `da67361c`. Its source
  was reverted before the tenant-aware replacement's guarded CREATE. Add
  nullable tenant/actor fields, preserve unresolved historical ownership, allow
  nullable note titles, and retain 180-character change-log areas. Never infer
  ownership from a user. Scoped HTTP tests prove two verified tenants cannot
  list, edit or delete unassigned historical records.
- Candle Club's original CREATE default was 1. A source edit changed it to 2;
  a later data migration never altered the live default. Change the future-insert
  default only, preserving every saved setting including explicit zero/one.
- Restore missing unique keys, FKs and lookup indexes. Duplicate or orphan
  conflicts stop before DDL rather than deleting or deduplicating records.
  Actual MySQL constraints still enforce integrity against concurrent writes.
  Retain reviewed ordinary supporting indexes on both installation paths.

The read-only aggregate preflight found no duplicates across nine substantive
unique keys. Twenty-one existing FK source columns were checkable; two
development tenant columns did not exist. Four failed April 7 delivery records
referenced missing marketing profiles, with no sent/delivered times. Their
tenants and campaign recipients were present. There were no other orphan groups,
linked non-null tenant mismatches, or triggers on the delivery table. Both
development tables and both historical tables were empty. These observations
are evidence, not assumptions used to delete or assign records.

The one reviewed orphan case adds internal nullable
`marketing_email_deliveries.legacy_marketing_profile_id`. A transaction locks
dangling rows, retains each exact original ID, then clears the unresolvable live
relation, matching the original nullable FK's `ON DELETE SET NULL` semantics.
Conflicting retained evidence stops the repair. Each update binds literal values
from the locked row, avoiding MySQL assignment-order dependencies. Status,
recipients, provider IDs, content, payloads, metadata and timestamps remain
unchanged. Query Builder emits no model/workflow/provider events. The retained
field is hidden from serialization and is not an operational profile relation.

## Fingerprint contract

The gate remains strict for missing/extra tables and columns, types,
nullability, defaults, unique keys, index column order/prefixes and FK targets.
The canonical hash now also verifies every FK delete/update action.
`raw_fingerprint` retains the original physical algorithm for historical proof.

`resources/schema/reviewed-mysql-compatibility.json` permits only declared
historical names with exact reviewed definitions. Unknown aliases, altered
definitions, missing objects, alias-plus-canonical duplicates, changed FK
actions and extra indexes still drift; no object is discarded or deduplicated.

Physical column order alone is equivalent on exactly four declared tables:
`customer_external_profiles`, `development_notes`, `development_change_logs`,
and `tenant_onboarding_blueprint_provisionings`. Every column's presence and
definition is still hashed. Other table column order remains strict. Engine
and collation remain outside the fingerprint contract; the data-free observed
SHOW CREATE fixture retains them for disposable rehearsal.

## Required proof and release sequence

1. Pin the historical observed fixture to raw fingerprint
   `0aa3562df3cca1d362cd49211982ebb47bf02b97526ffe55251cfa95574d76ee`
   and the prior baseline to
   `79322533c76854e55f36920596f93070063d71463aa62d44eab341a40266d03d`.
   Fixtures contain schema only, never customer rows or production counters.
2. Reconstruct observed DDL from the pinned prior baseline in a uniquely named
   disposable MySQL database. Verify its raw hash, seed synthetic preservation
   and conflict cases, and run the real repair twice. The fixture helper rejects
   names without an explicit test/CI/sandbox marker.
3. Prove fresh and observed paths converge to one canonical hash. Exercise
   partial DDL, nullable ownership, wide text, saved settings, exact orphan-ID
   retention, untouched controls, rollback, real constraints and FK-action drift.
4. Generate SQL and fingerprint only through **Build MySQL Schema Baseline** in
   disposable MySQL. Review both artifacts; never adopt an unexplained live hash
   or create a production schema dump as the approved baseline.
5. Require exact-head quality, application tests, actual MySQL recovery,
   prior-release upgrade and blank-baseline import. Fingerprint/helper/manifest/
   fixture changes also force the production migration gate to rerun.
6. Deploy through protected GitHub/Forge. Verify exact-SHA `/ready`, `/up`,
   migration status, matching fingerprint and preserved aggregate counts.
7. Rebase Phase 4 onto the repaired release, regenerate its baseline, pass its
   exact-head gates, deploy disabled and smoke its protected surfaces. Modern
   Forestry is the requested native pilot; its existing Shopify store and
   checkout remain independent.

Rollback changes code, retaining all forward-only schema repairs and evidence.
Never drop historical tables or restore dangling relationships as code rollback.
An older release's raw signature is not the new approved schema signature.

## Change log

2026-10-15: added reviewed forward repair, pinned historical compatibility,
FK-action verification, observed-shape recovery, orphan-ID retention and release
gate coverage. Code release and shipping activation remain separate steps.

## Production verification

PR #400 deployed through protected run `38815157152` / Forge `80345469` at
`228c84d5f302db247b00c110d9fac7d165e28bbe`. `/ready` returned that exact release;
`/up`, migration status and `schema:fingerprint` passed. Production matched
approved canonical hash `cb51b941553b6acb63b928f367db0f776a1f65bbf2c2cddea2f27d583bbb01bf`.

A consistent read-only snapshot verified all four original delivery references
and all other fields against the pre-release aggregate digests. No dangling
profile links remain. The one saved pause setting remains 2 and the future
default is 2. Historical table counts remain zero. Commerce/shipping are disabled,
and native payments, active holds and shipments remain zero. Phase 4 can now
proceed through its own combined schema baseline and protected release gates.
