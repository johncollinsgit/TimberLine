# Everbranch Fleet private CRM app

## Current operating mode — 2026-10-07

The owner requested temporary removal of the Everbranch subscription and a
100-vehicle limit per client. `HIGHLEVEL_FLEET_SUBSCRIPTION_REQUIRED=false`
is the default for this release. Set Marketplace pricing to **Free** as well;
a backend access switch does not cancel a provider subscription. Preserve the
previous monthly plan ID and historical payment events for a later restoration.

Free installed accounts have access regardless of plan ID/payment status.
Bouncie authorization, device selection and policy settings use `setupAllowed()`
and work before collection activation. Collection remains separately gated by
`HIGHLEVEL_FLEET_COLLECTION_ENABLED`, `HIGHLEVEL_FLEET_PILOT_LOCATIONS`, existing
Fleet entitlement/global tracking and approved policy. No collection or pilot
allowlist is activated by this release. The Bouncie subscription remains separate.
In free mode, billing verification is not a collection prerequisite. An
uninstalled account has no access in either mode and receives no new locations.

The backend configured vehicle limit is authoritative for validation, selection,
bootstrap and UI counts; it is now 100. Check selection of 100 and rejection of
101, both browser engines, client isolation and real provider load before expanding
live pilots. Earlier 25-vehicle test evidence below is historical.

To restore billing: configure and publish the intended paid Marketplace plan,
verify plan/payout readiness, then explicitly set
`HIGHLEVEL_FLEET_SUBSCRIPTION_REQUIRED=true` and rebuild config through the normal
release process. Existing payment records are preserved; review pending/failed
accounts before re-enabling the requirement. Do not fabricate COMPLETE events.

The original paid-launch reference and historical production evidence follow;
its billing prerequisites apply when subscriptions are required.

## Product and release boundary

Everbranch Fleet is a private, agency-admin-installed subaccount app. The app
record created on 2026-10-04 is `6ac30456b707d87f0573a733`. Its monthly plan
created in the portal is `6ac309e6efe2cd1f9e8defc1` ($99, no trial). One installation
owns one new Everbranch workspace; existing workspaces are never linked. The
reference portal settings are in `docs/integrations/highlevel-fleet/app-configuration.json`.
An hourly `highlevel:fleet-reconcile` task recovers incomplete bulk installs from the provider’s installed-account list without duplicating completed workspaces. Token refreshes are serialized and recheck credentials under a database lock before saving, so disconnect wins concurrent refreshes.

This file is an operator reference, not a CLI manifest. No provider secrets
belong in source control.

Native HighLevel billing is authoritative: $99 USD/month per account, 100 active
vehicles, no setup fee or trial. Bouncie is a separate client subscription.
Bridge City controls resale markup in its agency settings. Everbranch does not
create a Stripe subscription. Payout verification and a real paid private-app
installation are required before setting `HIGHLEVEL_FLEET_BILLING_VERIFIED=true`.
If the portal cannot collect private-app subscriptions, keep billing and
collection disabled and resolve with HighLevel before accepting paying clients.

The public URL prefix is `/crm/fleet`, because white-label listing validation
rejects provider names in redirect URLs. These routes do not expose the main
Everbranch navigation, Jobs, Time, Inventory, employee phone tracking, commerce,
CRM data or a nested app marketplace. Internal dependencies are granted through
the canonical module catalog and audited commercial service; they are not
exposed as product navigation.

## Developer setup

1. In the existing Everbranch developer account configure the private app:
   subaccount target, agency-only installers, mandatory bulk installation,
   white-label listing. Keep the public listing unpublished.
2. Use only `locations.readonly`, `users.readonly`, `oauth.readonly`,
   `oauth.write` and `marketplace-installer-details.readonly`.
3. Add the OAuth redirect
   `https://app.theeverbranch.com/crm/fleet/oauth/callback`. Set the default
   redirect to it. Marketplace-originated callbacks prove the agency and
   installer with the OAuth grant and provider APIs; the app-initiated install
   URL additionally uses a single-use OAuth state.
4. Add the Everbranch custom page/sidebar URL
   `https://app.theeverbranch.com/crm/fleet/launch` with label **Everbranch**.
   Generate the user-context shared secret in the portal. Store client ID,
   client secret and shared secret in Forge environment configuration, not
   in a browser or this document.
5. Register INSTALL, UPDATE, UNINSTALL and APP_PAYMENT_STATUS deliveries at
   `https://app.theeverbranch.com/crm/fleet/webhooks/lifecycle`. Ed25519
   `X-GHL-Signature` verification uses the raw request bytes. RSA fallback is
   intentionally omitted after HighLevel's stated legacy deprecation date.
6. Configure the one monthly plan and developer payouts. Save the actual plan
   ID as `HIGHLEVEL_FLEET_PLAN_ID`; do not substitute an invented ID.
7. Register/extend the Bouncie developer app with callback
   `https://app.theeverbranch.com/crm/fleet/bouncie/callback` and authenticated
   webhook `https://app.theeverbranch.com/crm/fleet/webhooks/bouncie`. Use a
   separate high-entropy `HIGHLEVEL_BOUNCIE_WEBHOOK_KEY`. The established
   `/integrations/bouncie/callback` and `/webhooks/bouncie` remain available for
   standalone Everbranch users.
8. Configure `GOOGLE_MAPS_FLEET_API_KEY` as a browser-referrer-restricted Maps
   JavaScript API key for the canonical Everbranch app origin, with API
   restrictions and a billing budget/alert. Production has no verified Fleet
   map key as of this release; do not reuse a server-side Places key. No new
   hosting service is required.
9. Set the getting-started page to
   `https://app.theeverbranch.com/crm/fleet/guide`. It uses the same narrow
   iframe policy as the embedded Fleet workspace and requires no account data.

The implementation pins the documented `2021-07-28` HighLevel API contract.
Do not switch it to v3 without updating endpoint casing, pagination and fixtures.

## Environment and pilot release

Use the existing GitHub CI and Forge atomic release process. Required gates:
focused Fleet tests, Modern Forestry regression, full Pest suite, frontend
build, migration lint, MySQL partial-DDL recovery, prior-release rehearsal and
schema baseline verification. The schema dump is generated only against the
named disposable test database. Do not change production data to make tests pass.

Start with:

```
HIGHLEVEL_FLEET_ENABLED=false
HIGHLEVEL_FLEET_COLLECTION_ENABLED=false
HIGHLEVEL_FLEET_BILLING_VERIFIED=false
HIGHLEVEL_FLEET_SUBSCRIPTION_REQUIRED=false
HIGHLEVEL_FLEET_PILOT_LOCATIONS=
```

Deploy migrations and code, verify `/ready` identifies the approved release,
then configure secrets and enable the app surface while collection stays off.
Check the worker listens to `HIGHLEVEL_QUEUE` (default is the existing
`default` queue). Production drains the database/default queue every minute
through its existing scheduled `queue:work --stop-when-empty` command; no new
daemon was provisioned. Worker and Fleet job timeouts are 120s. Keep the
database reservation (`DB_QUEUE_RETRY_AFTER=180` in production) greater than
the maximum job timeout so a running job is not reserved twice. Fleet jobs
declare their own eight attempts, overriding the drain command's default.
The existing scheduler runs `highlevel:fleet-maintain` every five minutes and
`fleet-tracking:prune-location-points` on its established schedule.

### Production iframe policy

Forge's Nginx site configuration also adds a frame-ancestor policy. Browsers
enforce the intersection of that policy and Laravel's policy, so both must
permit the verified CRM parent. Site `3053351` now selects the CRM policy only
for `/crm/fleet` or its descendant routes (including query strings):

```nginx
set $everbranch_frame_policy "frame-ancestors 'self' https://admin.shopify.com https://*.myshopify.com https://*.shopify.com";
if ($request_uri ~ "^/crm/fleet(?:/|\\?|$)") {
    set $everbranch_frame_policy "frame-ancestors 'self' https://app.gohighlevel.com https://app.bridgecitymarketing.agency";
}
add_header Content-Security-Policy $everbranch_frame_policy always;
```

Keep Laravel's exact parent-origin check as well. Do not broaden the global
policy, change Shopify embedded cookies, or add a conflicting X-Frame-Options
header. This configuration was saved and validated through Forge's Nginx
editor. The original file is backed up privately under
`/home/forge/.config/everbranch-fleet-backups/nginx-before-fleet-20261005.conf`.

### Environment maintenance

Secrets live in the existing shared production environment file. Before an
authorized change, take a private backup, preserve unrelated values and file
permissions, and rebuild the config cache. When running `config:cache` outside
the deployment script, export `RELEASE_ID` from the current release's
`git rev-parse HEAD` for that command; the deployment normally supplies it.
Do not pin an old release ID in `.env`. Verify `/ready` still reports that
exact SHA, then broadcast `queue:restart` after relevant queue/config changes.

After the pilot is approved (and native billing verified if subscriptions are required), use exactly the two pilot location IDs
in `HIGHLEVEL_FLEET_PILOT_LOCATIONS`. Keep existing `FLEET_TRACKING_ENABLED`,
tenant module entitlements and approved policy checks in force. Enable collection
only for this allowlist; adding a client requires an explicit allowlist change.
No unconfigured installation collects locations by default.

## Agency onboarding guide

1. Agency owner/admin installs the private app to the selected client accounts.
   Pricing is Free during this preview; confirm native pricing before installation.
2. Each account opens **Everbranch** in its CRM navigation. Current administrator
   membership is checked server-side. Ordinary users have no Fleet v1 access.
3. Settings: record the company vehicle tracking policy version, approved policy
   text and owner approval reference, confirm authorization, choose a retention
   period of 1–30 days, and enable company vehicle collection. Only a SHA-256
   fingerprint of the policy text is stored; retain the policy document separately.
4. Connection: authorize that client's Bouncie account in a top-level popup.
   Allow popups for the Everbranch app. Third-party cookies need not be enabled.
5. Choose up to 100 devices. Devices must come from that authorized account and
   cannot already be actively owned by another workspace. The 26th selection
   is rejected by the server. Save, then open Fleet.
6. Check vehicle name, location and **provider last reported** timestamp. An old
   parked-vehicle reading is labelled **older location reading**, never assumed
   offline. Provider outage preserves valid retained readings.

Support contact is always `config('everbranch.support_email')`, currently
`EVERBRANCH_SUPPORT_EMAIL` with the established fallback. Do not hardcode a
second support address into the app or portal material.

## Live acceptance checklist

Live acceptance is incomplete until all of these are recorded:

- Two separate HighLevel client accounts, two authorized Bouncie accounts and
  active devices. Confirm each workspace sees only its own data and settings.
- Single and bulk install, repeated OAuth callbacks, INSTALL-before-OAuth and
  OAuth-before-INSTALL, queued delivery retry and future-location installation.
- Native private-app initial collection, failed payment, bounded 30-day grace,
  recovery, uninstall billing outcome and developer payout readiness.
- Token renewal, Bouncie reconnect and account replacement, removed/restricted
  administrator access, revoked sessions and OAuth reinstall.
- Invalid signatures, malformed events, duplicate trips, out-of-order samples,
  provider outage, 100-device selection, 101st rejection, retention pruning.
- Chrome and Safari embedding at desktop/mobile sizes with third-party cookies
  blocked, popup authorization, page visibility refresh and session renewal.
- At 100 vehicles/client, record queue age, job runtime, DB growth, map usage,
  CPU/RAM and `/ready`. Share one map per page; inspect map billing before
  expanding. Existing production hosting capacity has not been load-proven
  solely by local mocked tests.

## Disconnect and uninstall

**Disconnect Bouncie** clears provider tokens, deactivates device mappings and
stops collection. It does not cancel Bouncie. Retained GPS is still pruned to
the workspace's retention period, never exceeding 30 days.

An agency administrator uninstalls the app in HighLevel to end the native app
subscription. UNINSTALL revokes all embedded sessions, clears HighLevel and
Bouncie tokens and mappings, and disables collection. The app's disconnect API
can stop Everbranch access immediately, but the response explicitly instructs
the agency to uninstall in HighLevel to end app billing. A delayed payment event
cannot resurrect an uninstalled workspace. Reinstall reuses its workspace and
requires fresh authorization and billing confirmation.

## Support and recovery

- App not loading: inspect active config, canonical host, exact parent origin
  allowlist, signed INSTALL domain and current HighLevel administrator access.
  No wildcard frame ancestors or cross-origin cookie changes are needed.
- API 403 after role change: sessions are revoked; have a currently authorized
  administrator reopen the app. Do not grant `platform_admin` or match identities
  by email. Shadow identities have random `.invalid` addresses and disabled
  password login, and are bound to installation and provider user ID.
- Payment pending: compare portal plan ID, signed INSTALL/APP_PAYMENT_STATUS
  deliveries and native billing readiness. Never create a second subscription.
- No GPS: check collection allowlist, global tracking gate, approved policy,
  connection status, active mapping and device-to-connection provenance.
- Provider outage: preserve saved locations; do not log raw provider exceptions
  because they can include credentials or GPS. Error responses are safe messages.
- Queue issue: inspect `highlevel_webhook_events` identifiers, provider,
  received/processed times, attempts and generic error code. Payloads are
  encrypted and cleared after successful processing. Accepted pending events
  older than 35 minutes retry through the scheduler. After 24 attempts or two
  days, inspect/replay an event manually once the root cause is fixed. Never
  dump decrypted GPS into logs. Unprocessed payloads are cleared at 30 days.
- Session/auth state rows are pruned after expiry without breaking live OAuth
  tickets. Ciphertext replay fingerprints expire after one day; HighLevel's
  documented CBC context has no embedded timestamp, so always fetch fresh
  context for launch and renew and independently verify provider membership.

## Rollback

1. Set `HIGHLEVEL_FLEET_COLLECTION_ENABLED=false` immediately. Ingestion checks
   this again when queued events run, including mapped devices arriving through
   the established Bouncie webhook route.
2. Remove pilot IDs and set `HIGHLEVEL_FLEET_ENABLED=false` if the embedded
   surface must close. Preserve identifiers, workspaces and audit records.
3. Use Forge's retained prior release through the established release authority;
   verify `/ready` after activation. Do not reset the live checkout or replace
   assets manually.
4. Additive migrations deliberately retain installation tables and provenance.
   Do not run a destructive migration down or delete tenant data to roll back.
5. The prior application does not understand HighLevel's billing gate. Before
   activating an older release, deactivate these tenants' Bouncie mappings and
   turn their vehicle collection settings off using the disconnect procedure;
   otherwise the legacy webhook could resume collection. Never change unrelated
   standalone tenants' tracking settings or global Bouncie credentials.
6. Notify agency admins through the agreed support process and arrange native
   subscription handling in HighLevel if service remains suspended.

## Primary provider references

- [Distribution and bulk authorization](https://marketplace.gohighlevel.com/docs/oauth/AppDistribution/)
- [Pinned installed locations API](https://marketplace.gohighlevel.com/docs/2021-07-28/ghl/oauth/get-installed-location/)
- [Current user membership lookup](https://marketplace.gohighlevel.com/docs/2021-07-28/ghl/users/search-users/)
- [Encrypted user context](https://marketplace.gohighlevel.com/docs/other/user-context-marketplace-apps/index.html)
- [Webhook authenticity](https://marketplace.gohighlevel.com/docs/2021-07-28/webhook/WebhookIntegrationGuide/index.html)
- [Native payment status events](https://marketplace.gohighlevel.com/docs/webhook/AppPaymentStatus/index.html)
- [Private app policy](https://marketplace.gohighlevel.com/docs/2021-07-28/MarketplacePolicies/PrivateAppInstallLimits/)
- [Bouncie API specification](https://docs.bouncie.dev/openapi.json)

## Local verification evidence (2026-10-04)

- 19 focused HighLevel tests, 87 assertions passed (plus the existing Fleet regression suite); includes actual
  tenant provisioning, OAuth callback order and Bouncie PKCE popup state.
- 156 Modern Forestry compatibility tests, 845 assertions passed.
- 28 MySQL migration recovery tests, 123 assertions passed against an empty
  disposable MySQL 8.4 database. Prior-release upgrade rehearsal from
  `d0aef743` passed; generated schema baseline matched both clean migrations
  and dump bootstrap.
- Chromium and WebKit embedding tests passed with 25 vehicles, separate parent
  and app origins, cookie-free authenticated requests, empty browser storage,
  search, selected details, 26th selection rejection and 390px responsive layout.
- Frontend production build, migration lint and changed-file Pint passed.
- Final full repository suite passed: 2,714 tests and 19,351 assertions. CI
  results are recorded in the release PR.

These tests use provider fixtures. They do not prove real provider account
access, native billing/payouts, production capacity, or completion of the two
live pilots. Collection stays off until live prerequisites are available.

## Production setup evidence (2026-10-04)

- PR #357 merged; GitHub tests run `37256053501` passed quality, full CI and
  MySQL migration recovery. Deployment run `37256301413` passed the release
  gate and triggered Forge deployment `79338404`.
- `/ready` returned healthy release
  `f53af086d246719fa240f53c518d1e1b0d06901c`. The embedded launch returned 200;
  both Laravel and Nginx permitted only the configured CRM parents. The live
  `/shopify/app` returned 200 with its original Shopify-only frame policies.
- Production app surface is enabled; credentials and the exact app/plan IDs
  are configured. Collection and billing verification are false and the pilot
  location list is empty. No installation collects vehicle data by default.
- Developer portal shows version 1.0.0 **Private / Live**, with zero installs.
  The sidebar page, five scopes, OAuth callback, shared secret, default
  lifecycle endpoint and $99 monthly no-trial plan are saved. Per-location
  charging and agency resale behavior still require a real pilot check.
- HighLevel's published payout process uses an invitation to Tipalti after
  payout eligibility. Payout readiness is unverified; do not substitute a
  separate Stripe subscription or claim payout onboarding is complete.
- Remaining live inputs: two selected CRM accounts, two authorized Bouncie
  accounts/devices, a restricted Fleet map key and native billing/payout verification.

The existing Bouncie developer application `6a9c7a497e9ca0d25650940f` now
registers both the established standalone callback and the dedicated Fleet
callback. Fleet webhook `6ac3156cfbb712d6e1204f8c` has its own authentication
key and subscribes only to tripStart, tripData, tripEnd and tripMetrics. It is
**Deactivated** pending the pilot; the existing standalone webhook remains
active. Activate the Fleet webhook only after native billing, collection gates
and the two pilot locations are ready. No Bouncie account has been connected
to a CRM workspace during this setup.

## Fleet operations and isolated demo (2026-10-08)

Everbranch owns the software, hosting, fleet settings and planning. HighLevel
remains the work source. No `FieldServiceJob` is required or created. Managers
choose a calendar or an open-opportunity pipeline, then sync a bounded source
snapshot. Only title, reference, status, assignee and supplied schedule are kept,
with encrypted fleet coordinates/schedule/skills/stock overlays. Configure the
source after learning where the client keeps work; do not infer jobs from contacts.
The additional read scopes are `calendars.readonly`, `calendars/events.readonly`
and `opportunities.readonly`. Existing installations may need agency approval
or reauthorization before source reads work. There are no CRM writes.

- Fleet map: latest provider location, manually assigned crew, scheduled current
  job and next stop; HighLevel stops appear as separate map markers.
- Connection health: provider refresh time, 15-minute older-reading labels,
  explicit provider connect/disconnect signals and count of unselected devices.
  A parked/stale vehicle is not automatically declared disconnected.
- Maintenance: provider odometer, date/mileage service plans, automatic deduped
  due tasks, service history and recurring intervals advanced after confirmed
  completion. Tasks run during bootstrap and five-minute maintenance. Odometer
  and due tasks depend on received provider data; history remains after GPS expiry.
- Trips: trip start/end plus Bouncie tripMetrics miles, total seconds and idle
  seconds. Drive time is total minus idle only when both metrics exist. GPS path
  is retained reported samples, not a reconstructed or verified road trace.
  Matching schedule references are suggestions; confirmation is always manual.
- Route review: manager supplies an approved road-distance baseline and its
  reference, extra-mile allowance (default 5), and extra-percent allowance
  (default 30). Review is flagged above baseline plus the larger allowance.
  Missing baselines remain explicitly unassessed. Approved detours keep the
  numerical flag and a separate recorded decision. No misconduct inference,
  automatic penalty or straight-line-as-road comparison is made.
- Health alerts: check-engine and low/critical battery provider events, assigned
  person, open/in-progress/resolved status and required resolution notes.
  Manual resolution does not clear a hardware fault. Snapshot polling can
  supply MIL/battery events; enable Bouncie `battery`, `mil`, `connect`, and
  `disconnect` subscriptions alongside tripStart/Data/End/Metrics at activation.
- Dispatch: recent located, explicitly available crews are ranked by straight-line
  distance after schedule conflict, skills and stocked-quantity checks. Unknown
  schedules on existing assignments block recommendations. The dispatcher chooses
  a suggestion in the fleet form and explicitly saves; no automatic CRM assignment.

Storage: `fleet_operation_records` is tenant-scoped and encrypts all payloads.
Provider deliveries dedupe by device/trip or event timestamp and preserve newer
field values and manager reviews. Trips, telemetry and work snapshots follow
1–30-day tenant retention; maintenance history and non-GPS alert decisions remain.
All provider ingestion retains collection, entitlement, connection, mapping and
policy gates. No account has been activated by this feature release.

`/crm/fleet/demo` is a public synthetic preview; the embedded app also offers
Explore demo. No embedded session or provider request is made in standalone demo.
Every change is browser-memory only and resets on reload. Never seed demo data into
client tables. The sample company, people, appointments, routes and readings are
fictional. The demo includes all requested feature sets and an ambiguous trip match.

Maps: a restricted `GOOGLE_MAPS_FLEET_API_KEY` selects Google Maps JavaScript in
the real fleet map. Without it, bundled Leaflet 1.9.4 provides an interactive
OpenStreetMap street map, with visible attribution, origin-only tile referrer,
normal browser caching and no prefetch/offline download. The tile service is
best-effort; switch `HIGHLEVEL_MAP_TILE_URL` and attribution to a suitable provider
if deployment volume grows. Tile service sees the requested map viewport.

Validation covers two-client isolation, signed admin session, uninstall denial,
replay/out-of-order telemetry, gates, encrypted payloads, task recurrence,
schedule/skills/stock dispatch exclusions, source scoping and private-data expiry.
Chromium and WebKit verify 100 vehicles, responsive layout, all demo feature
screens and browser-only demo mutations. The schema is built/verified only in a
disposable MySQL 8.4 database, with a retained-table recovery scenario.

The fictional preview at `/crm/fleet/demo` may use `GOOGLE_MAPS_FLEET_DEMO_API_KEY` from a no-cost Google Maps Demo Project (currently `gmp-demo-project-344944647`). It is strictly for evaluation with fictional data, never live tracking. Production fleet maps use the separate `GOOGLE_MAPS_FLEET_API_KEY`; without it, the app displays the interactive OSM street map. Explore demo opens a separate tab so testing SDK credentials cannot carry into real account rendering. Google demo quotas may pause the testing map. Activate and restrict a billing-enabled production key before using Google Maps for real clients.

For the Bridge City pilot/demo, keep distribution private and use the fictional preview through an agency-managed Custom Menu Link or direct demo link. User-facing white-label screens use “CRM” and avoid HighLevel branding, endorsement claims and logos. The preview is explicitly fictional and requires no installation, CRM context, provider authorization or real records. Private app distribution is limited to five agencies (sub-accounts do not count toward that cap); broader distribution requires the applicable public/security review path. Do not treat a demo as approval for production collection.
