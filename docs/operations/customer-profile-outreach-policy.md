# Customer-profile outreach policy and evidence (source candidate)

New `source_label=customer_profile` campaigns use tenant-reviewed promotional
limits without changing the existing sending stack. All legacy campaigns keep
their existing controls. Accepted email and SMS marketing attempts from those
existing ledgers still consume the customer's shared promotional allowance.
Neither policy publication nor saving a personal draft enables a provider,
grants consent, approves a campaign or sends a message.

## Tenant authority and review

`CustomerOutreachPolicy::revise(Tenant, User, array)` requires an active owner or
admin relationship (or the existing platform-admin authority). It serializes
on the tenant row, rejects stale `expected_version`, and replays a UUID request
only for identical normalized content by the same actor. A changed request ID
with an old version is rejected. There is no global-setting fallback.

The existing `tenant_marketing_settings` row
`customer_profile_outreach_policy` retains the latest draft, published pointer
and revision. Every revision's before/after state, reason and actor are stored
in the existing append-only `landlord_operator_actions` audit. Draft edits do
not alter the active published snapshot. Defaults have version zero and state
`unreviewed`; they allow draft preparation but block dispatch.

The policy editor accepts:

```json
{
  "request_id": "UUID",
  "expected_version": 0,
  "publish": false,
  "reason": "Reason for this merchant's limits",
  "rules": {
    "timezone": "America/New_York",
    "daily_cap": 1,
    "rolling_7_day_cap": 3,
    "email_daily_cap": 1,
    "sms_daily_cap": 1,
    "cross_channel_cooldown_minutes": 1440,
    "email_cooldown_minutes": 1440,
    "sms_cooldown_minutes": 1440,
    "delivery_history_verified_from": {"email": null, "sms": null},
    "coverage_review_note": null
  }
}
```

Publishing requires explicit reviewed history dates for both ledgers and a
coverage review note. Decisions require those dates to cover the full required
lookback (at least seven elapsed days). This is reviewed retained-Everbranch
ledger coverage, not a provider-wide completeness assertion. If external sends,
missing associations, deleted records or missing storage prevent verification,
keep the policy in draft and reconcile coverage. Unknown coverage returns null
counts plus separate observed counts; it never becomes zero outreach.

Daily boundaries use midnight in the reviewed tenant time zone, including DST.
Seven-day windows and cooldowns use elapsed UTC time. An attempt exactly seven
days old or exactly at its cooldown boundary has aged out. Zero caps prevent
outreach under that limit.

## Delivery evidence and current checks

`decision(MarketingProfile, channel)` rereads the canonical profile, explicit
aliases, current customer choices and existing suppression/consent authorities.
It evaluates both `marketing_email_deliveries` and
`marketing_message_deliveries`, scoped by tenant and explicit profile IDs.
Names, phones and emails never infer identity for history. Accepted records
with `sent_at` consume caps, including later bounces/delivery failures. Failed
or skipped records without acceptance do not. Queued/sending/unknown records
without acceptance and known legacy SMS transport uncertainty block further
outreach pending reconciliation, even outside the frequency window. Large
evidence/alias sets fail closed with a coverage warning.

Explicit transactional receipt labels/types and dry-run evidence are excluded.
POS and Website receipt authorities remain separate. This policy neither sends
receipts nor requires marketing opt-in for a transactional receipt. No new
delivery or rewards ledger is introduced.

Independently closed release gates
`CUSTOMER_PROFILE_SMS_DISPATCH_ENABLED=false` and
`CUSTOMER_PROFILE_EMAIL_DISPATCH_ENABLED=false` prevent new profile-based SMS
and email dispatch even after tenant policy publication. Existing Twilio and
SendGrid transports use internal retries; some transport failures are collapsed
into failed results. Before opening either gate, prove the exact supported
provider path avoids blind retries and reconciles original provider receipts
under timeout/5xx/lost-response cases. Source tests do not supply that evidence.
The shared provider implementations and legacy campaigns are unchanged.

The new SMS hook takes `CustomerAccountFence`, then the tenant row, then the
profile and recipient rows. It validates frozen text, current choices, contact,
policy, existing delivery evidence and sendable recipient state before creating
the existing `sending` delivery record in that same transaction. Thus another
email/SMS claim sees the unresolved original attempt. After the transaction,
the existing transport performs delivery. An ambiguous new personal SMS result
retains `send_status=unknown` and recipient `sending`; it is not retried as a
fresh failed attempt. Existing provider suppression/readiness, quiet hours,
approvals and normal dispatch gates remain authoritative.

## Frozen explanation and shared profile presentation

Preparation integration uses:

1. `CustomerOutreachPolicy::decision($profile, $channel)` for a draft warning.
2. `CustomerOutreachExplanation::build($profile, $commerce, $usedVariableValues,
   $decision, $missingVariables)` for a frozen evidence projection. Pass only
   variables actually used by the draft to explain its personalization.
3. Save that payload in `target_snapshot.explanation`, then call
   `recordPreparation($campaign, $profile, $explanation, $actorId)` inside the
   original draft transaction, and save the returned
   `target_snapshot.preparation_audit_id`. Replay preserves the original.

The explanation records staff authorship, profile/purchase IDs, timestamps,
token names, evidence hashes, the preparation-time policy and preference
revision, and quality codes. It does not copy message bodies, contact details,
product names, payment/card data or provider raw payloads into audit history.
An independently computed content hash in the immutable preparation audit binds
the original channel, subject, text and HTML, even if a mutable campaign render
hash is later recomputed. Explicit rewards objectives and rewards personalization
tokens honor a customer's declined rewards communications independently of
ordinary marketing consent.
Partial/missing product details, missing stable product identities, excluded
refund allocation, missing scent mappings, stale profile records and unknown
source freshness stay explicit.
Purchase dates are never represented as sync freshness. Complete lifetime
history/totals are always unsupported by this bounded projection.

At actual dispatch, inside the same fence/tenant/profile/recipient transaction
as delivery-row creation, call `dispatchDecision($campaign, $profile)` and
`recordDispatchDecision($campaign, $profile, $decision, $actorId)`. Abort before
sending state/provider work if `allowed` is false. Current policy changes do
not rewrite the preparation explanation. Missing/tampered audit evidence,
identity changes, preference revision changes and drafts older than seven days
require a fresh draft. Current suppressions always apply. The existing frozen
text/HTML guards remain necessary; explanation alone is not content approval.

`history($profile, $limit)` returns saved preparation/dispatch evidence for an
already authorized staff profile view. Never expose it through a public receipt
or rewards lookup. Controller routes must retain `auth`, active tenant access
and owner/admin checks for the policy editor.

## Validation and limits

The focused `tests/Feature/Team/CustomerOutreachPolicyTest.php` and
`CustomerOutreachEmailAdmissionTest.php` suites run under `phpunit.team.xml`
against isolated in-memory SQLite. They check tenant/alias
isolation, cap and DST boundaries, accepted versus failed/skipped evidence,
receipt/simulation exclusion, unresolved attempts, reviewed coverage,
revision/idempotency safety, immutable explanation replay, stale/preference
changes, closed/unknown email/SMS behavior with mocked providers, exact formatted
email handoff, and edits between rendering and locked admission. Both channel
release gates are explicitly enabled only inside the source tests.

No migration, external message, provider request, AI model, training operation,
data backfill, policy activation, publication/deployment or 2026 Asana cutover
is included. The combined disposable MySQL suite and real independent-process
cross-channel cap, same-recipient and legacy-unsubscribe races now pass with
recording provider fakes; see `customer-event-value.md` for the guard and exact
scope. Provider timeout/reconciliation acceptance, physical-device review and
protected release remain required. Recheck applicable provider terms and publishing requirements
from `provider-terms-and-publishing.md` before activation; engineering evidence
does not confer legal/provider approval.
