# Everbranch Team: source preservation and migration contract

Reviewed 2026-10-11. Phase 1 architecture audit and proposed implementation contract.
This document does not claim a Team module, migrated project, production import,
or complete source backup exists. Actual source coverage belongs in the private
discovery manifest, outside git. No schema, provider authorization, or deployment
was changed by this audit. Modern Forestry is the first customer configuration,
not a condition in shared product code.

## Existing implementation and reuse boundaries

| Capability | Code evidence | Reuse / gap |
| --- | --- | --- |
| Asana OAuth | `app/Services/Automation/AsanaWorkflowConnectionService.php` | Authorization code + S256 PKCE, 15-minute single-use cached state tied to actor/tenant, encrypted client snapshot, refresh-token capture and token revocation already exist. This is a Workflow connection, not an Asana migration. |
| Provider connection storage | `app/Models/IntegrationConnection.php` | Tenant-owned model using `BelongsToTenant`, encrypted/hidden token and client fields, granted scopes, expiry, connection health. Reuse this credential boundary; never read tokens from Codex/browser or put tokens in manifests. |
| Connection and refresh resolution | `app/Services/Automation/V2/Providers/TenantIntegrationConnectionResolver.php`, `ProviderAccessTokenService.php` | Explicit tenant/provider/connected-state filtering; refresh persists issued client pair, token rotation and expiry. Add refresh concurrency protection and migration-specific errors before parallel workers share a grant. |
| Asana project picker | `AsanaWorkflowConnectionService::fetchProjectsForWorkspace` | Paginated, cached discovery; explicitly requests `archived=false` and filters archived records. It cannot inventory historical projects. |
| Asana task polling | `app/Services/Automation/V2/Operations/AsanaTaskTriggerOperation.php` | Paginates recent modified tasks, hashes payloads, orders timestamp/GID watermarks. Default bootstrap is 14 days, capped at 90; limited fields and scan bounds omit historical graph/history/files. Do not repurpose this as a full export. |
| Field Work tasks | `app/Models/FieldServiceTask.php`, `FieldServiceTaskEvent.php` | Tenant/job-bound task, assignees, status, due datetime, order and native events. No project memberships, recursive parents, source snapshots or imported author model. Asana tasks must not become field jobs merely to fit these tables. |
| Client project checklists | `ClientProject.php`, `ClientProjectTicketTask.php`, `app/Console/Commands/ImportClientProjectChecklist.php` | Reviewed tenant checklist preview + explicit apply, tenant lock, stable keys, audit and retained progress on replay. Operator/customer delivery workflow with one ticket parent; not a general team project graph. |
| Documents / files | `WorkspaceAsset.php`, `app/Services/FieldService/WorkspaceAssetService.php` | Private configured disk, checksum/MIME limits, audited access, source IDs, resumable uploads and searchable metadata. Current relations/access are job or financial-document based; owner/team visibility is too coarse for private Team projects. Reuse storage patterns after object ACL review, not wholesale existing download authorization. |
| Team messages | `TeamChannel.php`, `TeamMessage.php` | Tenant/channel membership and messaging patterns exist. Messaging channels are not Asana teams/projects; importing stories as live messages would manufacture notifications and widen access. |
| Product gates | `config/module_catalog.php`, `TenantModuleAccessResolver`, `TenantExperienceProfileService` | Work, Team Communication and Workflow Automations already have separate meanings. New Team must use canonical catalog, existing authentication/tenant memberships, entitlement and billing services. No pricing or entitlement activated here. |

The existing Asana callback uses legacy `User::role` checks and a tenant membership
existence test. Team authorization must instead resolve active membership and
canonical tenant owner/admin import permission. `HasTenantScope::forTenantId(null)`
returns an unscoped query; every Team worker/lookup must reject missing tenant
context before querying. `BelongsToTenant` is an additional guard, not permission
to omit explicit boundaries.

The Workflow settings resolver supports global PAT/access/refresh fallbacks.
Self-service migration must use only the selected tenant-owned connection and
validated source workspace; shared OAuth application credentials are permissible,
shared customer authorization is not. Existing Asana connection persistence clears
other Asana rows in that tenant, and disconnect pauses dependent workflows. Any
connection-generalization change needs workflow compatibility tests and must
surface its effects to an administrator.

## Official API capabilities and gaps

Official documentation was consulted on 2026-10-11. Documentation support is not
proof that the connected account, plan or Codex connector grants the capability.
Record each exercised endpoint and requested fields in the source manifest.

| Source object | Preservation route | Limitation / fallback |
| --- | --- | --- |
| Projects, completed/archived work | Enumerate accessible workspace/team projects including archived; fetch each project and its tasks. | Existing picker hides archived. Request complete fields explicitly; compact records are insufficient. Verify actual account restrictions separately. [Projects](https://developers.asana.com/reference/projects). |
| Multi-project tasks / ordering | `GET /projects/{gid}/tasks`, full task memberships and project-section ordering | One source task may belong to multiple projects. Store task once and membership separately; enumerate order for each project. [Project tasks](https://developers.asana.com/reference/gettasksforproject). |
| Sections | `GET /projects/{gid}/sections` | Documented paginated endpoint; current scope table does not assign a `sections:read` scope. Probe existing authorized grant without silently broadening it. [Sections](https://developers.asana.com/reference/getsectionsforproject). |
| Nested subtasks | Recursively enumerate `GET /tasks/{gid}/subtasks` and fetch each unique task | Task list is not recursive; retain parent and subtask order, detect malformed cycles, report inaccessible parents. [Subtasks](https://developers.asana.com/reference/getsubtasksfortask). |
| Assignees/followers/tags/custom fields/dependencies | Full task records plus definition and relationship endpoints | Keep GIDs, raw values and nulls; read scopes cover users, teams, memberships, tags, custom fields and task dependencies. Import never grants membership automatically. [Scopes](https://developers.asana.com/docs/oauth-scopes). |
| Comments / activity | All pages of task stories, full story fields, author and original timestamps | Omit comment-only filter for preservation. Current docs warn extremely large activity feeds may truncate. Stories are accessible activity, not guaranteed revision history. Re-fetch known stories during delta to capture edits. [Stories](https://developers.asana.com/reference/getstoriesfortask). |
| Attachments | `GET /attachments?parent={gid}` + full attachment record + immediate byte copy when accessible | `download_url` can expire after two minutes; `permanent_url` needs an authorized Asana session. Some providers return null download URLs. Preserve source/provider links and permission requirements; mark uncopied bytes visibly. [Attachment metadata](https://developers.asana.com/reference/getattachment), [attachment listing](https://developers.asana.com/reference/getattachmentsforobject). |
| Project brief | Full `GET /project_briefs/{gid}` including text/HTML | Documented endpoint, but no explicit fine-grained scope entry verified; authorization/plan probe required. Manual export of visible brief is fallback. [Brief](https://developers.asana.com/reference/getprojectbrief). |
| Status updates | `GET /status_updates?parent={gid}` followed by full records | Paginated source exists; no dedicated scope in reviewed table. Preserve imported update metadata without emitting live native updates. [Status listing](https://developers.asana.com/reference/getstatusesforobject). |
| Templates | Project/task template read endpoints where authorized | Project template endpoint returns descriptor/variables, not proof of all embedded rules/tasks. Do not instantiate a template in Asana to discover it. Preserve definition and manual visible configuration. [Project template](https://developers.asana.com/reference/getprojecttemplate). |
| Forms, rules, recurring-task configuration | Account UI/manual configuration capture and API fields actually returned | No general complete exporter for these configurations was verified in reviewed REST reference. Treat as unverified/unsupported automation migration; preserve visible definitions manually, record recurrence semantics, and recreate only after explicit review. [REST reference](https://developers.asana.com/reference/rest-api-reference). |
| Deleted objects / prior revisions | No completeness promise | A current snapshot and accessible stories do not establish every deleted record or historical revision. Record known missing IDs and available admin/export/support routes; do not invent history. |

Minimum read-scope candidates are `workspaces:read projects:read tasks:read users:read
teams:read team_memberships:read stories:read attachments:read tags:read
custom_fields:read`, with template scopes only if actually used. Scope table has
no generic section/brief/status scopes. Some documented endpoints lack fine-grained
scopes; Asana describes Full permissions as a possible route. This requires owner
review of the actual missing endpoint and fallback first, never an automatic scope
escalation. Approved scopes must be registered in the developer console, callback
URL must match registration, and each customer authorizes their own account.
[OAuth registration and grant flow](https://developers.asana.com/docs/oauth).

## Proposed vertical slice mapping

Coordinator owns schema choices. Candidate additive names below are an implementation
proposal, not tables created during discovery. Final migrations require explicit
short index names, migration lint, recovery registration and disposable MySQL checks.

| Source | Candidate owned entity / semantics |
| --- | --- |
| Workspace | Existing tenant plus reviewed connection/workspace binding; never create or select tenant from a source label alone. |
| Team/project/section | `TeamGroup`, `TeamProject`, `TeamSection` with source visibility and reviewed object grants. |
| User/historical author | `TeamSourceIdentity`: source GID/email/name/activity metadata, optional verified `user_id`; no invitation or permission as side effect. |
| Task | `TeamTask` shared across project memberships; nullable parent; distinct date-only `due_on`/`start_on` and timestamp `due_at`/`start_at`; original completion evidence. |
| Task project + section | `TeamTaskMembership` with project/section/position; unique per tenant/task/project; a move edits this relation, not task identity. |
| Stories | `TeamImportedStory`, immutable source versions with original author/time and separate observed/imported time; native comments retain native provenance. |
| Notes / briefs | `TeamSourceDocumentVersion`: raw original text/HTML + checksum + readable sanitized rendition + source link. AI or typed extraction is a proposal linked to exact passage/version. |
| Files | Private durable object plus `TeamAttachment` source metadata/checksum/copy state and exact parent ACL. Unsupported file formats remain retained source objects with safe download policy, not silently discarded. |
| Tags/fields/dependencies | Definitions, typed raw values, and directed task edges; unknown types stay in source evidence. Absent data differs from explicit null and zero. |
| Provenance and workflow | `TeamImportRun`, `TeamSourceSnapshot`, `TeamImportMapping`, `TeamImportIssue`, `TeamImportCheckpoint`; audit independent of normal task activity. |

Select a real representative project only after inventory: at least one completed
task, notes, nested subtask, comment, attachment, source identity and privacy case.
If the selected project lacks a required edge case, use a separate synthetic fixture
instead of adding data to Asana. Show list, board, task detail, My Tasks, preserved
notes and source links over the same imported graph. Search and attachment access
must share object permissions before enabling them. Date-only source values stay
calendar dates; timestamps retain source timezone/offset and normalize separately.

## Source preservation manifest

The final private preservation root is recorded only in the restricted coordinator
report, outside both the application and outer Git checkouts. The coordinator copies
the final source bundle there, secures its ACL, then seals, backs up and verifies a
restricted restore.
The original Git-ignored `../discovery/source` capture remains a secondary working
copy; being ignored is not the verifier's outside-all-Git storage boundary. Discovery
JSON pages are evidence captured through the development connector, not a production
dependency or a claim of complete export. Preserve the entire returned result and
capture completeness/truncation/continuation metadata without dropping wrapper fields.
ACLs must be verified independently of git exclusion. Production backup storage
needs encrypted durable copies and a tested restore.

For each capture record: manifest version, export/run ID, actor authorization
reference (no token), tenant/source workspace IDs, captured UTC time, requested
endpoint/operation/field selection, parent ID, query/offset, raw-file path, byte
length, SHA-256, returned count, distinct IDs, next offset, terminal-page flag,
error status, and coverage (`complete`, `partial`, `blocked`, `unsupported`,
`unverified`). Include all discovery queries even when no rows return. Attachment
manifest stores source GID/provider/name/parent, attempted time, copy status,
durable path, byte length/detected MIME/SHA-256, safe link and failure reason.

Never persist authorization headers, refresh tokens, cookies or signed download
URLs in a public report. Private raw source may contain sensitive descriptions,
emails and links; source records must not become public fixtures or CI logs. Backup
validation proves file hashes, parseability, page continuity, referenced-file
existence and restoration to a second restricted location. Counts alone do not
prove complete permissions or fields.

## Resumable import, cancellation and idempotency

1. Authenticate and resolve active tenant owner/admin, entitlement, selected
   connection and accessible source workspace. Persist reviewed import scope and
   user/permission mappings. Workers carry explicit tenant/run IDs and revalidate
   grant, cancellation and entitlements before each bounded operation.
2. `dry_run` reads immutable evidence and produces create/update/unchanged/conflict
   plans, access transformations and issue summaries; no destination business
   mutation, message or invitation. Pin evidence hashes, mapping/config version,
   permission decisions and destination revision. Apply rejects a stale preview.
3. Capture raw page durably before checkpoint advancement. Use `limit<=100`, follow
   returned offsets until terminal page, never assume a short page is final.
   Offsets expire; resume an expired enumeration from its start and deduplicate
   stable source IDs. [Pagination](https://developers.asana.com/docs/pagination).
4. Persist durable checkpoint per object family/parent, queue leases and attempt
   count. A lease/transaction guards one logical object operation; expired leases
   recover interrupted work. Cancellation is checked between pages/files and
   commits current safe units only; resumptions use committed checkpoints.
5. Mapping uniqueness is `(tenant_id, provider, source_workspace_gid,
   source_resource_type, source_gid)` independent of run, user grant and project.
   Never deduplicate by title, filename or name. Version identity also includes
   canonical payload SHA-256. Creation and mapping commit atomically under a
   database unique constraint; retry after timeout resolves mapping before create.
6. Read-only HTTP retries use `Retry-After` for 429, bounded exponential backoff
   and jitter for transient 5xx/timeouts. Queue future attempts rather than holding
   a worker during long waits. 401 attempts one serialized refresh, then reconnect;
   402 records plan-blocked, 403 inaccessible, 404 unresolved/deletion candidate,
   malformed fields terminal-validation. Sanitize provider errors.
7. Rate budgets belong to the grant/token; migration and existing workflows share
   that budget when they share a token. Begin conservatively, honor cost and
   concurrency limits, and display pause/retry state. Current official standard
   quotas are 150/minute free and 1,500/minute paid, with independent search and
   concurrency limits; actual responses remain authoritative. [Rate limits](https://developers.asana.com/docs/rate-limits).
8. Import context suppresses assignment/mention/email/push/workflow dispatch and
   verifies absence of such jobs/events. Retain original authorship and dates in
   imported evidence; native `created_at`/actor record actual import semantics.

Outcome counters are disjoint: imported, unchanged/skipped (reason required),
failed, unsupported, inaccessible, and conflicts/review. Track newly created vs
updated separately. A task repeated across pages/projects increases membership
coverage, not task count. Attempts and attachment failures must not disappear when
the encompassing task succeeds.

## Initial copy, final delta and rollback

Record initial observation boundaries; Asana is mutable and ordinary enumeration
is not an atomic workspace snapshot. During validation Everbranch task edits should
be gated or marked separately; final apply never overwrites native corrections.
Use a three-way comparison of last imported source, new source and destination;
conflicting changes enter administrator review, preserving both versions.

Final delta refreshes project/section/membership inventories and known task,
subtask, story, note, field and attachment records; source modified time alone
cannot prove relationship/history/deletion coverage. Repeat changed snapshots
with stable IDs/hashes and a bounded overlap window. Asana events may accelerate
discovery but are at-most-once and expire within 24 hours (possibly sooner), so
full reconciliation remains mandatory; invalid event token requires recrawl.
[Events](https://developers.asana.com/reference/events).

Missing records in one page/list/403/404 do not prove deletion. Preserve destination
and raw evidence, distinguish lost authorization from confirmed deletion, and
review tombstone proposals after complete authorized re-enumeration. Confirmed
source deletions never hard-delete imported history. Access loss during import
quarantines affected data pending ACL review instead of widening visibility.

Cutover requires a fresh restricted backup, final source review window, zero
unexplained discrepancies and explicit owner review of representative content and
permission mappings. Rollback disables Team access/import writes and restores the
pre-apply destination snapshot or run-scoped before state while retaining evidence.
Asana remains available. No source mutations, two-way sync, subscription cancellation
or team-wide invitations form part of this import contract.

## Acceptance evidence and present blockers

Reconciliation must compare distinct object-ID sets, active/completed/archived
counts, project memberships/order, parent/dependency edges, identity mappings,
original text/HTML hashes, author/timestamp metadata and attachment byte hashes.
List each discrepancy and explicit administrator disposition; an unavailable
resource is an unresolved coverage gap, not a successful copy. A rendered note
may be sanitized, but the original bytes and transformation rationale remain.

Required future tests: interrupted page/file and lease recovery; concurrent/repeated
imports; expired offsets; 429/401 handling; cancelled jobs; multi-project tasks;
nested historical subtasks; null/zero/date/timezone distinctions; comment edits;
archived projects; former identities; unavailable attachments; tenant and guest
object isolation across API/search/export/files/AI; import notification suppression;
three-way delta conflicts and deletion/access-loss quarantine. Use fictional fixtures
only. Phase 1 doc inspection does not substitute for these software tests.

Unresolved before an applied slice: complete private source export and tested restore;
account plan/cutoff evidence; sufficient authorized scopes for history/files/roster;
actual privacy and guest inventory; original-note pattern review; selected project;
Team schema and module catalog decision; production OAuth app registration/distribution
status and redirect evidence; administrator-reviewed identity mapping. Pricing,
live charges, invitations, AI spending/data policy and production cutover retain
their separate owner decisions. No production credentials were read by this audit.

## Implemented Phase 1 archive verifier

`scripts/everbranch-team/verify-source-export.mjs` uses Node built-ins only and
accepts the saved connector `tool_result.structuredContent` and `response` wrappers.
It hashes all files, validates JSON, counts distinct source objects and memberships,
compares planned project membership counts, checks linked pagination offsets,
records missing families and capture errors, and verifies a sealed manifest on
replay. A seal refuses to replace an existing manifest. Later files remain visibly
unsealed; use a new manifest filename for a later checkpoint after reviewing them.

Run from the app directory after the coordinator freezes capture and copies the
bundle to a `source` subdirectory of the final private root (adjust filenames to
the coordinator's chosen final layout):

```powershell
$privateTeamRoot = '<reviewed-private-root-outside-every-Git-checkout>'
node scripts/everbranch-team/verify-source-export.mjs seal --source "$privateTeamRoot/source" --manifest "$privateTeamRoot/source-manifest.json" --report "$privateTeamRoot/source-report.json"
node scripts/everbranch-team/verify-source-export.mjs verify --source "$privateTeamRoot/source" --manifest "$privateTeamRoot/source-manifest.json" --report "$privateTeamRoot/source-integrity-report.json"
node --test scripts/everbranch-team/verify-source-export.test.mjs
```

Manifest/report destinations must be outside the source directory and a Git
checkout. Files remain private operator artifacts: the utility's `0600` mode is
useful on POSIX, but Windows ACLs and durable encrypted backup require separate
verification. It does not obtain credentials, call Asana, change source files,
download attachments, apply an import or validate destination UI. Stdout contains
aggregate counts/status only. CLI exits 0 for a successful seal or verified bytes,
2 for changed/missing sealed bytes or unsealed extra files, and 1 for invalid input or an operation error.
An integrity success is compatible with partial source coverage. Added files are
reported separately, prevent snapshot integrity success and require another seal.
`verified_sealed_subset` distinguishes unchanged previously sealed bytes from
`snapshot_verified`, which also requires that no unsealed files were added.

Ten synthetic verifier tests passed on 2026-10-11, covering heterogeneous wrappers,
multi-project deduplication, byte tampering, continuation gaps and uncertain starts,
inaccessible/malformed captures, path traversal, new/missing files, CLI privacy
and refusal to overwrite sealed evidence, including Windows case-alias output
paths. No real records were placed in tests.

An optional private `source/capture-provenance.json` sidecar can supply missing
originating request metadata without editing captured wrappers. Its schema is
`{version:1, authority:"reviewed_coordinator_request_log", captures:{filename:
{sha256, request}}}`. The SHA-256 must match the original file. Conflicting existing
request fields or mismatched hashes produce visible issues; source requests retain
priority. This is a reviewed operator assertion, not an independently authenticated
provider log. It can establish pagination starts; it cannot establish complete
permissions or missing source fields. `workspace_reference_observed` is likewise
only an observed reference: `source_binding` reports explicit mismatches and missing
per-record workspace evidence. Unrecognized content-only response wrappers become
issues rather than silently counting as empty results.

Nested task-detail comments/subtasks/attachments are counted as observed records,
with separate primary-snapshot and nested-observation counters. They never prove
complete family pagination. Request or reviewed sidecar
`capture_family_limits.comments` can document a known cap (for example 50); unknown
limits stay unknown, and null comments/files do not become zero counts. Tags,
custom-field definitions, users, dependency endpoints and projects referenced by
tasks are inventoried separately from their actual captured resource snapshots.
Empty attachment-list pages retain their family from explicit capture naming or
`asana_get_attachments` source-tool metadata. A terminal chain establishes only
the queried parent's zero attachment records, not global attachment completeness
or attachment-byte preservation. Singular connector `comment_limit` is recognized
alongside the reviewed sidecar cap; neither proves uncapped activity history.

## Implemented source reader primitives and Laravel integration seam

`scripts/everbranch-team/asana-preservation-client.mjs` is tested executable source
reader logic using injected transport and durable archive callbacks. It has no CLI,
live provider calls, environment lookup or credential provisioning. It accepts an
explicit token in a private field and an envelope containing matching tenant,
connection, selected allowed workspace, active verified owner/admin, and enabled
module/import gates. A required `revalidate(binding)` callback must recheck current
canonical authorization before every request/retry. These envelope values must
come from trusted server authorization, never an HTTP client's assertions.
Parent ACL claims and checkpoints must likewise be derived or loaded by the
trusted backend, never accepted directly from customer request JSON. The checkpoint
hash binds its inputs; it does not authenticate a customer-supplied checkpoint.
Bindings and checkpoint identities also include the verified acting user ID.
Actor account activity is required in addition to active tenant membership; role
aliases must be normalized upstream to the deliberately narrow owner/admin set.
Parent IDs are snapshotted before asynchronous reads so caller mutation cannot
redirect a later request while retaining the earlier authorization binding.

The allowlist uses only official GET task/subtask/story/attachment endpoints and
their documented `tasks:read`, `stories:read` and `attachments:read` scopes. Parent
records must have the same tenant, connection, workspace and expected resource type.
Full field selections remain explicit; default compact GID capture is not a full
record backup. `preserveTaskEvidence` reports each family's result and explicit
missing-scope/inaccessible gaps. Nested descendants require recursive scheduled
subtask enumeration; attachment metadata enumeration does not download file bytes.

`capturePage` must durably preserve exact raw response text before `saveCheckpoint`
commits its next offset. Failed HTTP responses are captured privately as well.
Checkpoints bind family/parent/tenant/connection/workspace and field selection,
retain continuation and cursor hashes, and detect cycles across resumptions.
Expired offset recovery restarts once with a visible recovered-gap record. Stable
capture keys let the archive sink deduplicate repeated captures; source task/story
GID + snapshot fingerprint mapping still governs destination idempotency. Checkpoint
`observedRecordOccurrences` counts returned occurrences, including recrawls, and
must never be used as the unique-task reconciliation count. `enumerationGeneration`
increases on an offset reset so overlapping observations remain distinguishable.

Retries are bounded and cancellable. `Retry-After` seconds/dates are honored;
waits exceeding the configured bound produce a deferred issue with retry time,
not an early retry. Errors/gaps never copy source text, provider error messages
or tokens into their public summaries. A raw archive write failure stops without
advancing a checkpoint. 401 requires reconnect/refresh through the trusted grant
service; this primitive does not borrow another account's credentials.

Twelve source-reader synthetic tests passed alongside the ten verifier tests:
tenant/parent/checkpoint rejection, raw capture order, bounded resume/replay,
retry timing, cancellation, expired offsets, missing scopes/inaccessible families,
sanitized bounded transport failures, malformed pages/workspace mismatch/archive
failure, persisted pagination-cycle detection, per-request grant revocation,
actor-bound checkpoint rejection, safe deferral of oversized retry headers and
source-parent mutation between asynchronous operations.

For product integration, implement a tenant-scoped `AsanaPreservationService`
in Laravel beside the existing provider resolver, without changing Workflow
trigger semantics. Resolve current active membership and Team entitlement through
canonical services; select `IntegrationConnection` through
`TenantIntegrationConnectionResolver`; obtain/refresh only that connection's token
through the provider service with serialization and sanitized errors. Port these
tested GET/checkpoint transitions into bounded queued jobs (or invoke this reader
only through a private server-owned worker bridge). Store raw responses on private
durable storage, commit hash-pinned snapshots before tenant/run checkpoint updates,
and persist sanitized `TeamImportIssue` gaps. Source-bound parent IDs must resolve
from those private snapshots. A separate attachment worker fetches freshly authorized
download bytes with host/redirect validation, size/MIME limits and integrity checks.
No such Laravel service, queue bridge, OAuth scope expansion or applied migration
is claimed built during this phase; the new scripts are preservation foundations.
The production transport must enforce GET and the fixed Asana origin with manual
redirect handling. The durable sink must enforce object ACLs, encryption and atomic
writes. These injected primitives are scaffolding, not installed server authorization
or a complete importer.
