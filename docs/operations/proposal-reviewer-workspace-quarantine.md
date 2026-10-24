# Private shared workspace attachments — quarantined source stage2

This packet is durable upload groundwork for the private fictional review workspace.
It is source-only, default-off and separate from the reviewed comments stage. It
has no scanner transport, binary read route, thumbnail, download or gallery. Its
client and every file response explicitly say preview/download unavailable and
scan not admitted. It must not be presented as completed photo sharing. Root owns
UI integration, normal CI and any future publication/runtime transition.

No native User/Team grant, old trial token, ordinary retail table, AI ledger,
provider, global mail configuration, scanner or paid infrastructure is used.
Names are existing self-selected review labels. They are checked at reservation;
a name is never treated as a verified employee or receipt owner. One server-issued
session capability owns a reservation, independent of later session-ID rotation.
Other legitimately unlocked computers/phones can read permitted shared metadata;
they cannot mutate or claim another capability's reservation. No physical-machine
allowlist is added.

## Explicit future-edition admission

The existing workspace descriptor, exact current private publication/unlock,
server policy, selected persona/areas/person and real current projector all remain
mandatory. The comments descriptor is required but remains uploads_enabled:false;
comments write approval is never inherited as upload approval.

A separate immutable Version descriptor must be explicitly admitted:

```
review_workspace_attachments = {
  schema_version: 1,
  policy_sha256: <exact approved canonical policy digest>,
  expires_at: <UTC, no later than collaboration/workspace/publication>
}
```

Its exact server-side policy has schema_version:1, uploads_enabled:true,
metadata_read_enabled:true, scan_enabled:false, and write_personas selected only
from demo_manager/manager/worker/requester. Unknown keys, a missing policy, hash
mismatch or scan_enabled:true refuse; there is no future scanner fallback in this
stage. Root's small configuration hook defaults attachments.enabled:false,
write_enabled:false and policies:[] with no environment key or provider endpoint.
This source packet creates no actual descriptor, policy, runtime, publication,
account, name, storage binding or database.

Upload writers additionally require the current workspace editor/write gates and
active visible target. Worker writes are limited to assigned tasks or owned/
assigned tickets; requester writes are limited to their own tickets. Executive/
viewer and worker project writes refuse. Current raw graph FKs check archived
parent projects even when a requester projection redacts that FK. No title match,
hidden/native discussion task or synthesized employee permission is introduced.
Read-only current target access and the exact uploader capability allow cancelling
one's own upload, including after its project archives. Expired/revoked grants
cannot read metadata or mutate; only the real owner cleanup service can then erase.

## Persistent state and bounded bytes

New encrypted ent_proposal_ws_upload records are isolated to the verified owner
anchor/tenant. Their envelopes bind context, Version/content/build/source hashes,
workspace/feature descriptors and exact target kind/ID. Every write uses the
existing tenant lock then current publication locking read. Upload intent is
reserved before filesystem IO. Generic row titles and encrypted audit events
contain no filename, bytes, comments or chosen-name content.

Closed operations are show, begin, status, chunk, complete and cancel. Begin
checks expected current target version/name and stores the exact command digest.
A repeated request UUID/digest returns its existing reservation. Changed input
refuses. The same capability/target/content digest cannot replace an uncertain
reservation through a new UUID or filename. Status is read-only, ownership-bound
and never dispatches/creates. not_observed means unresolved; it does not discard an
unknown reservation. Confirmed cancellation is required before a replacement.

Bounds: JPEG/PNG/PDF only; filename120 characters with no controls/path separators;
5MiB per file;256KiB chunks, at most20; six live files per exact target; three
unfinished uploading/assembling reservations per capability;50MiB logical declared
bytes per workspace;512 total upload records in the owner anchor, including bounded
receipt tombstones. Quarantined, cleanup_pending and failed/incomplete reservations
retain their allocation. Quotas are checked before new reservation bytes and are
never increased by changing a request UUID or demo budget.

The dedicated proposal-review-workspace namespace uses the actual isolated
team_private local/private/serve:false disk. It never derives a path from a
filename, URL or caller tenant ID. Each part is authenticated/encrypted with its
binding digest, upload ID, part name, size and SHA. Reads verify the entire
cryptographic envelope. A missing read creates no directory. Symlink paths refuse.
Atomic0600 temporary ciphertext+rename and one namespace file mutex enforce a64MiB
physical bound including encryption, old copies and temporary files. This physical
cap can be reached before the logical50MiB cap, especially while chunks plus the
assembled original coexist. It is intentionally not an assurance that50MiB of
uploads will fit. No filesystem IO is held inside a database transaction.

Each IO operation durably reserves a90-second claim. A live claim refuses
concurrent work. After an uncertain/crashed claim expires, only the same operation/
index/SHA can repair it; changed content cannot overwrite the unknown intent.
Actual current authority, exact workspace/target versions and claim generation/
expiry are rechecked after IO before metadata commit. Chunk retries use the same
bytes/hash and never duplicate metadata. A changed target version requires keeping
or cancelling the original upload; this stage does not silently rebase it.

Complete reassembles every current encrypted chunk, verifies index/size/SHA and
full declared size/SHA, actual finfo MIME and allowed extension. JPEG/PNG headers
are bounded to16million pixels before genuine GD decode; missing GD fails closed,
and decoded dimensions must match. PDF requires a PDF header but is never rendered.
The verified original remains encrypted. Completion commits quarantined only; it
never marks clean/ready. Repeating completion returns the persisted quarantine and
does not scan. Encrypted chunks are retained until explicit cancel/expiry cleanup,
so a slow old IO operation cannot race chunk deletion. Corrupt/unsupported input
stays held in assembling under its lease and can be explicitly cancelled; no false
Saved/Ready result or silent truncation is returned.

Cancel first commits cleanup_pending, performs namespace deletion outside the DB
lock, then rechecks current authority/claim and commits cancelled. A durable,
content-free .retired fence remains in the bounded upload directory, preventing a
late old writer from recreating cancelled bytes. Deletion failure retains the
allocation and cleanup_pending state, rather than reporting deletion or allowing
an uncertain replacement. No public storage URL is created.

## Source client and root hooks

workspace-upload-client.js exposes EverbranchWorkspaceUploads.create(boot, shared)
with the ONE existing shared adapter. Root must supply a separate trusted
reviewerAttachments descriptor, generated by AttachmentsAccess.clientDescriptor
for a current visible target. Do not add fields to the closed reviewerWorkspace
boot object. The descriptor has the same workspace/capability/binding/expiry/CSRF
fence and only the six fixed sibling /workspace/files endpoints. Foreign/native,
query or redirect targets refuse. Same-origin POST/JSON/cookies/CSRF/no-store is
mandatory; JSON request bodies are capped384KiB to accommodate the bounded chunk,
and real response streams are capped128KiB. Existing small AI JSON limits remain
unchanged. New routes preserve block(10,10), and a narrow outer-boundary hook
sanitizes pre-controller method/runtime/CSRF/throttle errors without bypassing any
guard or changing unrelated paths.

Client API:

- show(target) reads only current persisted metadata; it caches no saved files.
- prepare(view, File, {request_id, expected_reviewer_name}) hashes a bounded local
  file and captures the current form. It does not reserve or upload anything.
- begin(prepared) durably stores the intended reservation metadata before IO.
  Explicitly calling begin with the same prepared intent can retry its original
  reservation; another prepared intent cannot replace it.
- Open the original target with show(target) before recovering or resuming an
  existing intent. Another active target cannot claim that form.
- recover() checks only the original reservation/digest. It never redispatches.
  A verified quarantined/cancelled/expired final state retires that pending intent.
- resume(prepared, File) explicitly verifies the same file's name/MIME/size/SHA,
  reads current received chunks, sends only missing exact chunks and completes.
  After a reload the reviewer must reselect the same file. Local storage contains
  bounded pending metadata only, never bytes, saved comments/files or AI history.
- cancel() explicitly retires the original owned reservation after confirmed
  server cancellation. pending() returns its metadata for recovery.

The client fences target and selection generations, including away-and-back, expiry,
destroy and late responses. Scope loss aborts requests and clears retained File
references. A verified late final result may settle only its matching reservation
and returns a content-free receipt with stale_context; old filename/file metadata
cannot reappear in a new screen. Unknown intent stays pending. Storage cleanup
failure after a verified result yields storage_warning while preserving that
result, with idempotent server recovery after remount. Mount/reload never sends
an upload automatically. Root must not create another adapter on Center/Side
placement changes or discard its composer/draft/selection/focus/scroll state.

This packet has no DOM composer, camera/photo picker UI, native gallery mutation,
image viewer, zoom/next/previous/swipe or actual mobile/browser acceptance. Root
owns those integrations and must display Held for scanning without a clickable
preview/download action. File selection and pending metadata preservation are
source capabilities, not proof that a browser retains a File after navigation.

## Real owner retention and scanner gap

ReviewerWorkspaceFilesPruner.prune is a bounded real-current-owner service,
limit25/cursor, dryRun:true by default, with no recipient route/CLI/scheduler or
operator impersonation. It validates tenant/anchor/context and exact immutable
Version/build/feature/workspace bindings. After expiry/revocation it commits an
erasure claim, deletes only this upload namespace outside every DB lock, and
clears filename/chunks after fresh current-owner/claim verification. Bounded
content-free replay identifiers and retirement fences remain. With feature flags
off the real owner can still clean expired bytes. Failure remains cleanup_pending
and counts failed; no false erasure receipt. No live cleanup runs in this task.

A usable gallery of newly uploaded photos remains blocked on a separately reviewed
scan/ready state transition, exact current clean result/hash/config/claim binding,
ready-only download/preview endpoint and gallery integration. That future packet
must use the existing isolated Enterprise overlay names ENTERPRISE_FILE_SCANNER_ENABLED,
ENTERPRISE_CLAMD_HOST and ENTERPRISE_CLAMD_PORT; no generic HOST/PORT or native/
retail scanner grant is inherited. Activation/daemon/credentials require their
own approval. A scan result must never be fabricated from MIME, decoder success,
a fixture, file extension, completion or scanner availability. Existing native
clean-ready galleries do not grant this private reviewer access.

Verification uses actual isolated SQLite owner runtime and private filesystem
with explicit fictional future publication/unlock/role setup and stray HTTP
forbidden. It covers encrypted persistence, distinct sessions, request recovery,
wrong-scope/capability/archived-parent/target-version/refusal, full/hash/type/decode/
bounds, chunk crash repair, quota reservations, symlink/cipher rejection, large
bounded JSON, cancellation fences and real-owner expiry cleanup/failure. VM tests
use synthetic file/HTTP/storage objects; no real scanner is called. These tests
do not establish MySQL concurrency, targetPHP8.4, CI, hosted persistence, physical
phone file/camera behavior, real scanner or gallery acceptance. Existing comments/
workspace tests remain intact. Root must review the exact frozen source, integrate
only admitted stages, run normal final-candidate CI and obtain the existing
explicit immutable-publication/access/comment transition approval.
