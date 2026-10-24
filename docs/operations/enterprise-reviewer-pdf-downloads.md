# Private reviewer PDF original download — source stage

This stage is source groundwork only. Every new gate defaults off. It neither
publishes nor replaces the current review link, enables a scanner, creates a
runtime, grants employee access nor sends provider traffic. Root is the only
integration/release writer. This covers PDF originals only; JPEG/PNG continue
through the separately reviewed image service. TXT, office documents, archives,
inline PDF rendering, thumbnails, extraction and conversion are unsupported.

## Admission and access

A future immutable Version must explicitly contain `review_workspace_downloads`:
exact `{schema_version:1,policy_sha256:<approved SHA256>,expires_at:<UTC>}`. Its
expiry must be no later than attachments, collaboration, workspace and Version.
An approved closed policy is exactly `{schema_version:1,downloads_enabled:true,
scan_config_sha256:<approved SHA256>,max_scan_age_seconds:<60..3600>,
allowed_mime:["application/pdf"],max_bytes:5242880}`. Independent configuration
`service_proposals.reviewer_workspace.downloads` starts with enabled false,
scan_enabled false, empty policies and scanners. Image/upload/comment flags do
not grant PDF download. No runtime metadata or environment overlay is changed
by this packet. Integration must admit the exact descriptor/policies through the
existing reviewed server configuration path, without inventing an enable flag.

`ReviewerWorkspaceDownloadsAccess::publicationDescriptor(Request,string,array,
array)` is target-free trusted boot admission. It uses actual attachment
publication scope and a final fresh publication check, then re-derives PDF policy
and time. It does not choose the first record or grant any target. Every metadata
or download POST independently resolves actual current unlock, immutable
Version/source/build/silo/anchor, browser capability, current selection/projector,
exact typed ticket/project/task and parent archive constraints. A self-selected
review name is not a native User/employee grant. A separate legitimately unlocked
browser may read current authorized completed files; uploader ownership and
pending upload receipt remain unchanged. No local fallback exists.

## Scanner dependency and owner-only library seam

There is deliberately no new scanner/operator HTTP endpoint, command, scheduler
or wrapper. A separately reviewed operational invocation would first call
`ReviewerWorkspaceDownloadsScan::scan(Request,versionId,uploadId,intent)` with its
default dryRun true. Dry run never calls the scanner/evidence transport or creates
a scan row. The exact intent contains request_id and expected workspace, target,
upload and attestation revisions. Actually dispatching requires explicit false,
independent PDF scan admission and the existing real scanner configuration.
This is not permission to invoke it now.

The approved scanner document pins loopback127.0.0.1, exact port/file limit,
actual engine/minimum definition serial/freshness/timezone, existing TeamFileScanner
source, the new bounded PDF VERSION transport source and the unchanged inherited
pure VERSION parser source. It uses existing `ENTERPRISE_FILE_SCANNER_ENABLED`,
`ENTERPRISE_CLAMD_HOST` and `ENTERPRISE_CLAMD_PORT`; no global HOST/PORT, key,
provider or fallback transport is introduced. Never populate approval from the
fictional test engine/serial. Runtime activation, authentic current VERSION
proof and actual clean/rejected/unavailable acceptance remain separately held.

Fresh real owner active/verified User plus exact active owner/admin membership
is checked at entry, under tenant lock, after private IO, immediately before each
VERSION/scanner call and at final commit. No DB lock spans scanner/filesystem IO.
The actual quarantined PDF is decrypted/authenticated, bounded at5MiB, checked
by exact SHA/size/header/finfo, copied to0600 temporary storage and scanned with
the existing service. Plaintext is removed in finally; a crash lease enables
bounded later cleanup. Only matching authentic before/after engine/definition
replies plus clean verdict and final fresh authority can yield readiness. A
status-only result, stale/missing/changed evidence, wrong bytes, revoked access
or late expiry cannot do so. Fake test scanner outputs are never live evidence.

## Durable state, leases and retention

Encrypted `ent_proposal_ws_pdf_scan` records are separate from original uploads
and graph. They bind immutable descriptors, current exact target/version,
workspace revision, upload UUID/revision/SHA/size/MIME, scanner policy, request UUID
and canonical intent. A90-second claim,3/day attempt cap and300-second known
unavailable backoff preserve uncertain dispatch. Exact settled replay performs
zero scanner IO; a new UUID cannot replace an unresolved claim. A final rejection
is monotonic, including across prune/cancel/new upload UUID/name/browser/target
within the exact immutable workspace binding. Cancellation never removes this
private SHA tombstone. A saved clean file remains quarantined in its original
upload record; readiness is a separate time-bound attestation, never a relabel.

`ReviewerWorkspaceFileScanLeases` is called under the existing tenant transaction
by uploader cancel and owner upload expiry cleanup. Two closed exact child
lookups check existing image and new PDF claims even with flags off. Live leases
block original deletion; after lease expiry, late scan results still fail fresh
upload/current-authority checks. The PDF pruner is real-owner-only,25-row bounded,
dry-run default, cursor-based and works with feature flags off. It durably marks
cleanup_pending before erasing only its own scan temporary file outside locks,
with fresh owner immediately before IO and final commit. It preserves encrypted
original uploads and rejected tombstones. Original expiry cleanup separately
retires its upload bytes. No new cleanup scheduler is installed. Data after
publication expiry is inaccessible, but erasure needs the existing separately
approved maintenance operation; this packet does not promise automatic deletion.

## Private transport and one shared UI adapter

Only fixed session-locked POST metadata/download routes exist under
`/enterprise-owner/service-proposals/{token}/campus/workspace/downloads/`.
Same-origin, actual JSON CSRF,64KiB closed requests, current scope and independent
session/IP throttles remain required. Ranges/conditional responses are refused.
The exact-path outer boundary also sanitizes pre-controller method/runtime/
throttle errors without changing other routes. Successful binary is always
`application/octet-stream`, Content-Disposition attachment with safe UTF name,
Accept-Ranges none, exact typed X-Everbranch-Attachment identities and SHA/length.
No X-Team identity/native endpoint is borrowed. Metadata, errors and bytes carry
private,no-store,no-transform, explicit identity Content-Encoding and exact
Content-Length. A proxy must honor this contract. The client deliberately refuses
compression/length/hash/identity/range/inline drift; it does not send forbidden
Accept-Encoding headers or weaken verification. Hosted proxy behavior and a real
browser download remain acceptance gates, not assumed from loopback/native tests.

One `EverbranchWorkspaceDownloads` client shares the existing single shared
workspace adapter. Only current validated ready objects can initiate an explicit
button click. Files/body bytes/Blob URLs are never persisted in browser storage.
Request timeout, current scope/target/revision/phase/visibility/expiry and final
hash checks retire late responses. Temporary Blob URLs revoke promptly or on
scope/tab/page loss; files legitimately saved on a device cannot be recalled.
The existing file UI offers PDF only when that admitted client is supplied,
otherwise it remains image-only and existing PDFs say retrieval unavailable.
It cross-checks download metadata with uploads.show, labels held/rejected states,
preserves exact pending reservation recovery/reselection/explicit resume and does
not cache File objects durably. Same target/Center/Side retains one DOM instance,
selection/drafts and placement. Target/permission loss clears private controls.

## Verification boundaries

Native tests use isolated SQLite, actual encrypted private bytes and actual
source/route/authority services with explicitly FAKE scanner/evidence replies.
The VM tests use simulated HTTP/minimal DOM, not Safari, hosted proxy, physical
mobile, actual daemon, downloads saved to disk or live providers. Existing native,
upload/image/discussion/bridge tests are retained. Normal CI/PHP8.4/MySQL
concurrency, independent review, source closure, merge/deployment and hosted/
authentic scanner acceptance are still required before activation claims.
