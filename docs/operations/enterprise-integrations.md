# Enterprise integration acceptance

Source candidate, October 22, 2026. The dedicated Enterprise application shares
the owned Avery/Everbranch `ResponsesTransport`, native mailbox identity/grant
shape and ClamAV INSTREAM adapter. It has its own configuration and database.
This change creates no credentials, accounts, grants, OAuth consent, network
configuration, paid resources, real provider requests or real messages.

## AI

`Intelligence::generate` supports reviewed workflow drafts and source selection
for Ask Everbranch and the Home briefing. The caller constructs authorized
evidence, validates the output against its domain schema, and rechecks current
sources/permissions before displaying or saving it. Record labels, questions,
form descriptions and all other content are untrusted input. Generated text,
links, tool instructions and permissions are never executable authority.

The production-capable adapter requires explicit Enterprise-only settings:

- `ENTERPRISE_AI_ENABLED` and an exact `ENTERPRISE_AI_TENANT_ALLOWLIST`.
- `ENTERPRISE_AI_API_KEY` for the owner-approved provider account, and an
  approved `ENTERPRISE_AI_MODEL`. No retail key, tenant mapping or billing grant
  is inherited. Reusing a credential requires its own owner approval.
- Current positive input/output rates in integer micro-USD per million tokens,
  `ENTERPRISE_AI_RATES_VALID_UNTIL`, and a positive per-tenant lifetime provider
  cap `ENTERPRISE_AI_LIFETIME_CAP_MICROUSD`. A zero cap or expired rates refuses
  dispatch. These are provider cost controls, not customer pricing or billing.

Before HTTP, a per-tenant lock commits an encrypted `ent_ai_usage` reservation
for the maximum approved input/output token cost. Completed usage is recorded
at the approved rates, conservatively charging full input rate for cached input.
The integer micro-USD charge is
`ceil((input_tokens * input_rate + output_tokens * output_rate) / 1,000,000)`;
reservation uses both configured maximum token counts in that same formula.
Input bytes include fixed system instructions and message-framing headroom.
Pausing Enterprise writes also refuses new AI spend before any reservation or HTTP.
A stable user/request UUID replays the result without another call. Changed
input, pending or uncertain attempts refuse replay. Timeouts, invalid output or
unknown provider outcomes retain the maximum reservation; no automatic retry
or replacement request is issued. Operator reconciliation is required before
releasing an uncertain reservation. Configuration/credential changes before
dispatch cancel the unused reservation. Role/department revocation after a
completed paid request prevents its result from being returned.

The owned fixed endpoint remains `https://api.openai.com/v1/responses`, with
provider storage disabled and bounded timeouts/output. Fake-provider tests do
not establish that a particular account/model is authorized or operational.
No live configuration was inspected or copied into this isolated application.

## Own-user mailbox

The additive Enterprise migration installs empty native-shaped domain, mailbox
and explicit user-grant tables. It creates no domain, user, password, credential,
mailbox or grant. A replay repairs named keys/constraints while retaining rows;
code rollback retains evidence. Incoming message retrieval is outside this
slice: the application does not install or read a mailbox-message archive.

`ConnectedMailboxGateway` supports already authorized native direct SMTP
mailboxes. Prerequisites are:

- An existing verified/ready domain and ready mailbox in the Enterprise
  database, an existing current signed-in user's `read_write` mailbox grant,
  and an active tenant/department role permitting the work. An organization
  owner has no implicit access to another user's mailbox.
- Explicit mailbox ID approval in `ENTERPRISE_MAILBOX_ALLOWED_IDS`, plus
  `ENTERPRISE_MAILBOX_ENABLED`. It does not import retail mailbox records.
- A separately approved existing per-mailbox encrypted submission credential,
  `ENTERPRISE_MAIL_DIRECT_ENABLED`, and the Enterprise-only SMTP submission
  host/port. STARTTLS is mandatory; TLS verification is not relaxed. There is
  no owner credential fallback, provisioning endpoint or OAuth grant endpoint.
- Separate approval for real dispatch before `ENTERPRISE_MAILBOX_SEND_ENABLED`
  is enabled. It defaults off even when draft preview is usable.

The current exact sender, recipient, subject and body are shown before explicit
confirmation. The preview expires after fifteen minutes and binds mailbox,
transport, role and source versions. The existing `EmailDrafts` claim commits
before SMTP; it supplies a stable Message-ID/dispatch key, records acceptance
separately from delivery and never automatically retries an uncertain send.
The SMTP adapter cannot initiate mail from chat or workflow output. Changing a
grant, credential, domain or submission target invalidates an old preview.

Gmail or Microsoft 365 OAuth is a separate connection choice, not an existing
connection inferred by this adapter. If selected later, send-only Gmail API
access uses [`gmail.send`](https://developers.google.com/workspace/gmail/api/auth/scopes);
Microsoft Graph's delegated least-privileged send permission is
[`Mail.Send`](https://learn.microsoft.com/en-us/graph/api/user-sendmail?view=graph-rest-1.0).
Reading inbox content requires a separate purpose, scope, authorization and
injection review. Neither OAuth access nor domain-wide/application access is
requested by this change.

## Private file scanner

The existing `TeamFileScanner` streams the exact checksum-verified private
bytes to ClamAV using INSTREAM, with bounded connection/read timeouts. Only an
exact clean response makes those bytes eligible for download. Disabled,
unreachable, malformed or timed-out results retain quarantine; a detected
threat is rejected. The Enterprise configuration is independent:
`ENTERPRISE_FILE_SCANNER_ENABLED`, `ENTERPRISE_CLAMD_HOST` and
`ENTERPRISE_CLAMD_PORT`. Installing/configuring a daemon or network access is
an owner-approved infrastructure action; no such action is performed here.

`IntelligenceStatus` reports only authorized configuration readiness and clearly
distinguishes it from successful live provider/delivery/scan acceptance. It
does not reveal secrets, another user's mailbox or provider message data.

## Verification still required before real use

Focused tests use fake Responses HTTP and fake SMTP submission, including
replay, uncertainty, cost caps, stale rates, wrong users/departments, revoked
membership and mailbox grants. Dedicated MySQL CI exercises retained encrypted
mailbox details and domain proof through partial schema repair. Actual approved
model-account acceptance, one owner-approved mailbox delivery/reconciliation
and a clean/rejected scanner acceptance remain separate launch gates.
