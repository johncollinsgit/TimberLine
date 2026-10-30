# Public Lees-McRae Facilities demo on Everbranch

## Source and release

The owner asked to move the mobile dashboard and shared data off Sites and use
existing Everbranch/DigitalOcean hosting. The public destination remains
`https://app.theeverbranch.com/platform/demos/lees-mcrae/`.
The frontend source is `johncollinsgit/lees-mcrae-facilities`; build with
`npm run build:standalone`, then copy its entire `dist-standalone/` directory to
TimberLine `public/campus-facilities/`. This is a paired compiled asset delivery,
like the existing Field client. `build.json` identifies the deterministic build.
Do not run the Sites build to replace this package. Backend, package and share
image travel through normal GitHub CI and the protected Forge deployment gate.
No direct live-directory replacement or push-to-deploy is permitted.

The previous browser-only sample and its saved per-tab state are preserved under
`/platform/demos/lees-mcrae/classic`; private proposal editions keep their existing
renderer and boundaries. The current public URL defaults to the new dashboard
when `CAMPUS_FACILITIES_DEMO_ENABLED` is true (default). Explicit role links are
demo perspectives, never real access grants. The compact phone design is the
existing reviewed dashboard; broader redesign is deferred at the owner's request.

## Public fictional state and migration

`storage/app/campus-facilities-demo/` resolves through Forge's persistent storage.
`workspace.json` is outside the public document root, permissions0600, containing
only fictional work orders, upload metadata, usage counters and create receipts.
A stable separate lock plus atomic same-directory rename serializes updates.
Tickets require complete details, head approval before assignment/work, current
revision, team-appropriate technician and reviewed work fields for completion.
Create retries use a request digest and retained key; conflicting edits return409.
Limits:200 tickets,200 events/ticket,100 photo uploads/day,1000 retained uploads,
500MB total image storage; API requests are120/minute/IP without resolving an
authenticated identity. The file model is intended for this bounded single-server
demo. A production multi-server campus app should use its authorized database,
real identities, private media and account-specific provider budget.

The initial snapshot exported the existing Sites public API's eight sample
work orders on October30, all revision1 and with zero attachments. Their IDs,
fields, history and status are retained in `resources/data/campus-facilities/`.
Known seed retry keys retain their original identifiers. Confirm the old source
has not changed before cutover; do not automatically overwrite a later saved
Everbranch workspace. After deployment, route the old Sites API to this canonical
server or redirect it so old and new links cannot create separate workspaces.
No actual campus, native customer, private reviewer or tenant records are imported.

## Photos and live references

Uploads accept decoded JPEG/PNG/WebP≤8MB and25MP. They are re-encoded as JPEG to
strip metadata, stored0600 outside the public root and read only through the demo
photo route. Up to10 photos/ticket. The public demo is deliberately no-sign-in:
use fictional examples; role selection is not privacy. Reset replaces only sample
tickets and receipts, preserving upload quotas and bytes; it is not a retention
or deletion claim. Standard server backups should include this bounded directory.

Official lmc.edu map PDF, directory and Facilities page refresh on demand with a
one-hour cache and nonblocking refresh lock. Smalot PDF parsing is limited to32MB
per decoded stream; downloads are bounded to8MB, fixed official URLs, redirects
disabled. Retain the last successful reference and stale indicator on failure.
A real local refresh returned67 buildings. The source catalog's fetched time
remains its actual retrieval time, including when used as a stale fallback.

Stock/sample galleries stay visibly illustrative; uploaded photos take priority.
Additional official campus photographs and sources are retained in the frontend
media notes. Yellow leaf icons are transparent masks of the actual Everbranch
brand asset, without a white rectangle. Share metadata uses the fixed1200×630
campus photo card; messaging clients may retain old cached previews.

## PWA and provider boundaries

The package scopes its home-screen manifest and worker to the public demo path.
Only static owned assets/offline shell are cached; API records and uploaded
photos are never cached by the worker. Hashed JS/CSS and the build-specific shell
cache prevent mixed client bundles. Physical iPhone/Android installation,
microphone, camera and keyboard acceptance remains separate from viewport tests.

Live voice remains unavailable until secure key provisioning and a bounded demo
usage policy are installed and tested. No Enterprise, Collins or Modern Forestry
key/sender/provider budget is inherited. General test-email/SMS controls stay unconfigured. Mention mail uses the existing
Everbranch platform SendGrid connection and verified theeverbranch.com domain;
no email is sent by migration or fake-provider smoke checks. The user has asked for ticket voice, so
provider setup is remaining work, not an optional finished feature.

## Validation

Focused HTTP tests cover the new/default shell, share image, fixed host/origin,
role links, requests, completeness, retry replay/conflict, approval/assignment,
notes/work/completion, details/reply/rejection, photo validation/persistence,
reference failure and real independent-process write conflicts. They run beside
the unchanged classic isolation suite. Protected final-head CI and `/ready`,
live built assets and separate-browser shared-ticket acceptance remain required
before reporting this candidate as deployed.

## Comments, self-entered addresses and mention mail

The composer combines text, verified photo uploads, browser speech-to-text and
role-appropriate workflow icons. Comment authors and timestamps are recorded.
Up to5 mentions/comment resolve only currently verified, enabled participants.
People enter their own display name/address; a browser-bound HttpOnly capability
permits editing only that registration. Email addresses are encrypted at rest and
only masked hints are returned. Email link GET displays confirmation; explicit
POST confirms the inbox proof. This is separate from native accounts/permissions.

The existing platform SendGrid account was read-only verified to have an active
theeverbranch.com sender domain. Branded messages use notifications@theeverbranch.com
with ticket, author, comment and fixed canonical ticket link.50 messages/day across
verification and mentions,10-minute confirmation cooldown/address,100 participants.
Only explicit registration/comment actions send. Signed-off delivery is never
claimed from HTTP202: UI says accepted. Durable comment receipts claim sends before
HTTP, and unknown outcomes are held without automatic retries. The current request
view remains usable if mail is unavailable. No arbitrary email address can be
inserted in a comment payload. A verified participant can disable mentions by
updating the same owned address without another email. Physical delivery, spam
folder placement and browser speech recognition require live human acceptance.

## Buildings and expanded samples

The Buildings tab replaces Campus Guide. It uses the official map's 67 locations, canonical display names for featured buildings, plus ticket-specific outdoor locations. Counts, jobs, team filters and galleries follow the selected demo persona. All locations remain browsable; no verified exact photo produces a labelled placeholder. Eighteen selected building reference images have exact source captions and credit in the photo viewer. `docs/BUILDING-PHOTO-SOURCES.json` in the frontend source records all researched provenance and rights notes; publicly hosted college photographs require production reuse review. Reference and stock photos never become incident attachments.

Thirty-six fictional jobs cover Maintenance, Grounds and Housekeeping at 12 featured buildings. `DemoStore` applies the versioned fixture set once, additively, under the workspace lock, leaving all existing tickets, revisions, photos and histories intact. Reset uses all 44 samples. A full workspace remains capped at 200; existing work is never removed to make room for fixtures.

## Facilities-specific live voice

`CAMPUS_FACILITIES_OPENAI_API_KEY` is the newly authorized demo key, in the existing Forge shared `.env` only. Its local origin is the ignored frontend `.env.local`; it is absent from Git and browser assets. The selected key expires after 30 days (created October 30, 2026); renew deliberately before expiry. Do not copy private Enterprise, Collins or Forestry AI credentials.

The browser creates its WebRTC SDP offer and sends it to the fixed-origin native API. The server calls OpenAI Realtime `gpt-realtime-2.1` and returns only the SDP answer. `VoiceDraft` validates extracted fields and cannot persist or transition a ticket. User application controls confirm intake, advance to camera, submit, save work and complete work. Transcript and extracted fields are reviewable. Stop, cancellation, hidden page and unmount close media capture and request provider hangup. Late responses cannot update an abandoned draft.

The demo ledger allows three attempted calls per UTC day globally, one active/creating/unknown call at a time, five-minute expiry and 1,000 output tokens per assistant response. This is a usage bound, not a promised dollar budget. Creation is durably reserved before HTTP; exact retries replay the original answer. Unknown outcomes occupy the slot for manual reconciliation rather than issuing another paid call. Close requires a per-attempt capability; provider call IDs remain server-side. `campus-facilities:expire-voice` runs every minute in the existing Laravel scheduler to hang up expired known calls, retrying uncertain hangups. A scheduler heartbeat older than three minutes blocks new calls. Cleanup may lag expiry by the scheduler interval. Never reset the voice ledger to bypass a held or exhausted allowance.

Validation: the focused native suite checks replay, conflict, scope, comments/email confirmation, decoded uploads, additive samples and voice quotas/cleanup/draft tools with fake providers. A real Realtime SDP offer was accepted and immediately hung up with the new key, with no microphone capture/audio transmission. Actual spoken audio, iPhone/Android installation, camera permissions and received email still require physical-device acceptance. The native secret was installed securely; the normal protected release must load it before live voice appears at the public URL.

## Ask Everbranch

The bottom-right Ask Everbranch control opens a text chat or live spoken campus conversation. `CampusAssistant` constructs each answer from the selected demo role/person projection, current job status/assignment/location/priority and official building/department references with retrieval/stale timestamps. No native tenant records, email addresses, participant secrets or provider secrets enter the AI context. Returned work-order links are intersected with that projection. Admin context explicitly spans all service teams; the persona's default team does not restrict or label that overview.

Text questions use Responses (`gpt-5.4-mini`, store=false, no tools, 900 output tokens) with a global 50-attempt/day ledger, durable request digest and exact-answer replay. An uncertain request is held rather than automatically reissued. The browser keeps the unfinished question through failure; closing or changing view cannot apply a late response to another person's chat. In-session chat history remains in browser memory and the last ten messages accompany a follow-up. Voice uses `gpt-realtime-2.1` with no draft tools for Ask mode and shares the demo voice allowance/watchdog. Each spoken session uses a timestamped current board snapshot at connection time. Only application ticket controls can change work.

A real read-only text question about Shelton Learning Commons returned its active and completed sample work orders; no work was changed. Physical spoken conversation remains a separate check. The focused suites now include role-filtered assistant context, verified links, replay, uncertain outcomes and daily limits.

The no-microphone browser regression check now exercised a real WebRTC data channel with a synthetic silent audio track: fictional intake → `update_draft` → native field validation with zero missing fields → full spoken-output transcript/readback → provider hangup HTTP 200. It created no ticket and captured no microphone/camera. The check caught Laravel trimming the final SDP line ending; the native service now restores the required CRLF before creating a call, with a multipart regression assertion. Temporary local fixture pages were removed. Actual acoustic microphone/speaker testing on phones remains outstanding.
