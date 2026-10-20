# Superseded automatic-purge checkpoint — withdrawn from application

The earlier native privacy ZIP/patch can remove pending work on logout/new authentication and must not be applied. Archived bytes are preserved only for provenance. Corrected source and current evidence are in `everbranch-team-preservation-and-foundation-verification.md` and the native candidate's `OFFLINE_PRESERVATION_CHECKPOINT.md`. The historical report below is superseded, including its purge and five-browser-check claims.

# Historical report follows (superseded)

# Existing mobile offline privacy patch - local checkpoint

2026-10-17 UTC. The previous four checkpoint archives remain unchanged. This increment addresses the identified native queue privacy prerequisite in a small isolated patch of the existing app. It does not enable Team, install a binary, deploy, migrate real data or complete A-E.

## Checkout and actual change

Original mobile remains clean at `d9b0b31`, branch `codex/everbranch-voice-ai-pc-20261016`. No original mobile/backend/Team source or another agent's changes were overwritten. A shared-object sparse patch checkout at `C:/Users/father/Documents/Codex/2026-10-16/task-2/mobile-privacy`, branch `codex/team-offline-privacy-local-20261017`, materialized about 0.8 MB of TypeScript source plus small configs. It links existing mobile node_modules; no dependency installation, native project/asset copy or duplicate app was created. The patch is portable and tied to the original mobile base, separately from the existing backend patch.

`session-privacy.ts` binds an offline scope to fresh authenticated bootstrap user ID, tenant ID/slug and a token-session digest. The client scope protects local data; the server remains the authorization authority. `offline-storage.ts` uses existing native SecureStorage for a v2 owner envelope, serializes updates/purge and keeps browser task payloads memory-only. The existing queue now validates ownership/payloads, captures copies, fences stale sends/acknowledgements, preserves other workspaces in the same authenticated session and prevents lost concurrent capture. A new session cannot replay a prior user's/session's queue. Unowned v1 work is blocked and retained sealed for recovery review rather than guessed into a new account.

The existing API helper checks generation/scope before dispatch and after asynchronous boundaries, pins the credential, omits cookies, cancels old work and refuses late JSON/blob results. Actual 401/403 and scoped 404 paths invalidate/purge the affected access context. Cached private blobs are cleared across native/browser account changes and live scope refresh. Local sign-out fences reads immediately and clears token/queue keys without waiting for network; remote revocation is best-effort with the captured old token and a five-second bound. A durable nonsecret signed-out marker prevents stale persisted token/queue restoration if physical cleanup fails. Logout/new authentication intentionally clear local unsynced work, with visible account-screen guidance; server/source originals/history are unchanged.

The actual React App clears protected view state and ignores old session/workspace loads. Browser QA found a header grid that still allocated three slots despite the existing page-finder microphone; a small conditional four-slot rule fixes the 390px overflow without changing voice behavior. The native checkout was not modified in place. Full source behavior, upgrade cautions and commands are in the candidate's `OFFLINE_PRIVACY_CHECKPOINT.md`.

## Actual verification

| Check | Actual result and limit |
| --- | --- |
| Existing mobile Node/Vitest suite | **116 passed in 26 files**, including **21 new privacy cases**. Uses Node **v24.16.0**, Vitest **v4.1.10**, already installed dependencies. The local harness disables external fetches and caches. Tests execute the actual TypeScript modules and existing request helpers; SecureStorage/Capacitor native branches are mocked. |
| Scenarios | Account/tenant switching, same-account prior-token replay, reused slug/new tenant ID, offline/hanging logout, prior-session in-flight send and secure-write races, acknowledgement/concurrent capture, 401/403/revocation, late old 401, stale/private Blob results, browser cache isolation, malformed/unowned legacy queue, cleanup failure and durable restart denial. |
| TypeScript | Full application check passed: `tsc -p tsconfig.app.json --noEmit --incremental false`. A stale refresh-return type found during development was fixed. |
| Oxlint | **Zero diagnostics**, seven changed TypeScript/TSX files checked with existing rules/dependencies. |
| Vite web production bundle | Passed with native config loader. Existing main/CrewMap chunk-size warnings remain; no mobile performance target is certified. This is a web bundle, not an iOS/Android signed build. |
| Actual compiled-App browser | **5 passed** at 390px: fictional restored account, visible legacy recovery block, offline logout removing local token/legacy queue/protected React view, account-B sign-in without old content or queued replay, and no horizontal overflow/runtime error. HTTP responses are intercepted fixtures, all external traffic blocked. This does not verify the real backend or native hardware. |
| Original worktrees / artifacts | Read-only status/hash checks retained. No push, commit, deploy, credential/access grant, provider/billing change, package install, real queue migration or npm-cache deletion. |

Evidence: `../evidence/team-mobile-privacy/full-tests.txt`, `types.txt`, `lint.txt`, `build.txt`, `browser-tests.txt`, `browser-result.json`, `account-b.png`; executable local test harness configs/setup and `app-logout-browser.mjs`. Failed exploratory TypeScript checks are superseded by final passing output; initial phone overflow was fixed before the final five browser checks. The owned static server closes in the browser script's `finally` block. No source/vendor/runtime database or real native storage is included in the review archive.

## Can the current native runtime run the tests?

**The Windows JavaScript runtime can and did run the complete suite. Actual native-device execution is unavailable here.** No ADB, Android emulator, Java or Gradle command was found; conventional Android SDK ADB/emulator paths are absent. No Android device/emulator was contacted or installed. Windows cannot execute this iOS app. Therefore native Keychain/Keystore atomicity, OS background/restart behavior, true network aborts, native accessibility and signed binary acceptance remain blocked on a permitted supported runtime. Mocked native-branch tests are not device proof or encryption-at-rest proof.

## Precise remaining A-E summary and dependencies

All **643 clauses** remain in the master parity matrix and one-to-one milestone index. Counts below are inventory counts, not progress percentages. Full requirement-level details remain in `../architecture/everbranch-team-remaining-work.md` and the generated parity JSON.

| Milestone | Remaining feasible local work | External/runtime gates |
| --- | --- | --- |
| **A - 169 clauses** | Ownership/collaborators/followers/task sharing, duplicate/follow-up/restore/delete UI and retention, richer project/team/default-view administration, deep-link restoration, indexes/concurrency tests and reviewed current-backend merge. Existing core/private ACL/revisions/receipts remain a local slice. | Disposable MySQL recovery/schema baseline; reviewed release integration. |
| **B - 141 clauses** | Home/My Tasks feeds/personal sections/full sorting/filtering/quick actions; broader Inbox events/filters/bulk actions; rich text/reactions/mentions/follow-up; durable imported original/history views; full entity search/index/facets; file previews/retention and native picker adapters. | Approved production scanner/storage pipeline; real source read/export/reconciliation authorization; device upload/accessibility checks. |
| **C - 84 clauses** | Saved/full board/calendar views, zone selection/drag alternatives, advanced fields/tags/templates, recurrence/dependencies, milestones/approvals, project status/teams/messages/favorites/portfolios/weekly focus, source-preserving mapping preview/apply/undo, reporting/forms/workload/automation. | Required integrations and eventual native/performance acceptance; no blanket external blocker prevents local models/UI/tests. |
| **D - 219 clauses** | Review/apply this privacy candidate to the current native track; add the existing app's Team UUID renderer/destinations/catalog version gate, Field UI/common tagged work adapter, queue review/recovery/conflict/backoff/idempotency/cursors/merge, encrypted record/search/draft cache, deep links/notifications/media/device interaction interfaces and content-free instrumentation. Existing legacy comment/material ambiguous outcomes and token-rotation queue handoff still need contracts. | Real iOS/Android/tablet runtime and signed builds; secure-storage/restart/background/push/permissions/accessibility/latency acceptance. Team stays off until these are proven. |
| **E - 30 clauses** | Persistent local onboarding/import-review UI, source identity/lease/checkpoint/relationship/attachment/exception handling, existing-user mapping/history provenance, entitlement gain/loss/suspension tests, disabled provider-free permission-aware AI interfaces and privacy-safe telemetry. | Separately approved real connection/export/capture/restore/cutover; actual credential/payment/provider setup and production approval remain outside this task. |

Disk has remained sufficient for bounded source/tests/builds (roughly 3.3 GiB free); no dependency installation or large copy is needed for most listed local work. Lack of a native runtime, approved scanner and disposable MySQL are real acceptance blockers, not reasons to label the remaining local feature implementation complete. No source passwords/history or live Asana field availability are promised.

Before any app upgrade with v1 pending updates, recover/review them under the old authorized session. This candidate does not automatically migrate unowned work and does not add a complete recovery UI. New authentication/explicit logout purge protected device content; an already-dispatched server mutation cannot be undone by a client logout. Broader offline claims still require exact legacy action idempotency, conflict UI, durable record cache/access TTL and native device verification. Private Team actions remain absent from the native queue and the backend Team/native rollout flags remain default false.
