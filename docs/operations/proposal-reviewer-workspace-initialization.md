# Real-owner workspace initialization — source only

## Explicit new fictional baseline, separate from recovery

The closed approval `data_basis` accepts exactly `reviewed_current_edits` or
`reviewed_new_fictional_baseline`. The first retains its existing capture and
reconciliation prerequisites. The second means a newly reviewed fictional graph,
not recovered browser edits or imported campus data. Never relabel a fresh source
seed as recovery. Existing v5, links, passwords, comments, archive and browser state
remain untouched; no absence of private edits is inferred.

For the new basis, the compatibility wire field `import_evidence_sha256` is an
opaque digest of the actually reviewed new-baseline provenance artifact. Its legacy
name does not claim capture/import evidence. Review the graph and provenance first;
never fabricate a digest to stand in for that review. The basis is part of the
closed policy and approval digest, so existing signed consent and intent binding
cannot be reused under a different basis. No new client input fields or authority
are accepted. The server checks these exact hashes, not the truth of a claimed
human review; the actual artifact and graph review remain separate prerequisites. Unknown basis values still refuse.

Both paths retain the same exact source, owner/silo/anchor, seed, predecessor,
distinct prepared draft/new publication/workspace, descriptor, expiry, consent,
idempotency and current ACL requirements. There is no reset, migration fallback,
old-edition relabel, automatic publication or provider activation. Source support
alone does not approve a specific graph or runtime action. The steps below about
capturing actual current edits apply only to `reviewed_current_edits`; a separately
reviewed new baseline uses its explicit new-artifact provenance instead. Any later
publication and initialization remain separate real-owner actions.

This default-off owner HTTP stage initializes one already-published future immutable fictional workspace. It does not publish, attach descriptors, capture/import current browser state, create a silo/anchor/User/membership/ledger, enable AI, stage credentials/configuration, install retention or send anything. Existing v5 access and feedback stay on their original Version. Files/images/PDF/scanning remain separate and off. No runtime values are installed by this source.

## Restrictive data approval and prerequisites

An actual currently active verified owner/admin and fresh active owner-local membership are mandatory. Data JSON, a preview signature, caller actor/tenant fields or a fictional reviewer label never supplies this authority. The operator uses the existing real owner login/session, same-origin JSON and CSRF. No CLI impersonation entry is supplied.

For `reviewed_current_edits`, before considering this entry, independently capture/reconcile actual current edits from the relevant legitimate browsers and separate persisted stores. Preserve IDs, deletions, ordering, assignment/building links and canonical plans, and document conflicts explicitly. The fresh twenty-job source seed is not recovery. This stage checks the exact approved closed graph and evidence digest; it does not inspect the contents of a browser capture or establish that an operator actually reviewed it. That review, supported capture/reconciliation utility, trusted draft descriptor assembly and a new immutable publication remain separate prerequisites. Do not label an arbitrary JSON graph `reviewed_current_edits` and treat that string as evidence.

The trusted owner runtime may later receive `SERVICE_PROPOSALS_REVIEWER_WORKSPACE_INITIALIZATION_APPROVALS_JSON`: a maximum24KiB JSON object with at most10 SHA256-keyed records. Each key is the actual `ReviewerWorkspaceSchema::digest(record)`. Every record has exactly:

```
schema_version: 1
data_basis: "reviewed_current_edits" | "reviewed_new_fictional_baseline"
version_id, version_hash, build_id, source_hash
workspace_id, descriptor_sha256, seed_sha256, import_evidence_sha256
predecessor_version_id, predecessor_version_hash
expires_at: exact UTC timestamp
```

UUIDs and hashes are closed typed values. No native identity, approval credential or arbitrary path appears. The predecessor and new Version must differ, belong to this real owner tenant, remain active/unexpired and have actual payload hashes equal to their pinned content hashes. The future Version must pass strict current source/build/manifest admission and cannot be legacy-registered. Its immutable workspace ID/descriptor/seed hashes must match, and data-approval expiry cannot exceed descriptor expiry. The original predecessor is read/locked only; existing feedback, password/token/grants and payload are never rewritten. Original old-edition renderer/runtime compatibility is still separately necessary for deployment.

This metadata is effective only alongside the existing valid workspace approval, exact `proposal_owner` purpose and one configured owner-local tenant. Missing/invalid/empty metadata or nonliteral boolean configuration leaves the new operational gate off. `SERVICE_PROPOSALS_REVIEWER_WORKSPACE_OWNER_INITIALIZATION_ENABLED` defaults false. Ordinary workspace availability/marked anchor/current policy are still required. Apply additionally requires existing proposal writes and `reviewer_workspace.initialization_enabled`; status can read its receipt with those write gates off. Keeping the new owner initialization gate off closes all three endpoints; it does not claim recovery or erase anything.

## Closed HTTP contract

Only POST under:

`/enterprise-owner/owner-workspaces/{workspace}/proposals/reviewer-initialization/{preview|apply|status}`

Existing auth/verified/runtime middleware, pre-session `block(10,10)`, six requests/minute, exact Origin, same-origin Fetch metadata, JSON Content-Type, no query, real X-CSRF-TOKEN and maximum300KiB body apply. Closed seed graph retains its existing256KiB cap and FK/typed field bounds. Unknown fields, lists in place of the root/seed/preview object and provider URLs/actor IDs refuse. Middleware and controller errors are private sanitized JSON with no raw diagnostic or seed content. Existing mounted GET fallback returns404; it remains private and does not add a GET action.

Preview body has exactly `{version_id,request_id,approval_sha256,import_evidence_sha256,seed}`. Request ID is an explicit UUID chosen once for this initialization intent. Seed is the reviewed graph, not native SQL data. The server returns `{status:'preview',mutated:false,observation,preview:{claims,signature}}`. Claims are bounded content-free owner/session/context/Version/descriptor/data/request pins, counts, advisory observed state, issued time and at most120-second expiry. Existing application encryption-key material is used only in memory with a distinct HMAC purpose; no new credential, key response or secret file is created. Claims/signature do not replace fresh actual-owner authority. A new authenticated session requires a new preview; no raw session ID is returned. Preview performs locking reads inside a transaction but creates no graph, lock-row, audit or receipt content.

Apply body is the exact preview body plus `preview`. It verifies signature/closed pins/session/expiry, serializes under the existing tenant transaction and takes current locking User/membership and Version reads immediately before the existing native initializer. There is no external IO between authority and initialization. Exact approval/expiry/current owner/Version/context are rechecked before outer commit. The nested native initializer retains one `initial_request_id`/`initial_digest`, initial graph and initialization audit. There is no new duplicate receipt table or graph copy. Same original intent returns its original receipt with `replayed:true`, even if ordinary shared users have since edited the graph; no data is reset. Different intent/actor/binding cannot reseed it. Success is `{status:'initialized',settled:true,receipt:{request_id,digest,version_id,workspace_id},replayed}`; receipt is content-free and does not present the graph's current revision as an original commit revision.

Status body is exactly `{version_id,request_id,approval_sha256}`. It resolves that original native initialization digest for the current real owner, without seed upload, preview, initialize call, audit or graph mutation. Data approval and preview expiry do not prevent recovery of the original receipt; current owner, exact approved metadata/current Version/descriptor/anchor remain required. A matching receipt is `{status:'initialized',settled:true,receipt}`. Absence is only `{status:'not_observed',settled:false}`: a delayed apply may still win. Admission failure, malformed state, expiry/revocation, 5xx or lost HTTP output never prove non-application. Controller errors use `{error:'workspace_initialization_unavailable',automatic_retry:false,settled:false}`; outer middleware failures may use its existing fixed unavailable envelope and are likewise not settled. Do not invent a new request ID, reset state or automatically resend. Explicit original-intent recovery must precede any next action.

## Verification and limitations

New focused tests exercise actual isolated mounted owner HTTP, native publication/CSRF/encrypted workspace storage, signed session-bound previews, exact replay after later user edits, receipt recovery after TTL/write-gate changes, foreign/stale/legacy/source/anchor/payload refusals, inactive/unverified cached User and membership denial, fixed private debug/method/CSRF failures, and in-transaction late membership rollback. The late mutation is an explicit local SQLite event seam, not another-process concurrency proof. Independent configuration subprocesses prove literal flags and exact metadata cannot activate AI/workspace writes or legacy admission. All ordinary native/customer/old Version/budget records remain unchanged in positive initialization checks. Source tests use only synthetic data, empty ENV and private dependencies; stray provider HTTP refuses.

Target PHP8.4, actual MySQL row-lock/current-read/concurrent apply versus revocation, exact final-candidate CI/independent review, secure host/config authority, current-data capture/preservation, actual publication transition, retention installation and hosted owner/recipient acceptance remain unproven here. No application CLI, runtime initializer or production action is authorized by this packet. Root remains sole integration/release writer.

## Required real MySQL gate

`ReviewerWorkspaceInitializationMySqlConcurrencyTest` is registered immediately
after the existing workspace MySQL gate, with its own closed initializer worker
and the unchanged existing loopback-only bootstrap guard. It requires clean owned
CI state, actual independent connections and observed waits on the parent-held
User/membership/tenant rows. Same-intent concurrent apply, unsettled status, current
revocation refusal and later-edit/no-reset checks preserve the original feedback,
anchor, native authority and budget. Cleanup removes only the exact synthetic
fixture; it never clears foreign rows to make a test pass.

Only the local refusal guard and syntax/style checks have run. Actual MySQL 8.4
concurrency remains NOT RUN until normal exact-head CI; PHP 8.5/SQLite mounted
checks do not substitute for it. No server is started or provisioned by this test.
