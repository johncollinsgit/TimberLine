# SES readiness review and second-request draft — 22 October 2026

## Status and evidence boundaries

This is a source review based on `084bbb10` plus the changes in this PR. It is
not a deployment, AWS approval, live mailbox test or authority to enable SES.
SendGrid remains the selected production provider; real tenant and platform SES
sending must stay disabled. No AWS/IAM/DNS/credential changes, sends, paid tests,
external submissions, merge or deployment are part of this review.

The owner-supplied correspondence for case `179253440100294` (`us-east-1`, account
`894665396752`) declines production access without a specific reason. The previous
response already disclosed multi-tenancy, recipient sources, historic SendGrid
volume, successful DKIM, three simulator submissions/seven signed events, and the
unverified platform lane. Do not present these as previously omitted facts or
attribute the code gaps below to AWS's decision. A verified domain is not an
approval guarantee.

The prior operational runbook records tenant simulator acceptance and replay:
three provider receipts, seven authenticated events, seven first updates and zero
second updates. That is provider/simulator evidence in a fictional tenant, not
real-customer delivery or consent verification. Its first platform attempt was a
4xx rejection with no receipt/event; the original error code was discarded and
cannot be recovered. PR #453 (`bf6bd942`) added bounded future diagnostics. Never
retry the original rejected intent or describe local tests as live proof.

## Concrete changes in this candidate

- Tenant SES now checks existing `bounced`/`suppressed` address state at its final
  durable claim, even without caller marketing flags. SES feedback and claims
  acquire the same tenant lock before the delivery lock, so feedback committed
  first blocks a later claim. An already claimed provider attempt cannot be
  recalled. Permanent SES feedback is also retained in the existing immutable
  attempt metadata, so an earlier unsubscribe or later list reconsent/temporary
  feedback cannot erase its hold. Existing marketing unsubscribe and consent
  checks still apply. The existing cross-provider guard also honors permanent
  holds after SendGrid rollback, without turning a suppressed new message into
  an unknown provider attempt. Reconsent is not authority to clear a provider hold.
- Tenant callbacks require single-valued tenant/delivery/configuration tags and
  the original single-recipient destination as well as the existing signed SNS,
  native tenant, sender and opaque receipt bindings. Platform callbacks reject
  native tenant/marketing identity tags instead of adopting them into that lane.
- Public password-reset POSTs now reserve capacity under one cache lock before
  broker token generation: 5/minute and 20/hour per IP, 3/hour per normalized
  recipient across IPs. Unknown recipients consume capacity too. Keys use HMACs;
  they contain no raw email/IP. The broker's 60-second cooldown still applies.
  Production requires its existing shared database/Redis cache for cross-node
  enforcement; array/file per-node stores do not establish that guarantee.

These do not change customer pricing, billing exemptions, provider selection,
credentials or retry state. Agreement/proposal/Enterprise/Website/Trajectory
call sites and usage-billing files are not modified; the existing shared SES
event/claim boundary receives the safety repairs.

## Proposed first cohort — decisions, not active settings

| Decision | Recommended bound | Remaining evidence/action |
| --- | --- | --- |
| Sender | Existing platform `no-reply@theeverbranch.com`, `everbranch-platform` configuration set only | Reconfirm region identity/DKIM and real message headers; expose monitored `info@theeverbranch.com` contact. AWS recommends avoiding no-reply senders, so review a monitored From/Reply-To before broad rollout; do not silently change identity or DNS. |
| Recipient cohort | Explicit owner-controlled verified addresses only, `platform_allow_all_recipients=false`; no customer tenant IDs | Owner must name/approve the exact addresses and a separately bounded test; no real recipient is authorized by this PR. |
| Allowed messages | Named-mailer pilot of requested verification/password reset only; fixed service template and link | No global `MAIL_MAILER` cutover. Review template, trigger and stable logical ID for each selected flow. All other paths below remain on their current provider. |
| Attempts | At most 100 account attempts per UTC day, 100 platform attempts/day, 1/second; tenant IDs empty | Existing account default is 1000, not this proposal. Do not claim the proposal is active. Confirm approved cost estimate and budget separately before activation. Attempts include uncertain/failed submissions. UTC application ceilings differ from AWS rolling quotas. |
| Expansion | Manual reviewed step only after actual platform simulator/event/replay proof, then an approved owned-mailbox receipt/header check | No automatic increases, tenant marketing, provisioning credentials or production access claims. |
| Pause | Platform global gate off; tenant global gate off and explicit allowlist emptied for tenant-only pause | Preserve durable claims, unresolved holds and historical evidence. Never automatically send a failed/unknown SES intent via SendGrid. |

The 100/day ceiling is a conservative proposed initial maximum, not an observed
forecast. Historical SendGrid evidence in the previous case was 1,734 requests /
1,660 deliveries over 20 September–19 October, peak 1,464/day, 41 bounces and 37
blocks. That aggregate is not a transactional split, positive-consent audit or
SES performance result. Actual first-cohort expected volume must be confirmed
from named users/trigger frequency before describing it to AWS.

## Send-path inventory and eligibility review

Static inventory searches `Mail::`, `Notification::`, `->notify(`, `->notifyNow(`
and `sendEmail(` in tracked `app` PHP. Framework/Fortify notifications and queued
mailables must also be traced. The categories below identify source entry points;
they are not a claim that every historical recipient or dynamic template has
been audited. Each additional SES path requires its own trigger, recipient
eligibility evidence, fixed template sample, measured volume and stable intent.

| Entry points / source family | Trigger and recipient provenance | Initial SES status / evidence gap |
| --- | --- | --- |
| Fortify verification/reset; `CustomerAccessApprovalService`; admin `UsersIndex` | Account action or authorized approval/invite; registered user address | Only requested verification/reset eligible for proposed named pilot. New reset admission tests cover IP/recipient limits. Verification resend already requires authenticated user and route throttle. Approval/invite is excluded pending explicit recipient-purpose review. |
| `PlatformAccessRequestController`, `EvergroveServiceInquiryController`, `CustomModuleRequestController`, `PublicBudConversationController`, `MarketingModuleStoreController` | Public request; staff/review inbox from server config, with user-supplied content in some templates | Excluded. Public forms require separate flood/content review; a review inbox is not proof of permission to email the form's arbitrary input address. |
| `LandlordAgreementController`, `LandlordProspectOnboardingController`, `AgreementAcceptanceService`, `LandlordProspectInboundEmailService` | Operator proposal/prospect/acceptance workflow, named existing counterparty | Excluded; proposal/Enterprise lane owns changes. Do not classify prospect outreach as recipient-requested transactional mail without evidence. |
| `WebsiteShopperAccountController`, `WebsiteQuoteController`, `WebsiteOrderNotificationService`/`Transport`, shipment services | Host-bound shopper sign-in request; staff quote reply; immutable order/shipping transition | Excluded. Sign-in sends to input address; review recipient cooldown/content and public route throttle. Order confirmed-mailer readiness is separate; stable transition identity exists. |
| `SoccerFamilyService`, `SoccerDeliveryService` | Family sign-in code; registration and organizer notifications | Excluded. Existing code has 5/hour per address check; simultaneous requests need separate race review. Existing delivery IDs do not prove actual SES acceptance. |
| `Trajectory/ReviewEmailService` | Per-user finance-space opt-in and verified membership, current review queue | Excluded. Existing delivery-time rechecks and finance claim remain authoritative. Never infer opt-in for another address or switch its named mailer globally. |
| Operator snapshot / scent quiz / rewards wishlist commands; `ProductReviewNotificationService`, `TenantRewardsOperationsService` | Scheduled report or product event to configured staff recipients | Excluded. Confirm report purpose, recipient list, schedule and limits; recipient/customer lists are not interchangeable. |
| `FieldServiceJobNotificationService`, `FieldServiceMyDayService`, `TeamCommunicationService`, mobile field-service/support/bag reminders, `SendFieldServicePushNotification` | Authorized work assignment/team/support action; selected staff/provider channels | Excluded. Classify email separately from push/SMS and review dynamic content and fan-out. |
| `MarketingEmailExecutionService`, birthday/candle-cash/rewards reminders, embedded campaigns, direct/group messaging, automation native email actions, `TenantBudService`, `MessagingResponseInboxService`, `ShopifyEmbeddedMessagingController` | Campaign/workflow/staff action, profile/customer/group addresses | Tenant SES disabled. Positive consent and purpose must be verified for each intended recipient; opt-out absence is insufficient. Shared unsubscribe/failure policy and final SES holds are defense layers, not provenance proof. |
| `SendGridEmailService`, `TenantEmailDispatchService`, `TenantMessagingGateway` and provider adapters | Compatibility and transport boundaries for the preceding callers | Reuse existing stack. Ready account, tenant allowlist, verified scoped sender, native tenant/configuration set, usage reservation and durable claim are required; SDK retries are off. |
| `TenantMailboxService`, inbound/reply bridges and non-email services found by the search | Separate connected inbox / external provider or shared method naming | Not automatically an SES migration path. Keep existing authority/provider boundary; inspect individually before any future inclusion. |

Tenant provisioning currently creates a native tenant/identity association but
does not itself complete configuration-set association, per-tenant suppression
and event acceptance. Configuration validation blocks an incomplete send. Keep
customer onboarding disabled until that existing path is completed and tested;
do not install broader credentials as part of this source repair.

## Validation and release evidence

Record exact PR/head SHA, local test output, protected PHP/browser and MySQL
results before merge. Required local suites: `SesMigrationSafetyTest`,
`PasswordResetEmailAbuseTest`, existing `PasswordResetTest`, `PlatformSesOutboxTest`,
`SesSimulatorFixtureTest`, `PlatformSesSimulatorAuditTest`, `SesEventContextTest`,
subscription and unsubscribe/failure-policy tests. Existing protected MySQL
`SesSendConcurrencyTest`/`PlatformMailConcurrencyTest` remain mandatory for real
database locks/caps. SQLite tests do not establish cross-process MySQL behavior.
The Linux job also runs the SES recipient/signature/subscription/reset boundary
files explicitly in serial, so standalone PHPUnit class discovery is visible
independently of the full parallel test count.

Before any appeal claims these changes are operational, separately approve the
protected release, verify exact `/ready` SHA and production gates, inspect only
nonsecret active caps/cohort/cache configuration, and obtain the platform proofs
above. No read of production or AWS state was performed in this PC source review.

## Draft follow-up for owner review — not submitted

Do not submit this draft as a claim of deployment. Replace the bracketed evidence
only after it exists; otherwise retain the limitations and ask what further
verifiable evidence AWS needs. Respond in the existing Support Center case,
not to the notification email address.

> We request reconsideration of production access for Evergrove Software's
> Everbranch application in account 894665396752, us-east-1, case 179253440100294.
> We understand your review includes factors you cannot disclose. We are not
> treating domain verification or testing as a guarantee of approval.
>
> Our prior response already described the multi-tenant platform, recipient
> sources, historical SendGrid volume and three simulator messages/seven signed
> events in a fictional tenant. SendGrid remains active. Customer and platform
> SES sending are disabled; tenant marketing is excluded from our initial plan.
>
> Our additional review found and repaired final-send suppression checks for
> permanent feedback, stricter single-recipient/tenant event binding, and public
> reset-request IP/recipient admission limits. [Insert exact reviewed PR/release,
> dates, protected test results and active nonsecret configuration, or explicitly
> state that the changes are source candidates and have not been deployed.]
>
> The proposed initial sender is the existing verified platform identity only,
> with an explicit owner-controlled recipient allowlist and requested verification
> and password-reset messages, no promotions. We propose a ceiling of 100 account
> attempts per UTC day and one per second; [insert confirmed expected daily/monthly
> first-cohort volume and exact approved sender/configuration set]. These are
> ceilings, not a request for an additional quota increase or plan change.
>
> Verification and reset messages contain the account-action link, identify
> Everbranch and provide our monitored contact info@theeverbranch.com. We will not
> use purchased, rented or scraped lists. We do not claim historical customer
> lists have all been audited. All tenant marketing remains separately blocked
> until positive consent, purpose and unsubscribe/suppression checks are verified.
>
> The platform lane has not yet produced an accepted provider receipt or signed
> event. Its original rejected attempt is retained without blind resend; the
> original error code is unavailable. [Add actual separately authorized platform
> simulator/replay and owned-mailbox receipt/header evidence only after obtained.]
> Local fake-provider tests are distinct from AWS simulator and actual mailbox
> evidence. Unknown outcomes retain their durable hold and never automatically
> fail over to another provider.
>
> Could you identify any additional verifiable evidence or permitted validation
> step we should provide for this narrowly scoped transactional rollout?

## Official sources reviewed

- [Production access](https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html): regional approval, recipient-requested mail and an existing feedback process.
- [Sender/list/content practices](https://docs.aws.amazon.com/ses/latest/dg/tips-and-best-practices.html): authentication, list confirmation and unchecked public-form content risks.
- [SNS signatures and expected topic](https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html): authenticate before processing and reject unexpected topics.

The separate policy/forum research belongs to the parent task. Anecdotal approvals
and denials do not identify the cause of this account's decision.
