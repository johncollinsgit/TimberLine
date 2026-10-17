# Verification — 2026-10-15

## Automated checks

- `php vendor/bin/pest tests/Feature/Retail`: **44 passed, 552 assertions**
  (8.09 seconds; final local log `/tmp/retail-expanded-final-tests.log`).
- Website Commerce safety/operations, canonical onboarding draft store, and
  landlord prospect onboarding pipeline: **21 passed, 165 assertions**.
- `node --test tests/retail/commerce.test.mjs`: **7 passed**.
- `node scripts/retail/qa.mjs`: **46 scenario groups passed, zero page errors**.
  All twenty homepages, product details, categories, selected variants, search,
  cart quantities, shipping choices and staff tabs passed at 1280×800 and
  800×1280. All twenty storefronts and product dialogs also fit Galaxy widths
  600×960 and 960×600. The run checked simulated confirmation, inventory/customer
  history, private notes, prospect review, pricing and weekly partnership, offline
  reopening, simulated team roles, and Studio editing/autosave/audio recovery.
  Disk exhaustion interrupted evidence capture after 37 completed merchant flows;
  those verified flows were retained and the remaining flows/checks completed.
  `output/retail/browser-results.json` records that continuation explicitly.
- `node scripts/retail/offline-and-studio-check.mjs`: **6 checks passed**.
  Deliberately stale v2/current caches were replaced with the latest public shell;
  a 21-entry pack contains twenty concepts plus Sawyer and no private site reviews
  or customer notes. Network failure with Wi-Fi still on reopens prepared samples,
  while an online 403 never falls back. Presentation can show fictional staff tools.
  Studio titles fit at 600/800/1280 pixels and its cache excludes authenticated
  HTML/API responses.
- `npm run build` (backend): passed, Vite **2.95 seconds**.
- Scoped PHP Pint check: passed.
- `php scripts/ci/lint-migrations.php --base=3238f469 --working-tree`: passed,
  **zero changed migrations**.

Shipping/security checks cover the $174/$175/$176 threshold, ceramic quantity
protection, mixed package groups, oversized quotes, rejected farm parcel shipping,
invalid inputs, tenant/cart/customer isolation across all twenty concepts, unique
item limits, checkout/rewards replay, private-note/research boundaries, preview
revocation, and blocked real commerce/notification side effects. EasyPost fixtures
verify test keys/origins, integer-cent USPS rate aggregation and rejection of
production-mode responses. No actual live carrier transaction or postage purchase ran.

## Native builds and branding

Field's final standard build passed dependency installation, web build, Capacitor
copy and JDK 21 Gradle `assembleDebug`: **461 tasks, 33 seconds**. Its tests passed
**19 files / 70 tests**; lint retains six existing bundled-sample warnings. The APK
is **2.3.29/build 45**, debug signed. Showcase passed **4 tests** and its bundled
media validator; its standard native build passed **93 tasks, 17 seconds**.
Existing Gradle flatDir/deprecation and web bundle-size advisories remain.

Both final APKs contain the vector adaptive foreground and refreshed in-app SVGs,
with signatures matching existing installations. Both were installed on Galaxy
using `adb install -r`; final installation of these same artifacts on Lenovo awaits
its next connected session. The prior four-app installations on Lenovo remain.
Exact artifact sizes, SHA-256 values and build evidence are in
`output/retail/logo-review/native-build-results.json`; see `LOGO_CLARITY.md`.
PWA PNGs are exported directly from SVG, with separate maskable icons and versioned
URLs. Manifest/export verification alone does not prove an installed launcher icon
has refreshed; that physical check is tracked separately.

## Physical tablets

All four app identities were installed on Galaxy and Lenovo. Retail and Studio
launched standalone and reopened the original prepared seven-concept samples
offline on both devices. Lenovo Field/Showcase landscape behavior and Lenovo's
opaque sign-in heading were physically inspected. Galaxy's legacy Chrome96 auth
fallback was inspected before its official Chrome update to 155.0.8059.79.
Studio remained standalone after that update, retaining fourteen Renfrow records,
one physical microphone take and **49,620 audio bytes**.

The expanded Galaxy Retail app reopened offline with the Mac reverse connection
removed: **20 concept cards, 21 pack entries, standalone mode and the visible
Offline sample disclosure**, using `everbranch-retail-v3`. Evidence is
`output/retail/galaxy-twenty-offline-results.json`. The new tea concept and staff
workspace were also exercised physically after refreshing the public CORE shell.
Lenovo's twenty-concept pack was prepared in Chrome; final reopening of that pack
from its installed app awaits the tablet waking/reconnecting. The older
`output/retail/tablet-results.json` is a historical installation log until the final
combined device summary is refreshed.

Physical live Collins authentication remains unverified and needs the user's
existing team credentials. The local showroom test account does not authenticate
live Field services. Renfrow cloud-book provisioning, real merchant approval and
production hosting are not completed by this local build. No browser-data reset,
PWA uninstall or IndexedDB clearing was used to refresh the apps.

## Authentication, outreach and release limits

The legacy Android sign-in stylesheet corrects unsupported modern utility colors,
layout and oversized checkbox SVGs. The forest-panel heading uses opaque ivory.
The local test account completed sign-in. An isolated authentication suite reports
**6 passed / 1 existing redirect expectation failure** (workspace creation versus
landlord). The same failure was reproduced with the unchanged HEAD auth template;
it is not caused by the presentation stylesheet.

The owner-only outreach review denies anonymous/member access, returns
no-store/noindex, and issues no HTTP/mail/queue writes. It shows vendor-specific
copy, verified public contact evidence, exclusions and delivery status. The owner
reviewed and separately authorized external outreach, then directed delivery through
Facebook and email. Approved emails have been sent; fixed template draft metadata
must not be read as evidence that all messages remain unsent. Exact channel,
recipient and tool receipts are tracked only in ignored private
`storage/app/private/retail-outreach-delivery.json`. Honest Elderberry is excluded
from outreach at the owner's request while its storefront remains in the twenty.
No storefront, seeder, sample checkout or prospect save sends outreach automatically.

GitHub test/build and MySQL recovery both passed for earlier head `3c8a0665`.
The final expanded-head GitHub checks must be verified after its commit; that prior
run does not establish the new head's status. No production publication or merchant
activation occurred, and production publication still requires explicit approval.

## Review evidence

`output/retail/` is ignored. It contains the browser/offline JSON reports, 160 verified
merchant captures and eight visual contact sheets covering all twenty homepages,
product details, checkout and staff views in both orientations. All eight sheets
were visually inspected. Generated dialog captures were cropped around the dialog
without resizing text, and older generated PNGs were preserved as verified JPEGs
to recover disk space. `screenshot-crops.json` and `screenshot-format-mapping.json`
record those transformations; named final physical proofs were retained.

## Offline photo regression — 2026-10-17

`node scripts/retail/photo-offline-check.mjs` passed: all **75 public photos**
were present after replacing a simulated installed v4 cache without pressing
Refresh, even when fresh photo requests were blocked. A stalled bootstrap request
with Wi-Fi still on fell back to the saved pack within the bounded wait. With networking disabled, **20 homepage photos, 21 storefronts, 182
product cards and 21 product-detail photos** loaded with positive natural widths;
zero page errors occurred. Private API/HTML poison entries from the old cache did
not enter v5. Galaxy-size portrait/landscape captures and exact results are under
ignored `output/retail/photo-repair/`. Cart calculation tests remain **7 passed**.

The Galaxy was reached using the default ADB server, which had its existing paired
transport; the older separate5038 server had no Galaxy transport. Its installed
Retail PWA had21 saved catalogs and cachev4, but the farm images had zero natural
width. This confirms the reported missing-photo state. Physical repair passed on the installed Galaxy PWA: all **75 images decoded**,
all **20 homepage photographs** loaded, and **21 catalogs** remained available
with the Mac reverse connection removed. Standalone mode remained true and the
sample-cart/activity hash was unchanged.
`output/retail/photo-repair/galaxy-results.json` and
`galaxy-retail-offline-photos.png` record the actual device check.

Bootstrap and public navigation use a four-second connection bound; prepared
public modules/photos load locally. Live permission failures continue to fail
closed rather than falling back to an offline permission grant.

## Conversational voice verification — 2026-10-17

- Retail PHP suite: **67 passed, 756 assertions**. Voice-specific tests: **8 passed,
  123 assertions**, including owner/verification checks, unknown or private input
  rejection, no-store responses, missing configuration, model readiness vs network
  failure, bounded public context, multipart negotiation, restored SDP CRLF, quotas
  and masked provider failures. Scoped Pint passed for five PHP files.
- Voice browser regression: **9 scenario groups passed**, zero page errors. It uses
  mocked audio/WebRTC and validates readiness without
  recording, selected merchant/page, transcript escaping/order, follow-up turns,
  interruption state, track mute/end, offline recovery without automatic recording,
  authentication and microphone-permission failures, delayed permission cleanup,
  active close/background cleanup, failed WebRTC cleanup, five-minute expiry, typed
  navigation and transient transcripts. Portrait/landscape screenshots inspected.
- An opted-in **real OpenAI Realtime** session passed with two synthetic spoken
  questions and spoken replies. The first reply named Joy Fudge Company; the follow-up
  referred back to its sample fudge choices. **2,604 inbound audio packets** were
  received and playback was active. End closed the peer and stopped all tracks.
  No Mac physical microphone was used. The initial failed attempts exposed Laravel
  trimming the SDP's required terminating CRLF; the server now restores it.
- Actual installed Galaxy SM-T577U: standalone mode, ready connection screen, real
  microphone permission/track creation immediately muted before transport, live
  provider reply with **596 inbound audio packets**, active playback, End cleanup,
  portrait/landscape layout and unchanged cart/sample-activity hash. No ambient
  microphone audio was sent. A visitor's spoken input through the Galaxy microphone
  and audibility in the festival environment still need a human check.
- With the Galaxy's Mac reverse connection removed, the prepared storefront/photos
  reopened, voice Start stayed disabled, and cache v6 retained **all 75 photos** with
  no private API cache entries. Reverse forwarding, automatic rotation and the
  original 30-second timeout were restored. No uninstall or data clearing occurred.
- Repeated offline photo regression: **75 photos, 21 storefronts, 182 product cards
  and 21 detail photos**, upgrade carry-forward with fresh image requests blocked,
  weak-network fallback and zero page errors. Sales regression: **9 scenario groups
  passed**, zero page errors; real signup submission remained intercepted.

Exact reports and screenshots are in ignored `output/retail/voice/`, including
`browser-results.json`, `live-results.json`, `galaxy-results.json`,
`galaxy-offline-results.json`, `live-conversation.png`, `galaxy-live-muted.png` and
`galaxy-ready-portrait.png`. The local server holds the existing authorized key in
process memory only. Voice needs internet and the local Mac server for session
startup; it has no web search. No production release/settings change was performed.
Lenovo work remains on hold. Setup and limits: [VOICE_DEMO.md](VOICE_DEMO.md).

## Demo customer and order voice context - 2026-10-17

Voice sessions now include read-only records from the selected marked demo tenant,
or validated tablet-only sample activity when that is the workspace being shown.
Customer profile and staff workspace buttons preserve focus. The snapshot reports
its source, customer/order counts and start time. Restart after changing records.
All retained Sawyer products can be discussed; its real customer workspace is
never queried. Private prospect notes and real contact records stay excluded.

- Retail PHP: **71 passed, 812 assertions**. Focused voice/context tests:
  **12 passed, 179 assertions**. Covers same-tenant demo orders/customer joins,
  purchase/reward/note inclusion, read-only behavior, real-contact and staff-identity
  exclusions, foreign IDs and nested private-field injection, invalid totals and
  product lines, source separation and the complete 62-product Sawyer catalog.
- Existing voice lifecycle regression: **9 scenario groups passed**, zero page
  errors. New records browser flow passed profile entry, focused customer, tablet
  orders/rewards/notes, contact/actor exclusions, and fresh isolated context after
  switching merchants. Both use mocked transport for UI/lifecycle verification.
- Sales regression: **9 scenario groups passed**, zero page errors. Offline photo
  regression: cache **v7**, **75 photos, 21 storefronts, 182 product cards and 21
  details**, retained images during update, weak-network fallback, no private API
  cache entries and zero page errors.
- A real Realtime session answered two spoken questions about Avery Morgan’s
  sample Chocolate fudge order, its **$12 total**, **162 reward points** and
  chocolate-gift preference note. **2,324 inbound audio packets**, active playback,
  no browser errors and successful End cleanup. Synthetic speech and fictional
  records stayed in a fresh QA browser; no customer database records were created.
- Scoped Pint, JavaScript syntax and diff checks passed. No schema changes, live
  commerce side effects, production publication or hosted settings changes.

The latest customer-context UI has not been refreshed on Galaxy: ADB and mDNS show
no connected tablet, and a wake/unlock request is pending. Earlier Galaxy voice and
offline verification above applies to the prior build. Lenovo remains on hold.
Evidence for this revision is under ignored `output/retail/voice/records-*`.
