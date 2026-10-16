# Wren soccer operations

The soccer lane is additive, tenant-scoped to `wren-family-soccer`, and disabled
by default. Laravel owns families, players, season admissions, immutable signed
releases, import evidence, invoices, recorded payments, refunds, and delivery
outboxes. Parent sessions use verified email and create no Everbranch user or
membership. Coaches are active members with explicit team assignments; financial
and website actions remain tenant-admin-only.

## Release and initialization

Release through the standard GitHub test/build, MySQL migration safety, and Forge
atomic release gates. Deploy the matching `easleyfamilysoccer.com` Sites source.
Configure `SOCCER_ENABLED=true` and the same cryptographically random
`WREN_SOCCER_BRIDGE_SECRET` on Laravel and Sites. Sites also uses
`WREN_BACKEND_URL=https://app.theeverbranch.com`. Never commit runtime secrets,
CSV contents, release JSON, GroupMe tokens, or private invite links.

Run `soccer:prepare-wren --actor=<active Wren admin>` to preview. Supply `--spring`,
`--fall`, and `--releases` with private source paths; inspect aggregate counts.
`--starts-on`, `--age-as-of`, and `--opens-at` configure the confirmed dates.
`--groupme` reads the supplied mapping from a private JSON file. Only `--apply` commits. Initialization audits a zero-price included soccer
entitlement and focuses Wren navigation. Seasons remain closed. Source rows are
fingerprinted, D1 releases retain their original source IDs, signatures, and
answers, and imports never infer payments from payment-method notes. Alias,
changed-email, missing-contact, and date conflicts require admin review.
Additional guardian emails are admin-verified family aliases and still require
email-code verification; they never create backend memberships.

The default teams have public/reserved capacities 25/5, 44/6, and 35/5 for ages
3-4, 5-7, and 8+. The owner confirmed March 15, 2027 as both first practice and age cutoff.
Registration is scheduled for October 19, 2026 at 00:00 America/New_York.
The minute scheduler opens only after readiness passes and consumes the schedule
once; a later admin closure stays closed. Age and opening dates are separate.
The season row serializes allocation and monotonic queue order. Active offers
and confirmed admissions count against the relevant seat pool. A strict public
majority enables reserve use only when all remaining siblings fit. Automatic
waitlist offers require each waiting sibling to head its team queue.

## Required account setup before opening

- Complete Wren-owned Stripe Express onboarding in Settings. Onboarding and
  checkout retain the canonical tenant-payment rollout gates. Live Connect
  webhook verification blocks collection, but does not block starting onboarding;
  the account must exist before a controlled payment/refund can verify callbacks.
  Refresh checks
  Stripe's current charges/payouts flags; no parent declaration confirms payment.
- Configure the signed soccer endpoint `/api/soccer/stripe`, including
  `WREN_SOCCER_STRIPE_WEBHOOK_SECRET`. Subscribe to checkout completion and refund
  events. Confirm duplicate handling and a real controlled payment/refund before
  marking platform Connect webhooks verified. Never enable unrelated tenants.
- Provision Wren SMS with the existing tenant messaging provisioning service.
  `FEATURE_TENANT_SMS_PROVISIONING_TENANT_IDS=13` approves Wren only without
  enabling global provisioning. Settings prepares the subaccount and stores the
  legal operator details encrypted; EINs never populate forms or flash input.
  Complete its required business profile, brand, campaign, service, and number.
  Point signed inbound/status callbacks to `/api/soccer/sms`; enable
  `WREN_SOCCER_SMS_ENABLED` only once the sender is ready.
- Confirm live sign-in email delivery. Map Soccer, All Coaches, and Head Coaches
  in encrypted Settings. Each sender connects their own GroupMe token in Messages.
  Both the Wren role and GroupMe membership are checked before reading/posting.
- Confirm the season date, capacities, and admin smoke-verification checklist.
  Opening fails closed while any readiness blocker remains.

## Daily administration

One family submission can include several children. Each child has an immutable
season release and a separate PDF. Shared guardian details/signatures are reused
inside that submission; recurring medical details require fresh review. PDF
continuation pages preserve long answers instead of losing clinical notes.

Jersey fees are $30 without a new jersey and $50 with sizing. A coaching volunteer
may choose two household children per season for $0, including jerseys. Those
children confirm immediately once a place is allocated; backend membership still
requires admin invitation/assignment.

`soccer:process` runs every minute: offers expire after 48 hours by default,
reminders are deduplicated at 24 and 2 hours, and expired players preserve queue
priority but require admin reactivation. Admin offer withdrawal, team overrides,
reserve allocations, cancellations, and every waitlist mutation have audit
records. Durable encrypted SMS deliveries summarize the family action for Sarah
and appear with delivery results/replies in Messages. Opt-out is rechecked before
family delivery; failed sends retry up to five times. Ambiguous in-flight sends
remain visible for review and are not blindly resent.

Stripe payment-intent and provider IDs deduplicate confirmations. Late or
mismatched transfers are recorded for admin review without allocating seats.
Refunds operate against a selected recorded payment and its remaining refundable
balance. Stripe requests have a durable idempotency key; completed manual Venmo
refunds require admin confirmation. Refunding never cancels an admission.

## Rollback

Close registration first. Disable soccer processing/SMS if necessary and use the
normal backend rollback. Restore the previous immutable website version or Sites
publication. Preserve all additive records, source evidence, releases, payments,
refunds, and published snapshots. Never drop soccer tables or delete originals
as part of rollback.
