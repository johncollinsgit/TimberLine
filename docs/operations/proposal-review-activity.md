# Private proposal review activity

Owner/admin members open Proposals → Published versions → Review activity.
The view groups activity by an opaque browser visit and uses Eastern times.
It records link opens separately from successful password unlocks. Names remain
self-selected, multiple names may belong to one browser, and one person may use
multiple browsers. A page visit does not establish reading or agreement. Tour
scenes and completion are browser reports, not verified engagement. Link-preview
scanners may produce opens without an unlock. Owner tests are hidden by default
and can be included explicitly.

Only Lees-McRae publications in an explicitly configured enterprise owner
workspace collect activity. Recipient and active owner membership checks stay
unchanged. The activity POST requires CSRF, the version-bound password grant and
matching content hash. Campus/page and tour reports also require the current
manifest. The browser sends a fixed event, fixed page/scene, random retry ID and
version hash; arbitrary extra fields are rejected. There is no email open pixel,
external analytics, IP/user-agent journal, keystroke tracking or work/form capture.

Each private shared runtime keeps encrypted JSONL under
`storage/app/private/proposal-activity/{tenant}/{version}.jsonl`. Existing shared
private-storage backup and access controls apply. Files are owner-readable/writeable
only, scoped using server-resolved IDs, and guarded against journal symlinks.
Writers lock the file, deduplicate event UUIDs and stop at 4,000 events or 4 MiB.
The owner view reports incomplete/unreadable/capped journals. Recording failures
do not block ordinary proposal opening; activity reports return an unavailable
status and stop further browser reporting. No automatic retention purge is added.

The activity route has an independent rate limit. The proposal CSP permits only
the exact activity script hash and same-origin reports when the trusted bridge
is present. Other inline scripts stay blocked. The campus continues its existing
nonce policy and same-origin review channel. Unlocked-page and name events are
recorded on the server; client reports cannot forge a successful unlock event.
Known authenticated owner/admin sessions mark their whole visit as an owner
test in the summary. Unsigned operator browser checks must be identified
separately and must never be described as client visits.

Deploy through the normal exact-head GitHub-to-Forge gate. No schema migration,
provider activation or commercial change is needed. The campus manifest includes
the activity source and a fresh immutable edition must be published after release.
Preserve prior comments with their original editions. Resend the new link only
after hosted owner/recipient and tour checks pass. Reuse the approved proposal
content and attachment.

Validation covers encrypted storage, unlock/open distinction, owner/tenant
isolation, hidden owner tests, retry deduplication, rejected extra fields,
revocation, current-build checks and storage limits. The actual HTTPS browser
check covers proposal CSP, section changes, campus pages, display names, scene
selection and natural final-scene playback in Chromium and WebKit.
