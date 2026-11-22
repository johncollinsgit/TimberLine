# Watershed co-op registration

Watershed runs at `https://watershedfellowship.com/register`; John and Sarah use
`/watershed?tenant=wren-family-soccer` in Everbranch. It is included under the
existing Wren tenant and soccer entitlement. The public Site remains public;
household and release endpoints require program-specific verified-email sessions.

## Identity and source ownership

Canonical families, children, birthdays and reviewed medical details remain in
`soccer_families` and `soccer_players` for tenant `wren-family-soccer`. Watershed
references those exact IDs; it creates no retail customer master, soccer admission,
Everbranch membership, marketing subscription or rewards ledger. Guardian aliases
remain organizer-verified. New program sessions/challenges cannot authorize Wren
sessions. Existing signed Wren and Watershed releases are immutable snapshots.

Additive `watershed_*` tables own terms, applications, reusable preapprovals,
school-year placement decisions, semester releases, original payment attempts,
audits, encrypted import evidence and email outboxes. Medical data and reasons
are encrypted; emails contain generic status and private links only.

## Rules and transitions

Spring 2027 belongs to 2026–27 and uses September 1, 2026. Nursery is under three,
Kinder is three through kindergarten, Lower Elementary is grades 1–3, and Upper
Elementary begins at grade 4. The maximum is `6 + max(0, school_year - 2026)`;
Upper Elementary expands with it. Age-derived grades are suggestions based on
SC September 1 entry ages, not official school records. A grade edit cannot bypass
the age-derived maximum. Children born after the cutoff who are already born at
registration can use Nursery. Future birthdays are invalid.

Preapproved complete applications with no unresolved exceptions become accepted.
New families and placement exceptions remain pending. Every exception needs a
written reason; approval lasts only through that school year. A denied placement
returns the application for parent review and fresh signatures. Accepting a family
does not automatically grant future preapproval. Leadership decisions use optimistic
versions and row locks. Signed releases reference the submission version even when
later admission decisions increment the application's current version.

Fees follow approved classes: Nursery $0, Kinder and older $75 per child. Acceptance
creates one invoice; Nursery-only families register without collection. No team
capacities, reserved seats, waitlists or admission expiry timers are used.

Payments use the existing tenant Stripe readiness and destination-charge account.
The provider request and idempotency key are encrypted and committed before HTTP.
Uncertain creates retain the original request for exact retry. Known expired unpaid
sessions can be replaced; unknown outcomes cannot. Verified existing Wren Stripe
webhooks dispatch only Watershed metadata to the Watershed lane. Parent status checks
also reconcile the original session. Registration requires verified amount, currency,
invoice, payment-intent success and original settlement account. Duplicate events
cannot create a second confirmation. Mismatches remain in review.

## Preparation and launch

Release through the repository's protected CI / migration rehearsal / Forge route.
Set `WATERSHED_ENABLED=true` and one random `WATERSHED_BRIDGE_SECRET` of at least 32
characters in the backend runtime and the existing Site's secret runtime settings.
Set `WREN_BACKEND_URL=https://app.theeverbranch.com` on the Site. Never put secrets
in Git, command arguments or logs. Deploy the Site only after the backend is ready.

Preview `php artisan watershed:prepare --roster=/private/source.csv --actor=ID`.
`--apply` initializes the closed Spring 2027 term and records each source fingerprint.
Only one exact reviewed guardian-name match can seed preapproval; unmatched or
ambiguous rows appear in the leadership matching queue. Historical Paid marks are
ignored. Stdin may be used as `/dev/stdin` so the private CSV need not be saved.
Replays do not reinstate a subsequently revoked preapproval.

John and Sarah are the explicit configured leaders and must also be active tenant
admins. They resolve roster matches, inspect registrations, download releases,
review exceptions, manage preapproval and open/close registration. Creating a new
semester selects the latest school year/term; it begins closed. Confirm dates and
readiness before opening. The first release deliberately leaves registration closed.

`watershed:process` runs every minute and claims each pending email before dispatch.
Accepted mail stores its provider ID. Failures remain uncertain; do not blindly
reset or resend. No initial roster import sends messages. No SMS is added.

## Verification and rollback

Use fictional fixtures. Cover SC boundaries, annual progression, nursery fees,
preapproval, exception review, fresh semester signatures, household/tenant isolation,
role checks, stale decisions, duplicate submissions, original payment recovery and
complete PDF text. Run the Wren workspace/registration/email suites after shared
middleware or webhook changes. Run migration lint, real disposable MySQL recovery,
full-tree upgrade rehearsal and the data-free schema fingerprint check.

Rollback closes registration and disables `WATERSHED_ENABLED`, then uses normal
backend and Site release controls. Keep all new tables, original provider attempts,
payments, signatures, audit and import records. Migration down is non-destructive.
