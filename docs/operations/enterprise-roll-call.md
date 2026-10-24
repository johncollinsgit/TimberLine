# Project-first Roll Call (October 23 source follow-up)

Roll Call now begins with **Projects & people**: search, workspace/department,
in-house person, contractor, status and location filters, plus name/department/
person/recent-update sorting. Create project and Incoming tickets reuse the same
shared authorized wizard/queue. Projects open the existing native dialog, centered
and enlarged, with current task assignments, saved locations, project notes and
private file attachments. Close/Escape returns to the originating Roll Call context
through the shared shell integration; filters stay local to this view. Layout
preferences belong only in Settings.

The browser checks each currently returned allowed area through the existing
`data`, `operations` and `planning` read endpoints. There is no institution-wide
grant or new route. Contractor membership requires an explicit current planning
schedule joined by contractor and project IDs; contacts are not login accounts.
Unknown owners and assignments remain unrecorded. The view shows bounded visible
tasks, never an authoritative staffing, presence or total-work claim. Locations
are saved work locations, not live tracking. Refresh clears former names/rows
before new access checks; denied areas and late responses are not redisplayed.
Successful shared work saves invalidate this view through `enterprise:work-saved`.

Priorities and private meeting tools remain available in a secondary disclosure.
The disclosure reopens for a retained pending save/confirmation or selected saved
meeting so safe replay remains reachable after reload. Read-only viewers no
longer request a leader-only meeting listing before displaying readable priorities.

The project detail helper requires the shared shell integration: include
`enterprise.project-detail-script`, set the dialog's `data-entity-type=project`,
and call `renderProjectDetail(project)` in the existing project branch. It reuses
`renderProjectWorkControls`, `mountDiscussion` and `mountProjectOperations`; it
adds no command transport, grants, upload bypass or provider activation. The
shared layout packet owns DTO enrichment, demo-only Blaine controls, additive
20-job/10-worker/5-contractor fictional data and the return-to-Roll-Call lifecycle.
Those must be composed and reviewed together before release.

Local evidence: six Node checks cover explicit relations, combined filters,
nonmutation, denied-scope clearing and delayed-response fences. Actual isolated
HTTP scenarios in Chromium and WebKit cover project note/file reload persistence,
quarantine download refusal, centered project dialog, current meeting scopes,
exact save/confirmation retries, no duplicate native tasks, one-page PDF and
phone/tablet/desktop reflow. Fictional local HTTP evidence does not establish
hosted release, a real Blaine identity, physical-device or provider acceptance.

# Enterprise Roll Call and meeting snapshots

Source candidate. `ENTERPRISE_ROLL_CALL_ENABLED` defaults off and is independent
of Operations, Resources, briefing and provider flags. This work does not merge,
deploy, activate billing, provision campus identities or send messages.

## Current authorized priorities

Roll Call resolves existing active verified tenant membership and current
department permissions. Campus scope means currently readable workspaces, not
institution-wide authority. Workspace and project scopes retain native project
and task ACLs. Team scope is explicitly an assigned active verified person in a
selected workspace; no new permission group or membership is created.

The current method is deterministic record-derived ordering. There is no model
call or provider fallback in this lane. Current urgent native tasks, interrupted
work, overdue dates, pending linked handoffs, blockers, awaiting verification,
contractor date dependencies, resource conflicts, overdue custody and manually
recorded upcoming activities appear with an owner/current handler when known,
next action, due date, source link and last-recorded timestamp. Missing ownership
or freshness stays unknown. Internal checkpoint text, private contractor grades,
student details, security case bodies, comments, documents and mailbox bodies
are not copied into Roll Call. Project/task visibility remains authoritative.

Operations supplies its current authorized projection; interruption remains
until an explicit operational resolution. A confirmed meeting task update never
silently resumes interrupted work. Resources supplies authorized reservations,
asset custody and conflicts. Overlaps are manager decisions, not automatic
rescheduling. Recorded tours/classes/events are labeled manually recorded,
not live college schedule feeds. Planned contractor dates do not prove actual
contractor availability. Resource records without a linked project or accountable
person are omitted from those narrower scopes rather than assigned fabricated
ownership. No contractor evaluation is included.

Coverage is bounded: up to 100 readable workspaces, bounded peer projections,
and 200 displayed priorities. Authorization precedes visible result caps. Each
response declares the method, sample-data state, timestamp, coverage and overflow.
Eastern time defines date-only overdue work; timestamp deadlines compare instants.

## Leader-private meetings and task changes

Meetings are encrypted native operation records. The creator must currently be
an organization owner/admin or department coordinator. Notes remain private to
that leader, including from other administrators. Read, listing, export and
every mutation recheck current manager, workspace and linked-project/task
permissions. This conservative first slice does not create a meeting-sharing
grant. Decisions and blockers are notes; saving them does not mutate work.

The leader can save up to 20 decisions, blockers and commitments. Commitments
have an existing authorized owner, project and date, and stay in meeting scope.
A project meeting cannot create a task in another project; assigned-person
meetings retain that person. Blocker owners must already be verified workspace
participants. There is no account, area or project grant side effect.

Preview persists an expiring private review receipt without changing native
tasks. It shows exact native actions and pins actor, meeting version, project
versions, native task versions, current Operations metadata version and a digest.
The receipt expires after 15 minutes. Confirmation requires its preview ID, exact
digest, expected meeting version, explicit confirmation and stable request UUID.
Targets and assignee authority are rebuilt under the tenant lock before the
transaction applies any change. Stale edits, expiry, changed preview input,
different users, revoked membership or insufficient roles refuse the action.
All native mutations and receipts commit together. A lost response can replay
the same request UUID safely without creating a second task.

The form-based editor uses existing authorized owner/project/task selectors,
never JSON editing. Session drafts are partitioned by runtime, tenant, signed-in
user, scope and meeting. Returning first checks current permissions before
redisplaying retained text. Unknown save/confirmation outcomes keep their exact
request body/key and lock editing until the same operation is reconciled. Late
responses are fenced to their original context. Confirmed rows are immutable;
the UI displays exact task changes and requires a separate review checkbox and
confirmation. Desktop, phone and tablet use the same flow with touch-size
controls. Public sample execution remains explicitly distinct from native HTTP.

New commitments create canonical native tasks with already authorized assignees.
Existing-task commitments can change title and due date while preserving its
current assignee/accountable owner. Reassignment, reopening, checklist approval,
interruption and resumption stay in Operations. A confirmed commitment is
immutable; add a new commitment to propose a further change. Audits retain
meeting saves, previews and confirmed task identities without external delivery.

## One-page meeting PDF

Every download rebuilds current authorized Roll Call and current leader notes.
The one-page Letter document shows up to four priorities, three decisions, two
blockers and three commitments, with timestamp, scope, sample labeling, owner,
due/next action, source freshness, source links and an authorized complete-view
link. Omitted entries have explicit overflow counts. Long text is abridged;
the complete view retains saved text. A saved PDF is a dated snapshot, and a
new download regenerates it. Downloads are private/no-store and authenticated. The private UI fetches the current same-origin PDF using the signed-in identity, validates its content type and signature, then downloads a temporary browser Blob. A late response after scope navigation is discarded, and revoked access clears the current display. The isolated sample adapter supplies its own explicitly synthetic document.

The fixed PDF writer follows the existing BriefingPdf quoted-text/HTTP(S)-URI
contract: no HTML parser, script, file import or remote resource. Width bounds
protect long titles. Text uses built-in Latin font transliteration, and the
PDF is not tagged; the HTML interface supplies the accessible equivalent.
Both standard and maximum-content synthetic documents were rendered and
visually inspected using Poppler: exactly one 612 x 792 point page and no
JavaScript. Viewport tests and PDF rendering do not establish real iPad hardware
acceptance or live college/provider readiness.

## API

GET `/enterprise/roll-call` accepts `scope=campus|workspace|project|team`, with
`area_id`, `project_id` or `team_user_id` for the selected narrower scope.
GET `/enterprise/meetings` lists the signed-in leader's authorized meetings for
that scope. POST `/enterprise/meetings` saves an explicitly versioned meeting;
GET `/enterprise/meetings/{id}` returns its current authorized notes.
POST `/enterprise/meetings/{id}/preview` returns `preview_id`, `version`, `digest`,
`expires_at` and exact actions. POST `/enterprise/meetings/{id}/confirm` requires
`preview_id`, `digest`, `expected_version`, `request_id` and `confirmed=true`.
GET `/enterprise/meetings/{id}/pdf` regenerates the current snapshot.

## Verification evidence

The focused backend suite passes 10 tests with 82 assertions, including current
permission loss, private department/project/tenant boundaries, existing task and
Operations versions, immutable confirmed rows, exact preview/digest confirmation,
repeated native writes and bounded safe PDF output.

The isolated private HTTP browser flow verifies retained scope drafts, literal
hostile text, lost-response retries across reload using the same save/confirmation
bodies, no native task before confirmation, exactly one created task after replay,
and resaving notes after confirmation. The actual UI-downloaded PDF was inspected
with Poppler: one Letter page (612 x 792 points), no JavaScript. Desktop, phone,
iPad portrait and landscape viewport checks pass without horizontal overflow and
with at least 44-pixel controls. All provider/network calls outside the loopback
fixture are blocked; this is not physical iPad or live college/provider acceptance.
Evidence is held under `evidence/enterprise/operations/enterprise-roll-call-*`
and `evidence/enterprise/reviewer-trial/meeting-pdf/` in the task workspace.