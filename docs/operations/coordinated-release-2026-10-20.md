# Coordinated October 20 release

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

## Remaining releases and acceptance

Preserved independent quality candidates are settings `dc57b1de`, electrician
intake `1a6f1af0`, then truthful health `63e48025`. Integrate and verify each final
combined tree, merge and deploy one at a time, and confirm exact `/ready` before
the next release. Request a fresh live audit only after all released heads are live.

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
