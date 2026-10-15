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
