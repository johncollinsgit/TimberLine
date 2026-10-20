# Native transport / Field reference local checkpoint

2026-10-17 UTC. Prior three review archives/patches remain preserved. This checkpoint implements real backend integration seams and records current infrastructure/native limitations; it does not complete A-E or install/deploy a native feature. No push, deploy, real tenant/source access/import, external credentials/invitations/billing changes, package installation, large copy or cache deletion occurred.

## Source, preservation and implemented change

Local source: `C:/Users/father/Documents/Codex/2026-10-16/task-2/everbranch`, branch `codex/team-work-management-local-20261016`, base `69c3416`. Changes remain staged, not committed/pushed. Original Team and current backend/mobile worktrees were inspected read-only and remain untouched. Their observed clean heads are `69c3416`, `79d430c`, `d9b0b31`. Existing Codex task remains **Build Everbranch work management**, `01a14722-a633-74f0-9a74-6f197170b031`; no second task/app was created.

`EverbranchMobileTeamWorkController` adds a version-1 transport under `/api/mobile/v1/workspaces/{tenant}/team-work`, delegating to the same web Team/file/Field-link controllers and canonical repository/graph. The new route file is required separately from `routes/api.php`; existing Field task/channel APIs are unchanged. Guards include actual Sanctum bearer token, abilities, existing tenant resolver, independent default-off mobile flag, shared default-off rollout/exact tenant allowlist and current Team entitlement/private object ACLs. Web cookies are explicitly refused, preventing a CSRF-free alternate mutation path. UUID header keys bind commands/upload initialization; current versions and receipts remain shared with web. No bootstrap/catalog discovery or client renderer is enabled.

`TeamFieldWorkAdapter` provides actual versioned, replayable job link/unlink and minimal authorized job/task projections through web/native API endpoints. It uses current Field job access and canonical entitlement plus independent Team task access; platform/manager role or job access grants no private task visibility. Recycled/invoice-only/foreign-tenant jobs are excluded. Field entitlement is resolved freshly to avoid retaining cached grants. Legacy job/checklist rows and their notes/status/assignees/history are not converted or modified; tests compare full fresh raw rows and prevent mirror tasks. References retain native prior versions. No new schema is required in this increment.

Contract/route/source compatibility and next native work: `../architecture/everbranch-team-integration-contract.md`. Exact remaining A-E inventory: `../architecture/everbranch-team-remaining-work.md`, generated parity JSON/Markdown and `everbranch-team-milestone-index.json`. The index accounts for every clause once: A **169**, B **141**, C **84**, D **219**, E **30** (643 total). These are inventory counts, not completed features or parity percentages.

## Actual tests and evidence

| Scope / command | Result |
| --- | --- |
| `php -d memory_limit=512M vendor/bin/pest --configuration=phpunit.team.xml --compact tests/Feature/Team tests/Unit/UnifiedAppNavigationServiceTest.php tests/Feature/FieldService/FieldServiceWorkspaceTest.php tests/Feature/Mobile/EverbranchMobilePlatformTest.php` | **95 passed / 1,418 assertions**: 55 Team tests including 11 new adapter cases, plus 40 existing navigation/Field/mobile regressions. All forced in-memory SQLite with synthetic accounts/tokens/rows and no external provider delivery. |
| Focused adapter tests | **11 passed / 119 assertions**, included in the total above. Real Sanctum bearer/header/ability/expiry/revocation, cookie rejection, rollout/entitlement/verification/membership/private/viewer/cross-tenant checks, shared web/native task/Inbox/file state, quarantined duplicate chunk handling, Field link/unlink/replay/recycled jobs, minimal projection and untouched legacy records. |
| Shared graph standalone | **47 assertions passed**, after Field unlink support. |
| Migration safety lint | **4 changed migrations passed**, unchanged from prior checkpoint. No fifth migration. |
| Guarded MySQL recovery | **1 skipped / 0 assertions** under forced SQLite. No real MySQL evidence, connection, install or schema baseline update. |
| Pint | **10 changed PHP files passed** after formatting. |
| Inventory generator/syntax | Generator runs; **643 unique clauses indexed exactly once** across A-E; generated metadata explicitly says native renderer/device/production acceptance pending. |
| Earlier UI/build evidence retained | Prior checkpoint's **34 real Laravel browser checks + 4 targeted phone checks**, **52 Node preservation/offline/import tests**, TypeScript, **8 chart tests** and local Vite build remain evidence for unchanged web/client contract code. They were not rerun or relabeled as native-app tests in this backend-only increment. |

Evidence is in `../evidence/team-integration/adapter-tests.txt`, `regressions.txt`, `graph-tests.txt`, `migration-lint.txt`, `mysql-status.txt`, `format.txt`, `format-final.txt`, and the cache evidence below. Review archive paths place these under `evidence/team-integration/`. Native app files, installed devices, real tenants and production were not exercised. The prior owned loopback server remains stopped; no server was launched in this increment.

Exploratory Field preservation test initially compared partially populated newly created Eloquent attributes with a fully reloaded DB row; it now compares full fresh snapshots before/after. A separate entitlement-loss test exposed a captured resolver cache; resolving canonical Field entitlement freshly fixed the actual access recheck. Final focused and regression runs passed. Failed exploratory attempts are not counted as success.

## Cache and disk result

The exact candidate remains `C:/Users/father/AppData/Local/npm-cache`, **1,883,286,026 bytes / 10,119 files**. At **2026-10-17T01:29:20.5358543Z**, Windows Restart Manager registered all files (zero enumeration errors) and returned **0 open resource holders**. Sanitized process inventory found **0 standard builds / 0 other exact cache references**. Latest cache write was **00:37:45.8304848Z**. This independently establishes no observed active cache/build use at that snapshot; recheck immediately before any later approved cleanup. **Nothing was deleted; specific cleanup approval remains with parent.**

Evidence: `../evidence/team-integration/inspect-cache.ps1`, `npm-cache-activity.json`. The read-only script registers/lists resource users, never shuts down/restarts processes or reads/deletes cache content. Initial sandbox CIM denial was resolved with a narrow elevated read-only inventory. No installation/credential or process termination was needed.

C: had **3,602,546,688 bytes** free at this continuation's early check and **3,619,434,496** at a later observation. Work remained disk-light: source/docs/tests only, existing dependencies reused, no native checkout/dependency copy or build install. Preserve sources, DBs, backups, user files and prior archives.

## Prerequisites and limitations

`everbranch-team-scanner-and-environment-gates.md` records exact evidence/options. Existing content/PDF/Ghostscript validators are not malware scanners. Symfony Process and HTTP/queue facilities can host a future approved local or private-provider scanner; AWS SDK/S3 template alone does not establish a configured storage/scanning pipeline. No approved production scanner/service/provider was found or contacted. Current Team uploads therefore stay quarantined; clean tests remain explicit doubles. Slow production scanning needs a leased asynchronous workflow with hash/version/current-access rechecks, not a long scan under the tenant command lock.

No MySQL/MariaDB/Docker executable on PATH, corresponding service or 3306/33060 listener was verified. Existing CI declares disposable `mysql:8.4` / `everbranch_ci`, but it was not run. The guarded recovery test and data-free schema baseline remain release gates. No real DB was probed.

The existing mobile app already owns Field tasks/messaging/pickers/secure token storage/offline mechanisms. It remains unchanged to avoid competing implementations on the active native/voice track. Its inspected Field queue is slug-scoped and its sign-out path did not purge that queue key; private Team payloads must wait for reviewed account/session scoping and purge/conflict/retry hardening. The separate local queue state-machine tests are not installed native integration. Team UUID records must not be decoded as numeric legacy Field tasks. Field job consumer UI, native renderer, catalog/version rollout, encrypted sync/revocation, devices/accessibility/performance and all remaining B/C/E features remain work, precisely enumerated in the inventory. Current backend merge, approved scanner/storage/MySQL/native acceptance and separate parent-owned release approval are still required.
