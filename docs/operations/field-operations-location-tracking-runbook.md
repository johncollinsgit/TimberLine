# Field Operations: scheduling, timecards, and location tracking

## Scope and boundaries

Everbranch records field time and exports approved hours; it does not calculate
pay, withholding, taxes, benefits, filing, remittance, or initiate a payroll
payment. The approved CSV is the handoff to the tenant's payroll provider.

`time_tracking` is the tenant-scoped scheduling/timecard add-on. Managers can
schedule shifts, configure an optional clock-in window, review submitted time,
and export approved hours. A worker may request an edit only to their own
completed timer session. The original and requested values, decision, reviewer,
and timestamps are retained through `field_service_time_change_requests` and
the tenant audit log. Approved edit requests return the session to `submitted`
for ordinary manager approval; they do not silently self-approve payroll hours.

`fleet_tracking` is a separate, default-disabled, internal-only tenant add-on.
It has two intentionally separate feeds:

1. Company vehicle: a Bouncie OBD-II/LTE device mapped one-to-one to a
   tenant-owned or tenant-leased `field_service_vehicle`.
2. Crew phone: the Everbranch mobile app while the employee has an actively
   running timer. A paused or stopped timer rejects phone points. Do not infer a
   person from a van, and do not merge the feeds.

V1 has no speed scoring, route policing, geofence alerts, automated employment
decisions, or personal-vehicle/off-duty tracking.

## Required gates before any tenant rollout

All gates must be true. A provider webhook or mobile client assertion is never
enough by itself.

1. `FLEET_TRACKING_ENABLED=true` is enabled through the controlled deployment
   process. It is off by default.
2. The tenant has explicit `fleet_tracking` module access through the canonical
   module catalog/resolver, including its `fleet` and `time_tracking`
   dependencies.
3. An owner/admin records a policy version, policy text hash, retained counsel
   review reference, and a legal-review confirmation in the tenant settings.
4. Each phone-sharing employee accepts that exact current policy version in the
   mobile app. A new policy version requires a new acknowledgement.
5. The workspace owner/admin connects that workspace's Bouncie account through
   OAuth. The application webhook is configured to POST only to `/webhooks/bouncie` using the current
   `BOUNCIE_WEBHOOK_KEY`; the sender's `Authorization` or
   `X-Bouncie-Authorization` value is compared with `hash_equals`.
6. Every Bouncie device ID/IMEI is mapped to exactly one company vehicle in the
   tenant before an event is retained.

Legal requirements vary by location and facts. This gate records an operator's
review evidence; it is not legal advice and does not replace counsel's review
of the tenant's employment policy, notice/consent language, collective
bargaining obligations, and applicable state/local rules.

## Provider and map setup

- Bouncie OAuth uses authorization code plus PKCE. Each workspace stores its
  own access token, rotating refresh token, and provider account identifier in
  a tenant-scoped encrypted `IntegrationConnection`; tokens are never returned
  to a browser payload or written to logs. Bouncie exposes account-level access
  rather than selectable OAuth scopes, so Everbranch deliberately limits its
  use to account identity and company vehicle inventory/location data.
- The application-level webhook uses a deployment secret and globally unique
  Bouncie IMEI mapping to route an event. Duplicate cross-tenant mappings fail
  closed instead of guessing which workspace owns a tracker.
- Bouncie webhook deliveries must be safe to retry. Everbranch deduplicates by
  tenant, source, and a SHA-256 event key. Unknown devices and malformed points
  are ignored; unauthorized deliveries are rejected.
- Google Maps is a display layer, not a GPS tracker. `GOOGLE_MAPS_FLEET_API_KEY`
  must be a browser-restricted Maps JavaScript API key with production host
  referrer restrictions, separate from server-side keys, API restrictions, and
  a billing alert/budget. Until those restrictions are verified, the UI uses a
  direct Google Maps coordinate link instead of loading an interactive map.

## Mobile client contract

The server exposes these authenticated membership-scoped endpoints under
`/api/mobile/v1/workspaces/{tenant}`:

- `GET /field-service/shifts`
- `POST /field-service/timecard-change-requests`
- `GET /field-service/location-policy`
- `POST /field-service/location-policy/accept`
- `POST /field-service/location-points`
- `GET /field-service/crew-map` (contract v2 returns separate `crew` and
  `vehicles` arrays; the native app renders distinct employee and van pins)

The native client must request platform background-location permission only
after showing the policy and explaining the active-shift use. It should send a
point about every two minutes while moving, stop immediately on timer pause or
clock-out, expose a manual stop control, and never attempt a location write
outside an active timer. Android and iOS permission declarations/review copy
belong in the separate `everbranch-mobile` repository; this backend change does
not grant those operating-system permissions.

## Retention, access, and operations

- Raw location points are capped at 30 days. `fleet-tracking:prune-location-points`
  runs daily at 02:35 and permanently removes points outside the tenant's
  configured period (1–30 days).
- Only tenant owner/admin users (or a platform admin) can view the location
  workspace or configure tracking. Employee phone points are never included in
  normal job, payroll, or customer payloads.
- Pause the global flag to stop both collection and display. Preserve the short
  retention records and audit evidence; do not bypass the data-retention job.
- Rotate `BOUNCIE_WEBHOOK_KEY` in deployment configuration and Bouncie together.
  A failed signature check must be investigated without logging raw location
  payloads, tokens, or policy text.

## Fictional Green Shield Pest Control demonstration

`everbranch:prepare-pest-control-demo` is idempotent and creates the isolated
`green-shield-pest-control` tenant for sales/product walkthroughs. It contains
only fictional people, customer/job information, 20 varied service jobs,
tasks, notes, shifts, fictional job income/cost records, two company vans (one
Bouncie-mapped), and five sample location points. The Work homepage's money
cards and job-level finance panels are explicitly fictional and are never a
QuickBooks connection, accounting conclusion, tax calculation, or payroll data.

```bash
php artisan everbranch:prepare-pest-control-demo
```

The command deliberately refuses production unless `--force-production` is
given. Its public demo credentials are intentionally non-secret and must be
used only with this isolated workspace:

- Login: `demo@greenshieldpest.example`
- Password: `DemoPest!2026`

The public product tour is available at
`/platform/demos/green-shield-pest-control` and is rendered from the
`PestControlFleetDemo` Remotion composition to
`public/media/green-shield-fleet-demo.mp4`. Re-render after editing it with:

```bash
cd remotion
npm run render:pest-control-demo
npx remotion still src/index.ts PestControlFleetDemo ../public/media/green-shield-fleet-demo-poster.jpg --frame=150
```

Its CTA posts with CSRF protection to a demo-only handoff that signs into the
isolated fictional account and redirects directly to the Green Shield workspace.
It must never be generalized to authenticate a real user or tenant.

Its opening map scene uses the included, public OpenStreetMap-derived still at
`remotion/public/maps/green-shield-fleet-map-osm.png`. Keep the visible
`© OpenStreetMap contributors` attribution in the video. The animated route,
pins, service stop, and all tracking data are fictional overlays; do not use a
Google Maps screenshot or actual customer/employee location data in this asset.
The job-detail route overlays use the same attributed map with fictional route
coordinates stored in the isolated demo job metadata; they are not live GPS.

The authenticated Fleet Tracking page is still governed by the global
`FLEET_TRACKING_ENABLED` switch. Do not enable that switch merely to make a
sales demo convenient; complete the normal controlled rollout and policy
checks first.
