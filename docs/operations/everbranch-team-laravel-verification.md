# Team real Laravel checkpoint — 2026-10-17 UTC

This supersedes the contract-only implementation status in the preserved first checkpoint. The application now has a real, locally tested project/task flow and an initial collaboration/Inbox slice. Full feature parity, native integration and production acceptance remain incomplete. No production/existing real database, real Asana data, external credential/account, invitation, billing setting, remote branch or deployment was changed.

## Existing task and checkout

Codex inventory verified the active existing task **Build Everbranch work management**, ID `01a14722-a633-74f0-9a74-6f197170b031`, in the **Tasks** section. Use that existing entry; no replacement task was created and no other task/window was interrupted.

Work is staged locally in `C:/Users/father/Documents/Codex/2026-10-16/task-2/everbranch`, branch `codex/team-work-management-local-20261016`, base `69c3416`. Original Team preservation and separate AI/voice worktrees are preserved. This clone shares local Git objects; the review patch is portable. Integration with the reviewed current backend remains required before release.

The existing mobile repository was found clean at `C:/Users/father/Documents/Codex/2026-10-16/i-guess-i-was-asking-if/work/mobile`, branch `codex/everbranch-voice-ai-pc-20261016`, commit `d9b0b31`. It uses React/TypeScript/Capacitor and was not modified. No duplicate native application was created.

Final read-only coordination check found the original Team worktree clean, mobile clean, and the separate backend clean at `79d430c` on `codex/everbranch-fictional-voice-pilot-20261016`. That backend advanced from the initially observed AI/voice branch during this task. This task performed no checkout, commit or source edit in those worktrees.

## Implemented real application flow

- `routes/team.php` registers authenticated, verified, tenant-resolved, throttled, CSRF-protected web/JSON endpoints. `TeamWorkAccess` derives current actor/role/member IDs from the database. `TeamProjectPolicy` and `TeamTaskPolicy` enforce object access. A platform role never bypasses private Team grants.
- The canonical catalog contains default-disabled/internal-only `team_work`. A separate default-off rollout plus exact tenant-ID allowlist is also required. Only fictional test tenants were enabled. Normal app navigation includes Team only after current membership, rollout and canonical access pass; public/native discovery stays hidden.
- Three additive migrations create nine Team-owned tables: projects, tasks, project members, task-project memberships, tenant command locks, immutable native versions, activity, replay receipts and recipient Inbox. Guarded creates, recovery of missing keys/indexes on retained tables, and explicit short names pass the migration linter. Rollback methods retain work/history.
- `TeamWorkRepository` commits canonical entity changes, native original/revision snapshots with SHA-256, relationship changes, activity, idempotency receipts and eligible internal notifications together. Tenant commands are serialized; exact retries do not duplicate work. Current access is checked again on replay. No existing Field task/job table is converted or rewritten.
- Real responsive Blade/JavaScript screens use the existing app shell and branding: project create/read/edit/privacy/dates/members/sections/archive/reopen, task create/read/edit/assign/start/due dates/priority/complete/reopen, sections/membership position, subtasks, My Tasks and task search. One task can appear in several projects with one identity and comment/history stream. Task deletion is currently API-only; restore/duplicate/owner transfer and broader collaboration grants are pending.
- Author-only native comment edit/delete retains private native revisions. Explicit mentions validate recipient task access and never grant access. Assignment/mention events create internal recipient-scoped Inbox items. Read/unread/archive reauthorize current task access; revoked private content disappears. The Inbox examines the latest 100 recipient events and does not yet provide the full requested filters/events. No push/email/workflow/provider dispatch occurs.
- Permission/session errors purge protected detail, project choices and member choices and invalidate late responses. Stale text conflicts retain the local editor contents. New-task/project dialogs keep stable IDs and request keys across retries. Input rendering uses text/value properties, not source HTML.

## Actual verification

| Command / scope | Actual result |
| --- | --- |
| Team + existing navigation + Field Service + mobile-platform Pest files | **62 passed, 1,064 assertions**. Includes 22 Team feature tests and 40 existing regression tests; do not add the focused count again. Explicit `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. |
| Dedicated Team configuration | `phpunit.team.xml` forces in-memory SQLite, testing environment and non-delivering mail/session/cache settings. The focused configuration was exercised and is the `test:team-laravel` entrypoint. |
| `laravel-acceptance.mjs` | **25 real application browser checks passed**: ordinary Fortify login, actual navigation, missing-CSRF rejection, persisted project/task creation and edits, assignment/date, reload, completion/reopen, native comments/edit/delete/mention, archive/reopen, My Tasks, Inbox/read/archive, access-revoked write/cache purge, guest privacy, 390px layout and cross-tenant direct-ID denial. |
| Node preservation/offline/import suites | **52 passed**, 0 failed/skipped. Synthetic contracts; no real source request or application import. |
| Standalone PHP graph suite | **47 assertions passed**. Separate lower-level contract coverage. |
| Existing TypeScript check | `tsc -p tsconfig.workflows.json --noEmit` passed. |
| Existing chart regression tests | **11 passed**. |
| Vite production asset build | Passed in 5.01 seconds using `vite build --configLoader native`. This is a local build, not deployment. |
| Migration linter | **Passed for 3 changed migrations**. |
| MySQL recovery test | **1 safely skipped, 0 assertions** under SQLite. Not MySQL evidence. Recovery is registered in the existing disposable CI service job with explicit opt-in and test-database/host checks. |
| Source formatting/syntax | PHP formatting and JS syntax checked; Git whitespace and exported patch reverse-check recorded with the handoff. |
| Visual inspection | Actual Laravel desktop and 390px phone screenshots inspected. Responsive Chromium does not establish native iOS/Android behavior. |

Primary evidence: `../evidence/team-local/laravel-regressions.txt`, `laravel-tests.txt`, `node-tests.txt`, `domain-tests.txt`, `migration-lint.txt`, `mysql-recovery-status.txt`, `vite-build.txt`; `../evidence/team-laravel/browser-tests.txt`, `acceptance.json`, `desktop.png`, `mobile.png`. All data is fictional. Failed exploratory runs exposed dropped nested edits, stale detail loading and an error-handler name collision; these were fixed before the final passing acceptance. Failed runs are not counted as success.

`prepare-laravel-fixture.php` refuses existing databases/non-SQLite bindings and only creates a new named SQLite file inside this checkout's `storage/framework/testing`. Synthetic accounts are local fixture rows, not external accounts. Browser acceptance used the ordinary application with `APP_ENV=local`, file sessions and CSRF; external browser requests were blocked. Runtime database/session files and fixture metadata are excluded from review artifacts; scripts contain only explicitly fictional test-account values. No real credentials were used.

## Dependencies and disk

The existing Composer dependency tree was reused after identical `composer.lock` hashes were verified. No `.env` or real database was copied. The ignored local `.env` contains only a comment; disposable runtime settings were explicitly supplied.

Node dependencies are borrowed through a workspace-local directory junction to the existing backend `node_modules`; no package install or cache deletion occurred. Team versus existing lock comparison found zero version differences for Team's package entries and one extra existing package. The lockfiles are not byte-identical, and no clean `npm ci` was run. Vite's native config loader kept generated configuration outside the borrowed dependency tree. A clean locked CI build is still a release requirement.

C had 4.82 GiB free at discovery, 4.68 GiB before Laravel continuation, and 4,464,615,424 bytes (4.16 GiB) at the recorded later checkpoint. Capacity was sufficient for the bounded local work. No unnecessary source checkout or dependency installation was added during continuation.

Cleanup candidate remains **`C:/Users/father/AppData/Local/npm-cache`**, observed total 1,883,285,997 bytes (1.75 GiB): `_cacache` 1,827,001,767; `_npx` 56,271,595; `_logs` 12,635. Contents are reproducible when registry access exists. Windows denied the process/command-line inventory, so use by other builds/handles was not conclusively excluded; cleanup safety is unverified. **Do not delete yet.** Permanent deletion is not required to continue. Source, databases, backups, user files, private archives and all other worktrees are protected. The first checkpoint records conditional browser-temp candidates; Temp is not a blanket cleanup target.

## Remaining gates and next work

1. **MySQL prerequisite:** no `mysqld`, `mysql` or `docker` executable was available on PATH and no disposable MySQL server was provisioned. Do not connect an existing database. Run guarded recovery/rehearsal and refresh/review the data-free schema baseline in an authorized disposable MySQL 8.4 environment before release. Baseline files were intentionally not fabricated or refreshed here.
2. **B files/search/collaboration:** implement Team-specific private upload/download/chunk authorization, storage, scanner integration, progress/cancel/retry and interrupted-upload tests. Existing WorkspaceAsset/job authorization cannot be reused without the Team object boundary. Current synthetic attachment-byte tests do not prove a real upload pipeline. Rich text/reactions/followed-work events/full Inbox filters and indexed entity search remain pending.
3. **C:** board/calendar, custom fields, templates, recurrence, dependencies/cycles, approvals/status/portfolios and structured reporting with source mapping review remain in the 643-clause inventory.
4. **D:** integrate the existing native app and mobile API contract, Field Service compatibility adapter and encrypted persistent offline/cache/sync. No native binary/device/emulator/physical-device/performance acceptance ran. The lower-level offline queue is not yet connected to the actual app.
5. **E/source migration:** real tenant-owned grant discovery/capture, durable application import/reconciliation, exception review/onboarding and disabled optional AI interfaces remain pending. Do not access real Asana, invite users, create provider credentials or change billing under this task's local boundary.

The initial repository loads a tenant graph and serializes writes per tenant. It is not a verified large-tenant performance design. More simultaneous-worker, revoked-session, accessibility, upload and date-boundary cases remain. The full suite and all requested features are not complete. No approval is pending for current local work; parent retains cleanup decisions, production target and release approval. Deployment and all external writes remain outside scope.
