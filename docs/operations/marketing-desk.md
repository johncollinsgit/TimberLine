# Marketing Desk and Automation Studio

## Status and ownership

Implemented as a shared, tenant-owned product on the current production baseline
`08fca383` (including AI Branch PR #407), with all new execution gates disabled by
default. Modern Forestry is the intended retail/wholesale pilot, not a separate
implementation. No production
settings, credentials, live workflows, posts, customer messages, or billing were
changed by this build. The separately gated AI Branch keeps its own commercial activation and settings;
this feature extends Bud and reuses the canonical tenant AI usage ledger, marketing
profiles, campaigns, inbox, access resolver, and Workflow Studio v2 runtime.

The desk lives at `/marketing/desk`. Navigation appears only for entitled,
allowlisted workspaces. Existing `/workflows` URLs, revision conflicts, immutable
published definitions, tests, action receipts, and Asana/Calendar execution remain
compatible. Studio gains Overview/Build/Activity/Settings, a step outline, fit to
view, readable summaries, visible publication guidance, and a wider directory.

## Activation checklist

1. Pass the protected test/build and mandatory migration gates. Ship with all
   flags off through the existing GitHub/Forge release process; verify `/ready`.
2. Set `MARKETING_DESK_ENABLED=true` and an explicit nonempty
   `MARKETING_DESK_TENANT_IDS` list for the pilot. Empty never means all tenants.
   Existing `messaging` module access and active workspace membership are required.
3. Configure the workspace timezone in Settings. This is stored under
   `TenantAccessProfile.metadata.marketing_desk.timezone`, falling back to the
   separately configured AI Branch timezone, then UTC. Marketing send windows are
   09:00–18:00 local time. AI budget months remain UTC.
4. Approve brand knowledge with an expiry date. General knowledge may serve
   unclassified inquiries. Retail, wholesale and services policies retain separate
   knowledge and audiences. Approved versions are immutable; changes create a new
   draft. Pausing a reply policy remains a separate explicit action.
5. For paid AI, retain existing Bud activation review and AI module access. Set
   `EVERBRANCH_BUD_AI_ENABLED`, its provider key, and verified input/output rates.
   The owner must additionally approve a marketing AI cap of at most 5,000 cents
   in Settings. The effective cap is the lower of that cap and the existing Bud
   workspace cap. This does not reset usage or activate a subscription.
6. Configure each channel using its existing onboarding. Verify senders and
   recipient consent with controlled accounts. New marketing actions require live
   provider readiness even for non-delivering step tests. SMS global fallback is
   restricted to the existing explicitly declared legacy tenants.
7. Configure Meta OAuth, app review/advanced access and the signed webhook callback
   before setting `MARKETING_META_EXECUTION_ENABLED=true`. Keep this gate off while
   email/SMS tests proceed. Instagram uses its existing independent OAuth app;
   Facebook Page login is an additional connection, not LeadConnector.
8. After controlled acceptance, set `MARKETING_DESK_DELIVERY_ENABLED=true`.
   Separately set `MARKETING_DESK_AI_REPLIES_ENABLED=true` only after reply-policy
   evaluation and human handoff tests pass. Every worker rechecks current access.

Owner/admin roles manage connections, timezone and budgets. Managers and marketing
managers may approve knowledge, posts, policies and marketing workflow versions.
Staff may read/reply only to assigned inbox conversations and take over; they
cannot turn AI back on, approve campaigns, or edit knowledge.

## Content and provider contracts

Draft creation and editing never publish. Uploaded JPEG/MP4/MOV media lives in the
workspace's public `marketing-media/{tenant}` namespace; configure the storage disk
for public HTTPS delivery and its normal storage link/CDN. Other external URLs
require an exact approved `MARKETING_DESK_MEDIA_HOSTS` entry. Meta must be able to
fetch the media. Images are JPEG for Instagram compatibility. Up to ten carousel
images are supported; Stories, live broadcasts and generated video are excluded.

Single and batch approval freeze each revision, content hash, account choices and publish time. A batch is atomic: any invalid or changed draft prevents every approval in that batch.
Edits revoke approval and cancel unsent publications. A publishing/processing,
unknown or published generation must be copied into a new draft. Each platform
has its own durable publication. Instagram containers are polled before publishing;
Facebook videos remain processing until provider readiness is confirmed.

`marketing:dispatch-desk` runs every minute and queues bounded approved work.
Multiple workers claim a publication atomically. Interrupted/uncertain POSTs stay
unknown and never automatically repeat. An operator can supply the original post
ID and explicitly verify it against the owning account's latest 100 published
posts and approved caption. This records the existing publication; it never
publishes another post. Lack of a match preserves the uncertain state.

Insights are explicit read-only refreshes. Unsupported metrics remain null; a
permission failure does not become zero. The desk labels lifetime totals and
observation time and links to a provider-returned verified HTTPS permalink.
Compare within platform and format; no unsupported sales attribution is claimed.

Facebook callback/page selection rechecks the authorizing user and workspace,
uses single-use cached state and an encrypted short-lived page-selection ticket,
stores encrypted page tokens, and records actual granted permissions. Account
bindings prevent the same social identity from being silently claimed by two
workspaces. Existing duplicate bindings fail closed pending an ownership review.

Instagram exchanges short-lived grants for long-lived tokens before storage and
uses the unversioned refresh endpoint. Extend `INSTAGRAM_SCOPES` only after provider
approval for publishing/insights. The saved scope list is requested authorization;
Meta still enforces actual grants at every operation. Use **Verify Instagram
webhook subscription** after configuring its existing `/webhooks/instagram` URL.
Facebook uses `/webhooks/marketing-facebook`. Verify raw-body HMAC signatures;
ignore echoes, deduplicate message IDs, and preserve chronological inbox order.
Standard Instagram/Messenger replies require an eligible inbound message within
24 hours. Arbitrary cold DMs and comment-to-DM campaigns are not enabled.

## Recipes, consent and source readiness

Eight starter recipes cover retail welcome, post-purchase care/review, win-back;
wholesale inquiry, samples and reorder; and service inquiry/completion. Every
recipe is an inactive schema-v2 draft using the same native component catalog.
Replace starter guidance, configure actual customer mappings, and test every step
before publishing. Marketing delivery additionally requires an approval for that
exact published definition hash, audience and store. Customer Loop remains
review-only; its completion state cannot become an external send.

New channel consent is not inferred from import membership. Audience permission
review requires current canonical channel consent plus explicit evidence covering
that audience and store; an auditable consent event records the operator's review.
Historical grants with unknown scope must be reviewed. Profile opt-outs revoke
new audience permissions; merges retain their original identity/evidence and
cannot transfer those permissions to the survivor.

Subscription/segment/inbound and verified milestone events use the existing native
outbox. The desk exposes a scoped milestone interface for a verified inquiry,
fulfillment, delivered sample or completed service. These events require the real
customer identity and a source reference. Raw order/import fields do not by
themselves prove fulfillment, delivery, or service completion. Connectors can call
`MarketingSignalService::record` only with equivalent verified evidence; public
Website form submissions retain their existing submission-only behavior.

The inactivity scanner uses canonical external customer profiles with a sync less
than 24 hours old, an audience permission and latest verified order timestamp.
It emits deduplicated 90-day retail and 60-day wholesale events. Other import
warnings do not block unrelated social posts or FAQ replies. The scheduled
marketing trigger checkpoints audience members once per workspace-local day,
with daily/weekday choices and skipped missed days. It uses the existing poller
and durable run items, not a second scheduler/sequence engine.

Enrollments pin their workflow version and event. Reply, purchase, opt-out,
complaint and takeover stop applicable follow-ups. Delivery rechecks eligibility,
profile state, scope, approval, sender readiness, local window and frequency.
Default automated limits are two emails per rolling seven days and two texts per
rolling thirty days. Suppressions show their reason. Provider failures/ambiguous
attempts retain uncertain receipts for review rather than becoming a fake success
or an automatic retry. Existing transactional/operational actions are unaffected.

## AI, cost and human control

Owner Bud can read approved knowledge, bounded saved social performance and private
operator history, return ideas, save a suggested post draft and propose an inactive
recipe. It cannot execute model-generated code or publish/activate from chat.
Structured Responses API outputs are validated server-side; source references are
restricted to supplied approved knowledge. Keys and credentials never enter prompts.

Customer AI receives only the current inbound message and its approved knowledge.
Policies bind channel, store and knowledge version. Unclassified conversations can
use only one unambiguous general policy; specific audiences require an explicit
conversation assignment. Missing/expired knowledge, complaints, private order
questions, unsupported sources, cap exhaustion or failures hand off. Human replies
and takeover pause AI until explicitly resumed for future messages. Newer inbound
messages invalidate an in-flight reply; channel and policy access are checked
again before dispatch. These policies are narrow delegation; the existing global
Bud confirmation requirement remains intact.

Provider costs reserve the full bounded request under the existing tenant settings
row lock. Same request UUIDs cannot trigger another provider call. Actual usage
settles the reservation; ambiguous outcomes retain it and never automatically
retry. The daily desk shows 80% and cap-exhaustion states. Marketing cost-only usage
is explicitly excluded from automatic voice invoicing; no new commercial charge
or plan mutation is enabled.

## Verification and rollback

- Application: full Pest suite, existing Modern Forestry regression command,
  Workflow Studio, inbox, provider and customer-merge tests.
- New tests: `tests/Feature/MarketingDesk`, real MySQL recovery and concurrent
  reservation/idempotency tests, including simultaneous voice and marketing
  reservations against their shared budget. CI's mandatory MySQL job includes them.
- Migrations: eleven additive guarded table creates, concise indexes, registered
  recovery scenarios, data-free baseline generated only through the disposable
  MySQL workflow, prior-release upgrade rehearsal and dump/fingerprint verification.
- UI: `tests/e2e/marketing-desk.mjs` checks eight views at 1440/390 pixels, no browser
  errors/overflow, and Studio overview, outline, fit and mobile canvas. Run only
  against the isolated local browser fixture, never production.
- Browser fixture: create `database/marketing-desk-preview.sqlite`, migrate that
  disposable SQLite file, then run `php tests/Support/MarketingDeskBrowserFixture.php`.
  Start a local server with the printed tenant ID explicitly allowlisted, provider
  execution flags off, and run `node tests/e2e/marketing-desk.mjs`.

Pause new sends by setting the delivery flag false; pause customer AI independently
with its flag. Disabling Meta execution stops new Meta operations. Removing a tenant
from the nonempty desk allowlist blocks the UI and queued work. Preserve additive
records, unknown attempts and published workflow history; do not roll back schema
or reset active Asana/Calendar cursors. Complete a controlled Modern Forestry
provider/merchant acceptance before production activation. Real provider behavior,
Meta review and the wife's ten-minute exercise remain launch checks, not simulated
production evidence.

### Local acceptance evidence (2026-10-16)

- Full application suite after the production rebase: 3,295 passed, one existing
  skip; 23,252 assertions.
- Latest focused desk/Studio/Instagram/inbox/billing checks: 53 passed.
- Final shared-meter, desk and voice regressions: 38 passed, including rejection
  of a marketing reservation reused as a voice request.
- Modern Forestry regression command: 156 passed.
- Exact CI MySQL recovery sequence: 42 passed, 271 assertions, including
  missing-index repair and retained rows. Separate budget/idempotency concurrency
  check: passed, 21 assertions, including mixed voice/marketing requests.
  Prior-release upgrade and committed schema dump/fingerprint verification passed
  in the disposable database.
- Build/type checks and sixteen desktop/phone desk views passed. Studio checks
  also cover outline collapse, fitting the real canvas and error-to-field focus.
- Changed PHP files pass Pint. The repository-wide check reports 604 existing
  formatting failures, all in files this change leaves untouched.
