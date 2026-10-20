# Coordinated October 20 release record

This is a dated release record. The [current product/setup map](everbranch-current-state.md),
`/ready`, the protected deployment run and the latest immutable audit identify
subsequent live state. No docs-only deployment is needed to record its own SHA.

## Last verified checkpoint — 2026-10-20 08:58 UTC

- Public quality #436 merged as `df9469eadaff3a857e95c4e711aac80e1e20cd67`.
- Marketplace #438 merged as `a10acffaec8aa89f4b6bc64ecf3fa2c7436a70a4`.
- Settings/electrician/truthful-health #439 merged as
  `00acdc1b05b04e81356c53601f6a6f653dd6f843`, Forge 80669972.
  [Protected deployment 39396530459](https://github.com/johncollinsgit/TimberLine/actions/runs/39396530459)
  succeeded and independent `/ready` returned that exact SHA. The merge tree equals
  checked integration tree `dfb891e758e0d4ce6824a2920be2d32171a72dbc`.
- [Checked CI 39394548668](https://github.com/johncollinsgit/TimberLine/actions/runs/39394548668)
  passed 4,093 PHP / 29,247 assertions, 8 settings and 34 public browser checks, 55 actual MySQL
  recovery cases / 352 assertions with zero recovery skips, baseline/upgrade and
  concurrency checks. Three normal-suite skips were deliberate fixture exports.
  Final deploy Test/Build and Migration jobs reused this identical checked tree;
  they were skipped rather than executed again. Live public GET/HEAD smoke passed 22.
- Marketplace's eight effective launch/collection flags remained off and host unset;
  Teams rollout stayed unchanged. A private gzip-verified pre-M backup was retained.
  That release did not perform a production-backup data restore drill.
- The immutable post-release audit scored 87.3 provisional (original 75.3), with
  R4/I2/X3 unknown at that audit. Subsequent evidence is evaluated in a new audit;
  neither accepted score is rewritten.

The planning statements below are retained as historical context. In particular,
references to pending combined CI or preserved candidates are not current release
status and are not authority to replay the old branches.

## Historical planning sequence

The PC and Mac sessions share one serialized integration/release owner. The
coordinator uses an isolated clone; original worktrees, source branches, dirty
changes and existing stashes remain preserved. No older PC checkpoint is replayed.

## Verified quality baseline

PR #436 froze source head `fb0a6cfa89fb5d785baf9b6b5ce72cf18ee4acde` and merged as
`df9469eadaff3a857e95c4e711aac80e1e20cd67`. Production run
[39392281921](https://github.com/johncollinsgit/TimberLine/actions/runs/39392281921)
passed; independent `/ready` reported that exact SHA, Forge current was
`releases/80667328`, and 18 GET/HEAD-only public Chromium/WebKit checks passed.
Prior known-good `1912ae104b44e3687f74a7af823973b047abd7fa` was retained as
`releases/80662073`. Quality introduced no migration or schema change.

## Marketplace integration and gates

Held source PR #437 preserves `1082f729a278da1d3d1e988ff31289e230f03e25` and
`b8cbbd1ba999bcde17082a392e2c37520c916ede` on its original `b0ff0c0a` base.
The coordinator copied those two commits onto the verified quality merge, keeping
current Teams/quality code, rollout defaults, schedules, Vite entries and manifests.

The public premium product key remains `markets_makers`; its canonical module
mapping is `markets_and_makers`. Both names are rejected as Simple core-branch
selections without tenant/entitlement creation or HTTP. Combined focused
verification passed 81 cases/930 assertions; frontend build, Pint and three-migration
safety lint passed. Full combined Linux CI, actual MySQL recovery, prior-release
upgrade rehearsal and exact-head review are required before merge. Source tests
alone are not combined-release evidence.

[Disposable baseline run 39393168595](https://github.com/johncollinsgit/TimberLine/actions/runs/39393168595)
on `5d1ed74992d4801f06ddb17649c06c550da06b5b` rebuilt and verified the combined
data-free MySQL 8.4 artifact. It retains all 448 existing tables and adds 27
`marketplace_*` tables. 430 existing CREATE definitions are byte-identical; 18
Teams definitions only spell out the already inherited utf8mb4 character set.
No existing table definition changes semantically. INSERTs contain only Laravel
migration bookkeeping. Combined fingerprint:
`684096c11eadf0470864600b629c2470ccd0c700b7c530ca85c43b81c94ba1b6`.

Both CI and deployment recovery commands retain `TeamWorkMySqlMigrationRecoveryTest`
and `MarketplaceMySqlRecoveryTest`. Both steps explicitly opt into the isolated
Teams recovery fixture with `TEAM_WORK_DISPOSABLE_MYSQL=1`; naming a skipped test
is not execution evidence. Deployment logs must show actual recovery cases pass.

## Historical planned follow-ups and continuing acceptance boundaries

The preserved independent candidates were settings `dc57b1de`, electrician
intake `1a6f1af0`, then truthful health `63e48025`. They were integrated into #439 and verified at
the checkpoint above. Future changes still require serialized exact-tree CI,
protected deployment and exact `/ready`, followed by a separately versioned audit.

The original rubric remains unchanged: baseline 75.3 provisional, rubric SHA-256
`f4ecefe6861587da7b611eca3a7016f9597f74d8037b276a507eaa701ac71048`.
Recovery/export, genuine provider acceptance and production/device performance
unknowns must not be counted as verified from synthetic fixtures or deployment alone.

Marketplace storefront and collection flags stay off. Domain/TLS, dedicated
merchant/platform approvals and credentials, real mail, tax/delivery setup, policies,
approved makers, actual purchases/refunds and eligible-device wallet acceptance
remain separate launch prerequisites. Shopify/Wix publishing remains phase two.
No real payments, customer communications, new credentials or production flag
changes are part of this integration. See `markets-and-makers.md`.

## Rollback prerequisites

Retain the verified prior Forge release and a current private database backup before
the Marketplace schema deployment. Preserve backup metadata in the coordinator's
private release evidence; do not commit backup contents or credentials. A gzip
integrity check is not a restore drill. The original recovery unknown stays explicit.

These three migrations are additive and restart-safe. Application rollback must
retain the new tables, orders, encrypted evidence and historical reconciliation.
Do not run down migrations, delete tables or clear customer data to undo a release.
Use the protected Forge retained-release process, then verify exact `/ready`.
