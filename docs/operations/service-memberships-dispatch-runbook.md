# Service Plans & Dispatch Pilot Runbook

## Scope and release state

`service_memberships` and `dispatch_command_center` are disabled, internal-only
Branches. They are tenant-neutral in code, but Collins Electric is the only
approved pilot. Do not market, self-enable, or include either Branch in a
commercial plan until the pilot checklist below is signed off.

Service Plans uses manual external invoicing. It must not collect a card,
retain payment details, invoke Stripe Connect, auto-charge a renewal, or treat
an offer acceptance as a payment or membership activation. Dispatch is
route-aware from saved service addresses; live location/GPS tracking is out of
scope until a separate employee consent, retention, and privacy review exists.

## Collins pilot procedure

1. Apply the two migrations and deploy the application before enabling either
   module. Restart queue workers so the membership scheduler code is current.
2. Confirm the Collins tenant has each dependency enabled. An operator then
   enables `service_memberships` through the canonical module resolver and
   records the pilot decision in the tenant audit timeline. Do not bypass the
   resolver with a direct UI flag or database edit.
3. An owner/admin completes plan settings, publishes a versioned test plan,
   uses only tenant-owned workspace photos, and creates an internal offer.
   Check desktop and mobile views, photo order/alt text, expiry, revocation,
   add-on totals, and the acceptance audit entry.
4. For a real customer, copy the generated link only through an approved
   customer-contact channel. The link is a short-lived, rate-limited,
   single-purpose secret. Never place it in a public page, ticket attachment,
   analytics event, or audit context.
5. After signed acceptance and verified external payment/manual arrangement,
   a manager or owner records only the external invoice reference and optional
   HTTPS invoice URL, then activates the membership. The reference is staff
   data and must never appear in the portal or mobile contract.
6. Verify recurring work with
   `php artisan field-service:generate-membership-visits --tenant-id=<id>`.
   Re-run it once: its membership lock and unique membership/period key must
   leave exactly one visit and one linked `FieldServiceJob`.
7. Enable `dispatch_command_center` only after Service Plans produces pilot
   recurring work. Configure hours, zones, technician skills/capacity, vehicle
   availability, travel buffer, customer message drafts, and escalation rules.
   Perform one dispatcher-confirmed day before relying on the board.

## Operating controls

- Owner/admin: plan terms, pricing, add-ons, images, and customer wording.
- Manager: offers, acceptance handling, visit operations, and manual
  activation after external verification. Technicians cannot change plan price
  or terms.
- Portal tokens are stored only as SHA-256 hashes. Portal media is streamed
  after the token and offer snapshot are authorized; do not make workspace
  storage paths public.
- A reschedule writes an immutable dispatch event and sends an internal
  job-notification event. Customer change messages remain suppressed unless
  the approved messaging template and customer consent are both independently
  verified.
- Keep customer-facing descriptions, plan snapshots, selected add-ons, media,
  acceptance, and membership events immutable. New plan edits publish a new
  version; never rewrite accepted offer or active-membership snapshots.

## Rollback and acceptance gates

Disable the Branch through the module resolver if a pilot is paused. Existing
membership and dispatch records remain preserved; do not delete them to roll
back. Disable the scheduler entry or worker only when needed to halt *future*
generated work, then reconcile pending visits manually.

Before customer rollout, pass tenant-isolation, token expiry/revocation/media,
immutable snapshot, manual-activation, due-visit retry/idempotency, role,
accessibility, and notification-consent checks. Before Dispatch rollout, also
pass overlap/travel-buffer, capacity, skills, availability, timezone/DST,
audit, mobile My Day, and customer-notification suppression checks. Run the
full Pest suite, static/type checks, frontend build, and dependency audits.
