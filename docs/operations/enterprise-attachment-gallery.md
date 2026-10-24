# Private Enterprise ticket photos

This source adds thumbnails and a large photo viewer to the existing project,
request and canonical-task discussion. It does not scan, promote or relocate an
upload. Existing upload limits, scanner outcome, native permissions and private
storage remain authoritative. No migration, new provider, activation or runtime
provisioning is included.

## Authority and bytes

`GET /enterprise/discussion/{kind}/{id}/files/{upload}/preview` resolves the current
discussion, department and canonical task first. The selected upload must belong
to that exact task. The native file service then checks current tenant/task access,
`ready` status, exact private storage key, stored length and SHA-256. It accepts
only JPEG or PNG, within the existing 10 MB and 20-million-pixel upload limits,
with current file/header MIME agreement. SVG, PDF, text and unready files have no
preview. Preview reads do not create discussion tasks or change scan status.

The response is explicitly private/no-store, typed, nosniff, same-origin and
sandboxed. Ordinary file Download remains an octet-stream attachment with its
existing behavior. The discussion DTO supplies the server-owned preview URL only
for ready image metadata; a URL never grants access by itself.

The gallery validates the exact runtime mount, canonical thread, file and tenant
URL. It fetches bytes with the current X-Team viewer/workspace refusal fences,
no-store and redirects disabled, then checks returned identity, MIME and length.
Only an ephemeral object URL reaches an image. There is no file or URL persistence
in browser storage. Visible thumbnails load on intersection rather than fetching
all private images when a ticket is closed. A browser without intersection support
retains the explicit View photo action, with no automatic thumbnail fetch.

Every open and previous/next action performs a new authorized read. While the
viewer is open, a 30-second byte recheck preserves zoom/scroll and detects access
loss; server revocation cannot retroactively erase bytes already displayed before
that recheck. Denial, errors, target/scope changes, tab hiding and page exit abort
pending fetches and revoke all object URLs. Hiding the tab clears image bytes;
returning reauthorizes visible thumbnails without rebuilding the ticket. The same browser cannot keep an old
image as an access grant. A late response cannot restore a cleared target.

## Interaction

The large image dialog is separate from the existing Center/Side work dialog.
Zoom is bounded from 100% fit to 400%; Fit resets it. Previous/Next and left/right
keys navigate at fit. Zoomed arrow keys remain available for scrolling. Horizontal
single-finger swipe navigates at fit; it does not consume zoomed panning or pinch.
Escape closes only the photo, retaining the ticket. Closing returns focus to the
opening thumbnail and restores ticket scroll without rebuilding the note composer,
selected files, in-flight uploads, selected ticket or Center/Side preference.

## Verification and release

- Native `EnterpriseAttachmentGalleryTest.php` checks JPEG/PNG, unchanged Download,
  quarantine/nonimage/status refusal, wrong thread, current request task binding,
  department/tenant loss, stale page identity, disabled collaboration, corruption
  and forged image metadata. Scanner success is faked only inside disposable tests.
- `enterprise-attachment-gallery.test.mjs` checks client URL/byte/identity authority
  and refusal before HTTP for sample, quarantine and changed context.
- `tests/browser/enterprise-attachment-gallery.smoke.mjs` is an intercepted
  Chromium/WebKit presentation fixture with no listening server or real campus
  data. It covers modal controls, nested Escape, retained drafts/files/placement,
  focus/scroll, simulated phone swipe, denial cleanup, late response fences and
  page exit. Phone viewport and synthetic touch are not physical mobile acceptance.

The native clean-ready capability and reviewer browser sample are separate. The
reviewer adapter keeps sample uploads metadata-only and quarantined, with
`downloadable:false`; this change does not fabricate sample thumbnails or enable
a scanner. Root must register these checks in the integrated candidate and run
normal frozen-head CI. Hosted Safari/Chrome, physical mobile, real clean-ready
private images and current publication acceptance remain unverified until the
approved source/publication transition. Do not silently republish or replace the
current review edition or its access/comments to demonstrate this gallery.
