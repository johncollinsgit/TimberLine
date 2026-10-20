# Shared Team work / existing Field Service and native app adapter

Local checkpoint, 2026-10-17 UTC. This is an implemented backend transport/reference adapter, not an installed native feature or a migration of existing Field tasks. Both prior checkpoint archives remain unchanged.

## Current sources inspected read-only

| Source | Observed state | Relevant contract |
| --- | --- | --- |
| Original Team worktree | Clean `codex/everbranch-team`, `69c3416` | Preservation/source contracts retained. |
| Current backend worktree | Clean `codex/everbranch-fictional-voice-pilot-20261016`, `79d430c` | Existing Field jobs/checklists, messaging/attachments, native API and voice track remain authoritative. |
| Current native/mobile worktree | Clean `codex/everbranch-voice-ai-pc-20261016`, `d9b0b31`, package `2.3.28` | React 19 / TypeScript 6 / Capacitor 8; one existing app, no duplicate. |

Paths are recorded in the implementation/verification reports. No source, dependency, native project, package lock or Git state in these other worktrees was changed. Seven dependency contracts were compared by SHA: Field job/task models, `WorkspaceAssetService`, `EnsureMobileTenantAccess` and `TenantMobileModuleRegistry` are identical to this checkout. `routes/api.php` and `FieldServiceAccessService` differ. The current access-service difference adds manager/client-authorized customer-contact visibility; its job visibility functions used here are unchanged. Do not copy this checkout's older access service or replace the current API routes during integration. The only new API-file edit is requiring `team-mobile.php`; reconcile it manually with the reviewed current backend.

The native `EverbranchMobileTeamController` and `team-file-upload.ts` serve **team messaging**, not the requested project/task product. Their numeric channel/message attachment IDs, token/base64-offset upload protocol and existing routes must remain unchanged. Field tasks are numeric IDs with `open|in_progress|waiting|done`, multiple assignees, office handoff and task-event history. Team work tasks are UUIDs with independent project memberships and private object ACLs. Never coerce one ID type into the other or mirror Field checklist rows into `team_tasks`.

## Implemented server adapter

`EverbranchMobileTeamWorkController` delegates to the same `TeamWorkController`, `TeamFileController`, `TeamFieldLinkController`, repository and graph used by web. It does not implement another task command engine, auth system or storage pipeline. Successful JSON includes `contract_version: 1`; auth/validation errors retain ordinary Laravel error responses. Native clients must validate the success contract before decoding and discard old-tenant responses.

Base: `/api/mobile/v1/workspaces/{tenant}/team-work`. Guards: existing `auth:sanctum` and `mobile.tenant`, actual `PersonalAccessToken` (web cookies rejected), matching `mobile:read` or `mobile:write` ability, throttling, independent default-off `TEAM_WORK_MOBILE_ENABLED`, shared default-off Team rollout, exact tenant-ID allowlist, current active/verified membership and canonical `team_work` entitlement. Standard Team project/task policies still apply; no platform-operator privacy bypass. Token creation/expiry/revocation uses existing Sanctum, not a new credential flow. Tests issue fictional disposable DB tokens only.

| Path relative to base | Method / ability | Behavior |
| --- | --- | --- |
| `/` | GET / read | Same authorized projects/tasks/members, My Tasks/search/completion/priority/project/month filters, page size 50. Repository still loads tenant graph; no mobile SLA claim. |
| `/projects/{uuid}`, `/tasks/{uuid}` | GET / read | Same authorized detail; task has filtered subtasks/comments/fields/history. |
| `/commands` | POST / write | Same validated command vocabulary, version fence and atomic native revisions/activity/receipt/eligible internal Inbox. UUID `Idempotency-Key` required; any body `idempotency_key` must match exactly. |
| `/inbox`, `/inbox/{uuid}` | GET read / POST write | Recipient-scoped current-access projection and read/archive setters. Setter replay is naturally idempotent. Same recent-100 coverage limit. |
| `/tasks/{uuid}/files` | GET read / POST write | Same private file list/reservation; begin requires UUID `Idempotency-Key`, matching `client_key` when supplied. |
| `/files/{uuid}/chunks/{index}` | PUT / write | Same raw octet-stream, exact 256 KiB chunk protocol, upload/index/content replay checks. Header key is not required for a content-addressed chunk. |
| `/files/{uuid}/complete`, `/files/{uuid}` | POST / DELETE / write | Same finalize/quarantine/cancel state machine and uploader control; duplicate finalize/cancel is safe. No native scanner bypass. |
| `/files/{uuid}/download` | GET / read | Reauthorized private attachment download only after trusted clean scan. Scanner remains unavailable locally. |
| `/tasks/{uuid}/field-jobs` | GET / read | Minimal currently authorized job references, with existing typed `field_service_job` destination. |
| `/tasks/{uuid}/field-jobs/{jobInt}` | POST / write | UUID header key, task version and optional `remove`; same reference adapter as web. |
| `/field-jobs/{jobInt}/tasks` | GET / read | Page 50 currently authorized linked Team summaries. No private task names/IDs/counts inferred from hidden tasks. |

Equivalent Field-reference web routes are under `/team`, authenticated/verified/tenant-resolved with normal CSRF. These are API integration surfaces; no new native renderer or Field job screen consumes them yet. The generic command endpoint does not permit `task.field_link` or attachment scan-acceptance commands; dedicated adapters provide trusted job/file context.

## Field extension access and preservation

`TeamFieldWorkAdapter` verifies both canonical module entitlements and existing `FieldServiceAccessService` job visibility, always tenant-scopes the job query, excludes recycled jobs and invoice-only QuickBooks evidence, and independently checks Team task visibility/write access. Only ID/title/operational status/destination are returned for a job. Customer contacts, finances, lock-box values and source job notes never enter this projection.

A job reference grants no project/task access. An existing crew member can see the operational job yet receive no linked private Team task summary. Managers/platform operators receive no extra Team grants. Replay rechecks the current Field job, membership, entitlement and task; a recycled/inaccessible job cannot be resurrected by an old key. The canonical resolver is obtained fresh for Field entitlement checks rather than retaining its cached entitlement snapshot. Removing a reference is versioned/audited and keeps prior native snapshots.

Existing `field_service_tasks`, task events, job notes/photos/files/materials/time remain untouched. Tests compare full fresh raw job/checklist rows before/after linkage and assert no mirror task creation. This is a transitional reference adapter, not completion of shared task migration. A future common work read model should retain a tagged source reference and delegate legacy checklist writes to the existing Field workflow; any conversion requires explicit mapping/preview/provenance/rollback acceptance. Do not operate two independently editable copies of the same historical task.

## Required existing-app changes, not yet executed

1. Add a versioned Team work decoder and native route/destination using UUID task/project IDs, alongside existing numeric Field jobs/checklists and team messaging. Reuse `lib.ts` authenticated request/token rotation/secure storage, existing navigation/dirty-form/upload guards, native accessibility components and app branding. No web wrapper or duplicate app. Do not expose the hidden catalog entry until its renderer/minimum binary declaration and device acceptance are complete.
2. Add Home/My Tasks/Create/Inbox/Account and task/project/comment/file controls within that app. Native file picking must adapt its existing streaming/picker facilities to the Team upload protocol; messaging upload tokens/offset protocol cannot be sent to this endpoint unchanged. Camera/media/voice/share/deep links/haptics remain device-tested work. Field job screens may request linked Team summaries only after the current Team capability is enabled; missing capability must not break existing jobs.
3. Evolve the existing encrypted Field offline queue rather than introduce a competing native queue. At `d9b0b31`, `src/offline-queue.ts` stores one secure native key (browser fallback is `localStorage`), scopes actions by workspace slug only, and retries every failure without category/conflict handling. Its job-comment/material payloads do not carry the new Team version/receipt keys. `App.tsx` sign-out clears token/shell/workspace keys; no queue-key purge was found in that path. **Do not enqueue private Team content into this format.** This is a source-review finding, not an exercised live-device exploit or proof about other installed binaries.
4. Add explicit tenant-ID/user-ID/device/session ownership, encrypted payload durability, pending status, exact server keys/base versions, causal ordering, categorized backoff/conflict retention and revocation/sign-out purges in a reviewed queue version. Existing queued Field actions must not be silently replayed for a different user or discarded without recovery review. The transport-independent Team queue tests specify these semantics; they are not a second installed queue or proof the native queue already meets them.
5. Reuse current Field job access/readiness/lifecycle and typed destinations; preserve assignment/multiple-assignee/office-handoff semantics. Team-specific privacy may be stricter than tenant-shared Field access. Require current grants on related previews, counts, search, cached files and push targets. No source grant, login invitation or source-note rewrite follows from a link.
6. Test actual iOS/Android/tablet binary, account/tenant switching, token expiry/revocation, stale responses, sign-out with pending work, unsupported contracts, interrupted/background uploads, keyboard/dynamic text/screen readers, gestures and measured performance. Existing responsive browser evidence is separate. Production deploy/native release remains parent-owned.
