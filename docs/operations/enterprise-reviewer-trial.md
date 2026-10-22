# Controlled Enterprise reviewer trial

Source candidate, October 22, 2026. This is a separate controlled trial, not a
mutation/provider path inside the public fictional campus demo. It reuses the
dedicated Enterprise runtime and encrypted operation-record/audit storage. The
general demonstration and its replayable tour retain their own isolation.

## Authority and live prerequisites

The approved ceiling is **$5 total lifetime provider cost**, shared across SMS,
email, AI, reviewers, sessions, retries and acceptance checks. It is not $5 per
channel or an additional allowance after prior tests. This approval does not
authorize destinations, credential reuse/configuration, OAuth consent, real
messages, subscriptions, paid resources, production merge or deployment.

Reviewer trial email uses an existing verified **Everbranch service sender**.
A receiving email is not a sender credential or mailbox grant; reviewers do not
connect Gmail. A receiving address and each SMS test number must already appear
in that reviewer's independently approved server allowlist. Knowing an employee
email or holding the invitation does not approve another destination.

The source evidence inspected was:

- `docs/operations/ses-migration.md` records a dated October 20 platform SendGrid
  SMTP sender `no-reply@theeverbranch.com`, while SES activation was separately
  gated. That is dated source metadata, not proof of current sender readiness.
- `config/marketing.php`, `TwilioSenderConfigService` and `TwilioProviderClient`
  describe existing `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, sender-number and
  messaging-service configuration. No current approved trial number/account was
  established from source. No private environment or secret value was inspected.
- Owned `SendGridEmailProvider` and `TwilioProviderClient` automatically retry
  HTTP. The trial does not compose those retry policies into paid actions.
  It reuses SendGrid payload/response construction with a local no-retry request
  override, and a fixed SMS-only subset of the owned Twilio protocol. Existing
  marketing flags, tenant wallets, subscriptions and provider settings stay intact.
- AI uses the owned `ResponsesTransport`, with its fixed endpoint, disabled
  provider storage and bounded output. It does not borrow a retail AI grant/key
  or use the separate Enterprise AI budget to escape this trial's shared ledger.

Before live acceptance, the owner must identify and approve the exact service
sender/provider account and configuration, SMS account/from-number and recipient
permissions, AI account/model and current rates, conservative maximum costs
including carrier fees, and all prior trial/acceptance spend. No such approval or
provider configuration is inferred from a filename or earlier successful test.

## Configuration and storage anchor

All `enterprise.trial` flags default off and recipients/credentials default empty.
Only the dedicated runtime (`enterprise.runtime=true`) may resolve this service.
The explicitly configured tenant/area must already exist, use an Enterprise
profile and have `TeamArea.settings.synthetic_fixture === true`. The trial
never creates a tenant, employee, department membership or mailbox grant. This
marked synthetic anchor must not contain real campus work.

The principal configuration keys are:

- `enabled`, `tenant_id`, `area_id`, `canonical_origin` (exact HTTPS application
  origin, no path, userinfo, query or fragment).
- `prior_spend_verified`, integer `prior_spend_microusd`, and nonempty
  `prior_spend_evidence`; unknown prior spend refuses paid calls.
- `reviewers.{id}`: hashed invitation `code_hash`, `expires_at`, exact `emails[]`
  and E.164 `sms[]`. Raw invitation codes are never persisted by the trial.
- `rates_valid_until` and independent `channels.sms/email/ai.enabled` flags.
- SMS: explicit `account_sid`, `auth_token`, `from_number`, `sender_verified`,
  `sender_evidence`, positive `max_cost_microusd_per_segment`.
- Email: explicit `api_key`, `from_email`, `sender_verified`, `sender_evidence`,
  positive `max_cost_microusd` per message.
- AI: explicit `api_key`, `model`, positive integer input/output rates in
  micro-USD per million tokens, `max_input` and `max_output`.

Invitation and destination approval configuration is itself an owner action;
these instructions do not create it. Changing reviewer approval or frozen
deployment/storage context invalidates that session and its capabilities.
Session expiry is at most two hours, bounded by the reviewer grant's expiry.

Records use reserved `ent_trial_*` kinds in `team_operation_records`; sensitive
contacts, link tokens, selected content and results stay encrypted. Audit rows
use the native nullable employee actor with an explicit controlled-trial actor
type, rather than creating or impersonating an employee. There is no new schema.

## Shared lifetime ledger

`TrialBudget` fixes the campaign cap at **5,000,000 micro-USD**. Its verified
prior-spend baseline and synthetic storage anchor are pinned once initialized.
Changing either refuses a reset. All channels and server-side acceptance calls
must reserve on this same ledger. Source/fixture tests are not real prior-cost
evidence. Untracked manual provider tests must be included before activation.

A global MySQL named lock and the existing tenant serialization row protect
reservations. Provider IO occurs after committed transactions, never inside a
transaction callback that Laravel may retry. Every action reserves its full
approved maximum before IO, keyed to an immutable exact preview. A second
dispatch claim rechecks the campaign pause flag immediately before provider IO.
Duplicate, pending or uncertain claims cannot create another paid request.

SMS maximum cost is final segment count times the approved inclusive upper cost
per segment. Email reserves its approved inclusive per-message maximum. AI
reserves `ceil((max_input * input_rate + max_output * output_rate) / 1,000,000)`
micro-USD, accounting for prompt bytes/instruction headroom. Verified bounded
AI token usage settles the corresponding cost. Unknown actual SMS/email cost,
rejection or uncertainty retains the full reservation; it is not auto-refunded.

Reported over-bound/invalid usage commits a **global campaign pause** before
returning an error. Shared AI transport failures can hide unverifiable usage;
those conservatively freeze the campaign too. Already reserved actions must
still pass the final dispatch pause check. Unknown reservations and campaign
pauses need explicit provider-evidence reconciliation; there is no reset,
automatic top-up, blind retry or customer billing activation.

## Preview, confirmation and state

`TrialController` exposes a bounded contract for root-owned routes:

- `start`: POST `reviewer_code`; creates/reuses the approved browser session and
  three server-held fictional sample tasks. No provider request occurs.
- `show`: returns the session's synthetic tasks, permitted receiving
  destinations, field allowlist, recent actions and shared cost state.
- `preview`: POST stable `request_id`, `channel` (`sms`, `email`, `ai`), approved
  `destination` where relevant, and selected `fields[]`. It persists an exact
  ten-minute preview and returns its digest and maximum cost.
- `confirm`: POST `confirmed=true` and exact digest to a preview ID. Current
  approval, destination, selected sample versions, expiry and configuration are
  rechecked. No link opening or preview alone authorizes dispatch.
- `revokeLink`: revokes only a capability owned by the current reviewer session.

The SMS body uses ordinary punctuation, an opaque first-party link and no MMS,
media, shortening or smart rewrite. The final exact text is analyzed using the
owned SMS safety service and must be GSM-7 with at most three segments. Its
exact encoding/segment count appears before confirmation.

Email is responsive, displays the college name and bundled official wordmark,
coordinated green styling, selected fictional details and a prominent sample
request button. The fixed first-party wordmark URL contains no recipient data
or tracking key. The visible Everbranch demonstration label prevents it from
claiming to be a real college assignment. Provider click/open tracking is off.
Actual desktop/mobile email client rendering remains an acceptance check.

AI receives only explicitly selected allowlisted fictional task fields. It may
return at most three supplied source refs in priority order. Arbitrary narrative,
forged refs, URLs, tools, grants and actions are rejected. Displayed facts remain
the server-held sample values. The current complete campus is not searched.

## Cross-device task links and files

An independently generated 256-bit opaque token identifies an expiring/revocable
capability. Its URL contains no email, number, person name or other contact
details. Once the exact action is confirmed, the link can open the same stored
synthetic task on another device without the originating browser's storage or
an employee account. An uncertain send may already have reached its destination;
the confirmed link remains usable until expiry/revocation, while uncertainty
remains visible.

The capability reveals only its trial's primary sample task and evidence-file
metadata. Current reviewer approval, session expiry and capability state are
checked on every operation. It grants no real campus, tenant, membership,
contractor portal or other reviewer's data access.

Opening a link, provider acceptance, verified delivery and explicit task
acknowledgment are distinct states. GET opens can be caused by a scanner or
prefetcher and are never treated as human acknowledgment or delivery proof.
Only the explicit acknowledgment POST records that event. This first bounded
slice does not install an unsigned webhook or claim verified delivery: delivery
stays **unverified** until separately authenticated provider event ingestion or
accepted evidence is integrated. There is no polling that silently incurs fees.

Task updates permit a note and `in_progress`/`awaiting_review` status only, with
version checking and stable request receipts. They never approve/complete real
work. Repeated interrupted updates do not duplicate task history.

Files use the existing nonpublic local `team_private` disk, at most 5 MB per
JPEG/PNG/PDF, six files per sample task and 50 MB total trial storage. Actual
bytes/MIME/image dimensions, checksum and private storage are verified. A stable
upload key can repair an interrupted same-file upload after its claim expires;
changed bytes cannot reuse that key. The scanner must explicitly return clean
before download. Disabled/unavailable scanners leave quarantine. Downloads
recheck the current capability and use attachment, nosniff, sandbox and private
no-store headers. Uploading does not create an employee or real-work file ACL.

## Acceptance and remaining gates

Focused tests use only synthetic fixtures and fake provider HTTP/scanners. They
cover destinations/browser/context isolation, shared cap/prior costs, committed
claims, duplicates/unknown outcomes, global pause, independent open/ack states,
cross-device stored updates, private files and selected-field AI references.
Fake acceptance is not proof of real SMS/email arrival, rendering, delivery or
model-account authorization. Local SQLite tests do not prove MySQL named-lock
concurrency; the actual runtime CI/acceptance service must exercise that path.

Remaining live gates include approved recipients/session invitations, exact
credential reuse/configuration, verified sender/number and route/fee readiness,
prior-spend evidence, provider-account acceptance, authenticated delivery events,
scanner acceptance and reviewed protected deployment. Until then all live flags
remain disabled and no genuine trial action is claimed complete.
