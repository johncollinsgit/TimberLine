## SMS account-review preparation (2026-10-26)

Public Wren policies are `/privacy`, `/terms` and `/sms`; the latter presents
an explicitly non-submitting example of the same checkbox used in Family details.
It names Watershed Fellowship Upstate LLC / Wren Family Soccer, excludes marketing,
starts unchecked for new or unversioned enrollment, and allows registration without
SMS. Changing the primary number clears the browser choice. Backend rejects
new affirmative choices without the current version. Store consent evidence only
in encrypted family profiles and append-only soccer events; never put families,
phones, medical details, EINs or registration exports in PRs or public screenshots.

Outbound soccer SMS receives the Wren Family Soccer identity and STOP/HELP
instructions before provider dispatch and conversation recording. Email bodies
remain unchanged. The signed SMS webhook honors standard STOP keywords without requiring a prior
conversation and returns program HELP. When Twilio supplies OptOutType, defer its
keyword response to Advanced Opt-Out to avoid two replies. START does not restore
local consent; a fresh affirmative portal choice is required. Keep SMS disabled
and email mode active until Twilio clears the account, the specific Wren sender
and use case are registered, and a controlled delivery/STOP/HELP check passes.
Account clearance alone is not sender/campaign approval. Preserve all Modern
Forestry resources and opt-outs. Deploy backend through protected CI/Forge before
publishing the matching Wren Sites version.

# Editing a saved unpaid application — October 25, 2026

- The family account exposes **Edit application & coaching** beside payment
  recovery. It restores all unsettled players, jersey choices, saved medical
  details and the season's coaching preference; confirmed siblings remain in
  their existing places. Parents review shared details and sign the reviewed
  medical permissions before saving an amendment.
- Signed POST `amend-quote` and `amend` use only the authenticated family's
  complete unsettled player set. A profile revision prevents overwriting another
  session's submitted edits. Draft edits preserve their original revision and
  survive Save & leave/sign-in.
- Quotes keep still-current held spots and recheck expired applications against
  current capacity, earlier queue entries and sibling reserve rules. Up to two
  coaching waivers are available after existing confirmed waivers; organizer
  waivers and paid/confirmed siblings are never repriced. A request to withdraw
  coaching after confirmed waived places requires Sarah's review.
- Saving serializes on the season row, verifies the reviewed amount and available
  players, preserves signed release history, and retires/reconciles every prior
  checkout for the edited rows before replacing the unpaid invoice. Unknown or
  recorded provider activity blocks an edit. A racing actual payment commits its
  receipt even when it blocks repricing. Exact retries reuse one saved result.
- The amendment creates no duplicate registrations and preserves queue sequence.
  Only available children enter the replacement invoice; actual shortages wait
  without collection. A fully waived invoice confirms places without payment.
  A payable replacement opens card checkout directly; failure keeps a retryable
  saved invoice. Amendment audit and organizer summaries identify an update.
- Release the backend through reviewed PR/CI/Forge and verify `/ready` before
  publishing the matching Sites allowlist and edit UI. Test with fictional local
  families; inspect Collins and Gerber read-only without changing their choices.
  Rollback keeps old invoices, receipts and amended registration records intact;
  hide the edit entry point if the matching API is unavailable.

# Saved application and card-only recovery — October 23, 2026

The public Sites checkout is card-only. The backend retains old provider evidence,
refunds, and organizer reconciliation; do not erase receipts or reactivate Venmo.

- New offers reserve capacity for 30 minutes. Starting card checkout aligns the
  provider session and invoice to a 31-minute window (Stripe's minimum plus clock
  tolerance). Existing seasonal `offer_hours` is retained for historical/rollback
  compatibility and is no longer an editable checkout policy.
- Expiry first verifies/retires the original Stripe session and checks PayPal
  uncertainty. Unknown status keeps the reservation for review. A known unpaid
  closed session releases capacity and changes offered rows to `payment_pending`.
  Paid/waived siblings remain registered. Standalone verification invoices do not
  create family followups.
- Legacy inactive waitlist entries attached to expired invoices normalize to
  payment_pending. Explicit withdrawn invoices remain withdrawn. Neither pending
  nor inactive rows block available seats or eligible waitlist promotion.
- Signed POST `resume` requires the family's own session, a tenant-owned season,
  open registration, saved signed release/current birthday and age eligibility.
  Current receipt or unknown provider activity blocks another collection attempt.
  Prices and children come from saved registrations, never browser amounts.
  Earlier eligible queue entries and strict-majority sibling reserves still apply.
  Available children receive an invoice; actual shortages remain waitlisted with
  no charge. Repeating resume reuses the current invoice.
- `payment_recovery` in the private profile supports a persistent resume banner,
  current payment buttons for mixed offered/waitlisted families (including the
  reported Adams case), review contact state, and closed-registration state.
- Daily reminders require `WREN_SOCCER_PAYMENT_REMINDERS_ENABLED=true`. Record the
  independently verified old receipts before activation. One family/season/day
  key, a rolling 24-hour cooldown, and current delivery checks suppress paid,
  review, withdrawn, closed-season and unavailable-only followups. Parent email
  remains the working channel; this does not enable Twilio or organizer SMS.
- Preview `soccer:reconcile-offline` with the exact invoice, family, amount, actor
  and genuine provider reference. After owner authorization, `--apply
  --restore-places` records at most one verified receipt and restores an expired
  invoice only against that exact sole receipt, no refund/competing payment, and
  enough current capacity for every included unpaid child. A conflict stays in
  review without creating a replacement charge, receipt or forced overbooking.
- Release backend through protected CI/Forge first, restore verified paid families,
  then publish the matching Sites source. Verify Adams sees a payable invoice or
  the resume path. Do not create fictional live registrations to test the flow.
- Rollback: disable reminders and deploy through the normal release gate. Preserve
  payment_pending rows and all receipt/event evidence; retain a compatible recovery
  UI or roll forward. Never relabel paid families as unpaid to fit older code.

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

## Organizer registration summaries (2026-10-19)

`WREN_SOCCER_ADMIN_NOTIFICATION_EMAILS` is a comma-separated allowlist of the
owner-approved organizer addresses. Each submitted family registration, including
waitlist-only and waived registrations, queues one private HTML/plain-text email
per recipient. Confirmed payments queue refreshed summaries; retries and duplicate
payment callbacks do not duplicate notifications. These emails work in either
parent notification mode and recheck the recipient allowlist at delivery time.

Each summary includes the registering family and children plus the latest tally
for that season: families, children with spots, paid/unpaid/waived/waitlisted counts,
team rosters, confirmed invoice receipts, refunds and net collected. Family payment
counts can overlap when siblings have mixed payment states. Exclude cancelled and
historical roster rows; do not infer payment from parent claims or unreviewed
provider records. The summary contains no medical, emergency or insurance data.
Mail acceptance is recorded in durable deliveries; ambiguous SMTP outcomes remain
uncertain for operator review. Do not replay older registrations or create a live
test registration just to exercise organizer notifications.

## Required account setup before opening

### Email registration while SMS is pending, 2026-10-19

The owner prioritized completing registration through the working email setup
and selected Head Coaches for waitlist alerts. Explicitly configure
`WREN_SOCCER_NOTIFICATION_MODE=email`; the default remains `sms`. Parent offers,
reminders, waived-place notices, general waitlist updates and successful payment
confirmations use verified family email addresses through the existing mailer.
Automatic GroupMe notifications are disabled by default as requested by the
owner. Keep `WREN_SOCCER_GROUPME_NOTIFICATIONS_ENABLED=false`: new events do not
queue GroupMe alerts and pending/failed GroupMe deliveries are suppressed with
`groupme_notifications_disabled` before provider access. Parent registration
emails and manual GroupMe conversations retain their existing behavior. An
explicit opt-in can restore generic Head Coaches alerts with an authenticated
Everbranch link and no names, medical details, invoices or override reasons.
The saved verifying admin must retain active Wren admin access and provider group
membership. The GroupMe source GUID remains stable across retries.

Existing encrypted delivery rows carry the notification channel. Email acceptance
records the mailer message ID and appears in Messages; it is not a claim of inbox
delivery. Recheck family address and invoice payability immediately before sending.
Suppress closed/expired payment links. Fully waived confirmation notices do not
require a payable invoice. SMTP exceptions stay `uncertain` for manual provider
reconciliation; do not resend them blindly. Failed GroupMe sends retry with the
same source GUID. Delivery failures remain visible to admins in Messages.

Mode-specific smoke verification is required: `launch_verified_mode=email`, a
received sign-in code and registration-update email, the existing signed card payment/refund evidence and verified group
mapping. Preserve the active release ID when caching configuration. Let the
existing overdue opening schedule open registration only after all selected-mode
readiness checks pass. SMS remains disabled and its approval is not fabricated;
the existing $15 credit and operator tax details are preserved. Switching modes
requires a new smoke verification and operator review of pending deliveries.

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

An organizer's no-reference confirmation can attest an already-paid Venmo invoice
only when exactly one existing Venmo receipt matches its amount and there are no
competing receipts or refund records. The service verifies and retires any open
Stripe checkout before appending encrypted organizer evidence and one private
audit event. It preserves the original receipt ID, provider key and evidence;
it creates no additional receipt, revenue, registration transition or notification.
Replay is deduplicated. Unknown card outcomes stop the action; an actual competing
card receipt is retained for review, without adding an organizer attestation.
Use the existing offline reconciliation preview with the expected family and
amount before applying; never manufacture a new Venmo reference for an existing
receipt.

## Rollback

Close registration first. Disable soccer processing/SMS if necessary and use the
normal backend rollback. Restore the previous immutable website version or Sites
publication. Preserve all additive records, source evidence, releases, payments,
refunds, and published snapshots. Never drop soccer tables or delete originals
as part of rollback.
