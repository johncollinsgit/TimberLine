# Remaining Team / Field Service work by milestone

Updated 2026-10-19 UTC after the minimum-replacement workflow assessment and project-discovery increment. None of A-E is declared fully complete. The canonical inventory remains **643 clauses / 36 supplied sections** in `everbranch-team-mobile-parity.json` and its generated Markdown; each row carries a milestone, rules, dependencies, edge cases and evidence. The accompanying generated `everbranch-team-milestone-index.json` indexes every clause exactly once by milestone, preserving the original IDs. Counts describe inventory, not a percentage of parity or test accuracy.

## Standalone application packaging checkpoint

The user's clarified Everbranch link plus independent company Teams web app is
implemented locally at `/teams`, with the retained embedded `/team` surface.
Read `everbranch-team-application-packaging.md` and its verification runbook.
This uses the same records/auth/permissions and guarded workspace/task links.
Native/PWA/store packaging, complete mobile navigation/offline recovery and
all original A-E scope stay pending. No new host, entitlement activation or
real import/release is implied.

## Draft navigation safety checkpoint

See `../operations/everbranch-team-draft-navigation-verification.md`. The reported
ordinary task/comment navigation loss is guarded locally, including existing
structured/recurrence input, exact uncertain-save retries and original-account/
current-access recovery in page memory. Durable reload/logout/restart/native
recovery remains open; no automatic purge or offline write gate is enabled.

## Minimum usable replacement checkpoint

See `../operations/everbranch-team-minimum-replacement-verification.md` for the
usable-online/partial/blocked path assessment and exact real-import prerequisites.
Project-name/description discovery now opens the existing shared task workflow;
other global search entities, indexing, native/offline and durable recovery remain
unfinished. Real migration is blocked, not established by synthetic fixtures.

## Premium workspace / live read projection checkpoint

See ../operations/everbranch-team-premium-verification.md and
everbranch-team-premium-workspace.md. Local responsive premium UI, personal native
favorites/saved views, initial page-scoped planning projections and independent
per-user read-only Asana adapters have focused evidence. OAuth runtime setup and
real-user acceptance remain open. Original 643 clauses and unfinished A-E scope
are retained. This is not completion of the broad planning/collaboration roadmap.

## A - core lifecycle and access

New local evidence: `../operations/everbranch-team-core-workflows-verification.md`. Canonical task/comment web links select and reauthorize the workspace. Reviewed task/comment follow-ups create independent canonical tasks through the same graph, preserve original content/history, filter source provenance and retain stable retry identity and new draft text. Native/OS link handlers and follow-up controls remain unfinished. The original shallow/partial Git metadata and five Windows failures were diagnosed separately without weakening checks.

Locally implemented: tenant/project/task persistence, active/verified membership and canonical entitlement, explicit project grants/guest/viewer restrictions, task create/edit/assign/dates/priority/complete/reopen, project settings/sections/membership position/archive/reopen, multi-project identity, original native revisions/activity/receipts, and real responsive web controls. Native transport now delegates to these same commands/policies. Task delete is API-only.

Additional local slice: versioned owner-only project transfer to a verified active explicit editor, with reviewed confirmation and no tenant-role mutation; creator-only personal root sharing with explicit viewer/editor grants, inherited child ACL and immediate revocation. Project tasks retain project ACLs. Active personal grants must be revoked before a project move. Two actual simultaneous-process SQLite races verify one winner/conflict and one winner/idempotent replay. MySQL concurrency remains unverified.

Remaining implementation: full collaborators/followers and project-task sharing administration; duplicate/restore, native follow-up controls and complete delete UI/retention rules; richer project/team ownership and default-view settings; full membership/role administration; remaining deep-link entity families and native/durable screen restoration. Preserve restrictive defaults, independent assignment/access, parent ACL intersection and hidden membership metadata. Organization authentication/invite/deactivate policy is not supplied by these Team controls. Remaining acceptance: reviewed current-backend integration, disposable MySQL recovery/baseline, MySQL multi-worker concurrency, large-tenant query/index design, complete private/guest/viewer/access-loss UI coverage and native renderer/device gates. Existing Field checklists are not migrated.

## B - notes, collaboration, files, subtasks, search and attention

Locally implemented: native plain-text comments/edit-own/delete-own with retained private revisions, explicit permitted user mentions, internal assignment/mention Inbox with read/unread/archive, current task/comment/visible-field search, basic completion/priority/project filters, full child task details and authorized parent reorder (100-child cap), and resumable private file lifecycle with progress/cancel/retry/checksums/quotas/quarantine. Existing source preservation/import/preview tools remain synthetic local contracts.

Remaining implementation:

- Imported-source originals/history viewer and durable application storage: raw text/HTML/payload/version/hash/source link/author/time, safe rendering, explicit historical authors without accounts, and attachment/relationship reconciliation. Do not convert originals into reporting fields or replace them with AI summaries.
- Comment/task/project mentions, rich text/sanitization, reactions, comment follow-up, collaborator preferences and eligible followed-work events. No external notifications currently dispatch from Team.
- Home locally shows assigned incomplete counts for today/tomorrow/overdue/ISO week/undated, after ACL and before pagination, with feed buttons. My Tasks adds date filters and due/start/created/priority/title sorting with stable ID ties; literal dates retain their day and timed dates use the validated browser-selected timezone. Remaining Home: recent/recent-assigned/favorites/drafts/unread/suggestions, explicit refresh and dismissal. Remaining My Tasks: personal sections/manual order/collapse, assigned/project/custom sorting, type/custom-field/other-member filters and remaining quick/swipe actions. Calendar agenda remains explicitly UTC.
- Full Inbox event families, filters/person/project/unread/approval/date activity, bulk visible archive, likes/replies/bookmarks and remaining inline actions; current coverage is only latest 100 recipient assignment/mention events.
- Approved production scanner/storage operations; asynchronous scan publication; previews; camera/library/cloud/video/audio pickers and media transforms; complete retention/deletion/download audit policy; upload background/device/access-loss behavior. Clean API download tests use explicit doubles only.
- Project name/description search and independent authorized result pages now open the existing project workflow. Remaining global search: teams/people/portfolios/messages/tags; production index/facets/snippets and all creator/team/date/attachment/custom/type filters; recent searches and scoped offline index. Current search loads a tenant graph. Parent-child display/reorder beyond 100 and full list accessibility/performance remain work.

Remaining acceptance: full real UI/API/resource-family coverage, interrupted/background upload and revoked download on supported devices, imported byte/history reconciliation, comprehensive recipient/no-dispatch tests and current-grant search/count/cache behavior.

## C - views, structured work and reporting

New local evidence: `../operations/everbranch-team-project-status-verification.md`. Project overview and links, readable-task progress, reviewed current-owner status states/text/task references and immutable paginated native status history are implemented through shared services. Source descriptions/history and task completion stay independent. Viewer/account fencing and identity-verified memory-only draft recovery are tested; notifications, resources/roster/milestones, native/OS and durable session recovery remain unfinished. No new schema or provider action.

New local C evidence: `../operations/everbranch-team-milestone-c-verification.md`. Project-scoped reviewed task/project snapshots, private project copies, ordered inherited subtasks, completion-driven daily/weekly/monthly recurrence, DST/limits/idempotency and authorized directed dependencies/internal unblock Inbox now have shared API and responsive UI coverage. No scheduler or external notification runs. Current classification: 17 verified server invariants, 185 partial, 12 blocked and 429 without clause-specific completion evidence across all 643 retained clauses; full native/product acceptance remains open.

Locally implemented: section board with accessible select-based moves and per-page coverage; monthly due-date agenda (literal date-only values, timed UTC); owner-defined text/number/date/single-choice fields and task/project revision fences; values supplement original descriptions and private-project values are excluded from unauthorized views/search.

Remaining implementation: saved/default project views; board drag/drop/add-in-column and comprehensive grouped list reorder/filter/sort; full calendar grid/date selection/drag or accessible reschedule, unscheduled bucket and selectable workspace zones; tags/advanced fields and field lifecycle; advanced template editing/dates/assignments/fields/files/history mappings; recurring subtasks/weekday/yearly/business-day rules and optional reviewed automation; native dependency interactions/push and richer graph views; milestones; approval actor/state/history; remaining overview resources/roles/member list, milestone references, broader status authors and notifications; teams/privacy/join/request/leave/messages; favorites/portfolios and per-project visibility; weekly focus with tenant/user/week synchronization. Timeline/Gantt/workload may start simplified/read-only/web-only, but remain explicitly required scope.

Reporting remains pending: authorized structured dashboards/exports, native/source field provenance, exact original-version/passage-to-field mapping preview, stale-preview refusal, reviewed apply and compensating undo without history rewrite. Forms/workflow automation/template design/time tracking/workload/advanced dashboards remain backlog; existing Field operational time is not proof of generic Team time-tracking parity. Local tests now cover recurrence/date-zone boundaries, dependency cycles/hidden endpoints and template replay. Remaining acceptance includes broader supported rule/view families, approval transitions, report/portfolio ACL totals, real MySQL and native/device interactions.

## D - existing native app, Field extension, offline, synchronization and device quality

New local evidence: `../operations/everbranch-team-workspace-verification.md`. Team now lists eligible own workspaces and reloads through existing tenant context, with unsaved text/comment/file and pending-save guards. Actual two-workspace browser/API and bearer isolation/revocation cases pass. Native workspace rendering, durable caches, offline switching and device recovery remain unfinished; no automatic purge or recovery/write gate activation.

Implemented this checkpoint: default-off bearer-only native transport with current ability/tenant/object checks, same repository/commands/files/Inbox, and existing Field job reference link/unlink/authorized projection. Tests prove no legacy job/checklist changes and no private Team grants from a shared job. This is a backend API slice: the existing React/Capacitor app remains unchanged; no new renderer, native binary or installed feature is claimed.

Remaining implementation: native Team renderer/UUID decoding/destinations and guarded catalog/minimum app version; Home/My Tasks/Create/Inbox/Account/search and responsive tablet layouts; Field job UI adapter consuming authorized linked summaries; a tagged common legacy/new work projection delegating writes to the source service rather than making task copies; all native capture/comment/file/view controls. Preserve existing numeric Field task status/multiple-assignee/office handoff/history semantics and current customer/financial restrictions. See `everbranch-team-integration-contract.md` for routes and exact seams.

The existing mobile sparse candidate now preserves pending work on logout/new authentication/revocation, displays a safe recovery/cancel/explicit discard flow, and fences stale responses. Its 119 Node tests and eight compiled-App browser checks pass; native device recovery remains unverified and offline write/recovery gates remain off. The earlier automatic-purge checkpoint is withdrawn. See `../operations/everbranch-team-preservation-and-foundation-verification.md`. Remaining queue integration still includes recovery/conflict UI, legacy action idempotency, backoff/cursors/merge, durable encrypted record caches and native acceptance. Extend the existing native encrypted offline queue in one reviewed version: tenant-ID/user-ID/device/session ownership, secure durable cache/drafts/actions, exact idempotency keys/base versions, queue status/backoff/causal order, nonconflicting merge, retained conflicting text and user review, optimistic rollback, incremental cursors/realtime or refresh, background limits, late-response cancellation, access-loss/sign-out cache clearing with pending work sealed for safe recovery and explicit owner-confirmed discard. A corrected sparse candidate of the existing native Field queue now adds account/session/tenant fences and pending-work preservation. New offline writes and encrypted keep-and-sign-out remain default off until native recovery is verified; unowned legacy sources stay untouched. The earlier automatic-purge candidate is withdrawn. No native Team renderer or installed binary is claimed; standalone Team state-machine tests remain contracts.

Authentication methods beyond the verified existing Fortify/PKCE/Sanctum contract need an availability audit: passwordless/Google/Apple/SAML/MFA/biometric re-entry, rotation/revocation, multi-workspace switching and suspended-organization behavior. Push requires eligible recipient reauthorization, categories/quiet hours/grouping/privacy-safe previews, current device tokens and deep-link tenant/auth routing. Complete profiles/presence/away/timezone/language/settings; native share/camera/photo/receipt/scanning/voice/haptics/swipe/drag behaviors remain Team work.

Remaining acceptance: permitted actual iOS/Android/tablet device or emulator, signed builds, foreground/background/deep links, encrypted storage and account-switch isolation, VoiceOver/TalkBack/dynamic text/high contrast/reduced motion/keyboard/focus/media transcripts, network/auth/deleted/permission/suspended/plan/error recovery, TLS/device-risk/screenshot/audit/rate/file controls, and measured supplied launch/detail/search/scroll/crash/sync targets. Responsive Chromium and API tests are not these gates.

## E - local onboarding/import review, entitlement and optional AI interfaces

Implemented foundations: canonical hidden/default-disabled Team entitlement/rollout; synthetic source IDs/immutable versions/author attribution, idempotent page reconciliation/checkpoints/exception ledger/byte checks; read-only preservation pagination/backoff/capture ordering and original-note mapping previews. These are local tools, not durable application import, completed onboarding or real source discovery.

Remaining implementation: local self-service workspace/project/import-review UI; persistent private capture/checkpoints/leases and exception ownership; exact tenant/connection/workspace/actor/source identity binding; resumable relationship/attachment reconciliation and truthful source-family coverage; existing-user matching preview, historical attribution without access, original notes/links/history retention, mapping conflicts/undo and notification suppression. Preserve every omitted/unobtainable historical field as an exception, not a guessed reconstruction. Password migration is not promised.

Add local entitlement gain/loss/suspended scenarios without real checkout/charges; no duplicate billing engine. Optional disabled/provider-free AI interfaces require current source/object permission projections, immutable citations and explicit per-action review; summaries/task/status suggestions must never rewrite source history. Content-free telemetry and retention/consent review remain work; never capture private note/comment/file content by default.

Remaining migration/release gates: parent-approved read-only source/export scope and secure outside-Git capture, restore proof, representative private-project/attachment/history reconciliation, reviewed exceptions, tenant-owner acceptance, delta/freeze/rollback and separate parent-approved deployment/cutover. No real source access/import/provider/credential/invitation/payment change follows from local test success.
# Connected operations follow-up (2026-10-20)

The default-off Events/Wholesale candidate now includes workflow groups, per-user connections, source projections/reviews, reusable email templates and internal reminder options. See `everbranch-team-connected-operations.md` and `../operations/everbranch-team-connected-operations-verification.md`. This local implementation does not promote any of the 643 broad-parity clauses to complete by inference. Live source access, external campaign stops/execution, staff/contact matching, ambiguous financial allocation and comparable-event review, MySQL/Linux and protected release acceptance remain outstanding.

