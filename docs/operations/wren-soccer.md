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
The Alumni Family path checks for a matching family or verified guardian email
before sending its branded sign-in code. Unknown addresses receive a clear
prompt to check their prior email or choose New Family.

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

### SMS connection correction, 2026-10-19

The original SMS preparation used Twilio's account-specific update URL instead
of the account-creation collection. Wren's prepared row consequently referenced
the platform SID; no sender was ready and no soccer deliveries were recorded.
Existing platform numbers, services and brand approvals are not Wren resources.
The corrected service validates child ownership and rejects parent/shared account
bindings for provisioning, delivery, callbacks and registration completion.

Account creation has a durable encrypted intent reservation before HTTP. Unknown
outcomes stop for operator reconciliation, including after a connection timeout;
do not delete the reservation and retry. Reconcile a complete platform child-account
scan against the frozen intended name and tenant evidence before any new create.
Preserve the incorrect binding encrypted in the repair evidence, detach only
Wren's local reference, then create or adopt its proven dedicated child. Never
move platform numbers, brands or campaigns, or mark Wren ready from their status.
The saved business form is not a provider submission. Retain its encrypted EIN,
the existing $15 owner credit and GroupMe connection during repair. Provider
registration, approval and actual SMS delivery proof still gate opening.

### Launch connections

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
Soccer SMS starts only from 08:00 through 19:59 America/New_York; messages
queued overnight wait until 08:00. Offer and reminder texts include a link to
the family's specific invoice. The website requires verified family sign-in
before opening Stripe Checkout, and queued payment links are suppressed after
the invoice is paid or expires. GroupMe remains the team conversation channel.

Signed-in families can ask Wren help about teams, fees, waitlists, schedule,
and releases. Every exchange is stored encrypted in soccer events. Unanswered
questions capture the recent conversation and appear in the admin Messages
review queue. The assistant uses only public season facts for AI answers and
does not include saved family profiles or medical releases in model requests. Parent-confirmed
birthday corrections update the active player record and audit old/new values;
historical signed releases remain unchanged. Existing active registrations
require Wren review before a birthday changes because the team may change.
Common help intents answer locally without an AI credential, including casual
`2yo`/`almost 3` questions, human contact, season dates, the full church address,
and requests to move up. A human request immediately gives Sarah's text/call
number. Under-three players cannot enroll in Spring, while family profile saving
remains available. Returning eligibility stays through age 12 at the cutoff.
Registration answers read the saved season's actual open flag and required start
date; future scheduled openings are shown only from `registration_opens_at` in
Eastern time. Practice dates never substitute for registration opening dates.
The approved Spring schedule ends May 17, 2027, on Monday evenings 5:30–6:30 PM.
The attachment's old $25 price and swapped team labels are not authoritative;
current $30/$50 fees and Green Bears 3–4, Blue Dragons 5–7, Red Falcons 8+ remain.
Unknown answers refer to Sarah without claiming a question has been saved.
Successful signed-in exchanges still persist encrypted review evidence as above;
the AI is explicitly prohibited from claiming it changed or saved records.
AI requests contain the current question and public program facts only. Stored
conversation answers are not forwarded because local team assessments and
birthday confirmations may contain private family information. Encrypted history
and admin review transcripts remain available inside their existing access scope.
The family form collects spouse name and phone in place of the old secondary
phone field and no longer asks for a pickup phone. Assistant coach volunteers
identify Mom or Dad, give the coach name and adult jersey size, select all
preferred age groups, and confirm availability for at least 8 of 10 weeks.
Their season interest appears in the admin Coaches tab. Up to two child
registrations can use coach waivers; backend validation requires complete
coaching interest before applying a waiver. Parents choose a jersey for each
child; they do not choose which child gets the waiver. The backend applies
available waivers automatically to new registrations with the higher fees
first, counting children already waived in the season toward the two-child
limit. A later submission cannot claim a third waiver by reselecting an
earlier waived child. The family form shows the $30 registration fee, optional
$20 jersey add-on, and resulting per-child total separately.
The review page requests a read-only, family-scoped availability quote using
current team capacity, earlier waitlist entries, sibling reserve rules, and
remaining coach waivers. It displays available and waitlisted children and
"Due Today" only for places that can be offered. Registration repeats allocation
under the season lock and rejects a changed set of offered children or amount
before inserting new admissions; the family must review the refreshed quote.
When registration is closed, no payment is due or spot held. A quote is never a
reservation, and the final invoice remains the payment authority.
The help assistant answers common questions with local rules while
an AI key is absent. To enable broader AI replies, a Wren admin can save an
OpenAI API key in Season settings (stored encrypted), or an operator can set
`OPENAI_API_KEY` server-side; leave `WREN_SOCCER_HELP_AI_ENABLED=true`. The default model is
`gpt-6-luna`, overrideable with `WREN_SOCCER_HELP_AI_MODEL`. Provider requests
set `store=false` and never receive a family profile or release.

Soccer Stripe Checkout uses the verified connected account for both
`payment_intent_data.on_behalf_of` and `transfer_data.destination`, matching the
native storefront settlement pattern. This identifies the connected soccer
business as the settlement merchant without changing pricing, platform fees or
the existing destination-charge refund/reconciliation model. Verify the merchant
statement details in the controlled payment/refund smoke test before opening.

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
