# Enterprise workflow operating workspace

Source candidate for the Friday demonstration. This increment extends the separate
Enterprise application/database boundary and existing Teams tenant/department ACL.
It does not activate providers, change production records, grant membership or
replace the retail automation engine.

## Inspected and reused components

The existing Workflow Studio V2 compiler/draft/interpreter establishes the closed
definition, optimistic revision and reviewed publishing pattern. Its provider
catalog and scheduled action execution are unsuitable for Enterprise human forms
and approvals; this increment does not load its integrations or grants. The new
small operational language uses the existing Enterprise `RecordStore` tenant lock,
encrypted `TeamOperationRecord` payloads, encrypted append-only revision records
and native `WorkspaceAccess`/`WorkGraph` project authorization. No new schema or
backfill is needed. Trajectory and the existing Studio are UI references; neither
application's behavior is changed by this backend.

## First complete slice

Projects launch a dedicated workspace with canvas, operational screen builder,
safe preview, runs/history and settings. The server accepts at most 24 nodes and
32 edges: Start, Form, Condition, Assignment, Approval and Complete. Every node is
reachable, the graph is acyclic, conditions have yes/no edges, and all other
nonterminal nodes have exactly one next step. There is no executable expression,
arbitrary data binding, provider action, external URL or generated tool call.

Human screens accept bounded text, paragraph, date, checkbox and explicit-choice
fields. Field keys are unique. Conditions reference required fields collected on
every incoming path, with typed literal equality/inequality only. Unknown fields,
cross-screen submissions, invalid options and unsupported node types are rejected.

An illustrative maintenance template is explicitly marked as an example. It has
no college names or imported demo records: intake, priority decision, named
coordinator approval for urgent work, assigned completion and a final state.
Users review existing eligible people before activation. AI may propose a draft
through the owned `Intelligence` transport; the server independently validates
every node, field and assignment, then rechecks current access. A proposal does
not save, activate or execute anything. Missing AI prerequisites produce an
explicit unavailable result; the template is never represented as model output.

## Authority and durable execution

The linked project must be in the selected Enterprise department and currently
accessible. Designing/activation requires an existing coordinator or organization
owner/admin plus project write access. Assignees must already be active verified
tenant members with department and project write access. Approvers additionally
need current coordinator/owner/admin authority. Selecting a person never creates
an account, invitation, tenant, department or project grant.

Activation requires the exact current draft version, its validated digest and an
explicit confirmation. The reviewed definition is pinned in an active snapshot;
each run copies that definition and digest. Later draft edits or activations do
not reinterpret an existing run. Start/condition/completion transitions are local
and deterministic. A form waits for an authorized project editor; an assignment
waits for its exact named existing user; an approval waits for its exact named
current coordinator/owner/admin. Rejection ends the run. Assignments are durable
workflow run tasks; they do not silently create or modify native project tasks.

Every mutation serializes on the same tenant lock, checks expected version and
uses an actor-scoped stable operation key/input hash. A lost response can be
retried without duplicate transitions. Current tenant/department/project authority
and original human-step authority are checked even on replay. Revocation fails
closed. Run values and history use the same project/department read boundary.
Pause prevents starting and advancing runs while preserving their history.

The visible history records step, decision, actor, time and submitted field keys;
the encrypted run stores current values. Field values remain untrusted text for
rendering and AI. Neither forms nor conditions can send messages, grant access,
invoke arbitrary HTTP, bill, or mutate a separate project as a side effect.

## Bounds and following work

Lists disclose their bounded coverage (100 results from at most 1000 department
records). This is a complete manual operating slice, not a general scheduler or
integration automation platform. Native task materialization, run reassignment,
timers, resource reservations, restricted contractor documents and notification
actions require separately reviewed domain contracts. Physical iPad camera,
file picker and software-keyboard acceptance remains distinct from browser
viewport/engine tests.
