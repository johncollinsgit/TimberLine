# Enterprise current-main integration candidate

October 22, 2026. This is an isolated source rehearsal, not a production merge,
deployment or permission grant.

## Inputs and preservation

- Current main: `084bbb108e4f66b6720352f0bbb48408bb889759`.
- Enterprise source: `904ebe76df6473ef7bfeeace6aebbd3f9f429c63`, draft PR478.
- Earlier Enterprise source/review stack remains PR458, 459, 461, 463, 466, 468,
  470, 474, 476 and 478. This candidate makes their combined current-main tree
  concrete for review. It does not authorize bypassing their review/release order.
- The voice prototype PR472 is already integrated through its reviewed source;
  do not independently merge it again. Separate support PR473, proposal PR475,
  unmerged Fleet PR460 and native payments/terminal work are not imported.

The only merge conflicts were `README_FOR_AGENTS.md`, `SYSTEM_SNAPSHOT.md` and
`package.json`. Both documentation additions were retained. The package retains
Enterprise builds and voice tests and main's full
`tests/e2e/highlevel-fleet*.test.mjs` glob, including onboarding coverage.

Before committing, comparisons verified current-main HighLevel controllers,
services, views, scripts, routes, configuration and tests were unchanged, and
Enterprise application/runtime/view/controller/test paths matched PR478 source.
The integration adds no provider, billing, migration or authorization behavior.
The data-free schema and additive recovery registrations remain subject to the
normal MySQL gate; they are not backups.

## Validation and source approval

Run the combined frontend build, focused Enterprise and complete Fleet contracts,
independent Enterprise runtime/policy suites, applicable retail Fleet regression,
schema/migration lint and actual private Chromium/WebKit fixtures. Retain the
normal complete PHP/MySQL/browser CI gates and obtain independent review of the
conflict resolution and shared boundaries. Record the final exact SHA and checks
in the draft PR rather than treating an older head as current approval.

Keep this candidate draft and do not merge until repository review/release gates
are satisfied. If main advances, inspect that difference, refresh this isolated
candidate and rerun applicable checks. Do not overwrite other owners' branches.

## Live actions remain separate

The owner has not yet approved the isolated runtime/database/account/key/storage,
presenter membership or narrow route configuration. The concrete destinations
and decisions are in [the approval packet](enterprise-runtime-approval-packet.md).
Existing Forge tabs expose sign-in only; the computer-use runtime did not expose
a verifiable PC device binding. A strict, non-interactive PC SSH preflight to
`forge@129.212.138.111` failed before authentication because the ED25519 host key
was unknown. No host trust, credential or security setting was changed and no
remote command ran. Do not bypass that warning.

Repository history records server ID `1165565` and 8 GB RAM / 4 vCPU as of July;
these are historical facts, not current authenticated verification. Site ID,
PHP/socket, active layout and capacity still require the release owner's
authenticated read. Do not infer them from test configuration.

After checks/review and explicit applicable approvals: verify that live target,
prepare only the approved isolated runtime, release through the protected
GitHub-to-Forge atomic path and verify hosted persistence/ACL/files/cookies and
the exact `/ready` release. Use fictional records; no real college invitations
or private-data import. A public sample is not a persistent client workspace.
Do not use direct push-to-deploy, emergency test bypass, reset/clean, broad cache
clearing, live asset replacement or destructive database rollback.

AI credentials/shared trial ledger/rates/spend reconciliation, mailbox/OAuth,
service sender, recipients, real SMS/email and scanner acceptance remain their
own gates. The trial stays lookup-only and the tour stays preview-only. Related
paid AI/SMS/email calls from this work are zero. Backup/searchable recovery
remains plan-first; no operational backup or restore guarantee is established.
