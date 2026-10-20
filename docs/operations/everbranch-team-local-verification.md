# Team local verification and disk findings

Report date: 2026-10-17 UTC. Scope: local development and fictional acceptance only. No deployment, external data changes, credentials, invitations, live Asana reads, source deletion, subscription changes or billing configuration.

## Disk capacity and cleanup candidates

Discovery: C free **5,178,904,576 bytes (4.82 GiB)**; G free **2,996,659,654,656 bytes (2.73 TiB)**. After isolated clone/tests/screenshots: C free **5,028,155,392 bytes (4.68 GiB)** at the recorded checkpoint. Free space changes with other activity. The bounded local work fit without deleting anything; a full native build or dependency install still needs a new capacity check.

| Exact candidate path | Observed bytes | Recoverability / restrictions |
| --- | ---: | --- |
| `C:/Users/father/AppData/Local/npm-cache/_cacache` | 1,827,001,767 | Reproducible package-download cache when registry/network access is available. Do not clear during a package operation; current restricted network may prevent immediate redownload. Candidate only, not deleted. |
| `C:/Users/father/AppData/Local/npm-cache/_npx` | 56,271,595 | Reproducible temporary package runners, subject to registry availability and active-tool check. Candidate only, not deleted. |
| `C:/Users/father/AppData/Local/npm-cache/_logs` | 12,635 | Diagnostic logs; only removable if no longer needed for troubleshooting. Not deleted. |
| `C:/Users/father/AppData/Local/Temp/playwright_chromiumdev_profile-UaKV3B` | 11,870,241 | Generated browser profile. Conditional candidate only after its owning session is closed and no needed browser state remains. Active use was not disproved. |
| `C:/Users/father/AppData/Local/Temp/playwright_chromiumdev_profile-1k0ZCe` | 13,115,418 | Same restriction: may be active. Not deleted. |
| `C:/Users/father/AppData/Local/Temp/playwright_chromiumdev_profile-AKaXp0` | 7,132,701 | Same restriction; older timestamp does not prove safe deletion. Not deleted. |

The npm cache total was **1,883,285,997 bytes (1.75 GiB)**. Temp contained approximately **195,276,885 bytes (186 MiB)** in enumerable files at discovery; this is not permission to delete Temp wholesale. No source, database, backup, real preservation archive, user file, system file, installed application, dependency tree or worktree is proposed for deletion. The private Team archive path was inaccessible; no contents were read and it is not a cleanup candidate. No cleanup operation was attempted. Parent owns any cleanup authorization/follow-up.

## Repository state

Local branch: `codex/team-work-management-local-20261016`, base `69c3416`. Root: `C:/Users/father/Documents/Codex/2026-10-16/task-2/everbranch`. Original Team worktree was clean and retained unchanged; separate AI/voice work was also preserved. No changes were pushed or committed into another worktree. This clone has shared local object storage; the exported patch is the portable review artifact.

## Actual checks

| Check | Result | What it establishes |
| --- | --- | --- |
| `node --test scripts/everbranch-team/verify-source-export.test.mjs scripts/everbranch-team/asana-preservation-client.test.mjs scripts/everbranch-team/preview-note-fields.test.mjs scripts/everbranch-team/offline-queue.test.mjs scripts/everbranch-team/reconcile-import.test.mjs` | **52 tests passed, 0 failed/skipped** | 32 inherited synthetic preservation tests plus 20 new queue/import tests. No real provider request. |
| `php scripts/everbranch-team/work-graph.test.php` | **47 assertions passed** | In-memory lifecycle/permission/isolation/replay/date/archive/subtask/sharing contracts. Not a Laravel/Pest database test. |
| `node scripts/everbranch-team/fixture-acceptance.mjs` with existing Playwright/Chrome | **18 checks passed** | 6 loopback fixture API checks plus 12 desktop/responsive Chromium checks. Create/replay/My Tasks, cross-tenant and viewer rejection, stale text conflict, notes/comment/completion/reopen/capture, guest grants, tenant-switch clearing, 390px no-horizontal-overflow, dialog cancel, keyboard/Escape and no desktop runtime error. |
| Screenshot inspection | Desktop and responsive phone PNGs inspected | Original Everbranch-styled local fixture, readable list/detail and controls. Responsive Chromium is not native iOS/Android validation. |
| `php scripts/ci/lint-migrations.php --base=HEAD --working-tree` | **Passed: 0 changed migrations** | No new migration/schema change. No MySQL recovery evidence implied. A process-only Git safe-directory setting was needed under the elevated interpreter account; no global Git config changed. |
| Existing Pint `--test` on the three new PHP files | **3 files passed** | Repository PHP formatting. Existing installed formatter used; no installation. |
| PHP syntax and Node syntax checks | Passed | New PHP and JS contract/harness files parse. |
| Parity generator | **36 sections, 643 clauses** | Scope inventory only; no product parity completion claim. |

The offline deduplication test first exposed an incorrect property lookup, which was fixed before the final 52-test run. Browser locators were tightened after strict-selector failures. Those failed exploratory runs are not counted as passing acceptance. Final evidence reflects the corrected code.

Evidence files outside source checkout: `../evidence/team-local/node-tests.txt`, `domain-tests.txt`, `migration-lint.txt`, `acceptance.json`, `desktop.png`, `mobile.png`. All contain only code-level results or fictional fixtures. The loopback PHP server was stopped after acceptance; its disposable fictional JSON state is in the OS temp directory.

## Remaining integration and verification

Milestones A–E are incomplete. No production Team schema/routes/module gate, application persistence transaction, installed UI/native app, full task restore/collaborators/comment edit/delete, Inbox/mention/push delivery, scanner/upload adapter, board/calendar/fields/recurrence/dependencies/reporting, onboarding import review or optional AI interface is complete. The complete inventory and detailed phased acceptance plan preserve these requirements.

No full Laravel/Pest/MySQL integration suite, Vite build/typecheck, native emulator/device tests, device performance measurement, live source migration or real tenant review ran. This isolated checkout has no installed vendor/node_modules tree; standalone tests used the installed PHP/Node interpreters, and the browser/formatter reused existing read-only installations from another Everbranch worktree. No software was installed and no real database was connected.

PHP execution was unavailable under the default sandbox executable permissions; narrowly scoped execution of the already installed interpreter was approved and succeeded. No outstanding automatic approval rejection blocks this local handoff. Required future work is application integration and the acceptance/release gates in `docs/architecture/everbranch-team-local-implementation.md`. Parent retains deployment target/release approval and cleanup decisions.
