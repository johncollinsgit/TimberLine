# Ask Everbranch voice implementation

## October 20 web conversation and pricing increment

Read `ai-voice-pricing-and-rollout.md` for the current included-existing/$20-future
offer, 2x shared-credit usage, approved models/caps and total-$5 synthetic pilot.
The web panel now has an explicit **Start voice conversation** loop; final speech
sends a turn, playback pauses the microphone, and listening resumes after the
reply. Editable dictation still requires Ask. Stop/hiding/view changes invalidate
late callbacks. No phone or OpenAI audio transport is introduced. The older
dictation-only and included-usage claims below describe the retained legacy lane.

## Restoration

The October 16 Mac handoff was restored at backend base
`b6d63ed0061fdac417a3a5fec53fde36b7fd2ca4` and mobile base
`34af0396ccc79d3d53a8e4c2d309558b07fd0f6c`. Every transferred source hash and
complete Git tree matched before edits. Checkpoints: backend `43a6520`, mobile
`5468b01`. Mobile PR 52 is a separate baseline dependency; PR 53 is excluded.
Unrelated PC changes and the incomplete October 12 clone are untouched.

## Contract and coverage

- Existing search accepts `intent=navigate`; ordinary search retains its actions.
  Navigation filters both action fields and action types before ranking/limits,
  including soccer and landlord. Exact ambiguity is decided before truncation.
- Outcomes are `matched`, `choose`, `no_match`, `control`. Only one exact
  authorized page auto-opens. Records require selection; back/cancel are exact
  control phrases, never inferred write commands.
- Mobile adds optional `voice_navigation: {version: 1, pages: [...]}` with stable
  key, title, aliases, availability and either a native destination or an
  unavailable explanation. Unknown versions fail closed. Older clients ignore
  the additive property; older servers retain bootstrap-derived matching.
- Canonical navigation is indexed recursively, including settings, marketing,
  birthdays and help. Native tabs/modules derive from current server permissions.
  Records retain existing scoped search providers. This does not establish
  coverage of every GET route, export or internal page.
- Individual Documents assets cannot currently open through the native list
  renderer and are excluded as voice record destinations. Website-only pages
  have an explicit unavailable state. No guessed URLs or silent web handoffs.

## Interaction and privacy

Microphones start only on explicit activation. Navigation uses device/browser
recognition and deterministic matching, not a paid model call. Typing remains
available. Handle denial, silence, natural end, stop, cancel, timeout and late
callbacks. New clients send navigation text in POST bodies (web `/search` or
`/landlord/search`; native `/search/navigation`), keeping recognized words out of
access-log URLs. Navigation provider failures redact exception messages as well.
Do not configure request-body tracing for these endpoints. Legacy GET navigation
remains compatible and can put words in infrastructure access logs; upgrade those
clients before claiming transcript privacy across a deployment. The application
does not save navigation transcripts to recent searches.

Manual web choices refresh access and enforce same origin. Edits, close and
Livewire navigation invalidate old requests. Bounded session history is scoped by
user/workspace and revalidated before back; arbitrary browser history is not used.
Unverifiable previous pages remain unavailable.

Mobile refreshes grants, validates destination support and rechecks historical
record reads. A declined confirmation retains the dialog/draft/history. Complex
editors expose `data-navigation-dirty="true"`; active saves/uploads expose
`data-navigation-pending="true"`. Job editing, dictated notes, staged attachments
and material requests expose state. Native input/change events protect ordinary
forms and rich editors. Active photo queues prevent navigation. New complex
editors must supply explicit state rather than relying solely on native events.

## Local checks

Install dependencies from lockfiles. Use local testing configuration, SQLite in
memory, array mail/cache/session, sync queues and a local `VIEW_COMPILED_PATH` on
Windows. Build backend assets before rendering feature tests. Run navigation,
search, mobile contract and soccer tests, backend build/formatting and mobile
lint/tests/build.

For paired browser acceptance set `VOICE_MOBILE_DIR` to the mobile checkout and
`VOICE_EVIDENCE_DIR` to the evidence directory; run
`node tests/browser/voice-navigation.smoke.mjs`. Set
`VOICE_BROWSER_CHANNEL=msedge` to use installed Edge. The loopback-only harness
uses fictional fixtures, actual production components, fake speech and blocked
external requests. Mobile fixtures live outside its production build entry.
These checks prove control flow, not native speech accuracy or permissions.

## Release state

No schema migration, deployment, push, provider purchase, billing change or
production activation. Physical iOS/Android acceptance and paired signed builds
remain required. Follow protected release procedures; rollback paired navigation
commits while preserving checkpoints and business data.

## Business assistant increment

`EVERBRANCH_AI_ASSISTANT_ENABLED` and the exact
`EVERBRANCH_AI_ASSISTANT_TENANT_IDS` list gate the panel and mobile JSON adapter.
They do not grant the AI Branch entitlement, bypass its opt-in/budget, or grant
non-admin access. Verified active owner/admin membership remains mandatory.
Workspace-aware context additionally requires the existing context flags.
No GET dispatches a provider request. Dictation produces an editable draft;
only Ask sends it. Unknown provider outcomes reserve allowance and require
conversation/usage review; closing or Stop waiting does not promise cancellation
of work already accepted by the server. Existing chat UUID deduplication applies.

The web panel is lazy-loaded in the existing app shell. Its origin descriptor
is server-owned, allowlisted and contains only record kind/ID. Native visible
record context is a hint, validated against current server access. Both clients
render generated text as text, show available source/freshness evidence and
offer explicit OS/browser spoken playback with stop. Private memory is explicit,
encrypted, workspace/user-scoped, expiring and forgettable using existing services.
Changing memory invalidates old conversation context; unsent question drafts remain.

Activity shows current permitted internal note actions and receipts. Recent
permitted jobs can be selected to prepare a note preview without execution.
Approving requires the exact job/body digest and current job version. Replay
does not add another note. Undo is restricted to an unchanged, unattached AI
internal owner note; it archives the original rather than deleting concurrent
evidence, writes a content-free audit entry and creates one compensating receipt.
Model edits permanently invalidate undo, even if someone restores the old text.
This increment does not execute model tool calls or send communications.

`EVERBRANCH_AI_ASSISTANT_FINANCE_ENABLED` exposes saved, current-connection
QuickBooks Profit and Loss totals for the exact current reporting period, only
with canonical accounting/QuickBooks entitlements. Unknown values/currency/basis
stay unknown. Decimal conversion uses exact safe integer cents; snapshots expose
observation time and staleness. No provider refresh or accounting mutation occurs.
These totals are outside model input: financial question interpretation, expense
drivers, arbitrary historical comparisons and customer rankings remain open.

`EVERBRANCH_AI_ASSISTANT_REPORTS_ENABLED` additionally requires enhanced context.
POST reports takes a UUID, optional local week start, `executive|team` audience
and selected active team IDs. Seven local days use an exclusive end; DST may
produce 167/169 hours. Task events, current job notes and executive customer
activity are streamed in chunks and deduplicated by source ref, beyond chat
sample limits. Team entries require access for every recipient and exclude owner
notes entirely. At 10,000 entries generation fails without saving a partial report.

Snapshots are immutable/idempotent encrypted files under the configured private
workspace asset disk, bound to workspace/user/UUID, inputs and scope stamp, and
expire after 30 days. Reads and authenticated web print recheck current role,
modules, audience and source existence/visibility. JSON/native preview paginates
all entries; web print includes the full appendix. `coverage_complete=false`
explicitly excludes unregistered modules, finance, mail, calls and unrecorded edits.
These are not yet the full registered business ledger or a shared report inbox.

`php artisan ai-branch:prune` previews expiry. `--apply` deletes expired verified
reports/chat/memory and redacts old action and reversed-note text while retaining
receipts/usage. An unreadable report is retained for investigation and makes apply
fail visibly. Retention runs even with runtime flags disabled. Do not expose the
private report directory through a public storage URL.

All assistant flags remain false in the committed defaults. Fictional browser
fixtures replace every API/provider response; feature tests use HTTP fakes and
temporary storage. No pilot has been activated against a real workspace.

## Remaining release evidence

The paired local browser harness currently checks navigation, private assistant
interaction, exact note approval/undo, finance disclosures and weekly pagination.
Run it after both builds. The backend test suite needs process-local
`OPENSSL_CONF` on this Windows installation and `APP_URL=http://localhost` for
host-bound fleet tests; these settings do not alter application security.
POSIX file-mode and shell-quote assertions need Linux CI evidence.

See `docs/architecture/everbranch-voice-acceptance.md` for all 35 criteria.
Shared triage, connected customer workflows, comprehensive activity reconciliation,
mail/phone, receptionist and physical native acceptance remain separate increments.
The combined release is not complete or approved for activation.

## Shared attention increment

`EVERBRANCH_AI_ASSISTANT_ATTENTION_ENABLED` defaults off and requires all
assistant/context gates plus canonical customer/marketing access. The first
registered mutable source is Customer Loop follow-ups. Open, snoozed and resolved
views page 50 records at a time and disclose source coverage. Selected active
owner/admin members with marketing access are the only assignment targets; no
new employee or landlord grants are inferred.

A claim is an explicit five-minute coordination lease. Claim/release, assignment,
snooze (explicit timezone, at most 90 days), resolve (review reason) and reopen
check an exact content version and serialize through the existing workspace
account and source row. UUID receipts reuse encrypted `AiBranchAction` evidence;
replay cannot execute twice and changed UUID inputs conflict. Resolution text is
encrypted in the receipt; the source's safe context stores only its hash. All
transitions create a content-free audit record and never send the draft.

The paired component polls the visible page every 15 seconds, preserves unsent
resolution drafts across assistant tabs, checks dismissal, and refreshes after
each mutation. Unknown request outcomes require a read refresh. Existing Customer
Loop screens remain available and do not enforce these composing leases; their
changes invalidate the version checked by the new attention interface. This is
not yet universal cross-source claim/execution protection. Real MySQL concurrent
transactions and broader role grants remain release evidence requirements.

Latest increment checks: 169 focused backend tests / 1,493 assertions, 94 mobile
tests, lint, both builds and 29 paired browser scenarios pass. The previous
3,563-pass full-suite result predates this increment; it is not a new full run.


## Native capture rail (device acceptance deferred)

The bundled Java/Swift bridges accept a capture UUID and emit `captureEnded` for
natural completion, silence/errors and background interruption. Results are kept
only until the matching stop consumes them. Stop/cancel and delayed callbacks are
bound to that capture. Cancel during OS permission approval prevents a late start;
old final callbacks and stop timers cannot complete the next capture. JavaScript
registers its listener before starting, handles completion-before-start ordering,
removes listeners/timers on every terminal path and cancels when UI access changes.

The native Java/Swift changes have not been compiled or exercised on physical
devices here. Mocked bridge contract tests are not native acceptance. The user
chose to defer the Mac/device build and continue the underlying rails. Validate
permission denial, natural completion, silence, stop, rapid reactivation, cancelling
an OS prompt, interruption/background and late callbacks on both platforms before
release. Platform references: Android SpeechRecognizer
(https://developer.android.com/reference/android/speech/SpeechRecognizer),
Apple SFSpeechRecognitionTask
(https://developer.apple.com/documentation/speech/sfspeechrecognitiontask),
and Capacitor plugin events (https://capacitorjs.com/docs/plugins/android).

## Phone event/routing foundations

See `docs/architecture/everbranch-phone-rails.md`. A replaceable event-adapter
contract, strict Twilio form signature/identity normalization, a pure call-state
reducer, versioned timezone/holiday routing and a fake adapter are implemented.
No live ingress, dispatch, owned-number persistence or billing path exists yet.
Do not interpret adapter capability declarations as live provider readiness.

`EVERBRANCH_AI_ASSISTANT_PHONE_PRACTICE_ENABLED` is independently default-off and
local/testing-only for ordinary tenants, including when a production flag is
accidentally enabled. The separately audited fictional web pilot is the sole
production practice exception; it requires its current expiring owner grant and
scripted provider scope. See `everbranch-fictional-voice-pilot.md`.
Web/native practice explicitly runs one of seven fixed fictional scenarios,
discloses scripted coverage and never contacts a provider. Normal assistant reads
do not consume its namespaced rate limit. Report, attention, start and ask limits
also have separate namespaces to avoid collisions with the shared API limiter.

The latest focused run is 183 backend tests / 1,605 assertions; both builds,
mobile lint / 94 tests and 31 paired browser scenarios pass. Twilio has not been
connected; real calling, porting, voicemail, consent and device acceptance remain
open. Email-verification revocation is rechecked after refreshing the user record.

## Reviewed commitment tasks

`EVERBRANCH_AI_ASSISTANT_COMMITMENTS_ENABLED` is independently default-off.
Assistant/context, current owner/admin access, job access and the existing
reviewed-actions tier/gate are all required. The activity view can prepare a task
from an exact quote in a recent team-visible job note. Owner-private notes and
unsourced text are not eligible. A conservative English promise-candidate check
rejects suggestions/conditions; it is not general natural-language interpretation.
The person explicitly confirms the promise, chooses its task title, assignee and
due time, then reviews the immutable full preview before approving separately.

Dates and assignees are not inferred. Due times resolve in the server-owned
workspace timezone. Missing DST times are rejected and repeated times require an
explicit valid UTC offset. The preview records the exact zone/offset, job/source
versions and selected assignee. Approval rechecks expiry, digest, source visibility,
source/job hashes, active eligible assignment and timezone under row locks. It
creates one existing Field Service task, assignment and source-linked task event.
It does not dispatch a notification/message or alter accounting. Task undo is not
promised. Pending/overdue/completed queries use authoritative tasks, with bounded
pages and explicit source coverage; automatic reminders remain unimplemented.

The new approval center filters supported action kinds before limiting results.
Legacy AI Branch continues to render note previews only and revalidates job access;
replaying a previous approval also rechecks current record access.

## Shared navigation guard and final interaction checks

Web assistant source links refresh permission, enforce same origin and synchronously
request the page's `everbranch:check-navigation` guard. Missing guards fail closed;
pending saves/uploads or declined confirmations retain drafts/history. Dirty-input
evidence is not cleared before actual navigation. Close aborts pending source reads
and ignores late responses. The native source path cancels its read too and retains
all unsent assistant drafts, including collapsed task/resolution forms.

Starting recognition invalidates old search requests. An empty voice activation
does not search and overwrite the listening status. A final recognized query stops
capture before searching; manual selection stops capture as well. Native typed
correction invalidates capture through a cancellation token. Focus containment
includes disclosure summaries and all form controls, and access-change remounts
have a safe trigger-focus fallback.

The standalone mobile acceptance fixture refuses non-loopback API configuration;
the paired runner injects its local URL and blocks external requests. No manual
fixture opening can fall back to the production API. Native builds/device checks
remain deferred by the user. These rails do not complete the combined launch.

## Verified checkpoint results (Windows, October 16)

- Restoration: 12 package checksums, 20 transferred source hashes and both
  complete checkpoint Git trees match the manifest.
- Latest focused AI Branch/search/mobile inventory/provider/timezone checks:
  219 tests, 1,729 assertions.
  The APNs signature and affected environment-dependent suites also pass after
  configuring OpenSSL and localhost (98 tests in the environment rerun).
- Earlier complete configured backend run (assistant checkpoint, before the
  later attention/phone/commitment increments): 3,563 passed, five failed, one skipped;
  24,655 assertions, 570 seconds. This is not an all-green release suite.
  Remaining failures are the POSIX `0600` assertion in
  `ShopifyCatalogSnapshotImporterTest`, POSIX shell quote assertions in
  `WeeklyRewardsWishlistSummaryTest` and both `ScheduledImportsCoverBothStoresTest`
  cases, and the private-file import in `SubscriptionRechargeManualSettingsTest`
  (Windows does not establish its required POSIX mode). Keep those protections;
  obtain Linux CI evidence rather than relaxing privacy checks.
- Backend assets and formatting pass. Mobile lint, 95 tests and bundled
  TypeScript/Vite build pass, with the existing large-chunk advisory.
- Paired browser acceptance: 39 scenarios pass with fictional fixtures; no page
  errors. These are component interactions, not physical-device evidence.
