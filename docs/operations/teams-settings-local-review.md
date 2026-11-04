# Teams Settings local review — November 4, 2026

This local candidate ports the Settings behavior from PR #446, commit
`1d5bf9add0a547e54892a912593ffc91f8498a66`, onto verified deployed release
`2db6f506ae5dfc9cb5f9188c9c1c2b23507a9ee2`. It is not deployed or pushed.

The user requested running the live source locally, adding the pending feature
and comparing its effect. The larger Teams product remains intended to replace
Asana; an in-Teams Settings dialog does not complete migration or cutover.

Asana remains authoritative for existing Asana projects and tasks until an
explicitly approved cutover. Teams-native test work and enrichment remain
separate from that source. Preserve original notes/files/history and perform
final delta reconciliation, permission and attachment verification, backup and
owner acceptance before changing authority. The compensation spreadsheet
retains authority for its financial inputs. This review enables no source writes.

## Behavior

Premium standalone `/teams` and embedded `/team` open profile, connection and
reminder navigation within Teams. Profile writes reuse canonical validation,
email verification and Livewire. Locked tenant/viewer context is rechecked on
subsequent requests. Normal profile settings remain available outside Teams.
Task and profile drafts remain in the page, with focus restoration on close.
The existing authorized Operations views own account connections and reminders.

The Operations reconciliation adds settings navigation alongside current
`area`, `create` and `open` methods. Current search includes, indexing,
source-generation checks, enterprise permissions and keyboard handling remain.
No migration, provider setup, external send, real-data import or entitlement
change is included. Non-premium presentation retains its existing behavior.

## Local environment

The local preview binds only `127.0.0.1:8785`, using a newly created marked
SQLite fixture under `storage/framework/testing`. All users, projects, tasks,
Events and Wholesale records are fictional. The fixture router prevents stray
HTTP requests and uses fake provider responses. Mail is an array transport;
sending, campus voice, AI and source-write controls are disabled. No production
configuration, credentials, private data or database was copied.

For source verification:

```powershell
php -d memory_limit=1G vendor/bin/pest --configuration=phpunit.team.xml tests/Feature/Team --compact
php -d memory_limit=1G vendor/bin/pest --configuration=phpunit.team.xml tests/Feature/Settings/ProfileUpdateTest.php tests/Feature/LeesMcRaeCampusDemoTest.php tests/Feature/CampusFacilitiesDemoTest.php --compact
npm run build
```

The baseline focused suite passed 21 tests / 204 assertions. The first candidate
run passed 219 Teams tests / 2,149 assertions and 30 profile/campus tests /
386 assertions. One opt-in 10,000-document latency benchmark was skipped.
The subsequent six-case Settings run passed 37 assertions, including the new
search/settings coexistence regression (250 distinct passing cases across the
candidate verification runs, with five Settings cases repeated).
Both frontend bundles and the build's existing JavaScript checks passed.

Actual browser checks covered live Settings leaving Teams, the local dialog,
local profile saving without navigation, area connection navigation, task draft
retention, task save and search indexing, and phone-width dialog rendering.
Local Lees-McRae overview and building views load from its unchanged package.
Its routes, two controllers, DemoStore and frontend build identity match the
deployed base. Local campus records are seeded samples, not production copies.

No real Asana migration or installed-native-app acceptance was performed.
Before a production release, reconcile against the then-current release and
complete the normal protected review/build/deployment checks. Keep the current
campus data directory and API/package pairing; no reset or rollback migration
is needed for this feature.

The live release advanced by six unrelated Trajectory commits during review.
The local candidate was carried forward without feature conflicts; current
Operations/search behavior and all newer Trajectory changes are retained.
The user also asked about controlled Asana write-back. That is a supported
possible transition through official API scopes, but remains a separate
feature: this Settings review does not add write scopes, source mutations or
cutover authority.

Final verification on the refreshed live base: 250 tests passed with 2,543
assertions; only the opt-in 10,000-document latency benchmark was skipped.
The full frontend build and both Vite bundles passed again.
