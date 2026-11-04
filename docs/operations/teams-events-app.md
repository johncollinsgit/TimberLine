# Events My Tasks and installable Teams app

November 4, 2026 source candidate. Asana remains authoritative until a separately
reviewed cutover. This feature is task write-through, not an Asana import or
authority transfer. Existing native work, financial Sheets and campus data stay
in their original domains.

## Behavior

Events → My work → My Tasks queries the caller's own connected Asana identity in
its selected workspace and projects. It never matches names or borrows another
person's connection. Task details read fresh source notes, checklist subtasks,
comments/activity and permanent attachment links. Related records are paged;
attachment files open in Asana and may require sign-in. No attachment bytes are
stored for offline use. Unprojected subtasks require a freshly verified parent
relationship. The original task notes are preserved as plain text, not HTML.

Install entry `/teams/app?app=events&tenant=<slug>` opens Events My Tasks. The
manifest keeps the selected workspace in its identity and start URL. The app is
an installable web app for Safari on iPhone and Chrome on Android, not a signed
App Store/Play Store binary. Physical phone installation is still acceptance work.
Serve the production app over HTTPS. The service worker controls only `/teams/`
and caches only a public offline explanation, never private pages, tasks or APIs.
The event screens and saves require an internet connection.

## Controlled write-through

`TEAM_OPERATIONS_ASANA_WRITE_ENABLED` defaults false. Enable only after protected
release and runtime OAuth acceptance. Each user must explicitly authorize
`tasks:write` through their own Events connection; read access alone cannot save.
Existing send and event-creation flags stay independent. Read-only local snapshot
connections always reject writes, even when other flags are enabled.

The first slice edits only title, start/due dates and completion, including
verified checklist tasks. A durable encrypted `TeamOperationAttempt` holds the
original values, source version and reviewed changes. Timed start/due schedules
must be edited in Asana; date-only changes cannot silently discard their times.
A second explicit action
dispatches only those fields. Task/workspace/project access, role, grant and
connection generation are checked again. Source freshness is checked before
preparation and dispatch. A shared cache lock serializes this tenant's saves for
the same source task. The request is claimed durably before the provider PUT;
neither transport failures nor repeat dispatch requests replay that PUT.

An unconfirmed response becomes unknown (or remains executing after a process
failure). My Tasks exposes it again after reload. New saves for that task block
until review. Reconciliation reads current source values without writing and does
not attribute matching values to the uncertain request. The original user may
explicitly acknowledge the observed state, retaining evidence as `reviewed`, then
refresh and prepare a new change. Recent executing requests cannot be closed.

Asana offers no atomic compare-and-swap through this endpoint. An Asana user or
another integration can edit between the final read and PUT; this reduces stale
writes but cannot guarantee conflict-free simultaneous editing. Keep that limit
visible in release review and use the original Asana task/activity for disputes.
Asana rules, activity and notifications may run on API edits; write-through is not
invisible to Asana or its users.

## Release and acceptance

Use the normal protected PR test/build/migration gate and Forge atomic release.
No migration or live campus data reset is part of this change. Keep private event
snapshots, preview SQLite files, real account records and credentials out of Git
and public build assets. Local acceptance uses a marked disposable database,
loopback binding, blocked network requests and a separately stored snapshot.

After deployment, connect the intended account in the intended workspace, select
the event calendar and any relevant event projects, verify the assigned event,
then authorize editing if desired. Test a reviewed change with an explicitly
chosen disposable source task before normal use. Verify original notes remain
unchanged and confirm the actual source result. Install on each physical phone,
check sign-in/workspace, refresh, external attachment access and offline behavior.
Source tests and a desktop viewport are not physical-device acceptance.

References: [Asana task update](https://developers.asana.com/reference/updatetask),
[Apple web app installation](https://support.apple.com/guide/iphone/open-as-web-app-iphea86e5236/ios),
[Chrome Android web apps](https://support.google.com/chrome/answer/9658361?co=GENIE.Platform%3DAndroid&hl=en).

Local validation: 266 tests passed (2,655 assertions); the opt-in 10,000-document
latency benchmark was skipped. This includes all Team, profile and both campus
demo suites. Both Vite bundles and TypeScript/build checks passed. Browser checks
cover the assigned event, its seven checklist items, six comments and five
attachment links at desktop and phone widths. Actual source content stays in a
private local preview, outside this repository; no real source task was changed.

The first GitHub CI attempt stopped before app tests because the existing Ubuntu
mirror step rejected its runner source layout. The setup helper now parses
folded Deb822 fields without rewriting them, accepts direct official HTTPS source
URIs, and retains the exact official mirror allowlist. Five fixture tests cover
preservation, replay and rejection of malformed/insecure/unknown sources. This
does not skip the test/build gate or change production package sources.
