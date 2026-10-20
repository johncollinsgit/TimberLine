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
Exact SES DNS additions still require scoped approval. Workspace remains the human
inbox. Do not change apex MX, cancel SendGrid or remove its authentication.

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
   Confirm the exact subscription separately with reviewed AWS access. Subscribe
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

No DNS writes were made by this task. Initially prefer SES Easy DKIM with the
existing MAIL FROM behavior, which requires no MX change. `getEmailIdentity`
returns the real DKIM tokens; the readiness command prints the exact three CNAME
names/values. Never invent them. For a returned token `T`, the required record is
`T._domainkey.theeverbranch.com CNAME T.dkim.amazonses.com`, DNS-only (not proxied).
These selectors coexist with Workspace and SendGrid selectors.

Do not add another apex SPF record or replace Workspace/SendGrid SPF/DMARC. If a
custom MAIL FROM subdomain is later approved, propose its exact domain/region
first: a subdomain MX to `feedback-smtp.<region>.amazonses.com` (priority 10) and
subdomain TXT `v=spf1 include:amazonses.com -all`. This would be a separate reviewed
DNS change owned by the DNS task. Root MX remains Workspace. Identity changes for
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
reservations stay held; reconcile them against provider evidence before settlement
or refund. Callback evidence must resolve uncertainty before a recipient is sent
again. Never refund an unknown as though AWS rejected it.

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
Account-specific plan and all add-ons remain unverified. Do not silently enroll or
change buyer-facing pricing based on these public rates.

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

AWS console authentication/account completion; exact account and pricing review;
scoped IAM/runtime credential approval; exact DKIM coordination and DNS approval;
SNS policy/subscription approval; sandbox production-access submission if needed;
actual simulator send plus application event evidence; platform mail durable
retry/event implementation; controlled mailbox and tenant acceptance; protected
CI and scoped deployment. Until these are satisfied, the migration is incomplete.

## Primary references

- https://docs.aws.amazon.com/ses/latest/dg/tenants.html
- https://docs.aws.amazon.com/ses/latest/dg/sending-email-suppression-list-tenant-level.html
- https://aws.amazon.com/ses/pricing/
