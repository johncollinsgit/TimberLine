# Private shared fictional reviewer workspace — source candidate

This default-off source leaf stores a closed, encrypted fictional work graph in the proposal-owner silo. It does not retrieve college records or grant campus identity. Independent unlocked recipient sessions read the same server revision. AI evidence comes from that stored graph; browser facts are not accepted by this service. No AI/provider/key/ledger operation occurs here.

The existing immutable review edition has no `review_workspace` descriptor and cannot use this mode. Do not attach a descriptor to an existing edition, initialize production storage, import browser state or republish the current link as a side effect of source integration. Preserve its chosen names, comments, selections and access. A later reviewed publication transition must explicitly reconcile and preserve all twenty current jobs and the current saved canonical plans; none is reset or reseeded by these classes.

## Default-off descriptor and authority

All `service_proposals.reviewer_workspace` gates default false. Configuration supplies the already-reviewed synthetic anchor UUID/settings digest and an exact digest-keyed closed policy. Policy is `{schema_version:1,max_role:viewer|editor,area_ids:[UUID],personas:[closed names],allow_financial:boolean}`. An immutable publication may contain `{schema_version:1,workspace_id:UUID,seed_sha256,policy_sha256,expires_at:UTC}`. Actual Version content hash, client reference, owner silo, current build/source, expiry and unlock are rechecked; supplied IDs/JSON do not grant authority.

The owner-runtime configuration seam accepts `SERVICE_PROPOSALS_REVIEWER_WORKSPACE_APPROVAL_JSON`, at most24,000 bytes: exact `{schema_version:1,anchor_area_id,anchor_marker_sha256,policies,collaboration_policies}`. Each policy map contains at most10 exact SHA256-keyed policies. Workspace policy department/persona lists must already be sorted as required by current admission. Comments policies retain `uploads_enabled:false`; this seam cannot enable files or scanning. This is nonsecret reviewed metadata, never a credential or storage-provisioning instruction. No actual values are installed in this source milestone.

With a valid metadata packet and the exact proposal-owner runtime/one configured local owner tenant, independent boolean flags are `SERVICE_PROPOSALS_REVIEWER_WORKSPACE_ENABLED`, `_WRITES_ENABLED`, `_INITIALIZATION_ENABLED`, `_FINANCIAL_ENABLED`, and `SERVICE_PROPOSALS_REVIEWER_COLLABORATION_ENABLED`, `_COMMENTS_WRITE_ENABLED`. All default false. Missing, malformed, oversized, extra-authority or digest-mismatched metadata closes every gate even if an activation flag is true. Existing real silo/anchor/owner/publication checks remain mandatory on every operation. Metadata may stay installed with all gates off so approved retention can still verify its original anchor; no parser creates an anchor, membership, publication, ledger or provider call. Final activation needs its separately approved exact packet and observed cleanup; this parser does not supply that approval.

Only an actual currently active, verified owner/admin can call the initializer library. It requires initialization and workspace gates, the already-existing marked anchor with no members, exact immutable reviewed seed hash and explicit request/descriptor digest. The first create and exact lost-output replay use the tenant transaction. A different intent cannot reset existing work. There is no recipient initializer, new anchor creation, import, provisioning or runnable operator entry in this leaf.

A session-issued random nonce owns recipient-created tickets/command receipts; its digest is edition/mount-bound. It survives legitimate session ID regeneration. A reviewer name or selected fictional worker never grants ownership or native access. Viewer policy is read-only for every persona, including requester. Worker is restricted to current assigned tasks and own-capability tickets. Manager/editor can coordinate the closed fictional graph.

## HTTP and command contract

The integrated source mounts only POST `projection`, `facts`, `commands`, `command-status` under the existing private proposal web/session/CSRF runtime at `service-proposals/{token}/campus/workspace`. Same-origin JSON, no query parameters, matching CSRF and bounded session/IP rates apply. The outer global boundary sanitizes middleware/debug failures before the controller; only a controller-owned response marker can retain its fixed definitive-rejection envelope. Responses are private/no-store/no-referrer/noindex/nosniff/default-src-none JSON.

Projection body is `{binding,selection}`. Binding is `{version_hash,build_id,source_hash}`. Selection is `{persona,area_ids:[UUID],person_id:null|fictionalID}`; worker requires a currently active fictional person in every selected area. Work rows are selected; `allowed_areas` is the current permitted department directory. Response is `{workspace_id,revision,workspace_digest,selection,graph,allowed_areas,permitted_actions,capabilities,origin:'server_shared_fictional',expires_at}`. `capabilities` is `{can_view_costs,can_manage_plans,can_manage_costs:false}`; every work/plan row has server-computed `allowed_actions`. Tickets expose only `owned_by_caller`, never ownership digests.

Commands have exactly `{binding,selection,request_id,expected_revision,action,record_id,expected_record_version,payload}`. Capture revision/version when the form opens. Do not replace them with newer background values. The complete command is at most16KiB. Canonical digest recursively sorts object keys, preserves array order and uses unescaped Unicode/slashes/U+2028/U+2029. **Payload is always a JSON object.** `plan.cancel` sends `{}`; wire `[]` is refused. PHP callers use semantic empty array for this one empty object; use `ReviewerWorkspaceSchema::commandDigest`, not generic graph digest. Tests pin exact PHP/JavaScript equivalence.

Actions/payload exact keys are the source contract in `ReviewerWorkspaceCommands.php`. Main composites are atomic:

- `job.create`: project+task+ticket with current area, assigned person and saved building version.
- `ticket.plan`: route an incoming ticket to a currently permitted department/project, create its assigned task and save its building, with explicit routing acknowledgment and current project/building versions. `ticket.assign` does the same within the original department.
- Project create/update/archive/restore; task create/update/assign/status/archive; ticket create/update/classify/status/reorder; contractor plan create/update/cancel. Plans require current project/contractor versions and reject overlapping planned dates.
- Buildings/department/person/contractor directories are read-only reference data in this first slice. Plan assignment edits are supported. Cost rows are read-only; no cost-save or proposal-commercial-price action is admitted. Unsupported comments/photos/files/mail/provider/native commands are rejected.

Success is `{status:'applied',replayed,receipt:{request_id,record_id,revision,digest},projection}`. The receipt describes the original commit; projection is fresh and can have a later revision. Exact request/body replay never writes twice. Existing request IDs with different digest/capability are refused. Only an absent-receipt, currently authorized, pure pre-write409/422 rejection emits `{error:'workspace_conflict'|'workspace_invalid',automatic_retry:false,definitive_not_applied:true,request_id,digest,workspace_id}`. Existing receipt conflicts, admission failures, DB errors and post-commit projection failures remain generic/unknown.

Command-status body is `{binding,selection,request_id,digest}`. An exact own receipt returns `{status:'applied',settled:true,receipt,projection}`. Absence returns `{status:'not_observed',settled:false}` and never proves a delayed write cannot still apply. An explicit human Retry of the **same ordinary CRUD command/body/ID** is safe through durable receipt/version serialization. No automatic resend, replacement ID or optimistic success. A previously unknown command remains pending even if a later retry409/422 appears. Provider/paid dispatch protocols are separate and do not gain retry permission.

## Grounded evidence and bounds

`facts(request,token,binding,selection)` and explicit readonly POST `facts` (body `{binding,selection}`) return only `{snapshot,pin}`. Ordinary UI projection is independent of the24KiB AI bound. The client retains this server-returned sharing evidence/pin under its current generation; preview independently retrieves and verifies it again and never accepts browser facts. Snapshot is normalized closed schema2, projected from stored records with exact building/task/ticket links, current fictional assignments/contractor plans and deterministic project-level costs. No building allocation is inferred. Currency and estimate/recorded basis stay separate; missing differs from zero. Financial read requires policy, config gate and financial-capable persona. Ambiguous multiple linked tickets remain queue facts, not invented one-to-one links. All coverage is explicitly fictional, not verified college facts.

Pin binds workspace/revision/full graph digest/selection/snapshot/policy/capability/version/build/source. `assertFacts` performs fresh authority/projection and strict pin equality before/after provider boundaries. `statusFacts` rechecks current grant and returns only `{matches:false}` for changed evidence; grant loss still denies. Full global revision/digest invalidates history even when an unselected department changed. No facts call seeds, mutates a record or touches a budget.

Full graph cap256KiB:8 departments,100 buildings,100 projects,200 tasks,200 tickets; combined people+contractors≤50; combined plans+costs≤100. IDs are closed UUIDs except fictional people IDs. Exact foreign keys/department linkage, nonnegative integer minor units, bounded currency/basis and nullable server-recorded timestamps are validated. No client timestamps are invented. AI retains24KiB/32source/64task and nested evidence bounds from reviewed schema2. If current selection exceeds them, AI refuses422 with a smaller-selection instruction; UI graph is not truncated. A future finer project selector is needed if a single department itself exceeds32 sources.

## Retention and shutdown

Per-workspace command cap1000 is SQL-counted under the tenant lock; exceeding it rolls back graph mutation. Receipt/audit rows contain only bounded IDs/action/digest/revision, not command text. They remain content-free tombstones to prevent duplicate replay/reset. The graph itself is removed by the owner-only bounded `pruneExpired` library after descriptor/version expiry or actual revocation. It processes at most100 graph rows per call with an explicit UUID cursor, scrubs encrypted graph content to null, preserves immutable initialization/binding/receipt evidence and marks the aggregate expired. Repeating cleanup is safe. Workspace/CRUD gates can be false during cleanup; real current owner authority and exact owner silo/anchor are still mandatory. Unknown metadata fails closed.

**No scheduler/cron/cleanup executor is installed here.** Before any shared-mode activation, root must provide/review an authenticated operational cleanup entry, install a bounded schedule and observe erasure/deactivation behavior. Expiry blocks recipient access immediately; it does not itself prove physical erasure. Without that acceptance, retention readiness remains incomplete.

## Verification and remaining gates

Focused tests use the actual ProposalService publication and password unlock, actual synthetic ClientSilo/anchor/encrypted record store and independent Laravel sessions, with AI/Trial off. SQLite proves persisted functional behavior, not MySQL row-lock concurrency. Canonical Node vectors include `{}` versus rejected wire`[]`, arrays, Unicode, slash and line terminators. No provider calls, keys or native identity/permission bridge are tested or enabled.

The integrated source includes the routes/config/source manifest, fixed outer boundary, one shared UI transport, captured form pins, exact unknown-edit recovery, server-fact AI admission and default-off publication boot. List, assignment wizard, Roll Call, building links, scoped people/contractors, official-map references and work-month views use the same stored graph. Current scoped people provide project task display names; an unavailable name retains its assigned identity and cannot become a false Unassigned label. No unsupported native API or local sample fallback is admitted. A fresh pinned fixture contains20 projects,21 tasks,25 tickets,10 workers plus manager,5 contractors and25 buildings; it is not recovery or replacement of live browser edits.

`scripts/proposals/reviewer-workspace-browser.mjs` runs real loopback HTTPS/Enterprise HTTP with independent password-unlocked browser sessions. Default core checks persist/assign/reload work and reject CSRF/binding/runtime-marker/revocation failures with no extra accounts, memberships or budget rows. `PROPOSAL_WORKSPACE_SCENARIO=directory` selects the exact pinned official fictional map fixture and tests project/person/department controls, month/reload, map markers, Center/Side detail, building-linked tickets/prefilled intake and people/calendar navigation. Each scenario retains real route limits with2150ms pacing; applicable harnesses remain sequential in each engine lane. Its explicit local in-memory provisioning→private SQLite file/marker composition is a test seam, not an approved hosted setup procedure.

The separate `reviewer-workspace-ai-browser.mjs` adds one explicitly fictional existing campaign ledger and exact-Version approval in its disposable runtime. Only the Responses provider transport is fake. Actual facts/preview/confirm/status, opaque follow-ups, independent receipt404, other-client graph-change rejection, one ledger and unchanged account/membership counts are asserted. Original AI-off checks remain separate. This proves local HTTP/browser behavior, not hosted/provider, physical Safari/mobile or microphone acceptance.

`ReviewerWorkspaceMySqlConcurrencyTest` is registered immediately after the existing provisioning test cleanup in the native disposable MySQL lane. It refuses a nonempty inventory, uses independent processes and observed row-lock waits, and tests conflict, once-only replay, status ownership and current-read revocation. Without the existing approved loopback MySQL service it explicitly skips; that is not passing concurrency evidence. Current source verification still requires real MySQL results and normal final-candidate CI/review.

Remaining gates include preserving current twenty-job edits/plans/comments/access through an explicit immutable-edition transition, reviewed operational cleanup scheduling/erasure and hosted acceptance. Comments/uploads/gallery and resource/workflow parity have separate source stages; their absence must not be described as a complete shared workspace. No source test authorizes runtime initialization, a new publication or provider/scanner activation.

## Integrated file/detail and source-boundary milestone

Comments have actual local two-engine HTTP persistence/access acceptance. The
separate upload/image source has real-application admission/storage tests and
intercepted UI checks; scanner/version evidence is fake, and final mounted file
acceptance is still pending. Target-free `publicationDescriptor` admission works
before the first ticket exists and advertises only fixed private endpoints. Each
subsequent file/image request still resolves the exact current target and grant.
No first/sample record or native account is fabricated to bootstrap the controls.

The centered/side detail keeps the same active DOM. Thread change/close retires
file/image IO and selected byte references while the exact unknown upload intent
remains recoverable. Original-work navigation reads the current authorized shared
graph before choosing the actual ticket/project/task parent. Fresh saved-name
reads have independent ordering fences so a delayed response cannot replace a
newer expectation; the server still checks every upload's expected saved name.

JPEG/PNG private images require approved actual scanner/config/source evidence,
exact stored-byte identity, current publication/target/revision and scan expiry.
Quarantine alone confers no read access. General PDF retrieval remains unavailable
until its separate reviewed stage is integrated. No scanner/operator/scheduler is
installed or activated by these source components.

The publication source manifest retains strict exact-current admission and a
fixed256-source/20MB-per-file cap. It includes the image route/private partial,
actual scanner/config, all nested Enterprise partials and the inlined tour
wordmark; source dependency growth and byte limits still fail closed. Legacy v5
compatibility is a separately pinned immutable renderer. A feature deployment
requires explicit old-version allowlisting and a fresh edition; never silently
replace the current registry or attach new fields to an existing publication.


### PDF attachment source integration (October 24)

A separately admitted immutable `review_workspace_downloads` feature can expose
private PDF metadata and explicit original-byte downloads on the same current
work thread and adapter. Its independent runtime gates default off. Reads are
exact-target/current-grant POSTs with private, identity, no-transform envelopes;
binary responses are octet-stream attachments, never inline documents or public
URLs. Files remain held until the approved scanner and authentic fresh matching
engine/definition evidence admit them. Rejected PDF hashes remain rejected in
the exact immutable workspace across cancel/re-upload/name/browser changes.
Shared image/PDF scan leases prevent original deletion during active claims.

Independent source review and the combined local suite pass:290 native tests/
4982 assertions and278 JavaScript tests, no skips. These are localPHP8.5/SQLite
and VM contracts; scanner results are fake. Local mounted image/file acceptance
passed8 cases per engine; local PDF browser download checks pass9 cases per engine including390x844
viewports. Chromium uses retained pre-NFC evidence plus independently verified
canonical filename predicate equivalence; WebKit ran the corrected full script.
Both engines must still run in final exact-head CI. The fixed source closure covers224 entries within its256 bound;
strict current-edition admission is unchanged. Existing v5 remains untouched.
No runtime values, scanner/operator invocation, retention scheduler, provider,
feature merge or deployment is authorized by this source milestone. See
[PDF download contract](enterprise-reviewer-pdf-downloads.md).
