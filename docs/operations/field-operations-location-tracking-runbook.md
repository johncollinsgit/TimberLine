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
5. Bouncie is configured to POST only to `/webhooks/bouncie` using the current
   `BOUNCIE_WEBHOOK_KEY`; the sender's `Authorization` or
   `X-Bouncie-Authorization` value is compared with `hash_equals`.
6. Every Bouncie device ID/IMEI is mapped to exactly one company vehicle in the
   tenant before an event is retained.

Legal requirements vary by location and facts. This gate records an operator's
review evidence; it is not legal advice and does not replace counsel's review
of the tenant's employment policy, notice/consent language, collective
bargaining obligations, and applicable state/local rules.

## Provider and map setup

- Use Bouncie OAuth/API credentials only through a tenant-scoped encrypted
  `IntegrationConnection` when an OAuth connection screen is added. Do not put
  Bouncie tokens in a browser payload or logs. The initial webhook path uses a
  deployment secret and device mapping to receive Bouncie's location events.
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
only fictional people, customer/job information, a scheduled termite visit,
one Bouncie-mapped company van, and five sample location points.

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

The authenticated Fleet Tracking page is still governed by the global
`FLEET_TRACKING_ENABLED` switch. Do not enable that switch merely to make a
sales demo convenient; complete the normal controlled rollout and policy
checks first.
