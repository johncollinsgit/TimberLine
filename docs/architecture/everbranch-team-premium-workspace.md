# Premium Teams workspace and per-user Asana

Local candidate, 2026-10-19. Continues the shared `/teams` and `/team` application.

## Implemented web slice

The Forestry visual system provides a compact header, project/favorites navigation,
contextual filters, keyboard shortcuts, sectioned rows, inline assignment/date edits,
task selection and sequential completion, section boards with drag and keyboard
select moves, month/agenda calendars, and a resizable desktop/full-screen phone task
panel. Description/properties, files, conversation and activity retain their original
forms, handlers and shared graph. Existing uncertainty/draft fences remain authoritative.
Inline changes open the canonical task editor and use its retained exact-save path.
Selected completion retains each command identity while unconfirmed in page memory.

Personal favorites and saved views are stored separately from work records. Initial
timeline, workload and dashboard projections summarize loaded pages and say so;
they do not infer effort, capacity, global totals or employee performance. Native
editing remains available through the shared task panel. Calendars explicitly use UTC
for timed deadlines and preserve literal date-only values.

New behavior is split into Vite modules under `resources/js/team/`, with isolated
design tokens/styles. The existing public script stays the shared command/draft
engine and the unflagged fallback. No second work graph, duplicate native task store,
new framework, new domain or native binary is introduced.

## Asana boundary

Each authenticated viewer connects their own Asana account within the current
eligible Team tenant. The new connection never reads or modifies automation-provider
credentials. OAuth uses one-time, user/session/tenant-bound state and PKCE. Tokens
are encrypted at rest and never serialized to the browser. The request scopes are
read-only. Credential setup is through `TEAM_ASANA_CLIENT_ID` and
`TEAM_ASANA_CLIENT_SECRET`; register `/team/asana/callback` on the application origin.

Selected projects are checked with the current user's source token. Selection changes
rotate the connection identity, invalidating old cursors and requests. Project/task
reads recheck source access. A task's unrelated memberships, parents and dependencies
are excluded from the projection. Multi-project task identity is provider/workspace/GID;
it never becomes a native Team task. Subtask reads authorize an accessible selected
ancestor. Comment, attachment and subtask pages reauthorize the task first.

Workspace/project/task/related pagination uses connection/resource-bound expiring
encrypted cursors. The UI filters loaded Asana titles and fields and labels that
coverage; it does not claim complete global Asana search. Completed work is requested
explicitly. Native Home totals exclude Asana; source totals describe only loaded pages.
Source work supports list/board/calendar and initial planning projections. All source
edits link to Asana. Source conversation is separate from native Inbox events.

Entry/manual/visible 60-second refreshes pause in background tabs. Source content is
cleared before refresh, on loss of access, and when backgrounded; late results are
fenced by connection, page identity and request generations. Temporary errors,
partial project failures, permission loss and reconnect needs have explicit states.
429 honors Retry-After with a connection-scoped cooldown; token refresh is serialized
by a database row lock. Safe operation/status/latency telemetry excludes payloads,
tokens, source names, notes and source identifiers.

## Additive interfaces

- `GET/PUT /team/preferences`: personal authorized favorites and saved native views.
- `POST /team/asana/connect`, `GET /team/asana/callback`, `DELETE /team/asana/connection`.
- `GET /team/asana/status`, `/workspaces`, `/projects`, `/tasks`, `/tasks/{gid}`.
- `PUT /team/asana/selection`; `GET /team/asana/projects/{gid}/sections`.
- `GET /team/asana/tasks/{gid}/{stories|subtasks|attachments}`.

Existing tenant/entitlement/verified-member gates apply. Source reads additionally
require the viewer's own connection. Data responses include source identity,
read/write capabilities, freshness and page metadata. The new
`team_user_connections` table holds encrypted per-user credentials/selections or
native personal preferences, partitioned by tenant/user/provider. No real import runs.

## Remaining larger-plan work

This is the everyday-work web slice plus initial planning projections. It is not
full Asana parity. Dedicated milestone/approval lifecycles, named portfolios, complete
Gantt/dependency visualization, capacity models, all-page analytics/exports, rich text,
followers/notification preferences, forms, workflow automation, advanced template
editing and time tracking remain in the preserved backlog. Existing dependencies,
recurrence and templates continue to work; their presence does not complete those
broader families. Native apps, offline durability and Asana write-back remain deferred.
All 643 original clauses remain retained; this slice makes no native/product
completion percentage claim. See the verification runbook for actual evidence.
