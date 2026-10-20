# SendGrid to Amazon SES migration

## Verified starting point and ownership

On October 20, 2026, production `/ready` and GitHub main both reported
`f8da53c0f854b17cd5cf0a18d4319c6dbd9e7a05`; existing Forge SSH confirmed release
`80726977`. Application mail uses Laravel `smtp` at `smtp.sendgrid.net`, from
`no-reply@theeverbranch.com`. SendGrid API credentials and its signed event public
key are present. SES region defaults to `us-east-1`, but account ID and credentials
are absent. The tenant messaging platform is disabled and has no email accounts.
This is account configuration evidence, not an SES delivery test.

The isolated `codex/ses-migration-20261020` checkout starts at that live commit.
Other worktrees, dirty files, Teams, Marketplace, visualization, Android and Twilio
are outside this release. The general release queue remains stopped.

Workspace/DNS task `01a15aa1-d513-73c7-a569-d0163fa37013` completed and released
DNS edit ownership. Preserve its verified root MX (priority 1 `smtp.google.com`),
root SPF (`v=spf1 include:_spf.google.com ~all`), Google 2048-bit
`google._domainkey` and verification TXT, and DMARC (`v=DMARC1; p=none;`).
Preserve SendGrid `s1._domainkey` → `s1.domainkey.u61143478.wl229.sendgrid.net`,
`s2._domainkey` → `s2.domainkey.u61143478.wl229.sendgrid.net`, and `em6596` →
`u61143478.wl229.sendgrid.net`. `info@theeverbranch.com` is a verified alias of
`john@evergrovesoftware.com`; Gmail send-as is ready. No mailbox test was sent.
The exact SES DKIM additions below were explicitly approved. Workspace remains the human
inbox. Do not change apex MX, cancel SendGrid or remove its authentication.

### AWS checkpoint, October 20

The new Evergrove Software AWS console account is `894665396752`. SES in
`us-east-1` reports Healthy, sandbox, 200 messages per day and 1 message per
second. Its current plan is **Essentials**, verified in the region's Pricing
plans screen; no plan or optional paid feature was changed. Production runtime
still has no SES credentials. After specific user approval, this task created
`everbranch-marketing`, native test tenant `everbranch-tenant-900000001` and
Standard SNS topic `arn:aws:sns:us-east-1:894665396752:everbranch-ses-tenant-events`.
The native tenant has the verified identity and configuration set assigned,
Standard reputation policy, and tenant-level BOUNCE/COMPLAINT suppression. The
configuration set inherits suppression settings and does not enable optional
reputation metrics, archiving or validation. Native tenant suppression skips the
account list; the application suppression checks remain required.

Approved IAM user `everbranch-ses-simulator` exists with console login disabled and
only inline policy `EverbranchSesSimulatorOnly`, matching the simulator policy
below. No access key has been created by the agent; user key-creation handoff is
pending. The topic publishing grant is applied, alongside topic-owner
administration. Event destination and HTTPS subscription setup remain pending.

Identity `arn:aws:ses:us-east-1:894665396752:identity/theeverbranch.com` was
created with Easy DKIM RSA 2048 signing enabled and automatic Route 53 publishing
disabled. The user approved the exact three Cloudflare records below; all three
were saved DNS-only, TTL Auto, and independently resolved from the authoritative
Cloudflare nameserver. SES now reports identity **Verified** and DKIM
**Successful** in the `us-east-1` console.
No simulator or real message has been submitted.

## Inventory and blast radius

| Lane | Existing implementation | Migration boundary |
| --- | --- | --- |
| Critical platform | Laravel notifications/Mail: verification, password reset, access approvals, agreement proposals, shopper sign-in, Soccer registration and operational notices | Existing SendGrid SMTP stays selected. A separate platform identity/configuration set and durable logical-message retry contract must be accepted before switching `MAIL_MAILER`. |
| Tenant marketing | `SendGridEmailService` compatibility facade, `TenantEmailDispatchService`, `TenantMessagingGateway`; campaigns, group/direct sends, birthday and reward reminders | Reuse the existing `SesTenantEmailProvider`, native SES tenant accounts and verified sender profiles. Explicit global flag plus tenant allowlist. |
| Human mailbox and replies | Google Workspace, existing inbound parsing/mailbox seams | SES outbound migration grants no inbox, MX or inbound authority. Keep approved Reply-To destinations. |
| Existing audit and consent | `marketing_email_deliveries`, messaging usage ledger, contact channel states, consent events, HTTPS unsubscribe | Retain history and provider receipt. Preserve immediate unsubscribe and complaint suppression within the originating tenant. |

Repository orientation: `AGENTS.md`, `SYSTEM_SNAPSHOT.md`, `README_FOR_AGENTS.md`,
README provider guidance, automatic unsubscribe runbook and atomic release runbook
were inspected. No applicable `.agents/skills` or local `memory_summary.md` file
was found in the inspected checkout; historical context is not launch authority.

## Milestones and acceptance

1. **Safety foundation, default off.** Reuse existing SES SDK and adapter. Require a
   tenant-bound durable delivery, exact native tenant/configuration set and verified
   sender. Persist a claim outside caller transactions before HTTP; disable SDK
   retries. Preserve the claim when legacy callers save a stale delivery model.
   Hold network, 5xx and empty-receipt outcomes as unknown; never blind retry or
   switch providers. Known AWS 4xx rejection is terminal for that delivery.
2. **Read-only AWS readiness.** After existing access is available, run
   `php artisan email:ses-readiness --identity=theeverbranch.com` in the intended
   account/region. Record account ID, region, production/sandbox state, quotas,
   sending/enforcement status, identity and DKIM state. The command never creates,
   enrolls, subscribes or sends. It intentionally exits nonzero until external
   acceptance evidence is recorded. Confirm the account's actual pricing plan in
   its console/Billing; published pricing alone does not identify its plan.
3. **Approved AWS preparation.** Review exact account and costs before creating
   native tenants, sender identities/configuration sets, an SNS topic/subscription
   or permissions. Platform and marketing require separate configuration sets and
   operational controls. A separate AWS account for critical mail offers stronger
   reputation isolation; configuration sets/native tenants in one account still
   share account reputation. No dedicated IPs, VDM, validation, multi-region or
   plan upgrade is authorized by this implementation. Do not run generic email
   provisioning while the account/pricing/security approvals are pending.
4. **Authenticated events and simulator.** Bind the exact SNS ARN to
   `SES_SNS_TOPIC_ARNS`; require the configured SES account and region, AWS SNS
   signature and fixed HTTPS certificate host/path. Never follow SubscribeURL.
   Confirm the exact subscription separately with reviewed AWS access. The
   webhook keeps a fresh authenticated confirmation envelope encrypted in cache
   for one hour and never follows SubscribeURL. Preview with
   `php artisan email:ses-confirm-subscription '<exact-approved-topic-arn>'`;
   after checking the subscription's exact HTTPS endpoint in AWS, use `--confirm`.
   This sends the verified token to the fixed regional AWS ConfirmSubscription
   API, without creating credentials or printing the token. Do not rerun after
   an uncertain response before checking the subscription in AWS. Subscribe
   Send, Delivery, Bounce, Complaint, Reject, Rendering Failure, DeliveryDelay and
   optional Open/Click events. Configure native tenant and configuration-set
   associations. Verify simulator success, bounce and complaint receipts reach
   `/webhooks/ses/events` and update the correct durable delivery/tenant.
5. **Non-customer pilot.** Use only fictional recipients at the SES simulator
   first. Proposed content: subject `Everbranch SES migration simulator test`,
   text `Non-customer migration verification. No customer campaign or action.`
   Targets: `success@simulator.amazonses.com`, `bounce@simulator.amazonses.com`,
   `complaint@simulator.amazonses.com`. Require exact sender/identity approval and
   record resulting message IDs and application event evidence. No customer
   campaign is a smoke test. Report the exact mailbox and content before any real
   mailbox test. Verify HTML/plaintext, sender alignment, SPF/DKIM/DMARC and that
   the DKIM signature covers List-Unsubscribe and List-Unsubscribe-Post.
6. **Controlled cutover.** Only after proofs: pilot a named tenant, then expand
   gradually within approved rates/costs. Review bounces/complaints, queue age,
   unknown outcomes, throughput, delivery latency and spend each checkpoint.
   Platform mail moves separately after its durable retry/event implementation and
   acceptance. Do not describe preparation or simulator submission as completion.

## Exact DNS coordination contract

The following exact DNS-only CNAME records were approved and added in Cloudflare:

| Name under `theeverbranch.com` | Target |
| --- | --- |
| `2bx2bcvxcwnjlbir6bkpjjxr2pjs4zcy._domainkey` | `2bx2bcvxcwnjlbir6bkpjjxr2pjs4zcy.dkim.amazonses.com` |
| `3re7pd4xucv4hvomhhlfm2te6vqrlknq._domainkey` | `3re7pd4xucv4hvomhhlfm2te6vqrlknq.dkim.amazonses.com` |
| `yckwqn76ttrkx3he2zo6oizpwkuoiazx._domainkey` | `yckwqn76ttrkx3he2zo6oizpwkuoiazx.dkim.amazonses.com` |

These tokens came from the actual account/region identity. They coexist with
Workspace and SendGrid selectors. Existing SES MAIL FROM behavior needs no MX
change. The read-only readiness command can recheck the identity and its tokens.

Do not add another apex SPF record or replace Workspace/SendGrid SPF/DMARC. If a
custom MAIL FROM subdomain is later approved, propose its exact domain/region
first: a subdomain MX to `feedback-smtp.<region>.amazonses.com` (priority 10) and
subdomain TXT `v=spf1 include:amazonses.com -all`. This would be a separate reviewed
DNS change requiring scoped approval. Root MX remains Workspace. Identity changes for
customer domains require that customer's verified authority.

## Suppression, retries, queues and cost controls

SES native tenants permit selective pausing, but reputation is still aggregated at
the account level. Native tenant suppression is regional; TENANT scope skips the
account suppression list, so it is not a substitute for Everbranch's shared
application suppression policy. Review configuration-set overrides before choosing
scope. Keep both BOUNCE and COMPLAINT enabled. Do not delete suppression data,
restore consent from imports or infer reconsent from delivery/not-spam events.

Existing consent checks and HTTPS/footer/one-click unsubscribe remain upstream of
provider HTTP. SES complaints map to spam reports, permanent recipient bounces to
hard bounces; transient failures and sender/auth failures do not establish an
invalid recipient. Events bind tenant ID, delivery ID, native tenant name,
configuration set, sender, recipient and full opaque SES receipt; there is no
latest-email fallback. Duplicate events reuse the existing tracking deduplication.

SES requires a durable delivery row. Callers without that contract fail closed;
operator tests cannot bypass it through `sendTestEmail`. Queue jobs may retry
admission failures before HTTP with bounded existing backoff. Provider-submitted
attempts remain immutable, including definitive rejections; a new attempt requires
review, not a queue retry with a new delivery ID. Unresolved prior attempts for the
same tenant/address also block replacement SES deliveries. Unknown usage
reservations stay held. Each metered SES claim binds its exact tenant-owned usage
reservation before HTTP. Provider receipts and authenticated events settle that
same reservation once, including a send first classified unknown. Customer rates,
included allowance and provider-cost estimate come from the original reservation;
late events do not recalculate a changed rate. Callback evidence must resolve
uncertainty before a recipient is sent again. Never refund an unknown as though
AWS rejected it.

If accepted usage could not settle because of a storage failure, inspect
`php artisan email:ses-reconcile-usage <tenant-id>` and then use `--apply` after
review. This settles only existing accepted SES reservations, never sends another
email or creates an invoice. Duplicate events/reconciliation cannot double charge.
Zero-rate contracts still record units/provider cost with zero customer charge;
zero-charge periods do not produce overage invoices. Email overage invoicing and
prepaid/postpaid billing remain the existing billing service's responsibility.

The user confirmed the production email usage exclusions on October 20: Modern
Forestry (1), Green Shield Pest Control (6), Evergrove Software LLC (10), and
Fictional Cedar Works - Voice Pilot (14). Everbranch Admin is the landlord console
with no tenant ID. See the explicit email-only policy below. Provider costs still
belong to Evergrove for these sends. The legacy provider allowlist is routing,
not billing policy; comped module access is not an overage exemption.

Read-only production inspection found no explicit usage-exemption flags and no
email usage settlements before this policy. Collins Electric has an active
postpaid agreement with 1,000 included emails and 5,000 micros ($0.005) per overage
email. Front Yard Foods has those same usage terms but no active postpaid
agreement. These contracts remain unchanged. Customer SES sending stays disabled
until the policy is deployed/verified and provider acceptance tests pass.

Defaults cap account attempts at 1,000/day, each tenant at 100/day, and aggregate
rate at 1/second. `SES_APPROVED_ATTEMPT_COST_MICROS=0` blocks sending until an
all-in conservative per-attempt estimate is reviewed. The daily variable spend
ceiling defaults to $1; fixed tenant fees, attachment transfer and SNS/other AWS
charges need their own approved budget. These are application attempt limits,
not an AWS hard billing cap. A shared persistent cache is mandatory in production.
Watch its persistence/evictions; AWS quotas and budget alerts supplement it.

Published October 20 pricing: selectable a-la-carte outbound $0.10/1,000 plus
$0.005/tenant/month and $0.005/1,000 tenant emails; Essentials starts at
$0.16/1,000 and may be the default for newly active account/region combinations.
This account's current `us-east-1` plan is Essentials. Native tenants are an
add-on under that plan; the explicitly approved fictional tenant 900000001 is
created with tenant-level BOUNCE/COMPLAINT suppression. Do not silently enroll
in add-ons, change the plan or change buyer-facing pricing based on public rates.

### Prepared AWS approval scope

The user specifically approved this test setup, a maximum of 20 simulator sends
and a $1 total test budget. Some resources above are now applied; the files below
record the approved scope:

- [`ses-simulator-iam-policy.json`](ses-simulator-iam-policy.json): applied to
  `everbranch-ses-simulator` user with no console login, send permission restricted
  to `no-reply@theeverbranch.com`, the three simulator addresses, API v2 and TLS,
  plus account/identity readiness reads. No provisioning or customer sends.
  Access-key creation/secure entry remains a user handoff. IAM accepted the policy;
  prove its effective restrictions before using it. Production credentials
  need a separate reviewed policy.
- [`ses-marketing-sns-policy.json`](ses-marketing-sns-policy.json): applied to
  Standard topic `everbranch-ses-tenant-events`, accepting SES publishing only
  from this account's `everbranch-marketing` configuration set. Preserve any
  topic-owner administrative statement; this file is the SES publishing grant.
  Proposed HTTPS subscription is
  `https://app.theeverbranch.com/webhooks/ses/events`, with raw delivery disabled
  so SNS's signature envelope reaches the application. Confirmation must be
  performed through reviewed AWS access, not an arbitrary SubscribeURL.
- Created one fictional native tenant `everbranch-tenant-900000001`, associated
  with the approved identity/configuration set; no customer tenant activation.
  Initial tests are limited to 20 simulator messages and a $1 total test budget.
  Native tenant and SNS fees received explicit approval; Essentials stays selected.

Do not broaden these grants or create credentials merely because the files exist.
The application must be deployed with the exact topic/account configured before
event acceptance can be proven. A fictional application fixture with the matching
tenant binding must be reviewed before any send. Do not replace or repurpose a
customer tenant for this test.

## Rollback and release

Run `tests/Integration/SesSendConcurrencyTest.php` against disposable MySQL to
prove same-message holds, replacement-message holds and account caps across
independent processes and tenants. Admission uses the same explicit cache store
for locks and counters and runs outside the delivery transaction to avoid stale
MySQL repeatable-read snapshots. The protected MySQL job includes this test.

Run focused SES, failure/consent, tenant provider/readiness and broader marketing
regressions, PHP lint and the normal protected GitHub test/build + Migration Safety
Gate. No new schema is required. Open the PR as draft initially. Verify final
remote commit, complete required checks, and deploy only the scoped reviewed
commit through the existing protected GitHub/Forge atomic pipeline. Reverify
`/ready` and Forge release; a hook acknowledgement is not activation proof.

Pause SES by clearing its flag/allowlist; leave event ingestion active for late
callbacks. Preserve claims, consent, receipts, ledger and SendGrid credentials.
Restore SendGrid only for new messages that were never attempted at SES; keep
submitted/unknown logical messages quarantined. Never replay a whole campaign to
achieve rollback. Application SMTP remains SendGrid throughout this preparation.

## Outstanding acceptance

User simulator-key creation/secure configuration; event destination/subscription
setup and confirmation; separate production runtime credential approval;
sandbox production-access submission; verified customer billing exemptions;
actual simulator send plus application event evidence; platform mail durable
retry/event implementation; controlled mailbox and tenant acceptance; protected
CI and scoped deployment. Until these are satisfied, the migration is incomplete.

Local validation: 95 focused SES/marketing/billing tests passed (481 assertions);
after routing the legacy SES dispatcher through metering, 22 affected tests
passed (163 assertions). Real
MySQL concurrency passed (3 tests, 15 assertions), frontend typecheck/tests/build
passed, changed PHP formatting and migration safety lint passed (no schema
change). The full PHP run completed with 4,202 passes, 8 skips and two DNS-bound
failures (30,316 assertions). Both failures reproduce unchanged on live baseline
`f8da53c0`; each passes separately when DNS is available, with mail/HTTP faked.
Earlier full runs exhausted the repository's 1G PHPUnit limit; the completed run
used a local-only 2G configuration. These results do not substitute for protected
CI or actual SES delivery/event acceptance.

## Primary references

- https://docs.aws.amazon.com/ses/latest/dg/tenants.html
- https://docs.aws.amazon.com/ses/latest/dg/sending-email-suppression-list-tenant-level.html
- https://aws.amazon.com/ses/pricing/

## Platform application-mail outbox (separate, default off)

The opt-in `ses_platform` named Laravel mailer freezes fully rendered MIME in
`platform_email_messages` before sending. Both MIME and recipient addresses are
encrypted; queue jobs contain delivery IDs only. One child record is created for
each envelope recipient so quota, cost and event evidence are recipient-specific.
Bcc and caller-supplied SES headers are removed from the frozen MIME. Sender,
configuration set, content fingerprint, recipients and cost estimates cannot be
replaced after enqueue. New additive tables have explicit named constraints,
restart guards, retained-evidence recovery tests and a data-free MySQL baseline.

The existing SendGrid SMTP default is unchanged. Platform AWS credentials and a
separate configuration set are intentionally blank/unprovisioned. The existing
simulator IAM user is not a production credential. Critical reset, verification,
agreement, invoice and operator messages are platform expenses; this outbox never
writes the tenant usage billing ledger or changes marketing consent. All customer
marketing continues through its existing consent/unsubscribe and tenant billing
lane. Native SES tenant selective pausing does not isolate the account's combined
reputation, quotas or global account pause from platform mail. Separate AWS
accounts would be required for a stronger reputation/account-failure boundary;
no new account or paid resource is silently provisioned here.

`SES_PLATFORM_SENDING_ENABLED=false` and the current default mailer preserve the
rollback path. A pilot additionally needs separate reviewed runtime permissions,
a non-marketing configuration set with approved signed event routing, an exact
sender, explicit recipient allowlist, asynchronous queue connection and reviewed
all-in attempt cost. The shared account limiter covers marketing and platform
attempts; each lane has its own daily allowance. The platform defaults to 100
attempts/day and 1 MiB frozen messages, requiring at least 1,000 micros of reviewed
cost per recipient attempt (a conservative estimate, not a reconciled AWS bill).
The approved account cost still defaults to zero, blocking sends. Do not lower
estimates or reset daily limiter counters during an active budget period.

The dedicated `ses-platform` queue is drained by the existing scheduler while
its gate is on. A supervised worker may use the same queue instead; never use a
sync queue or nest this transport in Laravel failover/round-robin mailers.
`email:ses-platform-dispatch --limit=100` recovers only never-submitted `queued`
records after lost queue dispatch. Gate-off jobs leave the outbox queued. Pilot
recipient removal or frozen-context mismatch moves a record to `held` with a
reason, so recovery cannot endlessly enqueue an invalid intent. Review backlog
and token age before enabling/resuming a critical-mail pilot.

Each submission durably claims before HTTP, disables SDK retries and uses
5-second connection/20-second total SDK timeouts. A received opaque SES receipt
means provider acceptance; it does not mean delivery. A network/5xx/empty receipt
stays `unknown` or `submitting`; workers never resubmit it. Confirmed 4xx rejection
is terminal. Signed, exact-topic/account SNS events bind the original submitted
recipient, sender, configuration set, delivery tag and full opaque receipt, and
can reconcile acceptance. Duplicate and late events cannot remove suppression or
regress delivery. Permanent bounces and complaints hold that platform recipient;
they do not silently alter tenant marketing opt-in.

Rollback first disables platform enqueue, keeps `MAIL_MAILER=smtp`, and retains
all outbox rows, event routing and credentials needed for reconciliation. The
SMTP guard still blocks recipients with unknown/submitting SES attempts, and
blocks repeated explicit logical IDs, so rollback cannot become blind failover.
Retain `APP_PREVIOUS_KEYS` while any encrypted outbox evidence or recipient holds
uses a prior key. Never delete evidence, set unknown/submitting back to queued,
or resend just because a job failed. Resolve actual provider/event evidence
before any operator-approved replacement; there is no automatic release of a
held/unknown attempt in this change.

Laravel's normal queued mailables/notifications use the stable queue UUID as
logical identity. On retry, their already-frozen original job is acknowledged
before rendering, preventing a second reset token from invalidating the frozen
one. Arbitrary business queue jobs must supply a stable
`X-Everbranch-Logical-Message-ID` (16–200 alphanumeric/colon/underscore/hyphen
characters) for each logical mail; changed content/recipients under an existing
ID fail closed. Synchronous user requests receive a new ID per invocation and
are separate sends. Before a global default switch, audit all business-job mail
calls and add stable IDs, including per-recipient/per-purpose distinctions.
Do not switch the default until that inventory and actual non-customer delivery,
bounce, complaint, retry, event and rollback acceptance evidence are recorded.

### Application flow inventory and cutover order

| Flow | Current implementation | SES cutover condition |
| --- | --- | --- |
| Tenant campaigns, direct messages, workflow email, rewards/reminders | `SendGridEmailService` → tenant gateway/dispatch adapter | Native tenant + verified sender/config set + consent/unsubscribe + durable delivery + usage reservation + explicit tenant gate; billing policy validated separately |
| Password reset/verification, account approval, wholesale notices | Laravel notifications and ordinary mailables | Platform sender/recipient pilot, frozen token content and queue identity; actual delivery/event proof before expansion |
| Operator snapshots, scent/rewards reports, product review alerts, mobile reminders | Scheduled/service Laravel mailables | Platform pilot after reviewing recipient purpose, existing delivery records and content identity; preserve per-tenant billing where the current gateway meters it |
| Native Website order updates | Separate immutable notification snapshot and explicitly configured named mailer, with provider-acceptance receipt semantics | Remains on its current approved mailer. Its readiness accepts only `ses_platform_confirmed` with a reviewed matching sender, gates and cost. Stable order-transition identity preserves the existing provider receipt contract |
| Trajectory financial review email | Separate configured mailer/readiness allowlist and durable finance-space claim | Remains on its current mailer. `ses_platform` remains rejected; `ses_platform_confirmed` preserves provider acceptance and uses the immutable finance claim as logical identity |
| Soccer sign-in and registration/admin email | Laravel mailables; registration records inspect `getMessageId()` | Keep current provider during initial platform pilot. Registration has stable delivery IDs and must use the confirmed mailer for provider receipt semantics |

The transport returns `outbox:<delivery-id>` with a `queued` header for local
acceptance, never a generated ID that could be mistaken for an AWS receipt.
Provider acceptance and lifecycle evidence live on the child delivery. A named
non-customer pilot can test this core without changing every consumer. A global
`MAIL_MAILER` switch is not approved or ready until actual provider acceptance,
delivery/event and rollback pilots pass. Queue-only `ses_platform` is rejected as
a global default, preventing receipt consumers from treating a local queue ID as
provider acceptance. Use `ses_platform_confirmed` for reviewed receipt consumers.
Queue services reached indirectly from `SendWebsiteOrderNotification`,
`Trajectory/SendReviewEmail`, `SendMarketingDirectMessageBatch` and automation jobs
were inspected; no unrelated pending feature was enabled or released.


### Provider-acceptance compatibility

`ses_platform_confirmed` uses the same immutable outbox, SDK and signed events,
but returns only the actual persisted SES receipt for one recipient. It requires
zero caller transaction depth. A `prepared` intent is committed before HTTP and
is never picked up by the queued-intent recovery command. If the process stops
before submission or rate admission blocks it, the originating business flow
must revalidate its current state before any further operator action; the
scheduler cannot later send a stale Website transition or financial review.
Unknown/submitting outcomes keep the same quarantine and rollback fences.

Website order transitions and Trajectory finance claims now supply stable logical
identities and their readiness checks require the confirmed transport plus the
reviewed SES sender, credentials, queue and budget configuration. Soccer
registration/admin deliveries and rewards operator reports have stable IDs too.
Wholesale application retries preserve a persistent logical generation (a new
one only on an explicit enqueue/resend). When an outbox intent already exists,
accepted outcomes are reconciled before rendering; pending/unknown ones are held
without creating a replacement activation link. This does not alter Shopify
credentials, storefronts, checkout, account policies or other paused releases.

The current default remains SendGrid SMTP. The queue-only mode is for explicitly
selected callers that understand queued acceptance. The confirmed mode preserves
existing provider-acceptance semantics on a reviewed per-flow/default cutover.
Multi-recipient messages require separate recipient sends or the explicit queue
mode; they fail before a provider attempt in confirmed mode. Neither mode may
participate in automatic cross-provider failover.

### Isolated simulator application records

The approved native tenant `everbranch-tenant-900000001` must not be inserted in
the production tenants table, which would advance its auto-increment sequence.
Simulator records instead live in a customer-free SQLite database at shared
`storage/app/private/ses-simulator.sqlite`, mode 0600. Prepare it from the current
migrations on this Mac with only tenant 900000001 (`ses-simulator-fixture`), then
review/upload that nonsecret fixture. No production customer table is migrated,
cloned, truncated or reset. Preserve fixture evidence after the test.

`SES_SIMULATOR_FIXTURE_ENABLED=false` is default. When explicitly enabled for the
approved test, the public webhook still verifies the exact SNS account/topic and
AWS signature first. Only the exact native test tenant, marketing configuration
set, sender and one of the three simulator addresses can enter the fixture lane.
The database wrapper validates that the file is private and contains only that
fictional tenant, selects it for the ordinary application event handler, then
restores the production connection in `finally`. Invalid/missing fixture state
fails instead of falling through to customer writes. SQLite support was verified
on Forge. Local tests cover database restoration and rejection of customer-like
fixture contents and any mismatched recipient/native context.

The restricted simulator key may be read from the user-downloaded CSV and passed
ephemerally to the approved simulator process. Never print it, commit it, put it
in shell arguments, save it in Forge `.env` or a customer account, or broaden its
permissions. The test process must use the private database and exact reviewed
application release; runtime-only gates cap it at 20 attempts, one per second and
a $1 variable budget with a conservative 1,000-micro attempt estimate. Production
runtime credentials and customer sending remain separately unapproved.


### Customer email usage exemptions (2026-10-20)

`MARKETING_EMAIL_BILLING_EXEMPT_TENANT_IDS` is an explicit, email-only billing
policy. The default is empty in source so numeric production IDs cannot exempt
unrelated tenants in another environment. The user-confirmed production list is
`1,6,10,14`: Modern Forestry, Green Shield Pest Control, Evergrove Software LLC,
and Fictional Cedar Works - Voice Pilot. Everbranch Admin is the landlord console
(`tenant_id = null`), not a tenant to charge; application mail is a platform cost.
The user confirmed that AWS still charges Evergrove for excluded customer sends.

Activate the exact list only after verifying the deployed code and those tenant
IDs. This changes prospective customer email reservation charges to zero, while
retaining sent units, allowance consumption, provider-cost estimates and an
immutable `explicit-email-exclusion-v1` reservation/settlement audit. Existing
reservations retain their original billing decision across configuration changes;
review reconciliation before changing the list. Contracts, nonexcluded rates,
SMS/MMS, module subscriptions and historic invoices are unchanged. Comped module
status and the legacy provider routing list are not email usage exclusions.
Provider-cost estimates are not a reconciled AWS bill.

Acceptance covers both SES and SendGrid, included allowances, original contract
rates, exactly-once credit/ledger settlement, excluded usage closing without an
invoice, and nonexcluded reservation → settlement → one invoice across repeated
billing runs. The scheduled invoice retry path uses the explicit tenant filter
supported by `TenantDirectInvoice`; it must not call the unavailable
`forAllTenants()` scope on that model.


The foundation is deployed as commit `ad002aca3eeb9c01d524673ca3ef687451d302d5`,
Forge release `80737576`, through the protected production workflow. The approved
account `894665396752` and exact SNS topic are pinned in application configuration.
The webhook authenticated a fresh AWS confirmation, and the approved HTTPS SNS
subscription is confirmed. This proves subscription authentication only: no SES
simulator delivery or application delivery/bounce/complaint event has yet been
verified. SendGrid SMTP remains the application default and tenant SES sending
remains off. The separate platform-outbox work is not in this release.


### Actual simulator checkpoint and native event binding (2026-10-20)

Protected run `39498548953` deployed merged PR #450 as
`2b7a8bcaa5d53096a2a918c71772c8b429097102`; `/ready` independently matched.
All three final protected PR checks passed, including 4,218 PHP tests (8 skipped,
30,450 assertions) and the MySQL schema/recovery/concurrency gates. The exact
user-confirmed email billing exclusion IDs `1,6,10,14` are active. Existing
messaging contracts were fingerprinted unchanged. SendGrid SMTP stays active,
both real tenant and platform SES gates stay off, and no production AWS runtime
credential has been persisted.

Three fixed-content, approved simulator messages were accepted by SES. Seven
actual Send/Delivery/Bounce/Complaint notifications passed the exact SNS
account/topic/signature boundary and were retained encrypted in the private
fixture. They exposed an incorrect assumption: AWS publishes the native tenant
name in the `ses:tenant` auto-tag, not `ses:tenant-name`. The old binding rejected
the events without delivery or suppression updates. No customer was emailed or
billed. The fixture lane was disabled again and all evidence retained.

The follow-up source uses only a single `ses:tenant` value matching the reserved
native tenant. Missing, multiple, foreign and old guessed tags fail closed.
AWS's documented sender display-name form is parsed as a single mailbox before
comparison with the reserved sender; address lists and foreign senders remain
rejected. Signature/account/topic, configuration set, recipient and full opaque
receipt checks remain mandatory. See the provider's
[SES event examples](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-retrieving-sns-examples.html)
for named sources; the native auto-tag name was verified from this account's
actual authenticated messages.

This correction requires its own tested release. Reprocess the seven encrypted,
already-authenticated simulator events in place only after that release; use each
stored audit hash as a stable internal replay ID and verify a repeated replay
updates nothing. Do not export the raw private database, clear suppression or
resend the accepted messages. Keep the fixture disabled after acceptance and
retain receipts, encrypted audit evidence and contract fingerprints. The blocked
raw-database export was not executed; scoped in-place reports are sufficient.
