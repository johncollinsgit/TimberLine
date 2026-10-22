# Enterprise runtime and live-integration decision packet

Prepared October 22, 2026. Proposed destinations below are not provisioned or
verified access grants. Source tests, browser simulations and public sample data
are distinct from hosted persistent-workspace acceptance.

Current action-time approvals and access status belong in the private release
handoff. This source design is not an authorization record. The additional
proposal-owner silo is described structurally in
`enterprise-proposal-review-release.md`; do not install it in the client silo.

## Release and hosting

The existing approved server is `129.212.138.111`, Forge site record
`backstage.theforestrystudio.com`, serving `app.theeverbranch.com`. The requested
mount is `https://app.theeverbranch.com/enterprise-campus`. No new billable server
or domain is proposed. Capacity and backup storage have not been verified.

Before applying configuration, the release owner needs an authenticated read of
the numeric Forge server/site IDs, active release layout and PHP socket. Those
identifiers are unknown; this document is not authority to guess them or bypass
the existing Forge login restriction.

The designed isolated campus destinations on that existing site are:

- Database `everbranch_enterprise_demo`, schema-limited database user
  `everbranch_ent_demo`; no global privileges or grant option.
- Runtime `/home/forge/backstage.theforestrystudio.com/shared/enterprise-demo`
  with its own `.env`, `ENTERPRISE_APP_KEY`, private storage and bootstrap cache.
  No retail environment, application key, passwords or production data copied.
- A fixed trusted FastCGI mapping to the reviewed release's
  `apps/enterprise/public/index.php`, mounted at `/enterprise-campus`, with
  preserved request URI and fixed runtime selection before environment loading.
  Assets map narrowly to that build. No retail document-root or DNS change.
- Origin `https://app.theeverbranch.com`, base/session path `/enterprise-campus`,
  isolation enabled, own session cookie. A shared PHP Unix account is not a
  dedicated operating-system isolation boundary.
- Fictional reusable client `lees-mcrae-demonstration`, initial Operations/shared
  configuration and sourced 68-location catalog with unknown coordinates left
  null. No student records, employee invitations or real college integrations.
- One separately verified John presenter identity, exact tenant membership,
  Operations coordinator capability and canonical Teams entitlement/allowlist.
  The actual user/tenant/department IDs and secure credential process are still
  pending. Public persona names and test IDs cannot become production grants.

Acceptance must include durable create/edit/assign/workflow/reload, private file
and cookie checks, exact build identity and release-survival. None is established
by the old public preview. Backup monitoring and an isolated restore drill remain
separate plan-first work; this workspace is not yet operationally backed up or
restore-verified.

Protected GitHub checks/reviews and the normal GitHub-to-Forge atomic release
path remain mandatory. The Enterprise draft stack needs release-owner review
against current main; do not incidentally merge Fleet, POS, terminal, support or
proposal branches blindly. Proposal PR475 is now explicitly included in the
unified source candidate. A correct-client hosted immutable publication is still
absent; normal campus headers retain preparation state. Authorized publication
review routes alone replace that state with the actual bound proposal link.

## AI account, credential and spend

Source integration authorizes no credential transfer or paid dispatch. The only
proposed API-key destination is this isolated runtime's `ENTERPRISE_AI_API_KEY`.
The owner must identify an eligible existing Enterprise OpenAI project and key
identifier, permitted model/tier and retention policy; no secret belongs in a
document, browser payload or repository. Avery AI Branch and Jarvis credentials
are expressly excluded from reuse. No eligible project/key is currently verified.

See `enterprise-voice-live-decision.md` for the proposed fixed Responses endpoint,
bounded evidence/history, inclusive rate ceilings, reservation settings and
retention command. Its numerical settings are proposals, not an approved new
budget or contractual price. A configured feature flag alone cannot approve use.

The already requested trial allowance is **$5 total shared lifetime** across
SMS/email/AI and related acceptance tests, including prior charges and unresolved
reservations. It is not $5 per provider, customer session or recurring period.
This implementation owner and the voice owner report zero paid calls; that does
not reconcile other related testing or establish an account balance. Reconcile
all related charges before any dispatch. An empty local ledger proves neither.

The controlled-trial adapter deliberately remains record lookup only. Generative
voice has not been connected to the reviewed shared trial ledger. Its existing
tenant AI reservation does not prove a hard provider-account spending cap when
usage is unverifiable. A tenant-locked guard now refuses new reservations and
dispatch claims after any uncertain outcome, preserving its exposure while
allowing completed exact replay. Already dispatched calls cannot be undone and
may exceed their theoretical reserve. The shared-ledger integration and exact
account/rate/spend approval remain live-activation blockers. No separate voice
allowance may bypass the shared $5 cap. Current inclusive account-specific rates,
reconciliation procedure and one verified presenter/synthetic scope must be
reviewed before proposing an exact paid test.

Server-held voice history expires after ten minutes and the pruning command
must be configured and verified before activation. Provider `store:false` and
local pruning are not a claim about complete provider-side deletion. Physical
iPad/iPhone microphone, playback, echo, network and reconnection acceptance is
pending; automated fake speech is explicitly simulated.

## Messages and other integrations

Generated email/SMS drafts are exact-recipient **previews only**, with no send or
confirmation executor. Tests use `voice-preview@example.invalid` and the
fictional reserved number `+12025550101`. These are not approved live recipients.
Tour replay never starts speech, sends a prompt/message or spends provider money.

The intended first own mailbox is `john@evergrovesoftware.com`. Gmail callback
would be `https://app.theeverbranch.com/enterprise-campus/enterprise/gmail/callback`,
requesting `openid`, `email` and `gmail.send` only. Eligible OAuth client metadata,
credential destination and the actual consent grant remain separate owner
decisions. There is no authorization to impersonate another mailbox.

The reviewer trial instead needs a verified Everbranch service sender. Historical
SendGrid/SES references to `no-reply@theeverbranch.com` are not current sender
verification. Provider/account/key source and destination, approved receiving
address and exact subject/body/link preview are pending. Twilio account/from
number, current rates, consent and exact receiving number/message are likewise
unverified. No purchase, real send, OAuth grant or provider configuration was
performed. Real email delivery is not a gate for publishing the preview-only tour.

Live attachment scanner clearance remains pending; quarantined attachments do
not become downloadable merely because fixture validation passed. Support PR473
has a test-only cross-runtime contract, without live identity/network/key
bindings. Proposal PR475 source checks passing do not create an owner workspace,
correct-client publication or permission to change billing/access.
