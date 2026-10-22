# Pest-control Fleet dispatcher preview

Source candidate, October 22, 2026. Publication requires review and the existing
protected CI/Forge release path. No merge or deployment is part of this change.

## Open and rehearse

After an approved release, open `/crm/fleet/demo/pest-control` on the canonical
Everbranch app host. It retains the existing HighLevel surface availability and
host gates. The original `/crm/fleet/demo` remains unchanged and available.
No installation, sign-in, CRM workspace, Bouncie account or real records are
needed by this preview. There is no link that changes a live connection.

1. Start at **Overview**. Review the unassigned, start-time-passed, older-reading,
   disconnected and out-of-service examples. Counts follow the current fleet
   filters; one job or truck can have several attention items.
2. Open **Urgent callback**, suggest crews and inspect both matching reasons and
   exclusions. Assign in the demo; preview the fictional customer message.
3. Use **Fleet map** to drill from branch to territory, select a truck and open
   its job or maintenance. Lists are paged; the map uses groups or page pins.
4. Record a fictional maintenance action or alert review. Route review remains
   a human decision against an approved example baseline.
5. Show **Setup** and **Settings**. Preferences, rehearsal progress, assignments
   and reviews survive reload in the same tab. Reset has a confirmation and
   restores the original fictional scenario. Closing the tab ends the session.

The snapshot is fixed at 10:10 AM Eastern, with 250 synthetic trucks, 258 example
jobs and four fictional branches. Names and positions are invented. The map and
trip diagrams are illustrative, with no geographic tiles or provider calls.
Old location readings are not automatically called offline; start time passed
means completion is unconfirmed, not proof of a failed visit.

## Boundaries and useful settings

- A persistent demo banner and footer separate this from live operations.
- Dispatch uses branch, recent example location, manager-confirmed availability,
  skills, stock, time-window conflicts and confirmed spare stop capacity.
  Distance is illustrative straight-line mileage, never road ETA or route
  optimization. Suggestions require an explicit assignment in the demo.
- Customer messages have no address/number, transport or send button. Preview
  text shows a scheduled service window, never a calculated arrival ETA.
- Preferences cover miles/kilometers, older-reading threshold, default branch
  and a clearly labeled provider-outage simulation. They cannot change live
  policy, retention, billing, connection or permissions.
- Setup explains agency installation, client Bouncie OAuth, vehicle selection,
  company tracking policy/retention, activation and optional CRM read permissions.
  Each button rehearses a step; none opens a provider or grants access.
- Session storage uses only `everbranch.fleet.pest-demo.v1`. Corrupt/unavailable
  storage falls back to fresh examples or page memory. Restore accepts known
  fixture identities and bounded review notes. User-entered text is escaped.
- This adds no schema, customer data, native job, message, provider API call,
  telemetry collection, credential or production configuration change.

## Verification

Run `npm run build`, then `npm run test:highlevel-fleet`, plus
`php vendor/bin/pest tests/Feature/HighLevel/PestControlDemoTest.php tests/Feature/HighLevel/FleetIntegrationTest.php --compact`.
The existing protected build workflow already invokes the extended Fleet suite.
For screenshots set `FLEET_PEST_SCREENSHOT_DIR` to an output directory before the
Fleet suite. The local Mac checkout used a read-only dependency link and therefore
used `npm run build -- --configLoader runner` to keep Vite's temporary files in
the isolated build; normal CI installs its own writable dependencies.

Acceptance covers:

- [x] 250 distinct synthetic trucks; consistent filters and attention targets.
- [x] Dispatch excludes missing skills/stock/capacity and unknown/conflicting
  schedules, with a deliberate assignment and fictional message preview.
- [x] Browser reload, interrupted preferences, setup sequence, service/alert/trip
  reviews, reset cancel/confirm and corrupt-storage recovery.
- [x] Chromium and WebKit rendered workflows at 1440×1000, 1024×768, 768×1024
  and 390×844; no document overflow and selected navigation remains visible.
- [x] Keyboard activation, dialog Escape/focus return, accessible control names
  and bounded map/list pagination.
- [x] Request interception rejects every nonlocal URL and every non-GET request;
  the new preview makes no unexpected requests. PHP verifies no installation
  writes or provider requests, and retains the existing availability gate.
- [x] Original 100-vehicle embedded workflow and six-vehicle demo regressions.

Screenshots and visual review notes: [evidence index](evidence/highlevel-fleet-pest-demo/2026-10-22/README.md).
These are headless browser fixture checks, not live HighLevel/Bouncie acceptance
or physical-device testing. Protected Linux CI and reviewer acceptance remain
required before publication.

## Production scaling remains separate

The real embedded client still allows **100 selected vehicles per workspace**.
The API and service reject the 101st; bootstrap is bounded by that same setting.
The Bouncie adapter separately pages device discovery in groups of 100. This
preview neither changes nor bypasses those rules.

Before supporting a real 200-plus fleet, validate provider/account rate limits,
polling/webhook bursts, queue delay, database queries/retention and browser/map
performance with the intended workload. Address the existing single CRM-source
selection, 500-stop source bound and 200-record history windows; unresolved alerts
need a complete queue independent of recent-history truncation. Branch filters
here are fictional grouping, not production cross-location access. Raising a
configuration value alone does not establish production capacity.
