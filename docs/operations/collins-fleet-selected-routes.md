# Selected vans and recorded trip paths

Release: prepared for the protected GitHub/Forge pipeline on 2026-10-15.
Verify the merged release SHA at `/ready` before reporting production activation.

The application is the existing Laravel Field Service locator at
`/field-service/fleet-tracking`, not the separate `/crm/fleet` demo or mobile app.
It retains the existing Blade shell, Bouncie connector, tenant/module/role gates,
policy settings, vehicle assignments and retention table. Leaflet/OpenStreetMap
was already installed and used by CRM Fleet; the standalone map reuses that stack.

## Operation

- Initially select every active company vehicle already configured in the
  resolved tenant. This does not import, subscribe, bill, or change tracking.
  Display selections are stored by tenant and signed-in user in localStorage;
  no locations, routes or provider tokens are stored there.
- `/field-service/fleet-tracking/map` refreshes through the existing 30-second
  bounded Bouncie snapshot service. Latest positions are ranked per device,
  rather than taken from a shared 200-point window. The legacy activity table
  remains separate. Missing tracker/location, stale, disconnected and paused
  states stay visible. Stale means older than 15 minutes, matching the existing
  mobile contract. A document snapshot is explicitly not a new GPS fix.
- `/field-service/fleet-tracking/map/vehicles/{vehicleId}/history` resolves a
  current tenant vehicle on the server. A tenant query is included in both URLs
  so another tab changing active workspace cannot silently change map context.
  Existing owner/admin/manager Fleet viewing rules remain in effect; ordinary
  team members do not gain access. Existing CRM installation/collection gates
  remain checked where applicable. No configured vehicle/account limit changes.
- Official contract inspected: <https://docs.bouncie.dev/openapi.json>.
  GET `/v1/trips` accepts `imei`, `gps-format=geojson`, `starts-after` and
  `ends-before`. It returns trip identifiers, UTC start/end times, miles and
  provider GPS geometry. Query windows may not exceed a week. Calls use the
  existing server-side raw Authorization token and refresh-token mechanism.
- History uses account Field Service timezone, default America/New_York.
  Today/Yesterday and inclusive date ranges use local midnight boundaries,
  including DST. History is capped by the tenant's 1–30 day retention. Five-day
  query chunks with one-day boundary margins remain within seven days.
- Each trip stays separate. GeoJSON LineString, MultiLineString and Feature
  geometry is accepted. Invalid coordinates and sampling jumps over a quarter mile break
  segments. Douglas–Peucker simplification uses 12-meter tolerance, retaining
  only original coordinates and significant turns. It does not compute driving
  directions or invent road geometry. Sparse segments remain dots. Provider
  trip distance is never replaced by straight-line sample distance.
- The old standalone store has retained GPS points, not authoritative trip
  geometry/IDs. CRM encrypted trip metrics are a separate installation lane.
  Normal history therefore uses Bouncie trips. If history is missing/fails,
  bounded representative stored samples (one per five-minute bucket, latest
  100 buckets) are shown as evidence only. They are never joined into a road route.
- Signed connect/disconnect webhooks record timestamped health-only cache
  metadata, with no coordinates or configuration changes. Older duplicates
  cannot replace a newer reconnect. Expiry follows the retained event period;
  cache loss means unknown health, not proof that a tracker is connected.
- Each selected van loads independently, with at most three concurrent browser
  requests. Loading, missing GPS, no completed trips, incomplete history, provider
  failure, expired authorization, app session expiry and tile failures have
  distinct messages. Automatic refresh updates layers without recreating or
  fitting the map. Initial fit and the explicit fit button show selected data.

## Verification

Use the testing environment only (SQLite `:memory:` and fake HTTP/notifications).
No production credentials or databases are needed.

```sh
php artisan test tests/Feature/FieldService/FleetSelectedRoutesTest.php tests/Feature/FieldService/ReusableFieldOperationsTest.php tests/Feature/FieldService/FleetTrackerDashboardShortcutTest.php
npm run build
FLEET_QA_EXPORT=1 php artisan test tests/Feature/FieldService/FleetSelectedRoutesTest.php --filter='exports an isolated'
node scripts/qa/collins-fleet-browser.mjs
```

The opt-in exporter renders the real Blade page with fictional isolated data.
The browser server serves only local files and fixture JSON and rejects every
non-GET request. Screenshots are explicitly labeled as isolated QA. For a local
review, run `node scripts/qa/collins-fleet-browser.mjs --serve` on port 4328.
Stop this preview after review. `artifacts/collins-fleet/report.html` contains
prioritized audit findings, reproduction steps, screenshots and validation.

## Limits and follow-up

Production acceptance should verify live Bouncie trip retrieval, real refresh-token expiry,
production MySQL execution, actual unplug/reconnect delivery, physical mobile
devices and end-to-end password email delivery still require controlled acceptance.
Local browser tests use synthetic provider responses, never live fleet mutations.
The Bouncie REST geometry does not provide sample timestamps, so unknown short
gaps cannot be reconstructed or certified as continuous road travel. Trips
crossing a selected date boundary are labeled and shown in full when returned;
trips extending beyond the one-day query margin or retention may be unavailable.
Very large geometry is capped at 20,000 inspected points per geometry object and
marked incomplete. Provider history is memory-only/no-store, with no new durable
GPS store. Raw retained points keep the existing automatic pruning behavior.
No unrelated login, first-login, settings or navigation recommendation was implemented.
