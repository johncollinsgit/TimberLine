# Reviewed future workspace draft preparation

This default-off owner-only mechanism prepares a **new private draft**. It does not publish, capture browser state, import/initialize a workspace, provision an anchor, copy passwords/access grants/comments, enable AI or write a campaign ledger. Existing editions and their data remain unchanged.

Preparation requires a separately reviewed current-edit preservation packet. The legacy sample sessionStorage key is not an immutable Version binding. Preserve the raw current browser state before upgrades; reconcile it with the intended edition and separately retained native workspace/comments/names/selections. A clean source exporter is not recovery. This code does not implement that capture or establish that a claimed hash corresponds to observed data.

## Restrictive server metadata

`SERVICE_PROPOSALS_REVIEWER_WORKSPACE_PREPARATION_APPROVALS_JSON` is a closed JSON object keyed by each row's canonical SHA256, at most ten rows/24,000 bytes. Every row has precisely:

- `schema_version:1`, `data_basis:"reviewed_current_edits"`
- `source_draft_id`, integer `source_draft_revision`, exact complete source `source_draft_sha256`
- a fixed **new** `prepared_draft_id`
- exact `predecessor_version_id` and `predecessor_version_hash`
- reviewed `seed_sha256` and `import_evidence_sha256`
- the existing closed `workspace_descriptor`, optional/null `collaboration_descriptor`
- exact current `build_id` and `source_hash`, UTC `expires_at`

Only approved owner-runtime workspace/comment policies and the pre-existing explicitly marked private anchor are accepted. No input can supply a policy, seed, source path, actor identity, or permission. The new workspace UUID must differ from any predecessor workspace UUID. No images/files/download/AI descriptors are assembled by this stage. Metadata remains loaded while the independent literal boolean `SERVICE_PROPOSALS_REVIEWER_WORKSPACE_OWNER_PREPARATION_ENABLED` is false, so prepared drafts remain classified as restricted. These settings do not enable any other flag. Missing/invalid configuration refuses.

## Actual owner HTTP contract

All operations use the authenticated, verified owner's existing private mounted session and current active owner/admin membership. Paths are under `/enterprise-owner/owner-workspaces/{workspace}/proposals/reviewer-preparation/{preview|apply|status}`. POST only, same origin, session CSRF header, closed JSON, no query, at most8KiB, six requests/minute and session blocking(10,10). Errors are private sanitized JSON and do not prove that a delayed write did not occur.

- `preview` input `{approval_sha256,request_id,import_evidence_sha256}`. Read-only: returns the exact descriptor additions, source hash, import evidence binding and actor/session/context-bound signed consent, at most120seconds. It does not transmit a seed or initialize data.
- `apply` adds the exact original `preview`. In one transaction it locks current actual user/membership, predecessor Version, source Draft, then the fixed prepared Draft. It creates the encrypted clone once, preserving commercial payload bytes/values plus only approved descriptors and a private preparation marker. Source edits/expiry/current-authority drift refuse new preparation. Final checks remain inside the transaction. An exact original replay returns the existing receipt, never resets or creates a second draft; a different intent colliding with that UUID refuses. Original consent is required; its expiry does not erase an already-applied receipt. An expired consent cannot create an absent clone.
- `status` input `{approval_sha256,request_id}`. Same fresh real-owner/current-release/anchor/immutable-payload checks. It may recover the original content-free receipt after preparation consent/data approval expiry or preparation gate-off/source commercial edits. `publishable:false` indicates that new publication is unavailable. `not_observed,settled:false` is not a definitive rejection. No automatic new request ID or baseline fallback.

The immutable receipt is embedded in the encrypted prepared Draft marker; no additional ledger/audit kind is created. Keep the exact request ID and signed preview until resolution. The runtime metadata must retain the original approval for recovery. No reset/delete/garbage-collection endpoint is included.

## Separate explicit publication

Prepared drafts are read-only to the existing save API even when the preparation gate is off. The owner editor hides the save form and directs editing to the original proposal via the manager. Edit the original, obtain a **new reviewed preparation/UUID**, and explicitly prepare it; never strip descriptors by saving the prepared clone.

Normal HTML publication remains separate, with actual current owner Request, the real publish route, same-origin POST, hidden `_token` **or** header CSRF, recipient password, audience, lifetime, confirmation and publication request key. Prepared drafts require reviewer audience. Source/config/current owner and membership are locked and rechecked in the same outer transaction, including late creation checks. Constructing a Request or calling the service without the actual container Request cannot publish a prepared draft. The marker is removed before the Version hash; descriptors are part of the immutable recipient payload. No password, token, grant or comment is copied from the predecessor.

New publication requires every included workspace/comment descriptor to remain unexpired and its fixed expiry to be no later than the exact newly persisted Version expiry. A shorter requested lifetime is rejected before creating a Version; equality is accepted. Expired optional comments also make advisory `publishable:false` and refuse new preview/apply/publication, while original clone/publication receipt recovery remains available. Neither a descriptor nor the requested lifetime is silently extended.

Each prepared draft admits one publication intent. The original publication key/password/details recover the original Version/token after preparation gate-off/short approval expiry or later commercial source edit, subject to fresh owner/current binding and unexpired/unrevoked original publication. It never extends expiry or produces another Version. A second publication key refuses; normal unprepared draft publication behavior remains unchanged. General proposal enabled/write/publication gates still apply. A source release change is not replay authority for another release.

## Verification limits and remaining prerequisites

Focused local tests use synthetic accounts, real mounted kernel/HTML CSRF, actual private ClientSilo marker, SQLite and denied provider transport. They are not targetPHP8.4, MySQL locking/concurrency, actual operator/browser capture, hosted publication or runtime acceptance. Current-row locks are implemented; independent real MySQL process proof and normal exact-head CI remain mandatory for a production candidate. No runtime settings or existing edition were changed here.

Before a real transition: capture/reconcile current edits; review seed/import proof and actual legacy content-hash compatibility packet; freeze/review release and policies; observe current owner/silo/anchor; approve the exact future publication/runtime packet; prepare via actual owner, separately publish with a new password; separately initialize using the original reviewed initializer intent; verify cross-session edits/comments/access and install/observe authorized bounded cleanup. AI/key/scheduler activation are separate held phases. This library cannot substitute for those prerequisites.
