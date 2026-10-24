# Explicitly delegated recurring fictional-workspace cleanup

This is a default-off source mechanism. No runtime policy, security delegation, configuration or scheduler job is approved or installed by this change. It erases only expired/revoked published fictional graph and shared text comments in an explicit finite scope. Existing feedback, files/quarantine, AI receipts, spend/ledgers, native work, identities and memberships are outside scope.

## Future approval packet

A separate human approval must bind the final reviewed release/source/composer/build, actual owner runtime and UID/GID, current existing authorizing owner/admin and active verified membership, ClientSilo/anchor/context, actual authoritative MySQL8.4 schema/server UUID and up to10 exact Version/content-hash/workspace pairs. No baseline data or inferred current-data preservation proof substitutes for that observation. The policy must name absolute valid_from/expires_at, kinds graph/comments, page limit1..25 and run deadline1..60 seconds. No key or password belongs in this nonsecret policy.

Only after that NEW approval, an operator could install the exact reviewed bytes in the fixed private owner-root `reviewer-workspace-cleanup.json` (regular0600, one link, actual process UID/GID; root0700), then separately install the exact SHA256 in `SERVICE_PROPOSALS_WORKSPACE_CLEANUP_PINS_SHA256` and explicit `SERVICE_PROPOSALS_WORKSPACE_SCHEDULED_CLEANUP_ENABLED=true`. These values do not enable workspace CRUD, publish, AI, email, files or providers. The runtime assembly must remain `/enterprise-owner` with purpose proposal_owner. There is no installer, approval generator, supplied-policy CLI, bearer token or service User.

The fixed command is `proposals:prune-scheduled-workspace`, with no actor/path/Version/kind/limit options. Normal Artisan bootstrap occurs first; missing approval refuses before this command's own DB/content work, not before framework bootstrap. Error output is closed JSON. A future exact invocation needs observed PHP/release/runtime selectors and a bounded external process timeout; this code does not claim to interrupt an uninterruptible PDO/network call.

## Exclusion and current authority

One stable64hex application/schema/context/purpose GET_LOCK key excludes cooperating invocations across policy rotation on a single authoritative mysqld. It is acquired once on the same native PDO connection and retained across separate graph/comments page transactions; a reconnect, different owner or isolation fails. MySQL documents session-bound locks and retention across commit/rollback at https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html . This is not NDB/multi-primary exclusion.

Actual REPEATABLE-READ is required, never silently set or relaxed. Under existing tenant serialization, fresh User and tenant_user locking reads precede bounded current silo/tenant singleton inventories, exact anchor plus an all-tenant expected-empty area-membership predicate (LIMIT1 current locking read), sorted Version locks, cursor and finite content-row locks. The full membership range must be empty even if a malformed row claims another tenant. Existing indexes can cause physically broader locks; exact MySQL/host behavior remains acceptance work. Repeated fences use current locked rows and the existing ClientSilo binding/marker comparisons, not an earlier consistent-read snapshot.

A page validates all selected encrypted bindings before erasing. It rechecks current source/context/owner/membership/Versions, exact held connection, absolute policy expiry and monotonic run deadline immediately before commit, including after model events. Revoked or expired target Versions are eligible; loss of the authorizing account/membership denies. Ordinary manual HTTP cleanup retains its existing real-owner contract.

## Two durable cursors and policy changes

One page per admitted kind, fixed graph then comments, at most25 records plus a sentinel each. SQL filters exact tenant/anchor/kind and approved graph IDs or comment titles before limit and decryption. Content binding is authoritative; titles alone are not. At most two purpose/context/kind-bound metadata records retain after UUID/null, policy/scope SHA and one overwritten content-free last-page outcome. They contain no graph/comments/tokens or growing run history. Zero-erasure pages advance and terminal/empty pages wrap to null so earlier UUIDs and formerly active rows are revisited.

`previous_policy_sha256` is exactly `{graph:hash|null,comments:hash|null}`. A missing cursor requires that kind's null; nonnull plus missing cursor HOLDs. Existing already-current policy requires the exact scope. On rotation each kind must match its own explicitly reviewed observed predecessor, never its sibling. Same scope preserves progress; changed scope starts at null only after exact predecessor admission. This permits honest recovery after partial completion without inventing reset authority. Unknown/malformed lineage holds.

Graph scrub is graph=null/status expired; comments scrub is empty comments/status expired. Immutable identities/bindings remain tombstones. An erasure audit uses the actual existing authorizing User FK but encrypted actor_type `approved_schedule`, policy SHA and run UUID; it never claims a person clicked an HTTP action. Active content and zero-erasure scans produce no new erasure audit.

## Outcomes, disable and later hosted acceptance

Each kind transaction is atomic with its cursor/audit. `completed` requires both admitted pages and verified release. `partial_or_unknown` preserves a prior committed page; `unavailable_or_unknown` never asserts no mutation after a lost response. `busy` is no work. Do not automatically retry unknown outcomes, delete cursors or reset tombstones. Inspect durable metadata and current approved state before another authorized invocation.

Disabling cached configuration affects subsequent admissions, not an already admitted invocation. The live owner/membership and absolute expiry/deadline fences remain active. A future separately approved rotation must inspect per-kind digests; an expiry or cursor disappearance cannot be repaired by guessing new predecessors.

Before scheduling, inspect existing Forge jobs, install/read back only one exact approved job with nonoverlap and process timeout, and verify execution, disable, expiry, zero-page wrap and actual expired-only erasure across cycles. No job is installed here. Source tests: unchanged manual maintenance cases plus pure/parser/CLI refusal pass locally. Mutating scheduled paths require the existing strict disposable MySQL8.4 CI gate and are NOT RUN locally; there is no SQLite/fake-principal or fake advisory-lock acceptance. CI success itself would still not grant runtime authority or constitute hosted acceptance.
