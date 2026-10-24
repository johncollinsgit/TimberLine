# Private reviewer images and file controls — source stage

This packet is a default-off future-edition extension to the separately admitted
shared reviewer workspace, comments and upload quarantine. It does not activate
a scanner, republish a proposal, turn a held upload into a clean image, grant a
native employee identity, or change an existing runtime. The upload record stays
`quarantined`; a separate encrypted current attestation authorizes image reads.
PDFs remain held without a preview or download in this stage.

## Admission and authentic scanner dependency

An immutable Version must explicitly contain `review_workspace_images` with
exact `schema_version:1`, approved `policy_sha256` and UTC `expires_at`. Its expiry
cannot exceed attachments, collaboration, workspace or Version expiry. The
independent `service_proposals.reviewer_workspace.image_access` configuration has
`enabled:false`, `scan_enabled:false`, empty `policies` and empty `scanners` by
default. Existing attachment/comment flags confer no image or scanner authority.

The approved image policy closes the scanner configuration hash, maximum scan
age (60–3600 seconds), 320×240 derivative bounds and 256 KiB derivative cap. The
separately hashed scanner policy binds exact engine version, minimum definition
serial, maximum definition age (at most 48 hours), UTC daemon date basis, existing
scanner/evidence source hashes and exact loopback host/port/file limit. It does
not contain a key, arbitrary URL or credentials. The current adapter accepts
only `127.0.0.1`; another host requires a separately reviewed transport contract.

The existing isolated Enterprise overlay uses `ENTERPRISE_FILE_SCANNER_ENABLED`,
`ENTERPRISE_CLAMD_HOST` and `ENTERPRISE_CLAMD_PORT`. Do not change global retail
mail/scanner configuration, use generic HOST/PORT variables, install a daemon,
open a network service, or infer scanner approval from this source packet.
`TeamFileScanner` returns status only; that alone cannot authorize readiness.
The new evidence adapter reads the actual local daemon's `VERSION` command
before and after the existing exact-byte scanner call. Both program/database
replies must parse, match, be approved and have fresh definitions. Unknown,
missing, stale, changed or malformed evidence leaves the image unavailable.
The ClamAV protocol reference is
[official Clamd protocol documentation](https://docs.clamav.net/manual/Usage/ClamdProtocol.html).

An exact reviewed final packet and specific permission for the existing scanner
runtime/configuration remain prerequisites to any real scan. Real clean,
rejected and unavailable scanner acceptance has not occurred in this stage.
No test fixture verdict is evidence that a real upload is safe.

## Real owner scan contract

There is no recipient scan endpoint, scanner browser capability, automatic scan,
scheduler or native `User` bridge. `ReviewerWorkspaceImagesScan::scan` accepts a
real signed-in proposal owner Request, immutable Version UUID, upload UUID and
closed intent (`request_id`, expected workspace, target, upload and attestation
revisions). The default `dryRun:true` is a read-only preview and makes no scanner
or evidence socket call. An operator must inspect that preview, approve its exact
target/configuration/bytes/revisions, and explicitly invoke the same intent with
`dryRun:false` under the separately approved runtime gate.

Real user verification/activation and exact owner/admin membership are refreshed
using the reviewed `ReviewerWorkspaceMaintenanceAuthority` helper, including
current locking reads in transactions. No cached Request user can outlive
deactivation. Owner/publication/silo/anchor/graph/target/upload/config/expiry are
rechecked at reservation, immediately before every scanner/evidence call and at
final attestation commit. Scanner and private-file IO run outside database locks.
The source packet depends on that helper's exact reviewed pin; it must not copy
an unrelated moving maintenance file over the integration checkout.

One encrypted `ent_proposal_ws_img_scan` record binds exact Version, workspace,
target, upload revision, original SHA/size/MIME, graph/target revisions, approved
configuration and intent. Closed typed target identity ignores JSON key order
only; other IDs, kinds, missing/extra fields and non-string UUIDs fail closed.
A 90-second claim survives uncertain work. A new UUID cannot replace an uncertain
claim. Exact completed replay returns its historical receipt with no IO and no
claim of current readiness. Known unavailable retries require current revisions,
five-minute backoff and at most three attempts/day. Rejection remains permanent
in the tombstone, including after cleanup.

Actual decrypted original bytes are authenticated before scanning and again
before commit. A private 0600 temporary original exists only for the existing
scanner and is removed in `finally`. Only an actual clean result with authentic
matching evidence permits a decoded, metadata-free JPEG/PNG thumbnail. Decode
success alone never means clean. The derivative is encrypted in the same bounded
private namespace/quota as quarantine. No public disk, original download URL,
signed URL, EXIF copy or arbitrary file path is introduced.

## Current reads, expiry and cleanup

Metadata and thumbnail/preview POST endpoints use current real password unlock,
chosen reviewer name, exact selection/projector visibility, scope capability,
immutable Version/source/build/policy, current graph and exact target FK. They
retain existing session locks, same-origin/CSRF, read throttles and sanitized
outer error boundaries. Replies use `private, no-store`, `nosniff` and
`no-referrer`. Binary replies have a separate closed `X-Everbranch-Image-*`
identity contract; they never fabricate `X-Team-*` native headers.

A binary read verifies current attestation and authenticates actual original
and derivative bytes, then rechecks fresh recipient authority/expiry before
returning data. Current graph/target/upload/config/source or expiry drift
withdraws readiness. In particular a changed workspace graph revision requires
new explicit owner authorization/scan; an old result is not silently grandfathered.
Browser metadata is pinned to the current shared projection revision/digest.
Known attestation expiry retires cached media at its deadline without treating
that as a loss of ticket access; the UI asks to refresh files.
Only a typed ready object from that validated reply may reach the loader.

`ReviewerWorkspaceImagesPruner::prune` is actual-owner-only, read-only by default,
with at most 25 records and a cursor. It works with feature flags off, checks the
immutable ownership envelope and refuses a live claim. Before deleting crashed
plaintext or an expired/failed derivative it durably reserves cleanup, then
freshly checks owner authority outside the transaction. It rechecks again before
the final encrypted tombstone. It never deletes the original quarantine bytes or
unrelated customer/native data. Failed cleanup stays pending and retryable;
rejected tombstones cannot become automatic rescans.

## Detail controls and gallery integration

The new partial has actual Add image file/Add photo pickers, explicit Upload, pending
recovery, original-reservation retry, reselect/resume, cancellation and original
work navigation. New UI choices are JPEG/PNG images capped at 5 MiB. The older
quarantine API retains its PDF contract, but this image-only UI does not offer
new PDF uploads; existing PDFs explicitly say retrieval is unavailable. Generic
clean attachment downloads remain a separate unimplemented contract. Selected `File` references stay
in memory only; reload restores bounded pending metadata, requires the same file
to be selected again, and never automatically dispatches upload bytes. A photo
picker's `capture` hint is not a guarantee of mobile camera support.

Mount one `EverbranchWorkspaceUploads` and optional `EverbranchWorkspaceImages`
client with the same admitted shared adapter. Mount one file UI on the stable
ticket detail DOM. The root-owned integration hook guide describes the wiring;
it must not instantiate a sample/browser fallback. Calls to `setTarget` with the
same typed identity/version are no-ops, preserving selected file, comment draft,
focus, detail scroll and Center/Side placement. A fresh projection must call
`refresh` explicitly. Scope/target/graph loss retires all image activity.

Only actual current clean-ready JPEG/PNG objects get lazy thumbnails and a large
centered viewer. Zoom/Fit, previous/next, keyboard and swipe controls reuse the
existing gallery lifecycle through a typed loader seam; the old native loader
and download contract remain intact. Escape closes the image dialog without
closing the ticket below. Closing restores the same ticket, focus and scroll.
Page/tab hiding or context loss aborts/revokes private media; visible return
freshly authorizes. Genuine access/type/identity failures clear image access.
Object URLs and image bytes are never put into persistent browser storage.

## Evidence and explicit remaining gates

Native tests use an isolated fictional SQLite/private filesystem and fake
scanner/evidence objects. VM tests use synthetic HTTP/body/storage. Chromium and
WebKit tests intercept all HTTP without a listening server; supplied ready DTOs
are explicitly simulated and not real scanner acceptance. Mobile viewport,
synthetic touch and browser file input automation do not prove physical iPhone,
Android, Safari, camera or real hosted behavior.

Independent exact-packet review, root integration/source closure, normal final
candidate CI including PHP8.4/MySQL concurrency, explicit publication transition,
approved scanner runtime/configuration and real clean/rejected/unavailable
acceptance remain separate gates. Existing proposal links, names, comments,
workspace edits and quarantine are preserved until an explicit transition.
