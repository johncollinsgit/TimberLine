# Working in Everbranch

Read `AGENTS.md` and this guide. Start with the user's request, the current diff,
and the files that implement the affected behavior. A small change does not
require an audit of the whole repository or its release history.

## Scope and orientation

- Inspect Git status before editing. Keep other work and its staging intact.
- Read the relevant runbook below and search the matching sections of
  `SYSTEM_SNAPSHOT.md` and `docs/agents/operating-reference.md` when needed.
  The reference preserves detailed feature contracts; consult those that apply
  to your change. Do not load the full historical documents by default.
- Follow existing services and ownership boundaries. Expand inspection when the
  dependency path, a failing test, or a shared contract calls for it.
- Do not turn a visual or copy fix into unrelated refactoring, provider setup,
  billing activation, dependency upgrades, or a new acceptance project.
- Report a real prerequisite or incompatibility promptly. Fix related regressions
  within the requested scope; preserve independent release/activation gates.

## Local verification

Choose checks from the behavior being changed, then let protected CI run the
full application suite and all MySQL, browser, and concurrency checks.

| Change | Local checks before pushing |
| --- | --- |
| Copy, branding, CSS, layout | Diff/format checks and the affected rendering/browser regression |
| A feature or service | Its tests plus affected callers and shared contracts |
| Authorization, tenancy, payments, rewards, shared infrastructure | Relevant isolation, replay, recovery, and broader regression suites |
| Migrations or schema tooling | Migration lint, real disposable MySQL recovery, reviewed schema baseline, and the migration runbook |
| CI or deployment | Workflow lint, release contract tests, and a complete protected PR run |
| Documentation only | Check paths, current rules, and diff; application tests are supplied by CI |

- Run `git diff --check` and format only the changed code. For changed PHP files,
  use `vendor/bin/pint --test path/to/Changed.php`.
- Run the affected Pest files, for example
  `php -d memory_limit=1G vendor/bin/pest tests/Feature/Area/RelevantTest.php --compact`.
  Use `--parallel` for compatible multi-file suites.
- Keep Modern Forestry's `composer test:modern-forestry` guard for changes that
  can affect its commerce, rewards, customer access, or embedded navigation.
- A full local Pest run is appropriate for broad/shared changes, unresolved
  regressions, or explicit requests. It is not a mandatory duplicate of CI for
  every scoped change. Do not repeat unchanged successful checks without a
  new failure, edit, or specific unresolved concern.
- Use fictional fixtures and disposable databases. Never run test migrations
  or destructive fixtures against production or a customer's database.
- State what was verified and what still requires live acceptance. A passing
  source test is not provider approval, a confirmed payment, or a live upload.

## Commit, push, and release

1. Make a focused branch from current `origin/main`, reusing a suitable checkout
   when available. Include the relevant tests and documentation.
2. Commit and push once the scoped local checks pass. Open a PR against `main`.
3. Wait for the exact current revision's required checks. `ci (8.4)` aggregates
   the build, six PHP shards, and three browser groups; `mysql migration recovery`
   aggregates four independent MySQL jobs. A failed, cancelled, missing, or
   unexpectedly skipped prerequisite cannot satisfy either aggregate check.
4. Merge the completed scope after protected checks pass unless the user requested
   review only or a concrete release/acceptance condition requires a hold. Check
   that the PR still targets the tested tree when another release advances main.
5. Let `.github/workflows/deploy.yml` trigger Forge. Successful PR results may be
   reused only for an identical merged tree with successful named checks. Schema
   and migration-tooling changes still run the production migration rehearsal.
6. Verify `/ready` reports the exact merged SHA and perform the relevant live smoke
   check. Never claim a change is live just because it was pushed or merged.

Keep production deployment serialized. Avoid overlapping publication from other
chats. Inspect current PR/run state when necessary; do not repeatedly rediscover
the whole release history or poll unchanged Actions runs continuously. Check at
coarse milestones and tell the user about meaningful changes or blockers.

Never bypass required checks, lower benchmark thresholds to pass, enable direct
Forge push-to-deploy, hotpatch live files, broadly clear production caches, or
use `git reset --hard` / `git clean` as normal deployment steps. Keep the existing
audited emergency path and its mandatory migration gate separate from routine work.

## Find the right contract

These are starting points, not a requirement to read every row. Product-specific
owner directions in `AGENTS.md` and the relevant preserved reference sections
continue to apply.

| Area | Start here |
| --- | --- |
| Release validation and parallel jobs | `docs/operations/parallel-release-validation.md` |
| Migration and schema safety | `docs/operations/migration-safety-gate.md` |
| Production recovery and drift | `docs/operations/release-observability-and-recovery.md` |
| Teams events, updates, setup | `docs/operations/teams-event-details-and-setup.md`, `docs/operations/everbranch-team-connected-operations-runbook.md` |
| Pay, rewards, stock, event attribution | `docs/operations/pay-tenant-rewards-and-events.md`, `docs/operations/everbranch-pay-owned-inventory.md` |
| Customer identity and commerce | `docs/architecture/unified-customer-commerce.md` |
| Modern Forestry commerce | `docs/operations/modern-forestry-production-guard.md` |
| Website commerce and payments | `docs/operations/self-service-payments.md`, `docs/operations/retail-shop-pay-wallet.md` |
| Wholesale email and Gmail | `docs/operations/wholesale-email-messenger.md`, `docs/operations/wholesale-native-gmail.md` |
| Enterprise isolated workspaces | `docs/operations/enterprise-ci.md`, relevant `app/Enterprise` and `app/Proposals` contracts |
| UI/theme work | `docs/ui/UI_SYSTEM.md`, relevant entries in `docs/ui/UI_CHANGELOG.md` |
| Provider terms and native publishing | `docs/operations/provider-terms-and-publishing.md` |
| Other domains and previous decisions | Search headings in `docs/agents/operating-reference.md` and the relevant current runbook |

## Documentation maintenance

Update the relevant runbook when behavior or operating practice changes. Update
`SYSTEM_SNAPSHOT.md` only for a meaningful system capability or production rule;
it is not a required changelog for every small edit. Record UI changes in the UI
changelog when applicable. Keep this guide short and place feature detail in its
own runbook. Do not prepend each task's narrative to the global agent entry guide.

This guide supersedes older orientation, local full-suite, and delivery directions
in the preserved reference. It does not weaken tenant boundaries, financial
authority, migration recovery, provider permissions, or live acceptance contracts.
