# Software service support foundation (source candidate, disabled)

This is departments requesting SOFTWARE SERVICE SUPPORT from Evergrove, separate
from campus maintenance tasks, internal projects and client-project voting.
This milestone reuses native `TenantSupportTicket` and message persistence,
status/priority vocabulary and auth sessions. It adds an encrypted private
envelope, durable replay records, attachment metadata, status history and two
fake-only owner notification intents in `software_support_records`.

## Ownership and integration boundary

The native app uses `App\Models\Tenant`, `User`, active `tenant_user` memberships
and its own database. Campus Enterprise uses the separate application at
`apps/enterprise` in the Enterprise dependency stack, with independent database,
key, session and private storage. Native IDs are NOT Enterprise IDs. Similar
workspace names, hostnames or emails cannot establish a mapping. The new owner
workspace is not automatically the existing Evergrove Software tenant or a
HighLevel location. No workspace or membership is provisioned by this source.

The disabled native foundation requires an explicitly reviewed `owner_tenant_id`,
exact owner user allowlist, client Tenant IDs, department keys and exact client
user allowlists in private server configuration. Every user must ALSO retain an
active native membership. The owner requires active owner/admin membership in
that exact owner workspace; global role, email and client assignment grant no
access. Client department users see only their assigned departments. Multiple
authorized users in one department share that department's thread. A client
cannot triage tickets. Revoking active membership, owner allowlist or a client
binding takes effect on subsequent requests, including attachment downloads.

The campus runtime-to-support transport and identity binding remain UNIMPLEMENTED.
Parent coordination with campus/proposal owners must establish the reviewed
runtime/client/department/principal mapping and authenticated transport before
integrating campus navigation. Never query across databases, copy roles, share
keys or assume Enterprise session trust to make this candidate appear connected.
The current queue route is `/workspaces/{native-workspace-slug}/software-support`.
Common route includes, orientation notes and migration manifest need integration
coordination. No campus header, tour or proposal files were changed.

Legacy account-help/mobile support, landlord queue/search and support dashboard
queries exclude `source_type=software_service`, including detail/reply/triage
lookups. This exclusion is essential: tenant-level membership alone must not
expose department-private tickets through the older UI or numeric ticket IDs.

## Persistence and attachments

Client submissions serialize on their native tenant row. A hash of user ID and
request UUID identifies a submission. Identical replay returns the saved ticket;
changed content or files with the same key returns 409. Replies serialize on the
ticket envelope and have their own actor/ticket/key fingerprint. Status changes
use a locked revision and append before/after history; repeated identical status
updates are harmless. Database work and owner outbox reservation commit together.
Failed file writes roll back records and remove written bytes. Failed transactions
are not blindly replayed internally; the same form key can be submitted again.
No client-supplied tenant, assignee, owner, role or notification destination is used.

Allow at most three files per submission/reply, 5 MB each, with MIME AND extension
checks for PNG/JPEG/PDF/plain text. HTML/SVG/executables and invalid non-file array
values are rejected. Bytes live on the private local disk under generated UUIDs;
there are no public URLs. Downloads recheck ticket scope, verify SHA256, force
attachment/octet-stream and nosniff, and prohibit shared caching. An approved
malware scanning/quarantine service is not integrated and remains a production
acceptance item. Do not treat file-type checks as malware scanning.

## Notification review package: simulation only

No provider adapter or live-send switch is supplied. No scheduler is registered.
Existing SendGrid selection and SES-off policy are unchanged. There are no Twilio,
AWS, credential or account changes. Native legacy `OperatorAlertService` is not
invoked by this lane because its current SMS payload includes the subject/name.

The two transactionally reserved owner-only intents are SMS and email for each
incoming ticket. No client notification intents exist. Encrypted payload contains
only channel, reference, severity, authenticated owner link and simulation state;
it contains no subject, department name, conversation or attachment. Private
server keys `SOFTWARE_SUPPORT_OWNER_SMS` and `SOFTWARE_SUPPORT_OWNER_EMAIL` are
reserved for later reviewed provider wiring; this candidate never reads their
values and never persists or renders them. Do not put destinations in frontend
assets, Git, logs, screenshots or PR descriptions.

Proposed SMS and email text:

`Evergrove support: {reference} | {SEVERITY} | {authenticated owner ticket URL}`

Use canonical configured `app.url`, never a submitted Host header. The owner
must sign in; the URL is not a bearer access token. Email subject proposal:
`New software support ticket {reference} · {SEVERITY}`. No SLA implied.

Local review action (fictional data only):

```sh
APP_ENV=local php artisan software-support:simulate --fail=sms
APP_ENV=local php artisan software-support:simulate
```

Simulation uses database locks, per-ticket/channel durable dedupe, a default
maximum ten successful simulations per channel/owner per rolling hour and three
attempts per intent. Rate-limited work remains pending. A fake failure is shown
as `failed_simulation`/`fake_provider_failure` in the owner thread. Success says
`simulated`, never `sent`. Runs reject production environments. An eventual
provider integration must reserve durable attempts BEFORE IO, retain uncertain
outcomes for reconciliation (never blind resend), honor provider delivery events,
use existing approved provider restrictions and require exact separate approval
for account/channel/sender/destination/content/cost/test scope. None is approved
or activated by this milestone.

## Approved support allowance

The allowance below is available as a client-specific display template only.
It is hidden unless that client's reviewed binding explicitly sets
`approved_support_allowance=true`; no default/global commercial entitlement
or meeting scheduling/billing change is created. The proposal owner retains
the agreed client offer and must coordinate its exact client binding.

During onboarding, one 30-minute discovery/progress call per week, up to four
calls per department. After go-live, one 60-minute review call per active
workspace each month. Tickets remain available for bugs, service incidents and
questions between meetings. Additional consulting, workshops and development
are separately scoped/approved; a voluntary extra session is a one-time courtesy,
not a permanent entitlement. No pooling, SLA, service hours, rollout/billing
start, travel, cancellation or no-show term is inferred. No paid development or
unlimited support is promised by submitting a ticket.

## Acceptance and release

Feature tests exercise department/cross-client isolation, inactive memberships,
scoped owner access, legacy read/write exclusions, persistence, repeated and
changed submits/replies, optimistic status history, uploads/integrity/rollback,
and fake outbox failures/retries/dedupe/rate limits with mail faked and HTTP
blocked. The MySQL recovery test reconstructs retained rows before index DDL.
Run migration lint and disposable MySQL recovery/rehearsal/baseline gates before
release. SQLite passing does not establish MySQL or provider readiness.

Default flags and identity bindings are empty/off. No merge, deployment, real
admin grant, production migration, live activation or external notification is
part of this source milestone.
