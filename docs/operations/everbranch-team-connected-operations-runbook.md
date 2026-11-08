# Connected operations candidate runbook

## Rollout controls

Production activation must use the protected `Launch approved Teams workspace`
workflow. It exports the exact verified release ID while rebuilding configuration;
the command refuses activation without that context. The post-activation health
check must retain that same ID. Never pin a release ID in the shared environment.

For an approved native-app rollout, select `enable_mobile=true` in that workflow.
It passes `--enable-mobile` to `team:launch` for preparation, activation and
verification. This enables only the Teams mobile client; normal active membership,
tenant allowlist, entitlement and API permissions continue to apply. It does not
enable Pay, provider sends or Asana creation. A normal web rollout leaves the
mobile flag unchanged. The command records the mobile request in the existing
launch audit, retains a private environment backup and restores configuration if
caching fails. Verify the public Teams availability response and then sign in on
the physical device; availability alone does not prove a user's workspace access.

`TEAM_OPERATIONS_ENABLED=false` requires premium Teams plus current tenant entitlement/allowlist. Additional independent controls default off: `TEAM_OPERATIONS_SEND_ENABLED`, `TEAM_OPERATIONS_ASANA_CREATE_ENABLED`, `TEAM_OPERATIONS_SHEET_IDS_ENABLED`, `TEAM_OPERATIONS_AI_ENABLED`, `TEAM_WEATHER_ENABLED`, `TEAM_WEATHER_COMMERCIAL`, and the Teams ClamAV scanner setting. Disabling operations hides the new surfaces and stops scheduled synchronization/reminders without deleting records, credentials or evidence.

Configure Google OAuth and verified scopes using `TEAM_GOOGLE_CLIENT_ID`, `TEAM_GOOGLE_CLIENT_SECRET`, and HTTPS callbacks. Sending and Sheets identifier writes require separate consent. Gmail watch configuration uses `TEAM_GOOGLE_PUBSUB_TOPIC`, `TEAM_GOOGLE_PUSH_SERVICE_ACCOUNT`, and `TEAM_GOOGLE_PUSH_AUDIENCE`; unsigned notifications are rejected. Drive notifications request a new source read, never apply cell changes from notification payloads. Scheduled reconciliation remains necessary. Renew watches and run the existing scheduler/queue workers. `TEAM_OPERATIONS_SYNC_PAGES` bounds pages per run (default 5, maximum 20).

Do not reuse Codex plugin credentials as application tokens. Tokens/settings/cursors are encrypted and tenant/user/connection scoped. Never log source notes, mailbox content, files, financial rows or tokens. Safe sync telemetry includes connection/provider IDs, status, page count, duration and failure class.

## Pilot order

1. Resolve current Events project access and inspect mappings privately. Verify Wholesale project-per-client organization and selected projects with the owning user.
2. Connect each runtime account; verify source selection before refresh. Test a second user with different access, private/revoked projects, refresh failures, source switching and incomplete pages.
3. Preview and back up Sheets mapping. Review copied/reordered/deleted IDs and shared allocations. Do not trust event totals while ambiguous rows are excluded. If identifier write outcome is unknown, reconcile against the retained backup; do not repeat the POST.
4. Verify scanner and quota behavior with safe/unsafe files. Files without a clean scan must not be downloaded as published documents. Review uncertain email attachment matches individually.
5. Publish an operational email template, preview against current permitted work and a verified recipient, then prepare a reviewed outbox draft. Preparation is not delivery. A changed record/recipient/connection requires fresh review. Provider acceptance is not confirmed delivery. Reconcile uncertain attempts using the existing action; do not construct a new attempt to force a resend.
6. Preview internal reminder matches and schedule. Enable a rule only after checking its audience and quiet hours. External sequences remain drafts until canonical campaign stop/suppression controls are integrated and accepted.
7. Validate financial completeness, Square attribution/refunds/offline delays, confirmed schedule, forecast sample sizes and weather horizons. AI is optional; check the existing tenant budget and uncertain-spend reservation behavior.
8. Complete browser, MySQL recovery, Linux and protected release checks before tenant-scoped pilot enablement. Never deploy directly to the live release directory.

## Local evidence

The isolated loopback fixture at port 8774 contains synthetic Events/Wholesale data and fake compose accounts, with outbound delivery disabled and HTTP fakes preventing external requests. It is separate from the previous port 8773 preview. Do not treat it as real source verification.

Automated checks: `php -d memory_limit=1G vendor/bin/pest --configuration=phpunit.team.xml tests/Feature/Team tests/Feature/Wholesale tests/Feature/Tenancy/ModuleCatalogConfigConsistencyTest.php tests/Feature/Billing/TenantAiUsageInvoiceTest.php tests/Feature/FieldService/MobileVoiceDispatchTest.php --compact`; `npm run test:team-work-local`; `npm run build`; migration safety lint against the working tree. The MySQL recovery manifest/test is present, but a passing real MySQL run has not been claimed.

### Source note fields: event details

Source event notes expose exact labelled candidates for venue, venue address/address,
booth number/booth #, booth size, setup time and show/event hours. Booth number and
booth size are distinct fields; a combined venue-address line remains literal rather
than guessing a split. Existing source events are reprojected when read, so the
updated labels do not require a bulk import or source write. Candidates remain
unreviewed until an authorized reviewer explicitly approves them; existing reviewed
values are not replaced by a conflicting candidate.

For a source event, the browser includes the returned `fields_revision` as
`expected_fields_revision` with selected field indices. This binds approval to the
candidate order displayed to the reviewer and makes an older browser page fail with
an actionable refresh message rather than approving a different field. The server
also checks the current record version, the reviewer's own live Asana access, source
identity and modified time, the exact selected passage, and the connected source
generation. A source change or unavailable source stops review; notes are not
rewritten. Approval retains a versioned receipt and provenance. This is a check at
review time, not an atomic transaction across the external provider and Teams.

The current native app only displays source fields; it does not submit field
approvals. The native review transport supports the same revision contract for
clients that implement that interaction later. No production field corrections or
new Asana write scopes are applied merely by deploying the parser.

## All-event work views (November 8; candidate)

The `event_scope=all` compatibility interface reads all retained events without
a date window. The main Board default uses the upcoming window described below. The server still pages transport internally with encrypted viewer/tenant/
area/query-bound cursors; clients automatically drain and deduplicate those batches
before declaring the collection loaded. Missing/repeated cursors or failed source
rechecks fail the collection rather than presenting an earlier batch as complete.
There is no Board month selector or user page control. Calendar date movement
paints the mounted collection without a network request. The periodic refresh,
visibility/account/workspace invalidation and fresh detail access checks remain.
The explicit work-area calendar retains its bounded-date interface for older
clients. Loaded retained records do not establish complete provider history.

Collection access setup and crew lookup are reused within a request only. Compact
cards omit original notes, candidate parsing and finance detail; opening a card
retrieves those details through the existing authorized record endpoint. Search
fuzzy scoring deduplicates repeated note words and memoizes edit distance within
a single query; it does not store source content or access decisions between
requests. Verify real signed-in browser timing separately from fictional fixture
benchmarks. Required acceptance includes >200 events, cross-year Board content,
chronological List, zero-request Calendar movement, continuation access failure,
late-response retirement, tenant switching and full original details.

For a collection, Asana task membership is checked against a fresh, paginated
inventory of each selected project under the current user's OAuth connection.
Only exact IDs with matching workspace and project membership are admitted.
Oversized inventories fall back to fresh individual task admission for unresolved
IDs; inventory limits do not silently exclude them. Provider failure, changed
connection generation or uncertain pagination invalidates the collection. A
completed inventory's exact denial hides that record and retains a partial-source
notice while allowing other authorized events. No inventory becomes a stored
access grant; record details retain the original fresh task checks.

## Upcoming default and explicit history (November 8; follow-up candidate)

The main event surfaces request `event_scope=window` with required `calendar_from`
and `calendar_to` (maximum 62 days). Board and List default to 30 local dates,
retaining ongoing events. Calendar requests its chosen month. Controls can choose
an older window; universal search retains history independently of that window.
All matching server batches drain automatically. Matching encrypted schedules
happens before provider checks; reviewed outliers are preserved even when they
fall outside old source bounds. Compact window responses exclude historical card
payloads and original notes. Existing `event_scope=all` remains compatible for
explicit callers; it is no longer the main Board default.

Event date preference supplements search relevance using recorded bounds or
reviewed schedule evidence, with unknown dates kept unknown. Explicit historical
year searches still honor that intent. Search pagination binds ranking version
and the work-area local day so midnight or ranking changes cannot reuse an old
cursor. Verify real source request timings separately from synthetic benchmarks.

### Release queue observation (November 8)

An older shipping workflow approved `3ebb38c7` while main advanced to `4eaf2849`.
The unqualified Forge webhook cloned current main, so `/ready` correctly exposed
`4eaf2849` while the older workflow waited for its prior SHA. Only that obsolete
verification wait was cancelled; the workflow for the actual `4eaf2849` release
subsequently passed its own combined gates and exact-release readiness check.

This is an existing deployment pinning gap, not a completed safety improvement.
Keep a single publication owner. A follow-up needs both a pre-hook main-SHA
check and a trusted Forge candidate check against a custom expected-SHA parameter
before dependencies, migrations, or activation. Derive readiness release identity
from the candidate's actual Git HEAD. Forge's `forge_deploy_commit` parameter is
[a display label](https://laravel.com/forge/docs/sites/deployments#using-query-parameters)
and must never be treated as proof of deployed source. The pre-hook check alone retains a race between checking and cloning. Preserve the
healthy prior release if the new candidate does not match the approved SHA.

### Progressive search and inexpensive action lookup

A first search requests `stage=teams`, admitting only current permitted Teams
results and returning an encrypted continuation marked for deferred app work.
The client publishes those results, then requests `stage=apps` with that exact
cursor. App destinations use current role/module checks; their cursor retains
the exact Team continuation. Later pagination uses the existing combined read.
Both stages bind actor, tenant, filters, ranking version and local dates. Query,
account or scope changes abort and retire delayed responses. A failed app read
retains the initial permitted Team rows with a visible retry notice; it does not
claim that other apps had no matches. Selecting a row still revalidates it.

The app action provider now calls the same permitted dashboard action builder
without calculating dashboard finance, sales or reporting summaries. Soccer and
Website workspace action and membership rules remain shared with the dashboard.
Event candidate prefiltering uses a conservative in-memory superset only; current
policy, sanitized display projection and final matching remain mandatory. Private
raw candidate text is not returned, indexed, counted for display, or sent to AI.
