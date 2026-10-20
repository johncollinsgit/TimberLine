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

The user's actual excluded-customer list has not yet been verified. There is no
new hardcoded exception list in this migration. The legacy-provider tenant
allowlist is a routing setting, not proof of a billing exemption. Verify actual
tenant contract/exemption records and an invoice preview before any customer SES
allowlist expansion; preserve those terms rather than inferring charges from AWS
usage. Production's tenant messaging platform was disabled at baseline, so code
tests do not prove live customer invoicing is active.

Read-only production inspection found no explicit usage-exemption flags and no
email usage settlements. Collins Electric has an active postpaid agreement with
1,000 included emails and 5,000 micros ($0.005) per overage email. Front Yard Foods
has the same usage terms but no active postpaid agreement. Modern Forestry's
email/messaging module entitlements are `add_on_comped`; Front Yard Foods module
access is `complimentary`; Wren's email entitlement has no explicit billing status.
These module statuses do not establish free usage overages. No contract, rate,
entitlement or exclusion was changed. Keep the user's exclusion clarification
pending and all customer SES sends disabled until this ambiguity is resolved.

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
add-on under that plan; no native tenant has been created. Do not silently enroll
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
