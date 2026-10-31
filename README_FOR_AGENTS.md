## Native customer confirmation (October 31; source candidate)

The private delivery portal now uses the original native update journal for
Shopify members, with `CUSTOMER` provider actor and signed `shopify_customer`
request/confirmation/accepted history. `SubscriptionShopifyCustomerActor` separates
the authenticated customer/profile from the recorded merchant shipping/scents
and continuing-authority reviewer. Current gates/profile/policy/owner/method/source
and exact fresh quote guard one commit. Retained signed dispatch actors cannot
start new interactive work. Owner CLI requests remain merchant actions.

Pending/status/close routes retain the private token, original profile and member
scope. Status GET polls/reconciles only and never starts a new calculation/commit.
Auto-refresh is bounded; unapproved/stale reviews can be explicitly closed, while
sent unknown commits cannot be discarded or resent. Recovery and accepted retries
preserve later pauses after editing withdrawal. Historical signatures and original
policy remain independently verifiable. Braintree keeps its existing portal path.
No DDL/live rollout; actual original-method/source/provider/customer/backend and
two-member acceptance remain required. See the replacement runbook.

## Original dispatch durability (October 31; source candidate)

Original Shopify adoption calculations/commits and native/Braintree payment entry
and transport calls require transaction level zero for a new send. Never bypass
these production guards for tests. Recovery of an already-started original
adoption/payment remains available. Braintree reconciliation inside a transaction
defers a new external Shopify receipt; a later top-level pass handles it once.
The replacement receipt bridge guards direct new sends too.

The eight moved workflow suites and explicit durability cases are in
`tests/Integration/SubscriptionRuntime`; run them on disposable local test-named
MySQL without an outer RefreshDatabase transaction. CI includes the directory.
Cleanup tracks only tenants/users created by the current test. The fixtures still
fake all provider requests. Stored merchant-acceptance JSON is compared
canonically because MySQL reorders object keys; changed values/types still fail.
No DDL/live deployment/rollout. Native customer confirmation is implemented above; real acceptance remains.

## Initial adoption calculation IDs (October 31; source candidate)

The immutable initial `shopify_adoption.calculation_id` remains the polling handle.
Only a successful response proving original adoption attributes/method and exact
terms/totals can add immutable `terminal_calculation_id`. Commit intent, quote and
terminal ID must agree. Never replace the initial handle with a concrete success
ID, fabricate a provider ID or resend an uncertain commit. An unsent prepared
commit rereads its exact reviewed result; legacy generic intents gain terminal
evidence only from a fresh exact original commit-ID/review match. Recovery after
dispatch is unchanged. Original transaction durability and native customer
confirmation are implemented above; actual provider acceptance remains.
No DDL, live mutation or rollout occurred.

## Native amended tax preflight (October 31; source candidate)

`SubscriptionShopifyTaxService` is a calculation-only collection lane for accepted
native amended terms. It does not use amendment editing gates or commit a
contract. Its signed request/send/received/proof/expired actions retain exact
original context, terms/revision/date, unique calculation-only marker and returned
provider IDs. The dispatch claim commits before HTTP; enclosing transactions are
rejected for new amended collection. Only a successful exact-total/line/method/
policy/note projection can become a proof. Initial pending handles stay immutable;
terminal IDs are used only from their correlated result. No tax lines/rates are fabricated.

Freshness is three minutes from send and observation, never renewed by polling
an old completed result. Expired tax-only generations are retained and replaced
with a new unique calculation; none can be committed through the amendment CLI.
The minute `--tax-preflight` mode only prepares/polls due allowed active native
Everbranch members and never bills by itself. The five-minute collect/reconcile
schedule is unchanged. Both are default-off through existing exact collection
gates. Final cycle preparation and send check current proof/merchant/contract
version; `dispatch_native_tax_preflight` becomes immutable once captured. An
unsent cycle may refresh only its mutable proof pointer under unchanged frozen
amount, terms, revision and method. Original already-started recovery does not
recalculate tax or resend payment. See the runbook and MySQL tax-preflight suite.

## Native update approval and recovery (October 31; source candidate)

Use preview-first `subscriptions:shopify-amend` for owned native delivery/scents
reviews. All stateful actions require `--apply`; change and approval inputs use
separate private 0600 JSON files. Commit requires the original UUID/revision,
exact fresh quote hash, explicit owner review, unchanged provider version and
fresh original method/complete source-stop proof. Native/general amendment and
apply gates all remain default-off; do not enable collection based on this CLI.
Uncertain commit returns a nonzero CLI exit and retains the original send. A
retry/reconciliation reads the owned contract and never sends another commit.

`SubscriptionShopifyUpdateHistory` validates original intent/quote/approval,
single send/result and intervening signed stops without live access. It derives
accepted current contract attributes only from signed terms history. Never
restore an older local schedule/revision or fabricate native tax line/rate data.
Pending commit authority blocks positive actions and new collection even if a
parent approval is missing; safe stops and original-attempt recovery remain.
Finite customer pauses allow only the validated contiguous terms-amendment
revisions that preserved their schedule. The reconciliation worker finishes only
previously approved native work; it does not approve or send a fresh commit.
Native amended collection now requires the fresh tax preflight above. Customer native confirmation is implemented above; real provider/customer/backend/two-tester acceptance remains required. See the runbook.

## Native update calculation journal (October 31; source candidate)

`SubscriptionShopifyUpdateService` provides owner calculation, approval/commit,
reconciliation and explicit pre-commit closure. The independent
`replacement_shopify_amendments_enabled` gate defaults off; commit also requires
the general apply gate and original authority. Customer edits remain separate.
Immutable signed actions are authoritative;
never clear a mutable marker or regenerate an unknown calculation. Top-level
transactions are required so the send claim commits before HTTP. The bounded
send rechecks current owner/pivot and original credential under locks shared with
member/agreement actions. Known provider IDs commit before validating their
economics; polling does not rewrite current billing terms or later stops. The
MySQL durability suite runs without RefreshDatabase transactions against a
guarded disposable local database and verifies marker visibility through an
independent connection, competing workers, revoked credentials beyond an older
snapshot, and freeze/stop replay. Do not bypass its transaction guard for tests.
Commit/history/positive-action guards and worker recovery are described above;
Fresh tax preflight and native customer integration are described above; real acceptance remains outstanding.
No live update or source change occurred. See the runbook.

## Actual Shopify schema and native update protocol (October 31)

`scripts/ci/validate-subscription-shopify-api.mjs` validates 21 protocol documents
against the type-only pinned 2026-10 original-app capture in
`tests/Fixtures/Subscriptions`. Provenance/checksum accompany the projection;
`--schema-json` can verify a fresh successful original-app capture. No credentials,
app bootstrap, provider requests or customer data are involved in the CI check.
The bundled toolkit still lacks calculation types; its failure/skip is separate
from the passing actual-schema validation and from unproven mutation acceptance.

`SubscriptionShopifyUpdateProtocol` is a pure intent/quote/applied-result builder,
not an enabled amendment executor. Preserve real provider line IDs and complete
fixed price/pricing-policy and selling-plan fields when replacing lines. Omit
unchanged delivery/line fields; never infer line IDs from source IDs or silently
reprice from the catalog. Accepted prior attributes must come from signed retained
provider evidence, never a client or an unchecked response. The calculation
journal/commit/history and fresh tax collection preflight are described above;
native customer confirmation is integrated above; real provider/customer acceptance remains. No live update was
attempted. The latest method read sees Jennifer's active Shop Pay agreement;
source mapping and merchant acceptance remain required. See the runbook.

## Customer delivery review and confirmation (October 31; source candidate)

Use the existing private member portal for Braintree customer delivery edits,
behind `replacement_customer_delivery_enabled` (default off), exact rollout and
explicit `subscriptions:delivery-policy` merchant review. A policy records
original-member fixed shipping coverage and offered scents without changing
billing terms, revision or original authorization. Do not fabricate review flags
for a customer or impersonate the owner as the customer actor. Encrypted expiring
review data binds the exact customer/profile, input, original-store quote, total,
policy, revision and UUID. Confirmation recalculates tax/availability and shares
the original member/agreement/current-row locks before one signed future-term
action. Policy history remains valid for already accepted terms after a later
editing withdrawal; current policy still gates new edits. Preserve private host,
CSRF, throttle and safe stop access. No payment entry or charge occurs here.
Native Shopify amendments and actual hosted/provider/rollout acceptance remain
outstanding. See the replacement runbook and customer-delivery concurrency tests.

## Historical source access and live-state evidence (October 31)

`SubscriptionBillingShopifySource::originalPayment` checks `read_all_orders` and
order read/write permission alongside every original order read. Do not remove
this because an original order is currently recent: continuing verification must
still work after Shopify's default history window. Verification/activation and
fresh collection all use this path; missing scope accepts no method proof and
claims no new payment cycle. The original store has these scopes in the latest
read-only check, but no Braintree API connection or replacement deployment exists.
Both prepared testers remain Recharge-owned with zero pilot enrollments/cycles.
Real October order linkage is observed, not real reusable-method acceptance.
Retain native amendments and actual hosted customer/provider acceptance tests
as outstanding work. See `docs/operations/subscriptions-recharge-replacement.md`.

## Accepted Braintree future terms (October 31; source candidate)

`SubscriptionBillingAmendmentService` accepts a fresh exact quote and explicit
owner review behind independent default-off apply and existing rollout gates.
`SubscriptionBillingTermsHistory` verifies an unbroken encrypted signed action
chain back to the original authorization; quote hashes alone grant no authority.
Do not edit or backfill originals, replace unit pricing/source identities, rewrite
cycles or roll current terms back to an older signed state. Member/agreement,
current owner/pivot and cycle/action locks protect atomic acceptance and replay.
Original-account future billing verifies current tax through the quote protocol
before creating a cycle/sale; a change needs another review. Servicing freezes
stop new changes while accepted collection uses its independent gates. Frozen
tax lines feed only the new cycle's Shopify receipt. Real MySQL races and source
end-to-end collection/handoff tests accompany this path; no DDL or production
activation occurred. Braintree customer confirmation is described above;
Shopify updates and actual customer/provider acceptance remain outstanding.

## Subscription delivery quote foundation (October 31; source candidate)

`SubscriptionAmendmentProposal` derives address/scents proposals from an intact
current agreement. `SubscriptionAmendmentQuote` calculates via the original store,
preserving membership prices with explicit variant price overrides and reviewed
fixed source shipping. `subscriptions:amendment-quote` is offline until `--fetch`;
fetch requires verified owner/admin, servicing, exact rollout and the independent
default-off amendments gate. Current identity/terms/permission checks surround
each request. It validates both currencies, exact lines and tax breakdown, rejects
warnings/discounts/bundles and returns a keyed subject/context quote. It saves no
agreement/action/cycle or provider resource. Do not use its hash as authorization
or write returned terms directly: use the signed acceptance path above. Customer
confirmation and Shopify contract updates remain required. See the runbook.

## Existing subscription membership review (October 31; source candidate)

`SubscriptionMembershipReviewService` accepts original prepared membership or one
HMAC-bound append-only `membership_review` action. The preview-first
`subscriptions:membership` CLI requires a private file, exact source/customer
context, verified owner/admin and exact rollout. Apply retains original payment
authorization and every billing agreement field, including its revision; the
action's expected revision records the reviewed state rather than changing terms.
Member/agreement locks and current action reads preserve one immutable review
under real MySQL retries/races. Never backfill missing originals, infer membership
from a saved method, or treat this review as customer activation/payment consent.
Broader member/operator parity and real two-tester acceptance remain outstanding.

## Candle Club membership after cutover (October 31; source candidate)

`SubscriptionReplacementCommunityService` resolves benefits only from the exact
authenticated customer, activated Everbranch agreement and original reviewed
`membership_program=candle_club`. Preparation requires explicit source membership
review/reference when that program is selected; generic billing grants no club
benefits. Never retrofit the immutable original authorization from current data.
New member votes use `subscription_member_votes`, not synthetic Shopify GIDs or
billing attempts. Shared poll/member locks and current locking reads preserve one
ballot and reject a pause or poll closure committed after a repeatable-read
snapshot. Legacy writes acquire member/agreement locks before the poll and
recheck conversion against current rows. Comment replay uses a current locking
read after membership/scent locks. Old ballots
link through exact retained source subscription/customer IDs; stale source mirrors
cannot cast again for a converted membership. Ambiguous identity blocks a new
ballot. Legacy nontester voting remains in its existing lane.

The existing native endpoints retain polls/scents/history and write private pending
feedback to the existing review store. Unchanged old comment retries preserve the
accepted row; neutral membership metadata never rewrites an old contract link.
Member views do not fetch/enrich missing stock photos. No provider, payment,
message or reward issuance follows from voting or feedback. The one additive
table has named recoverable constraints, registered real MySQL partial-DDL tests,
concurrent voting/pause evidence and a verified regenerated schema baseline.
Future-term changes, commitment/gift/operator overview parity and real customer
acceptance are still required before broad rollout.

Subscription store binding equality uses canonical typed JSON content, preserving
exact original identity while tolerating MySQL JSON key reordering. Do not replace
it with loose equality or mutate original routing when a replay differs.

## Native Shopify subscription billing (October 30; source candidate)

`SubscriptionShopifyExecutionService` and the fixed billing protocol extend only
the original installed-app transport. `subscriptions:shopify-billing` previews by
default; explicit accept/activate/collect/reconcile/sync actions and the bounded
replacement worker preserve exact tenant/member rollout. Merchant acceptance
checks current own-contract writes, historical order access and pinned billing
state/input capabilities. Separate Shopify collection/live-acceptance gates
default off. Real merchant/API acceptance is still mandatory.

Member/agreement/cycle locks protect one durable dispatch marker, source proof and
stable original UUID. Recovery reads the original ID or complete bounded owned
contract pages; no uncertain response permits another attempt. Exact paid-order
economics, products, address, environment and payment transactions precede native
receipt confirmation and anchor advancement. The external Shopify order bridge
is never invoked. Only that fresh verified native receipt can be excluded from
post-payment source coverage; other collections still block. Late refunds,
disputes, pending challenges and duplicate signals require reconciliation.
Schedule patches serialize with local member actions; retries do not undo later
stops. The worker skips unchanged confirmed schedules without provider calls.
Native order/payment/fulfillment reporting remains tenant-scoped and excludes
sandbox collections. MySQL tests cover actual adoption/activation setup and a
two-worker billing dispatch race; no DDL or production activation is included.

## Original subscription authorization evidence (October 30; source candidate)

`SubscriptionBillingAuthorization` verifies the original HMAC-bound terms,
payment binding and authorization review saved at preparation. The encrypted
`original_authorization` snapshot and review reference/actors cannot be replaced,
removed or retrofitted on an existing agreement. Historical method/source proof
uses those original terms; current billing verifies original terms or the complete
accepted Braintree amendment chain. Shopify adoption retains its original-terms
guard. Retaining a stale hash
after editing JSON must not bypass the guard. Reconciliation continues to use
immutable original cycle terms even if the current agreement later changes.
Missing/corrupt snapshots require explicit evidence review, never an automatic
backfill. No schema migration or production activation is included.

## Shopify paused-contract adoption (October 30; source candidate)

`SubscriptionShopifyContractService` and its fixed protocol extend the original
subscription-app transport; historical shadow operations remain read-only. The
new adoption and production-acceptance gates default off. Original request,
calculation ID, commit proof/dispatch and contract ID are write-once encrypted
agreement evidence, with append-only actions. Create/commit dispatches claim the
agreement row before HTTP; real MySQL workers prove one provider submission.
Commit requires exact current quote review and fresh pinned Recharge stop proof.
Recovery uses the original owned customer and UUID, never another create/commit.
Schedule repairs only pause that original contract and restore its reviewed first
date; ambiguous/changed/overdue results remain paused for review. They never
activate local billing, advance a cycle, create an order or submit a charge.

The bundled 2026-10 validator snapshot is missing calculation fields present in
the current official 2026-10 docs/schema tool. Record that failure separately;
the later actual original-app schema capture validates the typed documents, but
does not prove mutation acceptance. Every adoption write probes the
original account's exact pinned schema and permissions. Real isolated-store API
acceptance remains mandatory before a production gate is enabled. See
`docs/operations/subscriptions-recharge-replacement.md`.

## Modern Forestry reward channel limits and Shopify method proof (October 30; source candidate)

`CandleCashChannelPolicy` resolves the persisted profile tenant's explicit config:
Modern Forestry issues new $5 Square codes and $10 online codes; other merchants
retain existing rules. A code freezes its amount/channel. Both staff reconciliation
and Square ingestion lock the same tenant/order namespace to reject duplicate use.
Unused new in-person codes restore only their original debit on cancellation.
Online codes cannot reconcile as Square codes, in-person codes cannot create
Shopify discounts, and Modern Forestry discounts explicitly exclude subscription
items. Existing subscription `candle_cash_allowed=false` remains authoritative.
Square discounts still require the established staff register/reconciliation flow.
The native rewards overview and Modern Forestry roadmap include the existing $10
annual Birthday Club coupon, with Coming soon shown until the exact tenant's $10
annual program is enabled. Reading rewards never issues gifts, sends messages or
credits the wallet; retain existing yearly identity/coverage checks.
Do not rewrite existing issued codes or production balances as a rollout shortcut.

`subscriptions:shopify summary|verify` uses only the original subscription app
credential and fixed query-only protocol, plus exact ephemeral Recharge method
mapping. Preview is default; `--apply` persists encrypted masked evidence only.
No contract adoption, source stop, payment attempt or universal no-reauthorization
claim follows from this proof. See the replacement runbook; collection stays gated.

## Replacement customer schedule access (October 30; source candidate)

Activated Everbranch members now use their original verified Shopify identity for
pause/resume/skip/cancel and a short private member page. Shared locks, revisioned
UUID actions, accepted finite-pause resumes and original payment retention keep
schedule changes separate from collection. Safe stops remain available during a
merchant freeze/lost entitlement; resumption requires exact current rollout.
The paired native source opens a fresh original-host private page and refreshes
on return. No new payment authorization, live activation or distribution occurs.
Address/scent amendments, membership parity and actual customer acceptance remain
outstanding. See `docs/operations/subscriptions-recharge-replacement.md`.

## Recharge replacement ledger and customer gateway history (October 30; source candidate)

Read `docs/operations/subscriptions-recharge-replacement.md` before continuing.
Reuse existing subscription entitlement, verified provider reads, source guard,
Stripe executor and Shopify bridge patterns; do not convert historical intents or
Stripe-specific fields into new-processor authority. Three additive billing
tables retain encrypted agreements, immutable cycles and append-only action IDs.
Exact tenant/member gates default off. Preparation leaves Recharge primary.
The Braintree executor separately verifies original saved-method/source mapping,
individual source stop, merchant acceptance and one durable recurring sale.
Unknown results reconcile the original attempt and never resubmit. Its generic
Shopify handoff records one verified external receipt; polling handles card/PayPal
settlement. Customer address/scent amendments and membership parity, Shopify-vaulted adoption and actual two-member
acceptance remain outstanding. Do not enable live billing from source tests.

The customer Orders table uses locally bundled gateway badges. Shopify ingestion
captures minimal recorded gateway names; `customers:payment-gateways` explicitly
previews one tenant/customer's bounded owned reads and only `--apply` saves gateway
metadata. Profiles make no new provider calls. Unknown data stays Not recorded;
PayPal/Shopify Payments must not be inferred to be Braintree/Shop Pay. No amounts,
statuses, loyalty, customer identity, fulfillment or provider records are changed.

## Braintree account verification (October 30; source candidate)

An exact tenant rollout adds Braintree account verification to the existing
owner-only Accept payments page. The official PHP SDK reads the selected active
merchant account; tenant-owned credentials are encrypted and hidden, excluded
from flash input and bound to an immutable connection identity/revision. Exact
customer/method lookups return masked proof only. This connection flow performs
no charge, verification hold, vault mutation or billing-owner switch. The separate
replacement executor has its own exact rollout, source-stop and collection gates.
See `docs/operations/self-service-payments.md`. Keep collection and source-stop
acceptance separate from connecting the merchant.

## Candle Club authorization and scent notes (October 30; source candidate)

The private subscription page uses Modern Forestry branding only for that exact
workspace, displays immutable recurring terms, and separates Stripe card consent
from a clearly labelled Coming soon preview of voting, scent choices, scent
notes, addresses, skips and account management. Other merchants retain their
own name and do not receive the Modern Forestry roadmap. The page keeps private
cache/referrer headers, required consent and the original setup-only flow.

The paired native app adds a star rating and scent-note composer for eligible
members using the existing feedback endpoint. Comments remain private/pending
for review; request UUIDs make unchanged retries converge without rewriting an
accepted comment. No schema or billing changes are included. Release the backend
before the native update and validate the hosted page separately. Braintree vault
reuse now has separate default-off replacement source; real merchant/source
acceptance and customer activation remain unproven. Provider-to-provider Stripe
migration is not performed by this source. See `docs/operations/subscriptions-pilot.md`.

## Facilities mobile controls (October 30; source candidate)

The paired public frontend adds whole-card work-order menus, a docked ticket
comment composer and always-visible live voice at the top of the opened Ask
Everbranch panel. Menus and photo job cards use the current persona projection;
AI cards resolve only server-verified ticket IDs. Ticket details and posted
comments scroll above the composer, with keyboard-viewport handling and retained
drafts. Voice still starts only by explicit tap and uses the existing cleanup,
read-only lookup and usage limits. Home Screen standalone launch avoids Safari's
URL pill; the iOS keyboard accessory controls remain OS-owned. No API, schema,
provider or release-gate changes are included. This is a source candidate until
protected release and live verification. Physical installation, acoustic voice,
camera and received inbox delivery remain outstanding. See
`docs/operations/campus-facilities-demo.md#mobile-controls-and-photo-job-answers-october30-source-candidate`.

## Public Facilities app migration (October 30; release candidate)

The public Lees-McRae URL now has a paired standalone React dashboard candidate
with Blaine default, campus-photo banner, status/team charts, photo cards and
yellow transparent Everbranch AI controls. Its dedicated Laravel API persists
public fictional tickets, history, verified photos and separately consented mention profiles in Forge shared
storage. Local file locking and revision checks serialize phones; no tenant
business tables or private proposal workspace are used. Official map/directory
refresh runs on this server. The old per-tab prototype remains available at
`/platform/demos/lees-mcrae/classic`. Source, protected release, provider setup
and actual hosted/mobile acceptance remain separate. Buildings → Jobs adds 36 fictional cross-team jobs without replacing existing ticket history, verified building reference photos and per-job galleries. Live Realtime uses a dedicated demo server secret, three five-minute sessions/day, one concurrent call and scheduler-driven cleanup; unknown provider outcomes remain held. The bottom-right Ask Everbranch conversation reads current role-scoped jobs and official campus context, with text and spoken options. Job questions select validated read-only filters; server-calculated counts and canonical ticket rows replace model arithmetic, and spoken lookup refreshes the permitted snapshot on each question. Ticket comments have compact role controls, self-entered confirmed email and branded mention mail using the existing verified Everbranch sender; no client provider credentials are copied. See
`docs/operations/campus-facilities-demo.md`. Do not create Cloudflare resources
or copy Enterprise/Collins/Modern Forestry provider credentials into this demo.

## Public campus photo galleries (2026-10-29; source candidate)

Follow `docs/operations/enterprise-campus-media.md` when changing public campus
photos. Preserve current-projection lookup, browser-only state, forms and focus.
Keep photo bytes local and checksum-pinned; captions distinguish stock images
from actual college work. Re-embed shared search/media after JS, CSS or asset
changes and run the gallery contracts/browser checks before protected release.

## Native Teams global search (2026-10-29; source candidate)

Read `docs/operations/teams-global-search.md` before changing search. Preserve
tenant/user/object/field permissions, encrypted payloads, keyed lookup tokens and
the default-off `TEAM_WORK_SEARCH_ENABLED` gate. Rebuilds never infer current
authority for legacy source snapshots. The campus palette uses only its current
fictional projection, and commands open existing reviewed forms. Shared asset
changes require the deterministic campus embed step and search/browser regressions.
Do not upgrade archived private editions or claim production activation from source.

## Wren registration notification correction (2026-10-29; source candidate)

Organizer summaries separate confirmed places, temporary unpaid reservations,
saved applications without reservations and waitlists using a season-locked
snapshot. Parent offers explain payment confirmation and reservation expiry.
Summary MIME is UTF-8/base64 with per-message SendGrid click/open tracking
disabled to preserve encoding through downstream rewriting. The preview-first correction command targets one
accepted original organizer delivery and recipient with durable replay/unknown
outcome protection. No registration, payment or provider configuration changes.
Follow `docs/operations/wren-soccer.md`; normal protected CI/release, current live
evidence and one received correction remain separate acceptance requirements.

## Private proposal conversation simulation (source candidate)

Current private browser-sample editions can demonstrate context-aware job
conversation, optional browser voice, editable department email drafts and an
explicit simulated outbox. This mode is separate from paid AI and shared-server
workspace mode. Drafts and simulated deliveries stay in page memory; existing
proposal review names and page comments retain their server persistence. Nothing
is actually sent. Native/public authority and provider gates remain unchanged.
Normal source checks, independent review, deployment and a separately published
new private edition remain required; old links and editions are preserved.
See `docs/operations/proposal-reviewer-ai.md`. Physical microphone and mobile
acceptance are separate from synthetic speech and viewport checks.

## Reviewed comparison and new fictional edition basis (source candidate)

A saved, compatible Lees-McRae proposal can explicitly include a far-left
Compare options tab. Its trusted snapshot binds the reviewed commercial terms;
ordinary saves preserve those terms, and unflagged old editions retain their
tabs and content. Activity recognizes the new section only for a valid snapshot.

Preparation and initialization separately admit `reviewed_new_fictional_baseline`
for an explicitly reviewed NEW fictional workspace. Its provenance is not a
browser capture or recovery. Existing edits, links, passwords, comments, archives
and workspaces remain untouched. All actual-owner, source, seed, predecessor,
new-workspace and original-intent recovery checks remain. Source changes do not
publish, initialize, activate providers or create a budget. See
`docs/operations/proposal-comparison-tab.md` and the reviewer preparation and
initialization runbooks. Normal exact-head CI/review and explicit runtime stages
remain required.

## Birthday Club consolidation (2026-10-28; source candidate)

Modern Forestry's prospective tenant policy can publish one Birthday Club page,
retire new signup bonuses and preserve all original gifts and balances. Durable
annual coverage spans reward types and verified aliases; owned history includes
previous-year usable coupons. Optional issuance selection, effective expiry,
canonical email links and once-only primary/belated sends remain compatible with
existing proxy routes. Follow `docs/operations/birthday-club-consolidation.md`;
normal schema/CI/release and live acceptance are required before activation.

## Campus shell reconciliation (source follow-up)

Persistent header search and the retained bottom-right Campus assistant reuse
the existing surface-specific adapters. Public lookup and guided intake remain
fictional and provider-free; native and shared reviewer authority stays separate.
Known map building names are distinct from job IDs; J05/custom associations remain
unspecified. Existing drafts, file references, receipts, comments and history are
retained. Financial leads with the existing usage example and collapsed
adjustments without changing prices, terms, calculations or selections.

This source does not upgrade archived private v5, publish a replacement, activate
AI/scanning or initialize a budget. Exact-head checks, protected release and
actual hosted acceptance remain separate. See
`docs/operations/enterprise-campus-shell-reconciliation.md`.

## Everbranch Field installable web standard (2026-10-28; source candidate)

The shared Field client now has an installable `/field/` web package using existing mobile sign-in, tenant/resource guards and operational data. Personal job order, high-quality checked photo chunks, multiline message/gallery improvements, task photos and My Hours charts are included. The read-only crew assistant has a separate per-workspace rollout with a shared monthly reservation cap and the existing provider transport; Collins alone has user approval for $25/month. Physical phone installation and production activation require the recorded release checks. See `docs/operations/everbranch-field-web.md`. Field web.3 adds bounded conversational browser voice, current-scope explicit job/page navigation, three-column document previews, persistent bottom navigation in chat and a four-second permitted-job-photo banner. Admin materials get a paginated photo board, employee Home adds View Jobs, and clock/hours questions reuse canonical scoped operational reads; broader redesign and durable assistant history remain paused. Field web.4 separates Dictate from Voice conversation, isolates its existing10/minute throttle from startup reads, and provides deliberate rate/auth recovery with drafts retained. Field web.5 identifies its updater by release fingerprint and checks a fresh public descriptor, preventing an old CDN-cached worker or stale waiting state from repeating the update banner.

## Public guided campus assistant (source follow-up)

The separate public sample can find/open current fictional jobs, projects,
tasks and requests, report recorded status/rank and guide a new ticket draft.
It is explicitly simulated and provider-free. Missing facts remain unknown;
event work is not a separate event schedule. Fresh projection and mounted
persona checks fence navigation. A bounded persona draft leads to a summary
and the existing form's explicit Submit; prior files/drafts/unknown intents
are preserved. No native/private bridge, provider or edition change. See
`docs/operations/enterprise-public-requests.md`.

## Shared fictional ticket policy (source follow-up)

The existing Demo admin perspective alone edits submitted ticket fields,
classification and assignment. Requester and Worker keep ticket creation;
ordinary Manager keeps unrelated task/project/plan authority. Actual linked
task priority, assignee and building changes cannot bypass this boundary.
Unchanged values preserve otherwise permitted task edits and independent
conversation/task-status permissions. No native identity, grant, provider,
runtime activation or immutable edition changes. See
`docs/operations/proposal-reviewer-workspace.md`.

## Public campus identity and map controls (October 28 source follow-up)

The separate public sample uses the official college SVG and displays the
existing Admin perspective as Blaine · Demo admin. Facilities Manager and
read-only Leadership retain their separate permissions; no real account or
saved identity changes. Demo details is reachable on desktop and phone.
Map labels use exact currently projected IDs and explicit known locations;
unknown/custom locations stay unspecified. Per-instance immediate zoom fixes
the reproduced delayed callback after rapid perspective changes. Selection,
keyboard access, drag preview and explicit save remain.

Existing Requests interaction/placement guards and all fictional saved records
remain intact. This changes neither native persisted work nor the immutable
private review edition, providers, credentials or runtime flags. The full local
comparator, final-source tour, new exact-head CI and source-bound hosted checks
are separate required evidence. See
docs/operations/enterprise-public-campus-identity-map.md.

## Controlled Stripe subscription pilot (2026-10-28; source candidate)

- Selected members can authorize a new Stripe card without charging. Independent
  default-off setup/collection/handoff and tenant gates preserve Recharge primary.
  Fresh source-stop, queued/collected charge checks, real callback proof and
  individual owner activation precede current-day collection.
- Each monthly cycle retains one original PaymentIntent request and one paid
  external-payment Shopify handoff. Unknown results reconcile original objects;
  no replacement charge/order is created. Shopify still owns shipping/fulfillment.
- Offline readouts distinguish payments, orders, fulfillment and time-bounded
  duplicate coverage. Native original Shopify identities can pause/cancel selected
  Stripe subscriptions; other Recharge customers retain their existing behavior.
  Prepaid, general Branch purchasing and native management-link UI remain gated
  future work. Follow `docs/operations/subscriptions-pilot.md`.
## Public Requests placement ownership (October 28 source follow-up)

Queued layout restoration now respects newer input, focus, pointer and keyboard
intent within the same Requests body. Session-local interaction/latest-placement
epochs and exact connected/open dialog ownership fence scroll, focus and caret
restoration; denial also invalidates pending placement. Center/Side retains the
same editor and local File objects. Drafts, simulated records, permissions,
private editions and provider/runtime configuration are unchanged. Controlled
local source scheduling demonstrates the old stale-Close focus defect; it does
not establish the cause of either earlier hosted phone failure. Normal new-head
CI, protected release and hosted phone/tour acceptance remain separate gates.
See docs/operations/enterprise-public-requests.md.

## Public reports, material examples and task Timeline (source candidate)

The separate public fictional sample adds projected phase/estimate charts, with
selected job sums separate from whole-project totals and absent fields retained.
Timeline uses authorized recorded dates/dependencies; due-only tasks are
milestones, with missing/invalid dates visible and no invented scheduling.
Demo admin can explicitly review and confirm fixed example material lines on an
empty permitted job. Strict local receipts and a retained application marker
preserve retries, edits and removals; no automatic saved-session upgrade occurs.
This changes no native campus authority, immutable private edition, inventory,
purchasing or provider. Normal CI/release and any new owner publication remain
separate gates. See docs/operations/enterprise-public-reports-timeline.md.

## Wholesale custom labels (October 27; additive release candidate)

The Shopify wholesale lane adds a private customer artwork library, buyer wizard,
Backstage import/proof approval, immutable purchase references and production holds.
New setup/reuse fees are one per order; regular candles and wax melts remain in
multiples of three. Customer artwork never becomes public Shopify media. Buyer
submissions and the separate Thank you request form remain default off until the
complete development-store flow passes. Preserve protected deployment, private
shared storage and pinned VTracer 0.6.5. See `docs/operations/wholesale-custom-labels.md`.

## Subscriptions pilot (2026-10-27; source candidate)

Read `docs/operations/subscriptions-pilot.md`. The Subscriptions Branch retains
its existing Modern Forestry included grant. The owner selected Jennifer DeWeil
and Rynda Baker for a November 2 Stripe-to-Shopify pilot while Recharge remains
primary during preparation. Use exact provider/customer/subscription identities,
never a first-name or email-only merge. Pilot reports are offline; explicit
Shopify reads and existing ingestion provide separately attributed order evidence.
Unknown coverage must never display a passed double-charge check. Do not execute
old intents, pause Recharge globally, reuse legacy payment tokens, or charge from
the preparation command. Candle Cash cannot fund subscriptions.

## Public fictional Requests follow-up (October 27; source candidate)

The separate public static sample retains its v3 session and current fictional
record access. Requesters create requests; only Demo admin can edit submitted
title/details, priority, building or assignment, with actual changed-field audit.
Restricted linked tasks remain denied and missing jobs are not manufactured.
Request-only comments and local JPEG/PNG/PDF references retain their separate
capabilities. Center/Side uses one dialog; mobile cards share the same permitted
rows. File bytes stay in tab memory, unscanned and unshared; reload may require
reselection. This does not change native campus authority or the private immutable
review edition. Preserve all normal CI, release and publication gates. See
[the public Requests contract](docs/operations/enterprise-public-requests.md).

## Notification readiness and institution-wide prototype copy (source follow-up)

Keep in-app updates, current-user mailbox email and controlled-trial delivery
separate. Readiness is current-user/department scoped and never sends or proves
live delivery. Root SES approval/configuration does not grant Enterprise sender
authority. Public prototype actions remain simulations; departmental AI and
student recruitment attribution/follow-up are phased future scope. See
`docs/operations/enterprise-notification-readiness.md`. This isolated follow-up
is not deployed and does not modify existing immutable proposal editions.

Campus copy uses a single concrete section title. Omit decorative kickers,
taglines and redundant introduction paragraphs. Preserve the top fictional-demo
banner, functional controls/data and essential permission, error and field
guidance. New copy and reports use no em dashes.

## Modern Forestry mobile catalog and website presentation (2026-10-27; release candidate)

- Retail browsing includes Winter, Room Sprays, and Sale. Room Sprays can use the active product catalog when Shopify has no dedicated collection. Product search accepts bounded scent tokens, including variant titles, and preserves customer-visible retail filtering.
- ModernForestryStorefrontPresentationService reads the fixed published retail homepage, preserving banner order and collection order. It caches for 120 seconds, uses an Eastern-calendar quarter key, and retains a last-good snapshot for one day during brief outages. MOBILE_CATALOG_WEBSITE_SYNC_ENABLED disables this sync for rollback.
- Release the catalog service, controller, presentation service, and configuration together through the existing GitHub/Forge gate. The native app update adds server search, refresh, scroll cues, and source-order rendering. No Shopify catalog, checkout, customer, or reward writes are included.

# SYSTEM SNAPSHOT

## SMS disclosures and consent evidence (2026-10-26)

Evergrove/Everbranch public privacy and terms now link `/sms-terms` and identify
Evergrove Software LLC. Wren's matching Sites release adds public `/privacy`,
`/terms` and `/sms` pages and an optional, sender-specific registration choice.
New affirmative Wren choices must carry `wren-operational-sms-2026-10-26`;
server-owned wording, normalized phone, purpose, source and time are retained in
encrypted append-only soccer events. Repeated unchanged saves do not renew
consent. Outbound soccer SMS identifies Wren and includes STOP/HELP.
STOP works without an existing conversation; HELP returns program
support, deferring keyword replies to Advanced Opt-Out when present.
This release does not activate SMS, alter sender registrations, migrate Modern
Forestry resources or change the working email notification mode. Deploy backend
first, then matching Sites source. See `docs/operations/wren-soccer.md`.

## Signed legacy campus archive preparation (October 26; source candidate)

The fixed v5 archive keeps its exact signed bytes and strict renderer checks.
Forge candidates must run `scripts/proposals/prepare-legacy-campus-v5.py` before
runtime cache preparation and activation. The helper validates all 126 files and
25 directories before removing group-write only; unknown bytes, modes, aliases,
links, ownership or inventory fail closed. It derives its own candidate, refuses
the active release and performs no runtime, database, provider or content writes.
The private release hook installation and any one-time correction of an active
archive require separately reviewed, specifically approved host operations.
Partial or uncertain outcomes stop for readback without automatic retry/rollback.
The existing native renderer refusal remains unchanged. See the proposal review
release runbook for this deployment prerequisite.

## Personal letter as the first proposal tab (October 26)

Lees-McRae drafts can explicitly include John's approved personal letter. Saving
snapshots its fixed copy and approved logo/family image references into the draft;
only editions containing that descriptor show From John first and selected by
default. Preserve the old edition, URL, grant and comments: absence means no new
tab, never a presentation-time retrofit. The v1 copy/assets are immutable; future
copy changes need a new snapshot identity. Only the two fixed same-origin image
paths are added to CSP on letter responses. No provider or runtime activation is
implied. The separately exported one-page letter PDF is unchanged.

## Wren editable saved applications (October 25, 2026; source candidate)

The family portal can reopen unsettled registrations through Edit application &
coaching. Signed own-family amendment quotes preserve current holds and recheck
expired applications against capacity and queue order. Saving reviews the revised
amount, preserves confirmed siblings and existing receipts, retires prior Stripe
checkout before replacing an unpaid invoice, and reuses exact retry results.
A racing paid or uncertain provider outcome prevents repricing; the payment stays
recorded. Draft edits survive leaving and signing in again. See the Wren runbook.

## Shared private fictional workspace (October 24; source integration)

Select shared mode only from the immutable publication descriptor and exact
owner-silo/policy/build/source/expiry admission. Keep absent-descriptor browser
mode separate; malformed or unavailable shared mode must never fall back.
Preserve one adapter, current area/FK projection, captured form versions and the
original unknown edit identity. Resolve assigned names only from current scoped
fictional people; unavailable names retain the assignment. AI sharing preview
retrieves server facts independently and binds their revision/selection pin.
Reviewer names/personas never grant native employee identity or file access.

Retain both engines' mounted core/directory and fake-provider shared-AI checks,
plus all existing required lanes. Disposable SQLite or a skipped MySQL fixture is
not concurrency acceptance. Source tests do not authorize initializing a runtime,
replacing the current publication, resetting the lifetime campaign, enabling a
provider/scanner or deleting data. Preserve the active edition and its encrypted
comments through a reviewed compatible renderer/new-edition transition. Shared
comments have a separate immutable feature/policy, reviewed backend/client and
centered-detail composer; actual local mounted two-engine comments checks pass.
Ticket threads do not require or alias a native task. Keep the comment accepted-projection guard: a
root bridge may obtain the actual active-selection read after scoped catalog
reads, never invent a revision or mutate active selection from background work.
Uploads have separate default-off JPEG/PNG clean-attestation/private-image source
and target-free descriptor admission; scanner/evidence tests are fake. Root file
controls share the current thread and ONE adapter; real-HTTP local image/file
checks pass8 cases in each engine with fake scanner evidence only. Separate
default-off PDF-original download source is integrated and independently reviewed,
with its own policy/evidence and exact-target octet-stream attachment contract.
No inline PDF, public URL, scan route/operator or runtime activation is installed.
Local PDF-browser checks pass9 cases per engine (Chromium pre-NFC plus verified
filename-predicate equivalence; WebKit full corrected script), including390x844
viewports. No physical-device claim. Preserve the exact evidence distinction and
run both engines on the final normal CI candidate. Resources/manual workflows
remain design-only. Real-owner maintenance HTTP is preview-first and bounded;
no operational scheduler/retention or runtime values are installed. The reviewed
nonsecret runtime metadata seam does not authorize activation or provision an
anchor; invalid input keeps every independent gate off. See [the workspace contract](docs/operations/proposal-reviewer-workspace.md)
and [the AI contract](docs/operations/proposal-reviewer-ai.md).

A separate default-off real-owner HTTP initializer now supplies signed preview,
explicit apply and original-receipt status for an already-published future exact
Version. It requires reviewed current-edit/seed/import pins and fresh locking
owner authority; it cannot capture, publish, reset later edits or activate AI.
Current-browser reconciliation, the actual publication transition, recurring
authenticated retention and actual host acceptance remain separate. See
[owner initialization](docs/operations/proposal-reviewer-workspace-initialization.md).
Focused root integration passes 27 tests/545 assertions, including the retained
independent adversarial cases and current source/asset closure. This is local
PHP 8.5/SQLite evidence; target PHP 8.4/MySQL and exact-head CI remain required.

A separate default-off real-owner draft-preparation flow now creates one new
encrypted read-only draft from exact reviewed commercial/source/import pins. It
adds trusted workspace/comment descriptors before immutable publication hashing;
the private preparation marker is excluded from recipient content. Normal Publish
remains a separate actual owner action with recipient password and original request
key. New publication refuses expired descriptors or a requested lifetime shorter
than their fixed expiry. Exact original receipt recovery cannot create another
Version, renew expiry or reset work. The original draft, publication, grants and
comments remain unchanged. Focused root integration passes29 tests/559 assertions
on PHP8.5/SQLite, including current source/asset closure. Three retained
independent authority cases separately pass44 assertions. Actual MySQL lifecycle,
targetPHP8.4, current-edit capture/reconciliation, host/runtime approval and normal
exact-head CI remain separate requirements. No actual publication or activation
occurred. See [draft preparation](docs/operations/proposal-reviewer-workspace-preparation.md).
The new preparation/publication MySQL lifecycle gate is registered after the
workspace/initializer gates. Only its local refusal guard has run; actual
independent native sessions, row waits and concurrency remain NOT RUN until CI.

A separate default-off real-owner local export now preserves one tab's exact
legacy fictional storage text/UTF8/base64/hash without uploading or importing it.
Actual owner login, fresh membership/Version checks and a short same-origin CSRF
authorization precede the explicit fixed-key read. The complete approved module
snapshot is reused for rendering/CSP; display names cannot supply authority.
Missing is not zero; schema, authorship, edition provenance and all-tabs completeness
remain unverified. Root verified29 JavaScript cases and21 distinct native/source/
asset cases across retained results and targeted setup/fixture corrections. All
assertions and the256 source cap remain; the positive fixture is170 entries plus
fixed dependencies, while257-entry/oversize refusal is unchanged. Both
engines passed6 synthetic real-login/download cases before the server-only module
pin correction; executed JS is byte-identical and final native guard regressions
pass. Do not claim those engines ran final PHP or physical devices. No real data
was captured. Host config/activation and current-edit conversion/reconciliation
remain separate. See [local export](docs/operations/reviewer-legacy-capture-screen.md).
The Enterprise-only capture metadata parser/overlay now has a closed default-off
ENV seam; root config remains literal disabled. Focused root parser/config and
current source-bound checks pass13/388 on localPHP8.5. No host values, flags or
config cache were installed; actual capture permission/fresh checks remain
independent. See [capture configuration](docs/operations/reviewer-legacy-capture-configuration.md).

## Enterprise CI performance candidate (October 23, 2026)

Enterprise verification prepares exact-run dependency/public-build inputs once,
then runs the full native PHP/MySQL suite and isolated browser matrix. Operations
groups preserve the `all` profile, request pacing and every helper in both engines;
fixed-port harnesses remain sequential within each fresh runner. The stable
`enterprise-runtime` aggregate requires every prerequisite and matrix entry.
No ENV/private storage or database state is shared; no production artifact or
Forge change is implied. Measure the frozen candidate's actual full CI critical
path before claiming faster releases. See [Enterprise CI](docs/operations/enterprise-ci.md).


## Private review activity (October 23, 2026)

Lees-McRae private review publications now have an owner-only activity view for
link opens, successful password unlocks, chosen display names, proposal/campus
pages, PDF downloads, saved-comment actions and browser-reported tour scenes or
playback completion. Current active owner/admin membership gates each view;
known owner browser tests are hidden by default. Self-selected names are not
verified identities, and page opening is not proof of reading.

The private runtime stores a separately encrypted, tenant/version-scoped journal
under private shared storage, with file locking and fixed event/byte limits. No
passwords, IP addresses, user agents, form text or sample work content enter the
journal. A separate rate limiter prevents activity reports from consuming the
review/comment request budget. Recipient reports require the exact current
password grant and version; campus/tour reports also require the bound build.
A small exact-hash script is permitted only on tracked proposal pages; public
samples have no activity endpoint or additional network authority.

Tracking starts at release; previous visits cannot be reconstructed. Browser
blocking, expired access or a full/unavailable journal can leave gaps. Campus
source changes require a fresh immutable publication and review link. See
[review activity](docs/operations/proposal-review-activity.md).

## Private reviewer AI (October 23; future source candidate, default off)

Only the exact unlocked, approved private owner-runtime edition may expose
reviewer Chats. Keep native/public paths, Assistants, Voice and sending separate.
Use the one existing lifetime campaign ledger, exact sharing preview, once-only
dispatch and receipt-owned recovery; never initialize or reset spend on demand.
Coordinate a fresh immutable edition and verified cleanup before activation.
See [the reviewer AI contract](docs/operations/proposal-reviewer-ai.md).

## Campus review presentation follow-up (October 23; source candidate)

Keep mobile menus accessible and scoped to narrow screens, preserve desktop
controls and unsent proposal/reviewer context. Permit only the exact-hash
authorized local menu controller in generic proposals; do not add general script
origins, unsafe inline execution or network authority. The ticket-routing summary
must use current authorized data without changing its version or permission checks.

The fictional contractor repair targets canonical `plans`, including tabs that
already applied the earlier job upgrade. Use the separate once-only repair;
preserve canonical edits, retained orphan records and later deletions rather
than resetting or replaying the twenty-job seed. No native database is involved.
Do not silently deploy over an active presentation: the changed campus manifest
requires a fresh immutable review edition. See
[the follow-up release notes](docs/operations/enterprise-review-presentation-followup.md).

## CI Ubuntu package mirrors (October 23; source candidate)

The primary PHP 8.4 and isolated Enterprise jobs verify the runner's known Ubuntu
mirror-file format before PHP setup, then remove only its Azure mirror entry.
Official HTTPS archive/security entries and signed source fields remain intact.
Unsupported formats fail visibly. All tests, browser engines, time limits and
loopback-only MySQL safeguards remain required. This does not cache OS packages
or guarantee download speed. See [Enterprise CI](docs/operations/enterprise-ci.md).

## Reviewer AI tools deferred (October 23; source candidate)

Keep the fictional reviewer demonstration's Chats, Assistants and Voice as
Coming soon placeholders. Do not expose chat, microphone, generated workflow
drafting, Home AI summaries or AI usage controls there. Preserve native signed-in
record-backed Ask and its independently gated capabilities. Future reviewer AI
must be released and accepted separately; do not hold the proposal/PDF/workspace
review package for provider work. See
[the release contract](docs/operations/enterprise-proposal-review-release.md).

## Enterprise work controls and scoped views (October 23; source candidate)

Keep work controls tied to the current authorized native graph, department,
record versions and exact retry receipts. Multi-project tasks and incomplete or
ambiguous request links require native review; never offer an edit the adapter
cannot safely perform. Project archive and task removal retain history. The
fictional Blaine role and additive browser demo records grant no real access.

Work details use one Center/Side dialog without replacing drafts or active uploads;
existing quarantine remains authoritative. Roll Call shows permitted projects,
people and contractors. Map placement uses current saved building identity,
version and official source provenance. Work due-date months and Eastern resource
reservation months remain separate. Keep layout/intake preferences in Settings.
See [jobs and ticket queue](docs/operations/enterprise-job-workspace.md),
[Roll Call](docs/operations/enterprise-roll-call.md),
[work details](docs/operations/enterprise-work-detail-placement.md),
[map and calendar](docs/operations/enterprise-campus-map-calendar.md) and
[request classification](docs/operations/enterprise-request-classification.md).

## Private proposal planning (October 23; source candidate)

Financial scenarios are bounded private browser-session planning, separate from
saved contractual selections and shared comments. Preserve exact saved scope and
prices, dated reference rates, editable illustrative departmental volumes and
explicit pending preferences/unpriced costs. Planning never activates a provider,
approves a budget or changes a published proposal. See
[the planning contract](docs/operations/proposal-financial-planning.md).

## Private proposal PDF download (source candidate, October 23)

A top-of-reader download generates a PDF from the exact saved draft or immutable
publication. It reuses current owner/recipient authority and private no-store
responses; changing tabs does not omit PDF sections. Rendering uses a pinned
local library with remote resources, scripts and PHP disabled, bounded input and
private per-request temporary storage. It activates no provider or agreement.
See [the proposal contract](docs/architecture/reusable-proposals.md).

## Proposal section panels and college graphic (October 23; source candidate)

Keep section controls as native radios with unique component IDs/groups; owner
managers can render multiple saved summaries. Do not add stale ARIA tab state or
navigation that discards unsent forms. Print must reveal all section copy. The
approved Lees-McRae graphic is inline and exact-client-only on the password gate
and Overview. Pass only the branding boolean to the locked gate; do not import its
login hook/configuration, preview movie or external media. See the
[proposal contract](docs/architecture/reusable-proposals.md#proposal-section-panels).

## Reviewed proposal commercial snapshot (October 23; source candidate)

Render commercial sections from the exact stored proposal, never current copy
constants in an older publication. Reviewed draft-only term overrides retain
explicit native catalog provenance. Preserve the stored commercial snapshot on
ordinary Save and reject contractual scope changes until separately reviewed.
Read [the proposal contract](docs/architecture/reusable-proposals.md) before
changing this boundary. Source release does not apply a pending draft plan,
publish a link, activate billing or authorize providers.

## Typed private memory candidate (October 23; disabled, not live-verified)

The existing Ask pane has separately gated explicit typed formatting preferences
and bounded source observations, with encrypted per-account/department storage,
provenance, versioned confirmation/edit/delete and 1/7/30-day retention. No free-text
facts or automatic chat capture are accepted. Every observation is rechecked
against current canonical source/access and can reach a model only after explicit
opt-in and only when that source already belongs to the selected check. Expiry
withholds values immediately; activation additionally requires approved bounded
cleanup. No paid route, credential, provider connection or schedule is activated.
See [the memory contract](docs/operations/enterprise-assistant-memory.md).

## Saved assistant configuration candidate (October 23; disabled, not live-verified)

A separate default-off flag adds private purpose/instructions/fixed read-check
configuration to the existing Ask pane. Current actor/department/object policies
remain authoritative; profile selection never changes provider/model authority.
Versioned confirmed saves/deletes use encrypted storage and content-free audit.
Profile edits and access loss invalidate short-session reuse. Persistent scoped
preference/fact memory and two-way voice are separate milestones. See
[the saved assistant contract](docs/operations/enterprise-saved-assistants.md).

## Text conversation UI candidate (October 23; disabled, not live-verified)

A separate default-off UI gate exposes setup/availability in the existing Ask pane,
not a second chat product. Explicit approved sends use the bounded backend;
read-only recovery checks current scope/source stamps without redispatch. Browser
storage holds receipt IDs only. Stop/navigation fence late replies, and explicit
clear preserves all spend evidence. Matching tour guidance distinguishes generated
text, deterministic record checks and queued voice. Local managed-runtime browser
acceptance uses fake provider HTTP and fictional records only. See
[the text UI contract](docs/operations/enterprise-conversation.md#text-ui-milestone-separate-from-connection-activation).

## Text conversation backend candidate (October 23; disabled, not live-verified)

The separate Conversation route can send a bounded, current authorized evidence
snapshot to an exactly approved OpenAI Responses connection. It uses the existing
single campus TrialBudget lifetime $5 ledger, with full model-context reservation,
durable single dispatch, verified bounded usage settlement and uncertainty holds.
All other Enterprise billable routes stay server-off while this trial is enabled;
retail provider routes are unchanged. Current record checks remain explicitly
deterministic. The separately gated text UI reuses the existing Ask pane and
read-only recovery. Saved configuration is separately gated; long-term memory,
audio input/output, sending and business-record tools remain unavailable. Its local
HTTP provider responses are simulated; actual provider acceptance is still gated.
See [the conversation connection and acceptance contract](docs/operations/enterprise-conversation.md).

## Read-only COO assistant candidate (October 23)

The dedicated private read-only assistant uses existing current WorkspaceAccess,
Briefing, Resources, RequestWorkflow, Operations, Planning and WorkGraph reads.
Keep its rollout independent from paid Ask/voice/email flags. Costs remain
owner/admin-only; estimates are not approved budgets, saved building IDs are
required for building counts, and no staffing capacity is inferred. Every answer
is ephemeral and current-scope checked. Do not enable paid providers through this
feature: shared aggregate $5 reservation/reconciliation and specific provider
approval are separate prerequisites. See
[the source/acceptance contract](docs/operations/enterprise-read-only-assistant.md).

## Enterprise work-table and building-link follow-up (October 23; source candidate)

Projects and requests use the available work canvas, with sortable fields from
the current authorized projection and a dismissible detail drawer. Close/Escape
preserve list search, sorting, scroll and unsent discussion/progress drafts.
Buildings retains its separate building/ticket/detail navigation and secondary
map. Request intake can explicitly select a current, authorized saved building;
free-text location labels never establish that relationship. See
[building links](docs/operations/enterprise-request-building-links.md),
[Buildings navigation](docs/operations/enterprise-buildings-workspace.md),
[tour placement](docs/operations/enterprise-tour-position.md) and the
[UI changelog](docs/ui/UI_CHANGELOG.md).

Completion without a replacement note preserves saved progress. Resource
history emits explicit UTC instants while retaining immutable revision bytes.
These source changes grant no access, enable no provider and create no billing
effect. Hosted acceptance remains separate from local fixtures: verify the
exact approved merged release, real persistence, isolation and reviewer flows.

The compact header uses the current workspace identity and existing authorized
published-review context for Proposal. The approved Rooted Peak horizontal logo
retains its exact SVG/PNG artwork and proportions. Guided table navigation clears
selection; opening a native detail drawer temporarily hides outside guide
controls until Close/Escape. See
[the header/tour contract](docs/operations/enterprise-workspace-header-tour.md).

## Wren saved-application recovery (October 23; source candidate)

Wren's public family portal is card-only. Expired unpaid checkout reservations
become `payment_pending`, preserve their saved registration/release/pricing, and
release capacity only after original provider checkout reconciliation. A signed,
own-family `resume` action rechecks eligibility, prior receipts, current capacity,
queue order and sibling reserves under the season lock, reusing a current invoice
or issuing a fresh short reservation without duplicate players or releases.
Actual capacity waitlists remain separate. Pending applications do not block the
queue. Daily email reminders are default-off behind
`WREN_SOCCER_PAYMENT_REMINDERS_ENABLED` and must only activate after verified old
receipts are recorded. Delivery rechecks payment, capacity and season state.
Verified expired Venmo invoices may restore available places through the reviewed
reconciliation command, without duplicating receipts. See the Wren runbook.

## Unified Enterprise and proposal review candidate (October 22; not released)

Proposal PR475 is now included in the isolated current-main Enterprise candidate.
Version/build/page-bound reviewer names and shared comments use the real proposal
engine and the existing fictional campus renderer, including the full-screen
workflow workspace. These names grant no campus access. Client operational data
and proposal-owner data require separate singleton silos, keys and storage. The
client runtime and owner runtime have independent provisioning/access gates. Read
[the unified review runbook](docs/operations/enterprise-proposal-review-release.md).
The combined MySQL baseline was workflow-generated and independently reviewed;
see [its provenance](docs/operations/enterprise-proposal-schema-provenance.md).
Final-head fingerprint/recovery CI remains mandatory. No live provisioning or
provider calls occurred.

## Earlier Enterprise integration rehearsal

Preserve both current-main Fleet behavior and the disabled Enterprise runtime.
This candidate combines `084bbb1` and `904ebe7` without importing the separate
support/proposal/native-payment branches. Both frontend build lanes and the
complete Fleet test glob are retained. No release, credential, permission or
provisioning approval is implied. Read
[the integration gates](docs/operations/enterprise-release-integration.md).

## Synthetic Fleet Calendar presentation (2026-10-22; source candidate)

The isolated browser demo adds three synthetic Calendar examples linked by job
ID to address/time, assigned van/team and required-versus-available material
quantities. It sends no HighLevel Calendar/provider requests, invitations or
stock mutations. `#calendar` opens the presentation directly. Live CRM sync and
vehicle limits remain unchanged. See the Fleet runbook for evidence and access.

## Fleet dispatch usability (2026-10-22; release candidate)

Jobs now starts with imported HighLevel addresses and times, searchable van/crew/
scheduled-stop choices, job-type chips and material quantity rows. Vans is a
separate searchable crew/stock editor. Trip distance checks and recurring service
settings are secondary. The fictional demo uses the same controls. See
`docs/operations/highlevel-fleet-runbook.md` for address lookup, isolation,
Google-derived point lifetime/map restrictions and release verification.

## CRM Fleet onboarding, recorded routes and guided demo (2026-10-22; release candidate)

Trip review uses matching Bouncie geometry, with sparse samples drawn as dots. The fictional demo bundles real-road driving shapes; it never sends customer coordinates to a public router.

See `docs/operations/highlevel-fleet-runbook.md`. The embedded Fleet surface now
shows a saved-state setup checklist, automatic CRM account sign-in and work
source discovery, Bouncie popup recovery, selected-vehicle recorded trip layers,
and optional USD maintenance estimates/actual costs. The separate fictional demo
adds a seven-step tour, overdue/upcoming/completed maintenance and illustrative
truck budgets. No provider activation, policy approval, billing, permissions or
vehicle limit is changed by this source update. Verify the protected release and
`/ready` before reporting it live.

## Enterprise generated conversation (source candidate, October 22)

The same private Ask pane can mount the reviewed default-off generation adapter,
with bounded server history, current source rechecks, old-scope cancellation and
exact manual-recipient previews. Preserve explicit simulated/generated/record
labels, existing usage reservations and the controlled-trial generation refusal.
Do not substitute sample authorization or enable providers from passing tests.
Read [the runtime and integration decision packet](docs/operations/enterprise-runtime-approval-packet.md)
before any hosted presenter, credential or provider action. Physical devices and
live paid acceptance remain unverified.

## Enterprise voice finale and admin usage (source candidate, October 22)

The existing Ask pane now integrates explicit Talk, record-backed current-job
lookup, source/detail navigation and preview-only messages. The final tour scene
opens that pane without starting capture or sending. Private usage aggregates the
existing authorized ledger; public department figures are explicitly fictional.
No live generative AI, messages, hosted client or hardware acceptance is implied.
Read [the boundary and checks](docs/operations/enterprise-voice-finale-and-usage.md).
Department-head/COO launch gates remain a reviewed design proposal in
[the activation plan](docs/architecture/enterprise-department-activation-design.md),
with no live permission or billing changes.
## Enterprise executive and visibility previews (source candidate, October 22)

Preserve the explicit sample-only COO overview and its global read-only mutation
fence. Simulated workspace lifecycle and proposed software figures never become
operating metrics, subscriptions or real access. Private member previews select
an existing verified tenant member and intersect both actors' fresh ACLs; they
never impersonate or expose descriptions/files/finance/mailboxes. Clear scoped
data before asynchronous reloads and reject late responses. See
[the preview contract](docs/operations/enterprise-executive-and-member-preview.md).
Map/list filters and pushed navigation history must preserve drafts and recheck
authorization. College decorative colors are specific to the trusted public
catalog; retain shared Everbranch branding and measured text/focus contrast.

## Enterprise priorities and procedural handoffs (source candidate, October 22)

Preserve the same renderer and explicit public-sample/private-server transport
boundary for the priorities overview and resumable walkthrough. Current-source
screens include period exports, certificate review/internal reminders and the
default-off owner/admin Finance packet register. Packet approvals are internal
routing evidence, never permission to pay or validated accounting. No provider,
credential, grant or recovery action is part of these tour controls. The separate
proposal workspace remains planning; Open proposal is honestly unavailable.
Read [the priorities matrix](docs/operations/enterprise-priorities-demo.md) and
[Finance packet contract](docs/operations/enterprise-finance-packets.md) before
changing status labels, permissions, sample behavior or confirmation semantics.

Preserve the default-off installable app's canonical mount scope and manual
update confirmation. Shared transport tracks pending mutations to block unsafe
refresh; do not infer failure from a dropped response or clear exact receipts.
No offline/service-worker cache or physical installation is established. Read
[the app contract](docs/operations/enterprise-web-app.md).

## Enterprise integrations under a trusted application prefix (source candidate, October 22)

Gmail callbacks and controlled-trial pages/links now derive their origin and
prefix from validated server configuration. OAuth and trial authority include
the mount context; moving a pending operation to another mount fails closed.
The source supports `/enterprise-campus` without enabling any provider or
changing the separate trial-only mount. See
[the mount runbook](docs/operations/enterprise-same-origin-trial-mount.md).
The public tour receives a trial destination only from an explicitly approved,
default-empty same-origin configuration; publishing a link never enables a
provider or resolves operational records during public rendering.
Actual hosting, Google consent, service credentials, recipients and live sends
remain separately gated. Tests use fake providers; no operational connection,
paid call or recovery capability is established by this candidate.

## Reusable Enterprise client setup and procedural records (source candidate, October 22)

The isolated runtime now has candidate trusted per-client runtime roots and
validated, exact-hash plan/apply/status provisioning for tenant structure,
departments, branding, approved location catalogs and inert workflow defaults.
Provisioning creates no identity, membership, entitlement, provider or billing
grant. See [client provisioning](docs/operations/enterprise-client-provisioning.md).
Hosting and initial verified owner access still require the explicit actions in
[the persistent-workspace proposal](docs/operations/enterprise-persistent-workspace-approval.md).
No operational backup or restorability is established; recovery remains plan-first.

Default-off manager features add a restricted contractor certificate metadata
register with reviewed replacements and a derived internal reminder queue, plus
quarter/date-window CSV/JSON exports of authorized current facts and recorded
events. No external alerts, insurance coverage policy, fiscal-quarter template
or automatic report submission is inferred. Read
[certificate procedures](docs/operations/enterprise-contractor-certificates.md) and
[period exports](docs/operations/enterprise-period-reports.md).

## Enterprise campus operations candidate (2026-10-22; draft, disabled)

The separate Enterprise app/database runtime now has candidate canonical task
ownership, interruption/resumption, explicit handoffs, manager-verified checklist
completion, resources/custody/reservation conflicts, requester-only public updates,
permission-filtered search and workspace preferences, Roll Call/meeting decisions
and regenerated one-page PDFs. Shared renderer adapters let an isolated public
sample use the same shell/workflow UI without employee resolution or provider IO.
A six-scene public tour previews email in-app and never sends. A separately
gated, signed-in demonstration tour opens the current authorized server-backed
screens without installing the sample adapter or mutating records. Read the
[requirement traceability](docs/architecture/enterprise-requirement-traceability.md)
for attributed Gemini Quick Notes, owner proposals, procedural recommendations
and remaining quarterly-report, insurance-expiry and Finance/AP gaps.
The controlled server-held
synthetic trial is separate, default-off, with a shared $5 lifetime reservation
ledger, exact confirmations, expiring links and quarantined private uploads.
No provider credentials/grants, real recipients, paid calls or deployment are
established by this source. All new rollout flags default off. Read
[the milestone boundaries](docs/architecture/enterprise-reviewer-milestones.md),
[CI acceptance gates](docs/operations/enterprise-ci.md),
[reviewer presentation](docs/operations/enterprise-reviewer-presentation.md),
[Roll Call](docs/operations/enterprise-roll-call.md) and
[controlled trial](docs/operations/enterprise-reviewer-trial.md) before activation.
The protected dependency stack is PR458 → PR459 → PR461 → this candidate;
PR460 is unrelated Fleet work. Release requires reviewed GitHub gates and Forge.

## Fictional hosted POS demo boundary (2026-10-21; source candidate)

`/platform/demos/alpha-printing-pos` is a separate public fictional browser
demonstration. Preserve its fixed fixtures, isolated sessionStorage namespace,
no operational writes, no provider calls and strict response policy. Never wire
it through production `PosAccess`, tenant impersonation, real stock/customers or
merchant configuration. Its availability is separate from real checkout and
native iPhone Tap to Pay. See `docs/operations/alpha-printing-browser-demo.md`.

## Local POS simulation foundation (2026-10-21; source only, disabled)

A separate local/testing-only register rehearses native catalog prices, confirmed
simulation tax, shared inventory holds, immutable payment attempts, independent
simulated-reader evidence, reconciliation, card/cash rehearsal, exact-SKU barcode
entry, explicit native customer/guest choice and practice history, private printable
simulation receipts and manager refunds. No Stripe/Terminal adapter, credentials,
provider call, merchant onboarding, production activation or real payment is included. All rollout gates
default off and production is hard-denied. Read
[the POS simulation runbook](docs/operations/pos-simulation.md) for the ownership
contract, verification and remaining hardware/provider acceptance.

<!-- SES failure diagnostics: bounded AWS HTTP status/error code persist in the existing hold_reason; sending/retry gates are unchanged. See docs/operations/ses-migration.md. -->
<!-- SES platform simulator audit: scoped encrypted original SNS envelopes enable in-place signature-verified replay; real sends remain gated. See docs/operations/ses-migration.md. -->
<!-- SES actual event binding: native tenant auto-tag is ses:tenant; signed source may include a display name. See the simulator checkpoint in docs/operations/ses-migration.md. -->
<!-- SES customer usage billing policy: see docs/operations/ses-migration.md. Explicit email-only tenant exclusions freeze at reservation; provider cost and units remain metered. -->
<!-- SES platform mail: default-off ses_platform encrypted outbox, dedicated queue and signed events; SendGrid stays default. See docs/operations/ses-migration.md before activation. -->
## Guided onboarding and settings (source candidate, October 20)

See [workspace settings and onboarding](docs/operations/workspace-settings-and-onboarding.md).
The tenant-scoped settings hub links existing authorized forms; a three-step
business profile extends the canonical blueprint without changing grants/terms.
Record-derived setup progress is distinct from per-user reminders. First-login
questionnaire drafts remain account-only until the existing creation transaction.
Minimal confirmed business context refreshes existing authorized help; absent
context preserves AI stamps and the approved voice lane. No new schema, provider
activation or production acceptance is claimed by this source note.

## AI Voice Branch — October 20 approved commercial policy

Read `docs/operations/ai-voice-pricing-and-rollout.md` before changing this lane.
Existing tenants receive included access and 2x metered usage; future tenants pay
$20/month access plus 2x usage. Pin the approved existing ID/slug boundary: never
grandfather newly created tenants on renewal. Default ordinary accounts are off
with a $0 customer cap; owners or verified workspace admins with existing billing authority approve model/rates/caps. Use the existing
service-credit account and immutable AI meter; do not create a second wallet or
invoice settled prepaid usage again. Unknown outcomes keep reservations until
provider-evidence reconciliation. The separately audited synthetic pilot supports
scripted or explicitly approved capped model answers; revoked/expired grants never
fall through. The user's $5 pilot cap is total lifetime provider spend. Preserve
legacy accepted subscriptions, the tablet demo and all data/action boundaries.
Protected release and exact readiness evidence remain required. No new schema.

## Fictional campus demo source candidate (October 21)

`/platform/demos/lees-mcrae` is a canonical-host-only GET/HEAD document with
embedded fictional fixtures and browser-session simulation. Do not wire its
demo persona controls to production identity, tenant data or providers. It has
no server mutation API; route-only session/heartbeat exclusions and restrictive
CSP preserve ordinary app/POS behavior. See
`docs/operations/lees-mcrae-campus-demo.md`. Deployment requires the normal
reviewed PR, passing gates and exact `/ready` release evidence.

## Current product map and verified checkpoint

Read [the current product/setup/release map](docs/operations/everbranch-current-state.md)
first. It separates source capabilities, a dated verified deployment and actual
provider/customer readiness. `/ready`, the protected deployment evidence and the
latest versioned audit determine the current live release; a source checkout or
old release note does not.

- Last independently verified checkpoint: **2026-10-20 08:58 UTC**,
  `00acdc1b05b04e81356c53601f6a6f653dd6f843` (Forge 80669972, protected run 39396530459).
  This is a dated observation, not a promise that production will stay on that SHA.
- New public **Everbranch Simple** offers: **$499 one-time setup + $299/month**.
  Additional text, email, and AI usage fees may apply. Choose included core
  branches; Teams, Trajectory and Markets & Makers are separate premium software.
  Existing accepted agreements/subscriptions, including Launch Partner terms,
  retain their recorded prices and conditions.
- The checkpoint includes the consistent public offer, tabbed plan/branches/add/
  setup screen, supported electrician intake and truthful unknown health state.
  Premium ownership/readiness work added after that checkpoint is source behavior,
  not deployment evidence; verify a later protected release before claiming it live.
- Marketplace code is deployed at the checkpoint, with all eight effective launch/
  collection flags off and host unset. Entitlement, configured workspace, rollout,
  provider readiness and payment confirmation are distinct. Never activate one
  from a marketing selection or infer paid/provider readiness from a grant.
- Original 75.3 and accepted 87.3 audit reports remain immutable. New acceptance
  evidence belongs in a new audit after release, using unchanged rubric v1.

### Superseded October 20 planning notes

Earlier headers described the public offer as an undeployed candidate and settings,
electrician intake and health fixes as pending. Those planning states ended at the
verified checkpoint above. Their dated evidence remains historical; it is not a
current instruction to replay an old source branch or reprice a customer.

## Markets and Makers marketplace (2026-10-20; default off)

- `/marketplace/preview` is a separate private example-data console, explicitly
  assigned by account ID with an empty source default. It grants no tenant,
  merchant, provider or payment access and does not change public launch gates.
- `marketplace_*` is the dedicated Everbranch merchant retailer/supplier lane.
  Do not reuse legacy, Modern Forestry or Website Commerce orders/customers.
  Domain ownership requires the verified Store; operators require current active
  owner/admin membership of its tenant. Email matches grant no workspace access.
- Preserve explicit native catalog opt-in, atomic stock allocations/reservations,
  accepted versioned rates, immutable integer earnings and promotion funding,
  canonical merchant-bound signed payment evidence, original-account refunds,
  encrypted private details and reviewed actual bank references. Checkout off must
  preserve reconciliation, fulfillment, orders, statements and credentials.
- Native Everbranch is the default checkout provider. Stripe direct payments and
  separate Shop Pay Wallet require their own configured merchant and test/live
  evidence; never borrow another tenant's account or the Evergrove Wallet binding.
  Unknown mutations cannot be blindly replayed. PayPal buyer approval/capture/refund also has independent default-off merchant,
  signature, test and live gates; seller setup alone never enables collection. Optional hosted Shopify is explicitly opt-in.
- Management uses a scoped Teams-style forest sidebar and focused details;
  public shopping keeps the tablet Showcase presentation. Preview must use only
  explicit fictional view data, never the tenant shell or branding initializer.
  See `docs/architecture/markets-makers-management-console.md`. Owner reporting and
  market participation are permission-scoped; shared store links do not imply
  catalog sync. Shopify/Wix reseller publishing remains phase two. See
  `docs/operations/markets-and-makers.md`; source defaults remain off.

## Self-service payment accounts, bank linking and native invoices (2026-10-19)

- New Stripe accounts use full Dashboard access and direct charges; owner-session OAuth can connect an existing Standard account. Frozen request/routing/environment/account context prevents cross-merchant retries. Existing Express reservations and Wren routing stay unchanged. Direct payments require their own connected-event signing secret and live proof gate; new direct attempts use stable platform-account/environment fingerprints across key rotation.
- The shared Accept payments screen separates optional bank feeds from payout setup. Plaid business links request Transactions only; encrypted user/space/browser Link sessions support the original-token OAuth return, safe reconnects and mapped ownership. A registered HTTPS return URL and genuine institution checks gate bank sign-in. No new subscription or accounting writeback.
- Native Website quote/draft/invoice authoring supports immutable issued terms, catalog/custom lines, nonstacking discounts, full payment or deposit+balance, private host-scoped links, one-time stock commitment, receipts and original-account refunds. Customer ACH remains independently default-off and processing until provider confirmation. Never infer paid status from a bank feed or redirect. One additive website_discounts table has durable MySQL recovery and data-free baseline evidence.
- Preserve provider/domain authority and existing entitlement/rollout gates. See `docs/operations/self-service-payments.md`; real merchant onboarding/provider acceptance remain required before customer collection.
- Stripe Dashboard-account OAuth and the shared return URL are active in production. The real account selector and owner/session-bound cancellation return were verified on October 20; the earlier blank provider pane no longer reproduced. Payout Financial Connections uses minimal permissions and manual fallback. PayPal verification must preserve its payment_processing purpose. See the same runbook's provider acceptance evidence; bank/merchant readiness and collection still require separate proof.
- Plaid's exact return URI is registered on the client matching production and a genuine Transactions-only Link request succeeds. New bank sign-ins are default-off behind exact PLAID_LINK_SPACE_IDS; only the existing Evergrove business space 3 is prepared as the pilot. Existing feeds and issued returns keep their original authorization. Merge the gate before staging the URI, and require real bank/mobile acceptance before widening. See self-service-payments.md.

## Wren payment choices and offline reconciliation (2026-10-19)

- The family portal offers explicit card and canonical WrenFamilySoccer Venmo choices. Link clicks are audit events only. Organizer-confirmed receipts and reasoned fee waivers serialize with checkout, retire/reconcile open Stripe sessions, preserve actual receipts for review and never fabricate revenue. Preview-first `soccer:reconcile-offline` applies a reviewed invoice/family/amount with an active verified organizer.
- Verified PayPal/Venmo create, capture, status, webhook and original-account refunds are implemented behind independent provider/merchant/collection/webhook gates. Order intent is durable and encrypted before HTTP; uncertainty cannot create a replacement order. Current provider status is checked before money movement. Existing Wren Express/destination routing remains unchanged. Real sandbox/live approval and acceptance still gate launch. See `docs/operations/wren-verified-venmo.md` and `docs/operations/wren-soccer.md`.

## Wren organizer registration summaries (2026-10-19)

- Owner-approved `WREN_SOCCER_ADMIN_NOTIFICATION_EMAILS` receive private formatted
  signup and confirmed-payment summaries with current season/team rosters, family
  and child counts, paid/unpaid/waived/waitlisted totals, receipts, refunds and net.
  Delivery rechecks the allowlist and excludes medical/emergency details. Replay
  does not resend. GroupMe automated notifications default off; parent email stays.
  No schema change. See `docs/operations/wren-soccer.md`.

## Wren email registration launch (2026-10-19)

- `soccer.notification_mode=email` is an explicit Wren launch path, not a ready
  SMS declaration. Parent operational email uses the existing configured mailer;
  automatic GroupMe alerts are disabled by default with
  `WREN_SOCCER_GROUPME_NOTIFICATIONS_ENABLED=false`, including queued retries.
  Do not put family names, medical details, invoice links or override reasons in
  shared alerts. Preserve per-event dedupe and stable GroupMe source GUIDs.
- `launch_verified_mode` binds the smoke checklist to the selected mode. Verify
  real sign-in/notification email, existing card/refund
  evidence and current readiness before opening. SMTP exceptions stay uncertain
  for operator reconciliation rather than blind resend. No schema or new SMS
  registration is involved. See the soccer runbook.

## Wren family help (2026-10-19)

- `SoccerFamilyHelpService` handles toddler/minimum-age, human contact, move-up,
  season schedule/address, and live registration-status questions locally.
  Preserve distinct season/opening dates, family-profile vs enrollment language,
  and returning players through 12. No under-three exceptions or invented fall
  cutoff. Review claims must reflect actual persisted evidence; the AI cannot
  claim it saved or changed records. See `docs/operations/wren-soccer.md`.

## Wren player age eligibility (2026-10-19)

- Use `SoccerEligibilityService` for quote, registration, profile, and helper age
  decisions. New ages 3–10; returning through 12 at the season cutoff. A guardian
  or coaching role is not player history. Only prior registered/historical rows or
  retained signed D1 releases qualify; fresh portal releases cannot grant alumni status.
- Keep profile saving available for children outside current eligibility. Do not
  auto-enroll future players or change payment/provider launch gates.

## Premium Teams workspace and per-user Asana (local, 2026-10-19)

Read docs/architecture/everbranch-team-premium-workspace.md and
docs/operations/everbranch-team-premium-verification.md. Independent default-off
premium/Asana flags retain the shared graph and legacy fallback. Vite modules add
Forestry navigation, dense sectioned work, inline canonical edits, task selection,
boards, month calendars and responsive detail tabs; favorites/saved native views
and initial loaded-page timeline/workload/dashboard projections are personal.
Per-user/per-tenant encrypted OAuth connections project selected Asana work read
only, with current source access, identity/cursor fences, background clearing and
rate-limit/reconnect states. Existing automation credentials remain separate.
162 Laravel tests/1779 assertions, 60 Node/47 graph, build and migration lint pass.
Browser evidence uses fictional records/provider fixtures, not live OAuth acceptance.
Dedicated OAuth setup, real-user connection and MySQL/Linux/release gates remain.
Later milestone/portfolio/collaboration/forms/automation/time-tracking work remains;
all 643 clauses are preserved without a native/product completion claim.
No production deployment, real import, source write or billing change occurred.

## Company Teams standalone web application (local, 2026-10-19)

Read `docs/architecture/everbranch-team-application-packaging.md` and
`docs/operations/everbranch-team-standalone-application-verification.md`.
Everbranch links eligible viewers to company-branded `/teams`, an independent
authenticated shell following Trajectory's own-sidebar pattern. `/team` stays
embedded. Both include one shared workspace/script and use the same controller,
APIs, records, current permissions and Fortify login. Task/project/comment and
workspace links retain the selected surface; draft guards remain in force.
No new domain/service/PWA/native binary, duplicate store or schema is added.
240 affected Laravel tests/2871 assertions and 16 new plus94 affected browser
scenarios pass; native/device/offline durability and real import/release gates
remain unaccepted. All643 clauses stay:17 server invariants,185 partial,12
blocked,429 not started; counts never mean product/native completion.
No real data/credential/billing/grant/deployment or user-file deletion occurs.

## Team task/comment navigation safety (local, 2026-10-19; disabled)

Read `docs/operations/everbranch-team-draft-navigation-verification.md`.
Shared web navigation now guards pending task/comment/create/field/recurrence
input; keep-and-close/resume and exact frozen save retries use page memory.
A stale viewer/workspace header refuses before reads/writes. Auth/access loss
seals drafts until original-account and current edit-access verification; no
browser storage, cross-user persistence, automatic replay or purge is added.
Conflict review and confirmed-save/refresh-failure handling retain other input.
Reload/accepted Leave/logout/restart/native/offline durability remains unaccepted.
All requirements and migration/infrastructure/release gates stay in the matrix.
No real imports, user-file/cache deletion, credentials, payments or release action.
Final local evidence: 186 affected Laravel tests /2568 assertions, 60 Node,
47 graph checks, 27 draft plus 67 affected browser scenarios. All 643 clauses
remain: 17 verified server invariants,179 partial,12 blocked,435 not started.
These are evidence/inventory counts, not mobile or product completion scores.

## Team minimum replacement workflow assessment (local, 2026-10-19)

Read `docs/operations/everbranch-team-minimum-replacement-verification.md`.
Authorized project name/description search now opens the existing shared project
and task workflow. Local evidence separately verifies the daily assignment/date,
subtask/comment, My Tasks, search and complete/reopen path. Other entity search,
large-tenant indexing, native renderer/offline and durable input recovery remain
partial. Safe real import is blocked by reviewed export/identity/privacy/file
inputs, durable application import and infrastructure/release gates. Keep all
643 clauses and original notes/history; no native/product completion percentage.
No real source access/import, deletion, credential, payment or release action.
Current matrix: 17 verified server invariants, 173 partial, 12 blocked, 441 not
started. Actual checks: 182 affected Laravel tests /2534 assertions, 60 Node,
47 graph checks and 40 browser scenarios (14 new plus 26 affected regressions).
These are local evidence counts, not product/native completion percentages.

## Team project overview and status (local, 2026-10-19; default disabled)

Read `docs/operations/everbranch-team-project-status-verification.md`.
The existing G: candidate now supports authorized project links, visible-task
progress, current-owner status posting, reviewed task references and paginated
immutable status history through the shared graph and native revisions/events.
No schema, task duplication, source-note rewrite or member notification is added.
Recovery text requires original-account verification; durable/native recovery
remains unaccepted and its corrected gates remain off. 176 affected Laravel tests
/2440 assertions, 60 Node, 47 graph and 53 frozen-source browser scenarios pass.
All 643 clauses remain: 17 verified server invariants, 168 partial, 12 blocked,
446 without clause-specific completion evidence. Native/OS, project resources,
milestones, roster/roles, notifications and remaining A-E scope are unfinished.
The five inherited Windows failures are unchanged and were not rerun this slice.
No original files/caches removed, dependency install, import, push, merge, deploy,
credential change or paid service occurred. This section records the prior project-status checkpoint.

## Team core task links and follow-ups (local, 2026-10-19; default disabled)

Read `docs/operations/everbranch-team-core-workflows-verification.md` and the
remaining-work backlog. Continue in the existing G: candidate. Task/comment web
links reauthorize the exact workspace before HTML; reviewed follow-ups create new
canonical tasks without copying or rewriting original notes/history. Stable retry
identities, source-version review and sealed copyable new draft text are tested.
159 affected Laravel tests/2256 assertions, 59 Node, 47 graph and 39 browser
scenarios pass. Clipboard success is stubbed; native/device acceptance stays open.
All 643 clauses remain: 17 verified server invariants, 152 partial, 12 blocked,
462 without clause-specific completion evidence. This is not product completion.
The history gap was omitted original shallow metadata, now copied exactly on G:;
163 commits traverse. Original partial-clone promises explain absent old blobs;
promise-aware connectivity passes without fetch/rewrite. Full history remains
unavailable locally. Five unchanged Windows assertions reproduce separately;
no security check or production expectation was weakened. Corrected native
offline preservation gates remain off. No import/push/merge/deploy/deletion.

## Team multiple-workspace increment (local, 2026-10-19; default disabled)

Continue from `G:\Everbranch\asana-replacement-20261019-01\backend-integration`.
Read `docs/operations/everbranch-team-workspace-verification.md`. The prior
staged checkpoint and 3,685 source-file hashes were verified before copying;
the C: original is preserved. Current eligible Team workspaces have a paginated,
searchable directory and guarded web navigation through existing tenant access.
Unsaved task/planning text, comments, selected files and pending saves block
switching. Native bearer payloads identify their workspace; no new native UI or
offline recovery is enabled. All 643 clauses remain: 17 verified server invariants,
142 partial, 12 blocked, 472 without clause-specific completion evidence.
The inherited Git object store has a missing historical parent; current commit,
index, source and available objects are preserved, not a complete-history claim.
No real source import, cache deletion, dependency install, push or deployment.

## Team milestone C (local, 2026-10-17; default disabled)

See `docs/operations/everbranch-team-milestone-c-verification.md` and the generated
`everbranch-team-parity-status-summary.json`. Reviewed task/project snapshots,
completion-driven recurrence and cycle-safe dependencies share the existing graph
/repository. No live scheduler or external notifications are registered or run.
The existing wall-time resolver is reused unchanged; no new schema or native app.
All 643 clauses remain: 17 verified server invariants, 140 partial, 12 blocked,
474 without clause-specific completion evidence. This is not a completion percentage
or native/production acceptance. Prior full-suite baseline failures remain visible.
The withdrawn mobile purge candidate remains excluded; offline recovery/write
gates stay off. No push/deploy/install, real data action or cache deletion.

## Team backend reconciliation (local, 2026-10-17; default disabled)

Read `docs/operations/everbranch-team-reconciled-verification.md` and the 643-clause
`docs/architecture/everbranch-team-mobile-parity.json` / remaining-work backlog.
The Team graph, ACLs, native transport, Field references, private files and Home/My
Tasks candidate are reconciled on backend `79d430c`. Preserve all newer voice,
payments, soccer, provider and migration-safety contracts below. No real tenant
activation, source import, provider action or release follows from this checkpoint.

The earlier mobile automatic-purge patch is WITHDRAWN and excluded from release
handoff. Only the separate corrected preservation patch against `d9b0b31` is the
native candidate; offline writes/encrypted recovery remain default off pending
device proof. Current source is local/staged, not installed or production.

Team uses the shared transactional graph/repository, current verified tenant and
object ACLs, canonical entitlement and exact rollout allowlist. It never grants
private task access through Field jobs, copies legacy checklists, dispatches
external notifications, or rewrites source history. Uploads remain quarantined
without an approved scanner. Native Team renderer/MySQL/device gates remain open.

Historical Team source-preservation utilities/docs removed from this newer base
are restored from the preserved Team checkpoint for synthetic local review only.
No real Asana access, credentials, invitations, billing, install or cleanup.

## Ask Everbranch (2026-10-16; local, disabled)

- The separately audited fictional pilot uses the fixed `everbranch-voice-pilot`
  slug, one existing verified owner, expiring grants and scripted zero-cost answers.
  Read `docs/operations/everbranch-fictional-voice-pilot.md`. Never replace that
  grant with fabricated paid billing, grant other tenants a provider bypass, or
  remove request-local restoration / revoke-before-provider checks.

- Read `docs/operations/everbranch-voice-runbook.md` and
  `docs/architecture/everbranch-voice-acceptance.md` before extending the assistant.
  Navigation uses deterministic authorized search. Business mode reuses AI Branch,
  requires its current commercial/owner/admin access and an independent exact
  tenant rollout gate. Landlord status is not a data-access grant.
- Financial snapshots expose saved QuickBooks totals only; never infer currency,
  basis or missing totals, combine channel revenue, refresh providers from a GET,
  inject raw accounting into prompts or add accounting writes.
- Reports are encrypted private, expiring registered-source snapshots, not a
  complete business ledger. Selected team scope intersects current job access.
  Preserve reauthorization on read/print and fail instead of returning partial
  counts. Prune also works while runtime flags are off.
- Note undo archives a precisely unchanged internal AI note and records a
  compensating receipt. Any later model edit permanently removes automatic undo.
  Never extend that promise to sent communications or changed evidence.
- Commitment tasks have an independent gate, team-visible note sources and
  explicit assignee/due-time review. Keep `WorkspaceWallTime` DST disambiguation,
  source/version checks and the existing action UUID ledger. Do not quote private
  sources into shared tasks or turn task approval into notification/accounting
  dispatch. Phone adapter/practice boundaries are in `everbranch-phone-rails.md`.

## Stripe and PayPal payment setup (2026-10-16; deployed, Wren setup pilot)

- Read `docs/architecture/stripe-paypal-payments-plan.md` and
  `docs/operations/tenant-payment-setup.md`. This first phase provides connection
  setup only; PayPal checkout and landlord subscription support remain unbuilt.
- Preserve exact tenant allowlists, active owner/admin membership and canonical
  Branch access. No platform-admin membership bypass. Sandbox/live credentials,
  merchant identities and account-create reservations must stay separate.
- Wren and the shared page use `TenantStripeConnectionService`. Never rotate a
  pending request's key/payload to recover an ambiguous account-create response.
  Provider identity and test/live mode bind the reservation; stop after 23 hours
  for reconciliation before a new account could be created.
- PayPal never trusts browser merchant IDs or permission flags. A connection
  cannot open checkout, create a subscription, grant a module or reroute refunds.
  Signed revocation/payment events and genuine sandbox acceptance remain gates.

- PR #410 deployed at `cb133ecf` through protected run `38904619886`. On
  October 19 the owner completed live identity and Connect agreement setup.
  A verified cached HTTP 400 plus a complete zero-account scan allowed an audited
  separate recovery reservation; the original encrypted request is preserved.
  Wren now has a live connected account in onboarding. Business/bank verification,
  callbacks and payment/refund proof still gate collection. See the payment setup
  runbook for evidence. Shop Pay diagnostics remain in
  `docs/operations/shop-pay-wallet-readiness.md`.

- Soccer Stripe Checkout explicitly names the connected soccer business as
  `on_behalf_of` as well as the transfer destination, matching native storefront
  settlement. No prices, platform fees or collection gates change.

## 2026-10-16 — Marketing Desk photos, Instagram history and planning AI

- The desk uses the full workspace canvas, explains the daily workflow and shows
  real draft/imported photos. Existing Instagram posts can be explicitly refreshed
  (latest 50); photo cards compare lifetime metrics with missing values and
  separate post-sync/insights freshness. No scheduled scrape or publication occurs.
- Read-only social access has its own default-off gate. Account ownership and
  credentials are rechecked after provider reads; repeated imports are serialized
  and reuse existing publications. Reach/saves need separate insights permission.
- Marketing Bud has independent provider activation and per-role model rates.
  Owners can plan without approving draft brand claims; customer replies still
  require approved knowledge/policies. The existing shared $50 cap and usage
  reservations remain enforced. Legacy Bud/voice and AI Branch billing stay separate.
- PNG/WebP uploads normalize to workspace-owned JPEGs. No schema changes.
- Composer creates the public storage link in each candidate release so uploaded
  marketing photos keep working after atomic deployment. See the Forge runbook.
  See `docs/operations/marketing-desk.md` for setup and verification.

## Recharge customer/settings rollout preparation (2026-10-16)

- Continue with `docs/operations/everbranch-recharge-rollout-preparation.md`.
  The owner confirmed Recharge billing and requested one-to-one customer/settings
  preparation with rollout OFF. Never infer provider ownership from Shopify plans
  or copy these customers into CRM, Website, Shopify or live subscription tables.
- Preserve exact provider IDs, money, source dates, nulls, accepted generations
  and encrypted evidence. Masked payment references never become adopted tokens.
  Source hashes/processor tokens are stripped before persistence. Page rendering
  is offline; explicit source GETs require their default-off tenant read gates.
- Reviewed manual settings use `subscriptions:recharge-settings` and bounded
  append-only encrypted source/draft versions; private comparisons stay offline.
  Merchant origin attribution is manual, never API proof.
- Manual settings and missing resource-family/history coverage remain blockers,
  not defaults or checkbox approval. Local field matching never proves functional
  parity. Billing, messaging, servicing, purchasing and cutover stay disabled.
- Production has no recorded Recharge credential for Modern Forestry. Actual
  reconciliation requires secure source setup and independently expected store
  identity; never request a secret in chat or invent a Shopify domain field.

## 2026-10-16 — Marketing Desk and Automation Studio (implemented, disabled)

- Shared `/marketing/desk` adds Today, Bud, a social calendar, measured best posts,
  a four-channel inbox, recipes, versioned approved knowledge and owner settings.
- Marketing reuses canonical profiles, campaign delivery/consent, the tenant AI
  ledger and Workflow Studio v2. New execution requires explicit tenant allowlists,
  independent delivery/AI/Meta flags and recorded approvals. No production
  activation, provider send, published post or billing change was performed.
- Studio gains a wider directory, connection summaries, Overview/Build/Activity/
  Settings, an outline, fit to view and clearer draft/live/test states. Existing
  workflow versions and operational Asana/Calendar behavior remain compatible.
- Eleven additive tables retain approvals, enrollment, cost-only usage references,
  social attempts and exclusive account binding. Unknown external outcomes never
  automatically resend. Profile merges cannot transfer marketing permission.
- Read `docs/operations/marketing-desk.md` for setup, exact boundaries, verification
  and rollback. Real Meta review/provider tests and merchant acceptance gate launch.

## Modern Forestry paid Launch Partner and AI intent (2026-10-16)

- The user subsequently authorized a genuine **AI-only $99/month checkout** and one fictional provider diagnostic capped at $0.10, and asked Codex to install the key. A dedicated OpenAI project/service key is installed in the server secret environment (expires November 14, 2026); one diagnostic completed using 39 input/18 output tokens, with a conservative $0.000024 cost ceiling. This is not a paid Stripe subscription, production tenant opt-in or final provider invoice reconciliation.
- `ai-branch:prepare-purchase` is a preview-first, platform-operator-only Growth pilot preparation command with its own default-off flag and tenant allowlist. It keeps an idempotent reservation in existing profile metadata, verifies exact Stripe price/customer ownership, and returns a customer-present checkout. Public catalog purchasing stays disabled. Preserve profile metadata during add-on fulfillment and normalize Checkout/invoice statuses separately from subscription statuses.
- The user selected Modern Forestry as the first AI Branch pilot and Launch Partner client. Read `docs/operations/modern-forestry-launch-partner-ai-rollout.md`. Production client record 314 / audit 620 records $89/month for six cycles, $149 thereafter, plus $99/month AI; billing and AI remain pending.
- Do not treat the client-directory conversion or profile enrollment metadata as paid evidence. Repair/verify the existing Stripe customer association, preserve operational alpha grants, obtain exact accepted billing terms and owner provider cap, then release through GitHub/Forge. The $499 public setup fee is pending existing-client review, not an authorized charge.
- Exclude paid `ai_branch` from alpha grant upserts while preserving legacy `ai`. Existing single-item promotional schedule code must be reviewed for permanent AI line items before any combined checkout. No client delivery, payment, public purchase activation or AI provider call was triggered by registration.

## Workspace-aware AI Branch (2026-10-16)

- Continue with `docs/architecture/everbranch-ai-workspace-context.md` and `docs/operations/everbranch-ai-runbook.md`. Preserve the two independent AI/context gates, explicit tenant allowlists, active owner/admin checks and current billing evidence.
- Read-only attention briefings and chat share one account-locked usage ledger, spending cap and UUID protections. Never auto-retry or refund uncertain provider attempts. Source projections are scalar allowlists; limits are five/family and twenty overall. Never add raw tenant/customer/integration metadata to prompts.
- Refresh context every request; version/module/role/memory changes and inaccessible source records invalidate history or in-flight delivery. Default timezone is UTC; validated workspace timezone changes calendar boundaries, never UTC billing periods. Preserve scoped deletion of incompatible chats.
- Standard and Website & sales launchers accept server-resolved page identities only. Keep dedicated Shopify and soccer surfaces unchanged. Voice, phone, financial summaries and workflow agents remain later phases. Local fake acceptance does not authorize a live tenant or deployment.

## Everbranch AI Branch development 2026-10-15

- Restart with `docs/architecture/everbranch-ai-implementation.md` and `docs/operations/everbranch-ai-runbook.md`. Preserve included deterministic Bud and the existing Shopify AI Assistant.
- The optional branch remains hidden and globally/tenant disabled. Pricing and proposed pilot allowances live only in the canonical catalog. Purchases are blocked until commercial approval; tenant settings cannot create or change subscriptions. Every AI call requires current active paid billing evidence and owner/admin membership.
- Keep tenant/user scope, encrypted text, pre-dispatch usage reservation and exact action approval gates. Never auto-refund ambiguous provider failures or retry their UUID. Reconcile only using the preview-first operator command with provider evidence. Keep voice and phone transports unimplemented until their separate acceptance gates pass.


## Subscriptions Phase 2 provider evidence (2026-10-15; deployed disabled)

- Read `docs/operations/everbranch-subscriptions-provider-reads.md` before setup.
  Explicit read-only CLI operations require an active entitled manager, a selected
  tenant-owned ShopifyStore, independently verified provider IDs, the global read
  gate and tenant allowlist. Do not borrow global retail/wholesale credentials.
- Keep credential/installation identities immutable after verification. Pending
  setup corrections are audited local operations that invalidate old probe UUIDs.
  Every read is fenced to its original credential, request, generation and slice.
- The five additive evidence tables need the reviewed disposable baseline and
  protected MySQL recovery/deployment gate. Partial sync never publishes a total;
  only complete index revalidation promotes staged resources. Never rejuvenate old
  observations or turn absence into cancellation. Preserve paused/cancelled history.
- `/subscriptions` reads local saved history only. Dedicated raw-HMAC webhooks use
  pinned per-installation signing revisions, retain encrypted evidence, and stay
  quarantined while background reads are disabled. Token setup does not configure
  signing or prove webhook registration. There is no mutation, mail, card retry,
  background worker, automatic token refresh or paid activation in this phase.
- Six Modern Forestry selling-plan groups belong to verified Recharge Subscriptions
  app `294517`; Backstage owns zero contracts/groups. This does not establish the old
  provider's billing/vault/migration state. Keep provider adoption a reviewed phase.
- PR #402 deployed at `f62584af`, protected run `38821001498` / Forge `80347709`.
  The 409-table fingerprint and production preservation checks passed. Later
  releases may add unrelated tables; generate new baselines through CI only.

## Subscriptions Phase 1 foundation (2026-10-15; deployed disabled)

- Canonical subscription entitlements cover admin, customer/mobile, public polls,
  webhook evidence and CLI apply paths. Active tenant managers require a separate
  Everbranch sign-in; Shopify launch/session context alone is insufficient.
- Store/app identity is locally frozen; new request evidence is encrypted and
  truthful. Requests, cutover review and received webhooks never execute Shopify
  changes, queue payment emails or charge cards. The isolated adapter contract is
  pinned to 2026-10; provider execution and scheduling remain hard disabled.
- App Review subscription demos no longer seed operational contracts/polls or
  live votes/reviews. Existing demo evidence is retained and excluded from metrics.
- Subscriptions has local USD 99/month separate add-on metadata; Modern Forestry
  retains its explicit comped grant. Canonical new purchases stay disabled in
  catalogs, direct/plan checkout and mobile handoff. No external billing setup
  occurs. Gift Cards is a later separate add-on with pricing pending approval.
- Read-only merchant checks found zero contracts owned by Backstage; other-app
  subscriptions require reviewed ownership/vault migration. See
  `docs/operations/everbranch-subscriptions-and-gift-cards.md` for the five
  subscription phases, gift-card phases, limitations and acceptance gates.
- Foundation PR #401 is deployed at `f8bf74f3`, protected run `38818732814` /
  Forge `80346741`. Modern Forestry's existing comped grant is unchanged.

## Reviewed schema reconciliation (2026-10-15)

- Keep platform drift repair separate from shipping Phase 4. Preserve historical
  tables, unresolved ownership and original orphan-profile evidence. Never
  guess tenants, delete conflicting rows, resend messages or adopt a drifted
  live fingerprint merely to pass the release gate.
- Compatibility accepts exact historical aliases and column-order equivalence
  on four declared tables only. Canonical fingerprints now include FK actions;
  raw hashes remain available to pin historical fixtures. Missing/duplicate
  constraints still fail. Helper/manifest changes force the MySQL release gate.
- Prove fresh and captured observed paths in disposable MySQL, generate the
  reviewed baseline through its workflow, deploy/retest disabled, then prepare
  Modern Forestry's native pilot with its existing Shopify operations intact.
  Follow `docs/operations/schema-drift-reconciliation-2026-10-15.md`.

## Native Shopify catalog snapshot pilot (2026-10-15; deployed drafts)

- The user explicitly approved Modern Forestry Shopify products as a read-only
  source for its separate native Website pilot. This narrow catalog exception
  does not authorize Shopify writes, customer/order imports, checkout changes,
  new connections, publication, provider calls, or activation.
- `website:import-shopify-catalog-snapshot` defaults to dry run and requires an
  existing scoped site, active catalog operator, and independently reviewed shop
  domain/Shop GID. Stable source mappings and encrypted provenance protect identity;
  operator edits or source removals require review rather than automatic overwrite.
- All imported products remain draft. Invalid/unknown tracked source stock becomes
  zero sellable quantity and unavailable, with the original source value retained.
  Weights stay missing when unknown; unreviewed dimensions are not inferred.
  Inventory is a snapshot, not shared live stock. Require pilot allocation before
  activation. See `docs/operations/native-shopify-catalog-snapshot.md`.
- Production import and replay verification are complete: 397 draft products,
  1,681 variants, 24 draft collections and 2,102 encrypted mappings; 101/109 source
  active products covered. Exact replay changed no catalog/inventory/audit rows.
  Missing weights/dimensions remain missing and 369 invalid-stock variants remain
  unavailable. No pilot publication, order, payment or shipment was created.

## Retail shipping Phase 4 (2026-10-15; deployed disabled)

- Continue the native-only five-phase plan. Phases 1–3 are live behind disabled
  checkout/shipping gates via PR #397 (`88bda81a`). Phase 4 keeps activation separate from its code release.
- `WebsiteShipmentLifecycleService` owns durable original-account postage,
  GET-only recovery after an uncertain POST, refund/replacement state, explicit
  pack/handoff, signed events and canonical tracking. Never reset an unknown
  buy to prepared or use a print failure to buy replacement postage.
- `WebsiteLabelDocumentService` retains private original PNG and exact-size PDF
  layouts. Only active workspace shipping managers may access address-bearing
  labels. Browser dialog closure never proves printing or carrier handoff.
- `WebsiteOrderNotificationService` is a separate encrypted native outbox. Never
  route it through marketing/Shopify gateways. Unknown acceptance cannot retry
  automatically; operator evidence and a separate explicit retry are required.
  Its new migration must repair partial MySQL constraints/indexes and retain
  deduplication. Refresh only the disposable data-free schema baseline.
- `website:reconcile-shipments` runs every five minutes; the native notification
  scanner runs each minute. Neither activates rollout gates. Health rendering
  uses local configuration/counters and must not call providers.
- Carrier credential rotation creates a new encrypted IntegrationConnection;
  retain old credentials for their pinned attempts. Never flash keys/secrets.
- Printing supports the standard PDF/driver path. The USB DYMO LabelWriter 4XL
  on Mac and Windows is the first physical target; other exact models need
  verification. Do not claim hardware, barcode, USPS delivery, tax, or settlement
  evidence from mocked or browser tests. Phase 5 remains the controlled pilot.
- PR #399 is deployed at `34a04d4b`, protected run `38816970879` / Forge
  `80346236`, with reviewed disposable schema `a4555a6b` (404 tables).

## Retail shipping Phase 3 (2026-10-15)

- Continue from the phased retail shipping plan. Phase 3 is isolated on
  `codex/retail-shipping-phase-3`, stacked on Phase 2 (draft PR #395).
- Native checkout attempts use `WebsitePayment.metadata.checkout_version = 1`.
  Never release their active reservations merely because `expires_at` passed;
  reconcile canonical provider state first. Pre-versioned pending attempts
  require manual provider review. Do not retrofit legacy or Shopify payments.
- Frozen Stripe requests/URLs/tax address are encrypted in
  `WebsiteOrder.service_request.checkout`. Keep PII and hosted payment URLs out
  of plain payment metadata and webhook evidence. Native provider calls use
  the pinned contract via `WebsiteStripeService` and the original platform-key
  fingerprint; key/account changes require deliberate reconciliation.
- New checkout requires existing entitlement/rollout/Connect/tax readiness and
  its signed webhook secret. Recovery/refunds deliberately service previously
  authorized native attempts while checkout is paused. The five-minute
  `website:reconcile-payments` command uses provider GETs, reports failures,
  rotates at most 100 attempts by default, and never enables commerce.
- Pickup/tax origins can be configured by scoped website editors before carrier
  activation. Package/policy actions retain carrier rollout checks.
- Existing native payment controls remain available while new checkout is paused;
  unresolved refunds have durable fixed-amount retry/check forms after navigation.
- Refund callers must retain their request UUID on retries. Pending/unknown
  operations reserve balance. Never auto-restock on refund. Fulfillment remains
  blocked for `payment_review` and `paid_review`. Real tax, fee/transfer/payout,
  label, notification, and physical-delivery evidence remain launch gates.
- Phase 4 printing must support multiple printer families through standard
  actual-size PDF/driver printing and separate optional device adapters. The
  first known physical device is a USB DYMO LabelWriter 4XL, not the sole
  supported family. See the plan's thermal/Letter/A4 compatibility matrix and
  no-duplicate-postage print/reprint acceptance requirements.

## Retail shipping Phase 2 (2026-10-15)

- Continue from `docs/operations/retail-shipping-launch-plan.md`. Phase 1 is
  draft PR #394; Phase 2 is isolated on `codex/retail-shipping-phase-2`.
- Reviewed package rules/services/mode live in `TenantSite.settings.shipping`.
  Quote `parcel._shipping_plan` carries current eligibility evidence; the full
  accepted plan is copied to `WebsiteOrder.shipping_rate_snapshot`. Never delete
  quotes on cart edits or rebuild a paid order's parcel from the current cart.
- Product dimensions or explicitly merchant-tested capacity establish a bounded
  fit. Box and protective packing tare is separate from item retail packaging.
  Unreviewed presets, unsupported fits, wrong accounts/modes, and stale quotes
  fail before payment. Existing rollout/entitlement gates stay closed.
- No real carrier test or production activation is implied by HTTP-faked tests.
  Finish payment/inventory, tracking/postage recovery, and the physical pilot in
  later phases before declaring end-to-end retail shipping ready.

# START HERE

## Ask Everbranch voice navigation (2026-10-16)

Read `docs/operations/everbranch-voice-runbook.md` before extending voice work.
Reuse canonical access/navigation and server-owned mobile destinations. Preserve
action exclusion, exact ambiguity, same-origin validation, refreshed permissions,
scoped back history and cancelled/unsaved-work behavior.

## 2026-10-15 - Retail shipping weight readiness

Native Website shipping weights are catalog readiness, not a vendor launch
failure. Products shows missing-weight counts and filters; the product editor
and /website/products/shipping-weights accept ounces, pounds, or grams with
upward whole-ounce storage. Blank bulk fields and omitted CSV/edit fields
preserve existing measurements; the product editor can explicitly clear a
weight. CSV exports include all variants with scoped stable IDs. Keep revision,
membership, active-product publishing, tenant, and site guards.

Missing physical-item weights block carrier quotes and shipping checkout only;
never use package tare as an item weight. Browsing and configured pickup remain
available. No commerce or shipping gate is enabled by this change. The five-phase
implementation sequence is docs/operations/retail-shipping-launch-plan.md.

The standalone Fleet map/history uses per-user selected vans and recorded
Bouncie trip paths. Read `docs/operations/collins-fleet-selected-routes.md` before
continuing it. Release through the protected GitHub/Forge gates, use isolated
fixtures, and preserve existing fleet/policy/provider configuration.

## 2026-10-15 — Everbranch Showcase workspace and landlord presentation

- Shared Everbranch web workspaces adopt the approved Showcase palette, readable
  typography, full-width canvas beside the existing sidebar, modern native fields,
  and contextual phone/tablet headers. Mobile Jobs/Customers use real paginated
  records and full-screen destinations; desktop grid editing remains available.
- The configured flagship console and separate Shopify, checkout/payment, and
  tenant-site surfaces retain their contracts. Public auth copy stays Everbranch
  branded while preserving authentication intent and permissions.
- The landlord console uses five primary groups. Owner-only client presentation
  presets save audited metadata without changing access or billing. Blank owner
  email configuration disables template editing.
- Administrative workspace status defaults to Active when unset and remains
  independent of setup/integration Health warnings in the directory and settings.
- No provider, billing, entitlement, schema, or production activation is included.
  See `docs/ui/everbranch-showcase-polish.md` for scope and verification boundaries.

## 2026-10-13 - Wren family soccer workspace

Wren has an isolated, default-disabled soccer lane and an authenticated bridge
to easleyfamilysoccer.com. Parents use public verified-email family sessions;
coaches receive assigned-team backend access. Historical evidence is immutable,
imports are replayable, and Spring 2027 stays closed behind season/provider
readiness. Wren-only SMS provisioning uses an explicit tenant-ID allowlist;
Settings collects encrypted operator details without activating an unapproved sender.
The family portal checks Alumni emails before sending codes and can show age-based
team guidance. Signed-in help keeps encrypted conversations and routes unknown
questions to admin Messages. Parent-confirmed birthday corrections are audited;
signed releases remain unchanged. Broader AI replies need a server-side key,
which Wren admins may save encrypted in Settings.
Follow `docs/operations/wren-soccer.md` for release, setup, and rollback.

Evergrove `/book` has consultation briefs and a short business-hurdle form using
`service_inquiries` and the existing Landlord Messages inbox. Google scheduling
uses the owner's verified one-hour Google Meet appointment schedule, embedded
directly on the page. Follow `docs/operations/evergrove-consultation-booking.md`.

Jarvis public branding pages at `/jarvis`, `/jarvis/privacy`, and `/jarvis/terms`
are limited to `evergrove.hosts`. They describe the separate desktop app and
must not be substituted for existing Everbranch or tenant policies. Follow
`docs/operations/jarvis-oauth-branding.md`; PR383 was owner-reviewed and published
through GitHub/Forge. Keep disclosures grounded and verify live release evidence
before production Branding links are used.

Field mobile job detail must redact customer phone/email unless
`FieldServiceAccessService::canViewCustomerContact` allows the active viewer.
The iOS and Android apps share this API. Keep authorization on the server;
employee preview masking alone cannot protect contact data.

## 2026-10-12 — Automatic marketing opt-out and weekly Everbranch recap

- Recipient-specific HTTPS unsubscribe links and mail-client one-click POSTs
  update every matching email profile inside the sending tenant. Both marketing
  provider paths recheck address suppression; imports preserve opt-outs. SMS
  consent remains independent. The existing consent timeline is the audit source.
- The Modern Forestry weekly rewards report becomes an Everbranch recap to
  `info@theforestrystudio.com`, Monday 08:00 America/New_York, for the previous
  Monday through Sunday. It includes customers added, opt-out identities, birthday
  and Candle Cash issuance/redemption, email delivery and storefront activity.
  A retained delivery record prevents duplicate reports on replay.
- Every recap metric opens a tenant-gated activity list for the exact reporting
  interval. Email details include failure reasons and future-send eligibility.
  Verified SendGrid events suppress permanent recipient failures, complaints
  and opt-outs; temporary and sender failures preserve address eligibility.
- Already sent mailto emails need legacy inbox request reconciliation; the new
  links work without SendGrid inbound domain configuration. See
  `docs/operations/automatic-email-unsubscribe-and-weekly-recap.md` for validation,
  historical request handling and the release contract.


## 2026-10-08 — Carolina Heritage theme (development)

- Carolina Barrel's connected Website editor has a theme selector with Carolina
  Original, Carolina Heritage (Wine & Oak), and tenant-owned saved designs.
  Applying a style saves the complete previous draft; preparation also saves the
  previous published design. Switching and restoring remain draft-only until
  publication, with stale-write, membership, tenant, and publish gates intact.
- Theme identity is versioned with connected content and returned for both public
  snapshots and signed private previews. Existing snapshots default to Original.
  Product/catalog/order data and commerce activation are independent of themes.
- The separate renderer retains wholesale and affiliate applications, adds a
  wine-and-oak presentation with maker photography, an accessible rotating hero,
  mobile navigation, and session referral propagation to quote requests.
- Automated commissions, affiliate payouts, wholesale customer login, and LTL
  checkout remain subject to their existing readiness; a style does not activate them.


## Fleet operations and demo (2026-10-08)

CRM Fleet adds crew/job context, connection health, trips and route-distance
review, maintenance plans/tasks/history, vehicle health alerts and dispatcher
suggestions. HighLevel calendar or pipeline references supply work; Everbranch
owns fleet planning and never requires or creates an Everbranch job. Source
reads are optional and require three additional read scopes. Encrypted operation
records and retained GPS stay tenant-scoped and obey collection/policy gates.
`/crm/fleet/demo` and Explore demo provide fictional browser-only examples of
all features. Google Maps uses the restricted fleet key when configured;
Leaflet/OpenStreetMap supplies a street-map fallback. No pilot collection is
activated. See `docs/operations/highlevel-fleet-runbook.md` for precise baselines,
manual confirmations, retention, dispatch criteria and provider prerequisites.


For the paired Everbranch Field 2.3.23/build 37 release, current tenant members can access and edit every current job and clock job time without a lead assignment. Customer fields remain restricted to managers, and on `collins-electric` only the verified active `collinselectric91@gmail.com` account may access the client list or edit customer fields. Team members can move jobs to an admin recycle bin; only managers can list and restore them. Job Notes mentions send a direct team message with a job reference. Team reporting contains week/month/year job counts and no financial amounts. These rules supersede the older assignment-scoped notes below.

## Tenant mailbox rollout (2026-10-06)

- Email Branch includes pending customer address signup at `/mail/setup`, a tenant-scoped inbox at `/mail`, explicit grants, and SendGrid/direct transport paths. Follow `docs/operations/everbranch-mail-service.md`; creating an address does not prove DNS or delivery.
- Cloudflare quick setup uses a one-time zone-scoped token and preserves an existing MX. Direct SMTP/JMAP remains disabled until the dedicated host, DNS, backups, monitoring, and end-to-end tests are ready.
- Stalwart Community has no native tenant boundary. Everbranch tenant/module/membership checks remain mandatory; mail-server administrators are privileged across tenants.

Everbranch Field iOS and Android share one mobile source repository. Mobile Job Notes may send `attachment_asset_ids` for PDFs already uploaded in guarded chunks. Before linking one to a note, validate the current tenant, job, uploader, team visibility, PDF MIME type, and absence of a prior note association. Both native apps rely on this contract to show the PDF in the note thread.

Material request comments and photos use existing job notes and private workspace assets with `field_service_material_id` metadata. Keep material writes tenant-, job-, and assignment-scoped through `FieldServiceAccessService::canUpdateProgress`; never let a client supplied material ID select a different job or workspace. `materials[].comments/photos` in mobile job detail are derived only from assets and notes already visible to that viewer.

General field time does not require a job. Keep `field_service_job_id` nullable through clock, hours, correction, and audit paths, while enforcing assignment for job-specific starts. Team message APNs uses active tenant memberships and channel visibility; never send message bodies in lock-screen alerts. The mobile Notifications section controls `tenant_member_preferences.team_message_notifications` for each workspace.

For mobile team access, list only active `tenant_user` memberships. Every workspace data route must keep the `mobile.tenant` membership gate. The web teammate invitation form must identify the destination workspace because administrators may belong to several tenants.

## Free CRM Fleet preview (2026-10-07; collection still gated)

Everbranch Fleet is temporarily free, with 100 selected vehicles per client.
`HIGHLEVEL_FLEET_SUBSCRIPTION_REQUIRED=false` is now the default; Marketplace
pricing must also be Free. Installed accounts can authorize Bouncie, choose
vehicles and save their policy without a payment event or pilot activation.
Collection still requires the existing global tracking gate, collection switch,
location allowlist, tenant entitlement and approved policy. No pilot is enabled
by this release. Uninstall still revokes sessions and stops collection.
The prior paid plan and payment history remain available for a future pricing
change. To restore subscriptions, configure/publish the paid Marketplace plan,
verify billing, and explicitly set the subscription requirement to true.
See `docs/operations/highlevel-fleet-runbook.md` for details.

## Private CRM Fleet integration (2026-10-04; pilot disabled)

Everbranch Fleet adds a private HighLevel installation layer at `/crm/fleet`,
with one new tenant per client location, encrypted provider grants, verified
CRM administrator bindings, cookie-independent short-lived sessions, native
HighLevel subscription authority, durable signed lifecycle webhooks, and
Bouncie device selection limited to 25. Collection remains disabled until
native billing and the two-client pilot are verified; `config/highlevel.php`
contains the separate surface, billing, collection and location allowlist gates.
Standalone Fleet/Bouncie routes remain available. The embedded app exposes
Fleet, Connection and Settings only. See
`docs/operations/highlevel-fleet-runbook.md` for onboarding, acceptance, support
and safe rollback; existing production hosting and CI/Forge deployment are used.

Production release `f53af086` passed GitHub gates and deployed through Forge.
The developer portal shows private version 1.0.0 live, with no installations.
Collection/billing verification remain off and the pilot allowlist is empty.
Nginx limits CRM embedding to `/crm/fleet`; `/shopify/app` retains its Shopify
frame policies. Queue reservations are 180s for the existing 120s scheduled
worker. `/crm/fleet/guide` provides setup and disconnect instructions. The additional Bouncie callback and an inactive trip webhook are registered.
The restricted browser map key, payouts and live pilots remain launch prerequisites. See the runbook for production evidence and config-cache
release ID preservation.

## Modern Forestry BSF Fundraising tab (development, 2026-10-02)

- Shopify embedded fundraiser POST actions rely on a verified App Bridge
  bearer token and are exempt from iframe CSRF state. The page uses the same
  Shopify frame response policy as the rest of the embedded app. Purchased Shopify
  products appear with quantities on orders, in the review queue, and in
  package review. Invoice delivery is controlled to
  `info@theforestrystudio.com`; the live QuickBooks company must identify as
  Modern Forestry and the Backstage connector owner must be
  `johncollinsemail@gmail.com` before any invoice write or payment-link read.
- Fundraising in the verified retail Shopify app reads BSF-tagged imported
  orders. The first-of-month job queues approved prior-month invoices only;
  QuickBooks creation and sending require separate staff clicks. The verified
  production mapping enables these controls through GitHub deployment, while
  non-production defaults stay off. Create makes a non-payable QuickBooks draft;
  Send enables ACH/card, verifies the live customer link, and emails the payer.
  The card-surcharge setting cannot be verified through Everbranch. Actual
  purchased-label costs are pulled by exact Shopify
  order ID from ShopifyQL `shipping_labels` when available; staff reviews and
  approves each order. Missing reports require a receipt-backed manual entry.
  Never equate purchased-label cost with Shopify checkout shipping.
- Existing August `BSF-AUG-2026` is already in QuickBooks and is excluded from
  automated backfill. The new encrypted-storage repair migration must run
  before the hourly detector writes fundraiser queue records. See the
  fundraiser order invoicing runbook for the full safety contract.

## Historical public launch-partner pricing (2026-10-13; superseded for new offers)

- Historical public offer (superseded for new public acquisition by the released Simple offer at the verified checkpoint above): the Evergrove/Everbranch public offer was $499 setup, $89/month for six months, then $149/month. At publication, it applied only to offers made under that historical offer. The first year totals $1,927 before taxes or add-ons. Preserve earlier client-specific agreement versions and subscription rates, including Collins Electric and Front Yard Foods; do not reprice their drafts to apply this public change. See `docs/operations/everbranch-billing-readiness-audit.md` for the public-pricing release scope.

## Collins employee install release (2026-09-30)

- Keep Collins' operational job browsing broad, but scope employee My Day and clock-in to assigned jobs. Financials, manager hours, and employee role changes stay server gated.
- The native group composer needs `/field-service/channels/group` and the active teammate list. Release backend changes through GitHub/Forge before pairing a development phone build. Follow the Collins phone smoke in `docs/collins-electric-access-and-quickbooks.md`.
- The Messages People directory uses the active teammate list and membership role from `/field-service/channels`; keep direct and group creation restricted to active tenant members.

## Modern Forestry operator sales summary (2026-09-30)

- `/api/mobile/v1/landlord/bootstrap` may expose the read-only
  `modern_forestry_sales` object only after landlord authorization. It resolves
  the exact `modern-forestry` tenant and reads that tenant's imported Shopify
  retail/wholesale orders; it never accepts a caller-supplied tenant or store.
- Treat the figures as gross operational orders and recorded refunds, never as
  Shopify payouts, cash received, profit, or accounting net income. Preserve the
  source/basis fields, fail closed for mixed currencies or missing evidence, and
  do not copy these figures into another ledger.

## Jarvis customer-acquisition read rail (2026-09-30)

- Landlord bootstrap may return `customer_acquisition` from the existing
  `landlord_prospects` pipeline. Keep it read-only, landlord-authorized, bounded,
  and free of prospect email, phone, communication-body, or provider snapshot
  data. The summary is context for prioritization, not authority to act.
- Never turn a Jarvis conversation into automatic Places discovery, outreach,
  booking, conversion, or onboarding. Preserve the existing paid-discovery
  confirmation, explicit send/review controls, provider readiness checks,
  tenant creation gates, and audit trail.

## Evergrove landlord revenue rail (2026-10-01)

- `landlord_revenue` is Evergrove Software's landlord receipt evidence only.
  It uses Stripe-confirmed Everbranch billing receipts and excludes every
  client's tenant commerce. Never blend it with Modern Forestry sales or other
  tenant earnings.
- Keep verified cash, refunds, recurring line items, one-time line items, taxes,
  and catalog-derived recurring run rate distinct. Unknown or mixed-currency
  evidence fails closed; do not invent an allocation or relabel run rate as cash.

## Shared website catalog administration (2026-09-21)

Products, Collections, Customers, and Orders have separate workspace destinations
for every tenant with Managed Website access. Products use dedicated editors with
photo uploads/previews, variants, cents-based pricing, inventory and organization.
Collections use tenant-owned `website_collections` and membership records. Active
catalog edits are independent of static-page drafts and publishing. Carolina's
connected renderer consumes these records; publishing pages never reimports or
resurrects catalog products. Customer detail editing requires editor access but
not checkout activation. Payment/fulfillment gates remain in force. See
`docs/operations/website-catalog-admin.md` for release and rollback.


## Carolina Barrel connected website (2026-09-21)

Carolina Barrel's existing Cloudflare site now has an opt-in Everbranch content
editor, versioned draft/preview/publish/restore controls, an independently editable quote-product catalog,
and workspace inquiry capture. Its existing renderer and public domain remain in
place. Other sites retain the generic editor. No accounts, payments, or entitlements
are activated by this integration; quote emails are explicit staff responses. See
`docs/operations/connected-carolina-website.md` for release, gates and rollback.

Public Carolina Barrel catalog products are quote-only and their anonymous catalog
response omits retail and variant price values. Quote requests require name, email,
phone, and quantity; authorized staff handle them in **Orders → Quotes**.
Replies are explicit staff sends, and attribution requires both the normalized email
and phone to match a tenant-owned Website customer before a later Website order can
be linked. Keep this flow in Website Commerce and form submissions—never legacy,
Shopify, or marketing records.



## Website client workspace focus (2026-09-21)

- Access-profile metadata `workspace_focus: website_sales` opts a tenant into
  the same website/sales menu for its client and operator. Navigation, dashboard,
  branch discovery, and search follow this focus; personal finance, legacy
  shipping, production, marketing administration, and unrelated branch prompts
  are omitted. Existing module and role authorization still applies.
- This is a presentation setting, not impersonation or a billing/entitlement
  change. Other workspaces and standalone Trajectory access stay unchanged.
  See `docs/operations/website-client-workspace.md`.

## Client project launch checklists (2026-09-21)

- Tenant logins now expose a two-owner launch checklist at
  `/client/projects/checklist` when a client project opts in through metadata.
  It reuses client-project tickets/tasks, enforces active membership and
  ownership, and audits completion/reopen without activating business services.
- Import reviewed tenant content with `client-projects:import-checklist`;
  preview is default and replay preserves existing progress. See
  `docs/operations/client-project-checklists.md`. Imports send no notifications.


## Trajectory financial outlook charts (2026-09-19)

The main dashboard now pairs the daily cash outlook with monthly expected-income,
income-needed, and reviewed-history lines. `outlook.js` consumes existing scoped
forecast and planning payloads; the monthly income plan remains distinct from daily
bill timing. `chart-theme.js` provides shared accessible HTML legends, light tooltips,
and compact currency axes. `chart-series.js` joins projection paths to the last
observed cash point without adding observed records. Currency remains integer cents.
Looking Ahead leads the dashboard. Separate Trend lines, Month by month, and
Actual vs. projected tabs preserve distinct balance and income-rate semantics.
The dashboard opens on Month by month Income runway: projected income from matching dates
last year, an explicit prior-year line, and expected cash-outflow bars. Users can
compare the current income plan or recent reviewed income without silently
changing the daily cash forecast. Cash balance remains a separate monthly mode.
Monthly cash bars use closing observations/estimates, never sums of balances;
missing observations stay null. The monthly unexpected-expense card ranks scoped,
posted Face Punched charges and links to their evidence.
See `docs/operations/trajectory-runbook.md` for chart validation.

## Fleet map Bouncie recovery (2026-09-16)

- Mobile crew-map reads now refresh the connected tenant's mapped company-vehicle positions from Bouncie at most every 30 seconds, with a tenant lock and safe connection-health metadata. Existing positions survive provider outages, subject to the configured retention window. Snapshots retain the provider timestamp and are identified as last-known positions, never a new GPS fix.
- Bouncie `tripData` webhooks ingest each `data[].gps` sample using its own timestamp; overlapping deliveries deduplicate by device, timestamp, and coordinates. Invalid, future, expired, unmapped, ambiguous, inactive, and unapproved points are ignored. No provider payload, speed, token, or coordinates enter diagnostic logs.
- The iPhone map improvements are shipped from `everbranch-mobile` separately. See `docs/operations/field-operations-location-tracking-runbook.md`.

## Wholesale application reliability (2026-09-15)

Wholesale applications now commit a tenant-owned request, form mirror, and durable
review-notification state before returning a receipt. The minute scheduler retries
failed review/decision emails and the detail page shows delivery status. Review
email defaults to info@theforestrystudio.com; links launch the wholesale Shopify
Admin app. Approval requires a successful Shopify wholesale tag sync and never
activates an internal user or grants tenant membership. Buyer emails use Shopify
account activation/login, not Everbranch password setup. See
`docs/operations/wholesale-application-reliability.md`.
## Trajectory transaction review email (2026-09-17)

Private review reminders use `ReviewEmailService`, `SendReviewEmail`, and Laravel's transactional mail transport. Each user opts in per finance space from Accounts → Email reminders; daily or Monday weekly delivery runs around 9 AM in their selected timezone. The scheduler checks every 15 minutes. Empty queues send nothing. Other recipients stay off by default; changed recipient addresses require fresh opt-in. Jobs carry IDs only and recheck verified identity, finance membership, entitlement, preference, and current queue at delivery time.

Preferences and delivery claims/results are encrypted Trajectory audit events. A finance-space row lock serializes period claims; ambiguous provider errors and interrupted jobs retain their claim to prevent duplicate mail. `accepted` means provider handoff, not confirmed inbox delivery. `trajectory:status` exposes aggregate uncertain/unfinished email counts. Log, array, and failover transports are blocked for these messages.

## Trajectory live-source coverage reconciliation (2026-09-18)

Plaid is active from its first transaction for a uniquely matched household account; Monarch fills only the earlier gap. `SourceCoverageReconciliationService` excludes overlapping Monarch rows without deleting encrypted source evidence, retains a reversible audit marker, and leaves unmatched accounts, statements, and cross-account transfers alone. Use `trajectory:reconcile-import-sources` to preview, apply, or restore; future Monarch imports use the same idempotent policy. See `docs/operations/trajectory-source-coverage-reconciliation.md`.

## Trajectory standalone experience and evidence imports (2026-09-14)

Trajectory has its own public `/trajectory/welcome` page and full-width authenticated `/trajectory` navigation. It reuses Everbranch login, finance spaces, financial permissions, and entitlements; the Back to Everbranch link does not change financial access. Personal-only use remains independent of company books. Checkout remains disabled until configured prices and lifecycle readiness pass.

The dashboard includes subscription evidence, upcoming loan payments, account cash history, historical month comparison, dated budget versions, receipts, and interest by account. Imported cash history is not net-worth history. Unknown card terms leave purchases on the card liability and do not invent cash payments or APRs. Monthly and actual/365 biweekly debt calculations retain decimal rates; separate mortgage withdrawals expose the suspense/allocation assumption.

Trajectory now opens with Personal, Business, and, when both authorized spaces exist, Both controls. Both is a reconciliation-aware overview and a cross-space transaction-review queue; it does not combine income or spending until the spaces are linked. The Review transactions destination surfaces up to 250 outstanding imported items across history so users can categorize them without changing the reporting period. Detailed planning remains scoped to Personal or Business.

`trajectory:import-household` imports private Monarch history, optional Chase CSV and Monarch balance history, dated account observations, budgets, receipts, and reviewed financial records. It requires an existing verified household owner and entitlement. The default executes validation inside a rolled-back transaction; `--apply` commits atomically. Import identities, audited evidence corrections, and record replay markers preserve subsequent user corrections. Keep every real input, receipt, screenshot, database, and financial export outside git and public fixtures. See `docs/operations/trajectory-runbook.md`.


## Level Foundations transcription bridge (2026-09-07)

- The separately hosted Level Foundations editor may use only the default-disabled, bearer-authenticated transcription bridge described in `docs/operations/level-foundations-transcription-runbook.md`. It is a narrow integration exception, not tenant access or a general Bud AI release.
- Keep provider credentials in Everbranch runtime configuration. The Level Foundations deployment holds only the bridge URL and a dedicated shared integration token. Do not persist or log audio or transcript content, and do not reuse this integration token for any other caller.

## Mobile manager timecards and material requests (2026-09-03)

- `/api/mobile/v1/workspaces/{tenant}/field-service/time-clock-hours` is owner/admin/manager-only and requires the canonical `time_tracking` entitlement. Keep ranges bounded, the unified timer/manual ledger paginated, editor choices tenant-scoped, and all completed-entry corrections validated and audited. Do not turn its duration analytics into payroll or overtime calculations.
- Completed timer edits update the reviewed session aggregate only. Preserve `field_service_time_breaks` as immutable punch evidence, include their totals in the edit audit snapshot, and reject edits to running/paused timers.
- Manager My Day material cards are a bounded preview, while `counts.material_requests` must use a separate exact tenant-scoped count. Material-request deletion is manager-only, limited to field requests, and requires audit evidence; it must never delete inventory or imported QuickBooks material rows.
- Full mobile job edits must validate active tenant assignees/participants, tenant vehicles, and `scheduled_end_at > scheduled_for` using the resulting stored values. Audit meaningful before/after state, but never place a lock-box code in audit payloads.

## Field Service address search (2026-09-02)

- Job-detail address autocomplete is an admin/manager-only Field Service feature. It uses the server-side Google Places key and only tenant-authorized endpoints; do not expose the provider key to the browser. Provider/key failures must preserve normal manual address entry.

Read `SYSTEM_SNAPSHOT.md` before making changes.

## Shopify state sales tax reporting (2026-08-20)

- Embedded **Sales Tax Reports** is a tenant-scoped, read-only reporting
  surface. Its State Sales Tax Summary and State Sales Tax Detail presets use
  imported Shopify delivery-address data only; they must never infer a county
  or local tax, decide taxability, submit a return, make a payment, or mutate
  Shopify/Square/QuickBooks data. See
  `docs/operations/shopify-state-sales-tax-reporting-runbook.md`.

## Modern Forestry fundraiser invoice preparation (2026-08-19)

- The verified Modern Forestry retail Shopify Settings page has a
  configuration-only Fundraiser Order Invoicing card. It stores payer/contact,
  cadence, terms, source-shipping, and tax-review posture; its default internal
  notification address is `info@theforestrystudio.com`.
- Its token-protected Zapier order intake is a dedicated encrypted,
  duplicate-protected manual-review queue. It must not be treated as an active
  commerce or payment lane: QuickBooks invoice creation/delivery, collection,
  tax decisions, and email-open tracking remain disabled until the requirements in
  `docs/operations/modern-forestry-fundraiser-order-invoicing-runbook.md` are
  satisfied. Never route the flow through legacy orders, Shopify Checkout, the
  Website commerce lane, or the platform's direct-invoice system.
- The monthly command runs at 9:00 AM America/New_York on the 1st and processes
  the prior calendar month. It only packages already approved Zapier orders.
  QuickBooks writes and sends require separate production gates and exact
  customer/item mappings; a scheduled command alone is not proof of delivery.

## Modern Forestry embedded app base (2026-08-19)

- Shopify's live Modern Forestry Backstage App URL is the Everbranch root.
  Preserve the signed, retail-surface-protected root compatibility entries for
  `/settings` and `/product-options` alongside canonical `/shopify/app/...`
  routes. The root settings entry must inject the full current settings
  controller dependency set; otherwise Shopify Admin shows a 500 in its iframe.

## Modern Forestry product options and bag reminders (2026-08-13)

- Treat `ShopifyProductOptionsService` and its product-handle assignments as the
  only Everbranch authority for bundle scent selectors. A handle has one active
  ruleset; ambiguous mappings fail closed. The Apple bundle has fixed scents
  and must remain unassigned. The mobile catalog must not infer selectors from
  a bundle title/tag; checkout rejects stale `Scent N` attributes for an
  unassigned fixed bundle.
- The legacy Shopify Infinite Options app is external, read-only comparison
  state. Do not disable, uninstall, or edit it without explicit owner approval.
- `ModernForestryMobileBagReminderService` completes matching bag snapshots
  only from a paid, non-cancelled Shopify order. Stale cart synchronization must
  not restart completed reminders.

## Shopify bundle option checkout rule (2026-07-30)

- Required bundle scent choices need both layers: the theme app extension
  collects Scent 1 through N, while the
  everbranch-bundle-scent-validation Shopify Function blocks standard and
  express checkout when they are missing.
- Keep Product Options tenant-scoped. Ruleset assignment changes must
  synchronize the Everbranch bundle scent rule metafield on the assigned
  Shopify product; use the previewed product-option-validation sync command
  for backfills.
- Do not rely only on product-form submit events. Shop Pay and other
  accelerated paths require Shopify's server-side Cart and Checkout Validation
  Function.

## Everbranch public-site redesign boundary (2026-08-07)

- The public marketing redesign is limited to the canonical Everbranch pages:
  `/`, Plans, demo/start access, Contact, and the public Module Explorer. Keep
  it separate from tenant websites, authenticated workspaces, Shopify apps and
  embeds, Modern Forestry commercial flows, pricing source data, and the
  access-request handler.
- Public media must be rights-tracked and customer-safe. Product captures use
  demo/anonymized data; sound-on film requires captions and a transcript. Use
  `docs/operations/public-site-media-runbook.md` and the public Playwright
  checks before release.
- The six home-page industry cards open fictional, linkable website-to-
  workspace presentation pages. Their persistent control bar lets a visitor
  return to Everbranch, choose a business type, and switch between Website and
  Operations workspace. They may show workspace, messaging, email, and
  consent-safe text-marketing states, but must never send, store, publish,
  resolve a tenant, create a customer, or call Managed Website, Shopify,
  messaging, or marketing APIs. Keep the controls keyboard accessible and
  switch Website / Operations workspace views immediately; a brief entrance
  transition may not delay controls or content.
- For a public-site interaction change, run `npm run test:visual`,
  `CLICK_PATH_CONFIG=tests/e2e/click-path-public-routes.json npm run qa:click-path`,
  and `npm run test:lighthouse:public` in addition to the normal PHP checks.
- The home-page Everbranch story film is a lazy-loaded, silent-first public
  asset. Keep its Remotion scenes deterministic and public-safe; real tenant,
  customer, Shopify, and message data is never permitted in the render. Its
  stock-photo provenance is recorded in
  `docs/operations/everbranch-story-photo-sources.md`. The direct-link surprise
  route is intentionally unlinked and `noindex`; it switches after four seconds and may
  embed the official YouTube upload but must never copy or host the music video.

## Everbranch mobile customer administration (2026-08-08)

- The cross-tenant native app lives in `../everbranch-mobile`. Its compact charcoal command bar and dense operational screens follow `docs/ui/UI_SYSTEM.md`; do not copy public marketing typography into the work app.
- Workspace visibility comes only from authenticated memberships returned by `/api/mobile/v1/workspaces`. Collins Upstate Electric must remain discoverable for explicitly attached users, but the app must never hardcode its slug, tenant ID, or operator email.
- Customer create/update/delete are live-only, tenant-scoped, audited owner/admin actions. Delete must stay limited to app-created profiles with no connected provider, work, message, consent, delivery, reward, birthday, or group history. Client permission flags never replace the server gate.

## Modern Forestry production guard (2026-08-06)

- The verified production baseline is documented in
  `docs/operations/modern-forestry-production-guard.md`. Run
  `composer test:modern-forestry` for every change that could affect Modern
  Forestry retail/wholesale Shopify, Candle Cash, birthday data, rewards,
  customer account/mobile, or embedded search/navigation. The full CI and
  production deployment gates run the entire Pest suite as the enforcement
  layer; no direct production deployment bypass is permitted. The 2026-08-06
  audit found and remediated a live embedded-launch regression: Shopify now
  signs `admin_theme`, so it must remain part of
  `ShopifyEmbeddedContextQuery`; the verifier also checks future signed scalar
  metadata, and wholesale uses its current Developer Dashboard client secret
  in production. Verify both Shopify Admin launches after every release or
  credential rotation.
- Retail and wholesale are separate Shopify embedded surfaces. A verified
  wholesale session must redirect from retail HTML and receive `403` from
  retail APIs/mutations; a retail or mixed store cannot open wholesale
  operations. Preserve `EnforceShopifyEmbeddedSurface` and its regression
  coverage.
- Candle Club subscriptions are intentionally pre-cutover. Modern Forestry has
  access to the mirror and staff workspace, while `tenant_module_states` is
  deliberately absent/default setup-needed and
  `subscription_module_settings.billing_scheduler_enabled` is false. Customer
  and staff subscription changes are recorded as auditable intents only until
  an explicitly approved Shopify/Recharge cutover. Do not force the module to
  configured, enable the scheduler, or treat an intent as a Shopify mutation.
- Legacy points/Candle Cash and birthday records are production data. Preserve
  the compatibility layer and use its read-only checks before any retirement
  migration; do not reset its observations as part of routine verification.
- For Modern Forestry retail, the audited tenant birthday override is a $10
  discount code with a 30-day post-birthday window. Do not infer the retail
  offer from the global legacy 50 Candle Cash fallback. The daily birthday
  issuance command must scan the entire tenant cohort; its limit caps eligible
  rewards processed, not the first profiles selected.
- The 2026 retail catchup uses `marketing:backfill-birthday-coupons` after an
  owner-approved email preview. Preview it without `--execute`; process only
  consented profiles, skip existing annual rewards, and retry failed email
  events deliberately with `--retry-failed`. See
  `docs/operations/birthday-coupon-catchup-2026-10-02.md`.

## Managed Website guardrails (approved contract; not yet enabled)

- The reusable `managed_website_launch_partner` agreement is tenant-named and
  authorizes Stripe-direct recurring billing only after exact-version client
  acceptance. Its default founder schedule is $299 onboarding, $89/month for
  six billing cycles, then $149/month beginning with cycle seven. The contract
  states the included monthly user, photo/content, and revision limits; do not
  replace it with the client-specific Collins Electric or Front Yard Foods
  templates.

- `managed_website` must remain default-disabled and use
  `config/module_catalog.php`, the canonical access resolver, verified/audited
  fulfilment, and an explicit rollout allowlist. Never infer access from a
  billing screen, agreement, host, or provider connection.
- Add only tenant-owned website/site/page/version/section/navigation/media/
  redirect/publish-event records. Drafts and immutable published snapshots must
  remain separate; publishing and rollback may update only the relevant site
  pointer/cache keys.
- Modern Forestry's separate Shopify app and Shopify Checkout are hard
  exclusions. Do not alter or call their routes, UI, credentials, app config,
  checkout, customer account, webhooks, orders, customers, rewards, imports,
  provider connections, or workflow cursors. Modern Forestry is not a pilot.
- Retain four independently auditable fail-closed gates: global availability,
  tenant rollout allowlist, editor/publishing freeze, and Managed Website
  public-render disablement. The normal rollback preserves the last good
  snapshot; suspected isolation/security incidents disable only Managed Website
  hosts and use the rollback runbook.
- Theme-level settings, navigation, footer, and announcement content belong to
  immutable `tenant_site_versions`; never allow a draft theme setting to affect
  a published host. `tenant_site_media` is a public-site-only tenant media
  library and must never expose job, field-service, customer, or workspace files.
- First-time Website setup must support an entitled tenant before a site record
  exists and must use an explicit approved starter-theme choice. Never assume a
  trade, business type, or tenant-specific theme in the shared setup path.
- Website editor canvas links must never resolve against the Everbranch app
  root. Canvas clicks select structured controls; customer-link testing uses an
  authenticated, no-store draft-preview route whose internal destinations are
  server-resolved to the current tenant site and whose toolbar returns only to
  the matching editor page.
- `theeverbranch.com` is platform-only. Never allow the canonical public host
  to fall through to `host_tenant`, a flagship tenant, or a Managed Website
  renderer. This is a permanent regression boundary: a tenant's published
  Website may render only on its approved tenant subdomain or an explicitly
  active `tenant_site_domains` hostname.
- Customer domain setup stores only a tenant-owned hostname and encrypted
  one-time DNS proof. It must use independent global, tenant, and activation
  gates; DNS ownership verification alone never activates a public host. Do
  not store registrar credentials or change unrelated DNS records.
- An active custom Website hostname is public-render/form-only. It must not
  become a second app, login, landlord, API, Shopify, webhook, or workspace
  origin, and it must not share the `.theeverbranch.com` application session.
- V1 forms create only tenant-scoped submissions. Do not create customers,
  send messages, modify marketing audiences, trigger workflows, or process
  Shopify/Square/Stripe/booking orders. Existing app, checkout, customer
  account, webhook, and public route ownership must resolve before website host
  rendering; unknown hosts fail closed.
- Required reference: `docs/architecture/managed-website-architecture.md`,
  `docs/operations/managed-website-operations-runbook.md`, and
  `docs/operations/managed-website-rollback-runbook.md`.

## Website Commerce isolation rule (2026-07-27)

- Sawyer Naturals demo setup and the default-off Pirate Ship spreadsheet bridge are documented in `docs/operations/sawyer-naturals-demo-and-pirate-ship.md`. Keep the bridge in the tenant-owned Website lane; it is a manual CSV handoff, not a Pirate Ship API or Shopify fulfillment integration. The commerce preview tenant allowlist opens only catalog/cart; checkout remains fail-closed behind the global commerce gate and payment readiness. Do not enable checkout or public launch merely because the seed command ran.

- Native Website Commerce tables are named `website_*` and are the only data
  lane for Website catalog, cart, shopper, order, payment, inventory, and
  fulfillment behavior. Never reuse, join against, backfill, or write to the
  legacy `orders`/`order_lines` tables or Shopify catalog/customer records.
- Multi-channel reporting may read normalized, tenant-scoped summaries from
  independent channels, but it must never merge records or make Website sales
  a legacy order. `SalesChannelSummaryService` is read-only: Website payments
  count only after confirmation and provider channels retain their own source
  of truth, customer records, checkout, and operations.
- Stripe Connect is the regulated payment processor only. Server-side product
  snapshots determine checkout amounts; signed, idempotent Website Stripe
  webhook events determine payment success. No client price, tenant, order, or
  payment status may be trusted.
- Website public shopping routes must resolve the tenant from the verified host
  and published Website snapshot. Admin routes resolve the active tenant with
  `tenant.access` and the `managed_website` module guard.
- The tenant application sidebar exposes **Products** immediately below
  **Website** for the same Managed Website entitlement; it is not an independent
  module or billing gate. Product CRUD, optional wholesale pricing, image URLs,
  CSV import/export, and archival must remain generic and site-scoped.
- Catalog CSV import matches normalized handles, is transactional, and is
  limited to 1,000 rows. Invalid rows roll back the full file. "Delete" in the
  UI is history-safe archival: keep product, variant, and order references, set
  the product archived, and make its variants unavailable.
- Native retail operations use dedicated Website tables for fulfillment
  locations, package presets, rate quotes, fulfillment lines, shipments, an
  immutable shipment-event ledger, and staff order events. Order lifecycle,
  financial, fulfillment, and shipment state are separate; keep customer,
  address, line-item, and price snapshots immutable. Do not turn a native
  Website refund, cancellation, label, or tracking event into a legacy or
  Shopify record.
- EasyPost is available only through a tenant-owned IntegrationConnection,
  the dedicated shipping gate, and a shipping tenant allowlist. It is
  US-domestic only. Never reuse Modern Forestry's shipping account or routes.
  EasyPost webhooks require a valid signature and are idempotent before they
  can change a native shipment.
- Connected-store migration is a separate, read-only commerce lane for
  Shopify, WooCommerce, Squarespace, and Wix. Provider adapters normalize
  stable IDs before persisting encrypted source snapshots. The mapping wizard
  must explain unavailable provider content, never silently fabricate it, and
  never write to native Website, legacy Shopify, Modern Forestry, or marketing
  tables. Imported consent remains source evidence only.

## Accounting Command Center guardrails (2026-07-23)

- Keep `accounting_command_center` reusable and tenant-scoped. Modern Forestry
  is a preset and first rollout target, never a runtime branch condition.
- QuickBooks P&L/report snapshots are the ledger truth. Shopify, Square, and
  event sources support classification and reconciliation only; never add their
  sales to QuickBooks revenue.
- Every accounting route and payload must pass both module entitlement and
  `TenantFinancialAccess`. Never place accounting amounts or metadata in member,
  employee, or general mobile payloads.
- Missing or unreviewed mappings remain unavailable; do not substitute zero,
  invent tax deadlines, infer workbook formulas, post entries, or write back to
  QuickBooks.

## Release and Documentation Rule (2026-07-21)

- Treat `SYSTEM_SNAPSHOT.md` as the concise map of current product structure,
  tenant boundaries, live operational surfaces, and release state. Update it
  when a meaningful system capability or production operating rule changes.
- Keep `README.md`, `README_FOR_AGENTS.md`, `AGENTS.md`, the relevant runbook,
  and `docs/ui/UI_CHANGELOG.md` in sync when their audience needs the change.
  Do not leave a newer operating rule buried only in a pull-request description.
- Production now has a Forge zero-downtime site with shared `storage`, retained
  releases, and a `/ready` health check. The GitHub test/build gate is still
  mandatory. Its post-gate production action is a Forge deploy-hook POST using
  the protected `FORGE_DEPLOY_HOOK_URL` production secret; Forge then performs
  the atomic release. The first automatic hook release was Forge release
  `73789933` for commit `c272464…` and passed `/ready` on 2026-07-21.
- Treat every additive migration as restart-safe, not merely non-destructive.
  A release may complete early DDL before Laravel records its migration batch.
  Add a MySQL recovery test that reconstructs that partial state and make each
  independent schema step safely resumable. Never delete a production table to
  force a retry. The pending-release MySQL recovery cases for Customer Loop and
  Commerce are the reference. Use explicit short names for indexes and foreign
  keys that could exceed MySQL's 64-character identifier limit, and keep
  composite `utf8mb4` keys below MySQL's 3072-byte InnoDB limit.
- Migration safety is an always-on production job, including an emergency
  dispatch that skips the broader test/build job. It lints changed migrations,
  rejects edits to released migrations unless an exact before/after checksum
  pair declares a narrow clean-install compatibility repair, requires guarded table creation and
  short MySQL identifiers, runs registered partial-state recovery tests, and
  rehearses the prior commit's schema upgrade on MySQL 8.4. Multi-step
  migrations must be registered in
  `tests/Integration/migration-recovery-manifest.php`. See
  `docs/operations/migration-safety-gate.md`.
- Forge API observability is implemented as an optional **read-only** failure
  diagnostic. When exact-SHA `/ready` verification fails, GitHub may query the
  current Forge API for `latestDeployment` and print an allowlisted commit,
  status, and timestamp summary. The script performs GET only and cannot
  create a deploy, reset status, change site settings, or replace `/ready`.
  Credential creation and least-privilege scope selection remain an explicit
  operator task.
- Release observability also includes a configured operations-webhook alert,
  nightly data-free `schema:fingerprint` drift checking, a reviewed MySQL
  schema baseline, and a deliberately isolated backup/restore drill script.
  Follow `docs/operations/release-observability-and-recovery.md`; never put a
  production backup, database credential, Forge token, or alert webhook in the
  repository.
- Never run `git reset --hard`, `git clean`, in-place frontend replacement, or
  cache-clearing as a normal production deploy. The approved Forge runbook uses
  a fresh release, build/test preparation before activation, compatible
  migrations, atomic activation, then queue restart. Retain an audited
  emergency path only.
- CI is deliberately split by purpose. Pull requests run one full PHP 8.4
  asset-build/test gate plus the MySQL migration-safety job; superseded runs
  are canceled. On `main`, the deploy workflow can reuse those checks only
  when GitHub proves the merged commit has the exact same tree as its merged
  PR and the named `quality`, `ci (8.4)`, and `mysql migration recovery` checks
  passed. Direct pushes, unverifiable merges, and manual standard releases run
  both gates again; schema/migration-related changes always repeat the MySQL
  rehearsal on the merged release. Composer and npm downloads are cached and
  Node installs use `npm ci`.
- Default delivery preference: when a scoped feature is complete and its
  protected GitHub checks pass, merge it to `main` and allow the normal Forge
  release to deploy it. Keep a change on a feature branch only when John asks
  for review-only handling, a release gate fails, or a customer-data/safety
  concern requires a deliberate hold. Never bypass the CI/Forge gate.
- Local verification for a scoped change is
  `composer exec pint -- --dirty --test`, followed by
  `php -d memory_limit=1G ./vendor/bin/pest --parallel --compact`. The parallel
  suite is the supported fast path. Do not bulk-format inherited style debt or
  stage unrelated rewrites. Check Actions at coarse milestones instead of
  continuously polling it; canceled superseded runs are expected.

## Operator Alert SMS Guardrails (2026-07-25)

- Use `App\Services\Operations\OperatorAlertService` for operator SMS alerts.
  Do not call Twilio directly for landlord/operator texts.
- Operator texts must describe real production activity. Test fixtures,
  sandbox-validation agreements, demo/sandbox/test tenants, localhost/`.test`
  hosts, and test signer emails must be suppressed and logged, not sent.
- Every caller should pass enough context for suppression and audit:
  `tenant_id`, `tenant_name`, `tenant_slug`, `target_type`, `target_id`,
  request host when available, and domain-specific fields such as agreement
  type/template/title or ticket priority/source.
- Never add a hardcoded operator or support-alert phone number. Operator live
  delivery requires `EVERBRANCH_OPERATOR_ALERT_PHONE`; legacy Modern Forestry
  support-alert routing requires `MODERN_FORESTRY_SUPPORT_ALERT_PHONE` or a
  tenant-saved support-alert setting. `EVERBRANCH_OPERATOR_ALERT_SMS_ENABLED`
  may disable texting while preserving `operator_alert_logs` evidence.
- Preserve pre-send reservation and dedupe. If `operator_alert_logs` cannot be
  reserved, the safe behavior is no SMS. Repeated identical same-tenant/event
  alerts should coalesce through
  `EVERBRANCH_OPERATOR_ALERT_SMS_REPEAT_WINDOW_MINUTES`.

## Agreement and Billing-Lane Guardrails (2026-07-16)

- Treat `agreement_versions`, `agreement_acceptances`, and `agreement_events` as immutable/append-only legal evidence. Never update or delete accepted evidence; create a new version or child amendment.
- Proposal GET opens the current immutable version using the private bearer link; no separate code is requested or sent. Acceptance and Checkout remain CSRF-protected POSTs bound to that version. A stale form redirects with HTTP 303 to a fresh proposal and does not replay the submission. Keep private links out of referrers and shared caches.
- Public proposals belong only on configured Evergrove hosts. Store only the SHA-256 token lookup and encrypted token, retain legacy password hashes for compatibility, throttle requests, never log plaintext secrets, and fail closed for expiry/revocation.
- Tenant “User Agreements” must re-resolve current tenant membership and financial access. Never expose `internal_notes`, token/password fields, raw IP, user agent, or internal audit references.
- Agreement acceptance is not billing activation. Shopify App Store merchants use `shopify_app_pricing`; direct/non-Shopify customers may use `stripe_direct`. Never charge one subscription through both providers and never route a Shopify App Store merchant around Shopify billing.
- Pricing is agreement-specific and may be à la carte. Preserve exact authorized line items, content/version hashes, provider plan/subscription references, and provider-confirmed tax/receipt values. Do not derive tax locally.
- The landlord Transactions surface is a compact Stripe activity ledger. Its primary source is the configured Stripe account's Payment Intents API (cached for 30 seconds, with an operator refresh action); `TenantBillingReceipt::stripeActivityRecorded()` is the signed-webhook fallback and must exclude validation-only and wrong-mode records. Incomplete activity stays visible with a neutral amount/status, while only succeeded Payment Intents with a positive provider `amount_received` may increase “Payments received.” Open direct-invoice reminder actions must resolve a tenant-scoped local invoice, re-retrieve its current Stripe invoice, require open status plus positive amount remaining plus an HTTPS hosted URL, require explicit operator confirmation of billing-SMS consent, block known opt-outs, use messaging-ledger idempotency, and avoid full phone numbers in audit/reminder metadata.
- Any future checkout or entitlement activation must pass `AgreementBillingActivationGuard` or an equally strict pre-check requiring exact accepted version, approved lane, verified active provider subscription, and audited fulfillment. Defaults stay disabled until provider evidence exists.

## Front Yard Foods Scheduling Guardrails (2026-07-15)

- Keep Class Scheduling reusable and tenant-scoped. Public signup must resolve a visible, enabled Branch and published class, then enforce capacity and normalized-email uniqueness under a database transaction.
- Use `php artisan everbranch:prepare-front-yard-foods` for demo content. It must remain idempotent, preserve memberships, prefer tenant ID 4 only when free, fall forward to the smallest open ID, and audit commercial/module changes.
- Reminder creation requires class consent plus a usable channel. Demo preparation may schedule reminders but must never deliver SMS or email automatically; live test delivery requires provider readiness and explicit action-time confirmation.
- Job images must be imported into canonical private workspace assets and retain source/license attribution. Never hotlink untracked images.
- Mobile class, enrollment, customer, message, and reminder requests must re-resolve membership, tenant, Branch access, and resource ownership on the server.

## Production Infrastructure Reality (verified 2026-07-21)

- Production is ONE DigitalOcean droplet: IP `129.212.138.111`, hostname `Backstage`, managed by Laravel Forge (`modern-forestry` / `backstage-pfw`). One nginx serves every domain: `theeverbranch.com` (canonical, incl. `app.` and tenant wildcards), `backstage.theforestrystudio.com` (legacy), `evergrovesoftware.com`, `forestrybackstage.com`. All are Cloudflare-proxied.
- MySQL lives on the same droplet (`DB_CONNECTION=mysql`). The scheduler cron (`schedule:run` every minute) is installed directly in the forge crontab and IS active, even though Forge's UI scheduler toggle looks off.
- The tenant-scoped Candle Cash + wishlist checker is `marketing:send-weekly-rewards-wishlist-summary`. Modern Forestry runs Monday at 08:30 America/New_York and emails aggregate usage/health to `info@theforestrystudio.com`; use `--dry-run` before an ad hoc send. A `No activity observed` result is neutral, while recorded apply/fallback/wishlist errors produce `Needs attention`. See `docs/operations/rewards-wishlist-weekly-summary.md`.
- The Forge production site tracks `main` and is configured for zero-downtime
  releases. Direct Forge push-to-deploy is intentionally off so a change cannot
  bypass the GitHub Actions test/build gate. GitHub deploys only by posting to
  the protected Forge hook after that gate succeeds; routine SSH deployment is
  retired and available only as explicitly approved emergency recovery.
- Prod is a Laravel-managed Forge server (server ID `1165565`, VPC "Laravel Managed", created Feb 20 2026): Laravel provisions and bills the underlying DO droplet, so it does NOT appear in John's own DigitalOcean account. Resize/scale prod through Forge (or Laravel billing), not the DO console.
- ⚠️ TRAP: a second, identically-named "Backstage" droplet (`134.209.43.25`) sits in John's personal DO account (`johncollinsemail@gmail.com`, GitHub login). It is a blank, never-provisioned box serving nothing (root-SSH verified 2026-07-06), billing ~$12/mo since Feb; it is NOT production. Always confirm the target IP is `129.212.138.111` before any prod infra action.
- Prod was resized to 8 GB RAM / 4 vCPU on 2026-07-06 (fixes the earlier `vite build` exit-137 OOM). Resize a Laravel VPS via Forge → Settings → General → Size, not the DigitalOcean console.
- Deploys are gated: `.github/workflows/deploy.yml` runs the Pest suite + asset build on every push to `main` and blocks the deploy on failure (a manual `workflow_dispatch` with run_tests unchecked is the only emergency bypass).

## Developer Control Center (landlord operator dashboard)

- Route `/landlord/developer` (`landlord.developer`), controller `App\Http\Controllers\Landlord\LandlordDeveloperDashboardController`, view `resources/views/landlord/developer/index.blade.php`. Landlord-operator only (host-locked group). Read-only.
- Live data comes from `App\Services\Operations\OperationalStatusService` (scheduler heartbeat, last backup, open integration health events, last Shopify import). It is defensively `rescue()`-wrapped so one failing probe never blanks the page.
- "Last backup" reads a cache key stamped by `php artisan ops:record-backup`. Wire that command to Forge's "run a command after backup" hook (Server → Database → Backups) so the widget reflects real backup completions.
- Three landlord-global (NOT tenant-scoped) models back the content: `AgenticChange` (recent changes log), `VisionIdea` (vision board), `ReadinessChecklistItem` (production-readiness checklist, status `done|partial|todo`). Seed/update them idempotently with `php artisan db:seed --class=DeveloperDashboardSeeder` (rows keyed on `slug`).
- **When you ship something that was on the vision board:** set the matching `VisionIdea` status to `done` (it then drops off the board — the controller filters `status != done`), flip the matching `ReadinessChecklistItem` to `done`, and add an `AgenticChange` entry. Do this in `DeveloperDashboardSeeder` (idempotent) so it stays reproducible, then run the seeder on prod.

## Everbranch Customer Electrician Tutorial Restore Rule (2026-07-01)

- The customer-facing electrician intake/tutorial is now intentionally hidden by default behind `FEATURE_CUSTOMER_ELECTRICIAN_TUTORIAL=false`.
- Hidden customer/public surfaces include:
  - the public promo `Electrician` profile
  - public access-request/demo/start business-type options for `electrician`
  - tenant `/start` electrician onboarding teaser, modal, and reopen CTA
  - tenant `/onboarding` for customer users, which now redirects back to `/start` while the flag is off
  - the tenant quick action labeled `Setup plan`
- Landlord/internal electrician setup remains intact. Do not delete the `electrician` template key, blueprint metadata, or landlord onboarding lane just because the customer-facing tutorial is hidden.
- To restore it later, turn `FEATURE_CUSTOMER_ELECTRICIAN_TUTORIAL=true` back on and keep the current filtering/redirect guards in place rather than re-adding hardcoded customer-facing electrician copy by hand.

## Collins Electric Guided Launch Rule (2026-07-11)

- The October 2026 Everbranch Field App Store repair is a paired Laravel/native release. Employee time analytics are tenant/user scoped; job crew hours and job hours analytics are manager-only. Team unread status is per member. The Safari in-app email form returned 419 even after a scoped cookie fix; the new native build posts email/password and optional TOTP or recovery code to the throttled `/api/mobile/v1/auth/password` endpoint and stores the resulting mobile token securely. Keep Shopify embedded cookie behavior intact and verify native login before iOS submission. Apple's unlisted approval is case 20000141339318; a direct link is not an access control.
- Everbranch Android push uses FCM HTTP v1 with a dedicated Firebase service account and the existing platform-separated `everbranch_mobile_push_devices` records. Keep it disabled until the Firebase project credentials are configured; never reuse Modern Forestry push credentials or device rows.
- Email verification notifications now link to the canonical host's signed `/email/confirm/{id}/{hash}` route. It confirms the addressed account from an email browser without a login session, then offers sign-in. Preserve signature, expiry, account-email hash, and idempotent Verified-event checks; do not authenticate the browser as the confirmed user. Legacy Fortify `/email/verify` links retain their existing auth requirement.
- A signed-in `member` must not land on the admin-only `/dashboard` after `/login` or email confirmation. Send members with Field Service entitlement to Work; use Account Help when that Branch is unavailable. Preserve only a same-host `/mobile/authorize` intended URL after confirmation.
- A successful password setup for a `workspace_invite` account also verifies that invited email, because Fortify's password broker validated the token sent to that inbox. Keep this scoped to workspace invites, preserve the invalid-token fail-closed behavior, and emit `Verified` only on the first verification.
- Do not add QuickBooks TimeActivity writes to this App Store release. Any later Collins-only hours export needs verified Intuit production credentials, employee mapping, explicit tenant enablement, durable idempotent export/reconciliation, sandbox validation, and a separate approved release. QuickBooks stays authoritative for payroll and money.
- Collins Electric (`collins-electric`) is the first guided electrician launch-partner workspace. It is not a 3-day trial, public self-service tenant, or billing/subscription activation.
- Use `php artisan everbranch:prepare-collins-electric --seed-demo-job` to create or refresh the tenant, apply the `electrician` blueprint, attach `johncollinsemail@gmail.com` as active verified admin, and keep SMS provider status `not_verified`.
- `collinselectric91@gmail.com` is provisioned as Collins owner until Nathan supplies another verified identity. Do not remove John's other memberships or use either email as a tenant-scoping shortcut.
- Setup interests may include `billing`, `uploads`, and `quickbooks`, but final blueprint modules must remain limited to safe workspace modules (`customers`, `field_service`, `messaging`, `reporting`) unless a future approved PR adds real fulfillment.
- QuickBooks is a reusable opt-in beta Branch with tenant-scoped OAuth plus read-only audit/sync commands. CSV/XLSX remains the concierge fallback through `php artisan field-service:import-quickbooks`. Do not enable payments, write-back, webhooks, CDC, estimator write-back, or payroll automatically. Collins may use the shared Estimator in owner/admin draft-only mode.
- Collins Field Operations v7 uses `field_service.metadata.member_job_visibility=all_operational`. Active employees may read all current and past operational jobs, PM contacts, team files, tasks, and updates; do not broaden job edits, progress, or task completion beyond the existing assignment and role rules.
- Job Drafts are owner/admin-only operational staging records. Never serialize the QuickBooks source type, document number, amount, balance, private note, receivable value, or dollar-formatted financial evidence into Job Draft, employee Work, Search, Reporting, Documents, bootstrap, or job-detail payloads. Archive/restore the draft; never delete or mutate the source accounting document.
- PDF drawings uploaded from Files or Documents are authenticated tenant/job assets with `team` visibility and a configurable 50 MiB limit (`WORKSPACE_ASSET_MAX_UPLOAD_MB`). The local-disk mobile path is proxy-independent: initialize a two-hour session with a client UUID `Idempotency-Key`, send ordered 512 KiB chunks with token/offset/SHA-256 validation, and complete with the whole-file SHA-256. A repeated initialize request returns the same session without consuming quota. Completion requires exact size and a structurally valid PDF, moves through a non-cancelable `completing` lease, is idempotent, and links only to a job the uploader may update. Every PDF ingress path receives the same header/xref/object/EOF validation; production additionally runs bounded, safe Ghostscript validation (`WORKSPACE_ASSET_PDF_VALIDATOR_BINARY`, 120 seconds by default). Legacy multipart/base64 mobile requests remain compatible but accept one PDF of at most 25 MiB; the resumable path carries files through 50 MiB. Five active sessions per user, 20 per tenant, the configured staging-byte reservation, verified idempotent cancellation, and scheduled expiry cleanup bound abuse and abandoned data. Authorized clients request a five-minute signed PDF preview URL once; WebKit then uses the unauthenticated signed local-file route for RFC-style byte ranges with `206`, `Accept-Ranges`, `Content-Range`, exact length, inline disposition, and `nosniff`. The URL issuance is audited once, range requests are not, and the signature remains tenant/asset-bound.
- Field-service tenants have one canonical Work surface: `field_service`. Hide duplicate `work_core` discovery and preserve old routes only as compatibility aliases. Member job visibility requires assignment, participation/following, a task assignment, or a mention; never rely on client filtering.
- QuickBooks lifecycle reconciliation derives Quote, Active, Needs details, Complete, and History while preserving manual overrides. Records older than one year remain searchable/history-only and must not inflate current job or receivable counts.
- Job photos selected through the iOS photo picker are private Everbranch copies. Do not crawl Apple Shared Albums or treat an iCloud URL as permanent storage.
- SMS/reminder configuration is admin-guided setup intent only. Do not enable real customer sends for Collins Electric until provider readiness, consent state, and delivery logs have passed a smoke test.

## Tenant Import + Workspace Guide Isolation Rule (2026-07-13)

- Import ownership is tenant-based, never email-based. Every OAuth callback, importer command, run, exception, normalization, banner, search result, and admin action must carry or resolve `tenant_id` and fail closed when an active tenant cannot be established.
- `store_key` is source context, not a sufficient authorization boundary. Legacy rows may be backfilled through their order or installed store, but new writes must stamp `tenant_id` directly.
- `/wiki` is protected by `tenant.access`. Modern Forestry uses the legacy production wiki; every non-flagship workspace uses the shared Everbranch guide baseline and its own tenant-specific override file. Direct links to another tenant's wiki articles must resolve as unavailable.
- Multi-membership users are expected. Switching the active workspace must switch import alerts, import history, exception queues, search, dashboards, OAuth connections, and guide content within the same session.

## Modern Forestry Facebook Scent-Share Preview Refresh Rule (2026-06-30)

- Modern Forestry scent-personality sharing is intentionally **latest-only** per account. Retakes should update the current public share target instead of creating immutable historical share snapshots.
- Facebook preview freshness depends on a result-derived revision token appearing in both:
  - the public scent-share page URL
  - the scent-share `og:image` / `twitter:image` URL
- Do not regress the share metadata back to a token-only/static image URL. Facebook can cache that preview aggressively and keep showing the old card after a retake.
- Keep the current `socialShareConfig` payload shape. The revision change belongs in the returned `share_url`, not in a new API field.
- Do not switch this flow to per-retake token rotation or immutable share history unless product behavior is intentionally being redesigned.

## Modern Forestry Live Checkout Recovery + Account First Paint Rule (2026-06-30)

- End-to-end checkout now prioritizes producing a usable Shopify checkout URL over preserving every signed-in prefill detail.
- Signed-in mobile checkout recovery order is now:
  - first attempt unchanged
  - retry without `deliveryAddress.phone`
  - retry without the full `delivery` block
  - if Shopify still returns `Phone is invalid`, create a clean anonymous Shopify checkout and return it as a successful recovery
- Treat `anonymous_checkout` as a designed fallback, not a visible customer error. Keep it operator-facing through logs/metadata only.
- Keep `buyerIdentity.phone` omitted in the signed-in Modern Forestry mobile path.
- `ModernForestryMobileCheckoutService` now emits one `checkoutAttemptId` across retries/fallback so phone repros can be matched to production logs quickly.
- `GET /api/mobile/v1/modern-forestry/account` should stay lightweight on first paint:
  - do not reintroduce synchronous per-line product-detail enrichment
  - keep non-critical sections fail-soft so wishlist/support/rewards hiccups do not blank the whole Account tab
  - preserve timing logs for `customer`, `orders`, `wishlist`, `support`, `rewards`, and total payload time

## Modern Forestry Mobile Phone Invalid Recovery Rule (2026-06-30)

- The signed-in Modern Forestry mobile checkout path already omits `buyerIdentity.phone`; do not add it back.
- If Shopify returns `Phone is invalid` during mobile cart refresh, treat it as a recovery case before treating it as an error:
  - first retry without `deliveryAddress.phone`
  - if needed, retry once more without the entire `delivery` block
- If recovery succeeds, return the recovered cart normally and do not promote it to a visible customer error state.
- If recovery fails, include diagnostics metadata in the API error so the iOS bag can explain the cart-refresh problem without exposing raw Shopify phrasing.
- Current root-cause theory is stale Shopify cart or delivery identity state, not the iOS client sending `buyerIdentity.phone`.
- The Home API may still emit shell/stale diagnostics for operators and tests, but the iOS Home surface should keep that context off-screen.
- Instagram Story sharing must use the native `instagram-stories://share` composer route. Do not cargo-cult the Facebook `source_application` query string onto Instagram, or the handoff can degrade into opening the share link page instead of the Story publisher.
- Device-QA caveat: the current Debug iPhone build still points at the live `app.theeverbranch.com` mobile API, so a phone repro can persist even when the local Laravel patch is correct. Separate “local code fixed” from “backend deployed” before assuming the recovery logic regressed.

## Modern Forestry Mobile Checkout + Performance Rule (2026-06-29)

- Mobile checkout is Shopify Storefront Cart API based when the storefront token is available. Do not regress it to a handcrafted permalink-only flow. The intended path is: validate bag lines against Laravel product detail, create Shopify cart, apply buyer identity, attach delivery address when available, and return Shopify `checkoutUrl`. Anonymous checkout remains supported.
- Mobile Candle Cash rule: if rewards redemption hits an existing live issued storefront code, prefer returning that reusable code to the iOS client instead of a dead-end blocked error. The bag should only show a lower total once Shopify's own checkout estimate reflects the discount; never fake the subtraction locally.
- Buyer identity phone is stricter in Shopify than local app formatting. Only send `buyerIdentity.phone` when it normalizes to E.164; omit non-normalizable values or signed-in checkout can fail with `Phone is invalid`.
- Mobile sessions can be stale even when the phone thinks the user is signed in. Refresh/validate the session before depending on signed-in checkout, Candle Cash, or account-linked bag behavior.
- iPhone Mirroring auth QA note: if the Shopify sign-in sheet opens, Continue returns to the app still signed out, or Account appears stuck at a sign-in/loading state after a fresh install, fully close the app from the iPhone app switcher and reopen it before diagnosing the build or backend. This has cleared stale auth presentation/session state during device testing.
- `/api/mobile/v1/modern-forestry/home` is the slowest mobile endpoint on cold cache. It now serves a local shell first and defers full Shopify-backed refresh; continue moving the app toward stale-while-revalidate bootstrap so bag, product, and shop navigation do not feel blocked by Home.
- Home boot rule: do not add root-level iOS startup tasks that wait on Candle Cash cleanup, checkout recovery, or session-linked bag repair. Keep reward-release and bag-repair work scoped to explicit Bag / checkout actions, or Home can look frozen even when the regression is unrelated to Home itself.
- `/api/mobile/v1/modern-forestry/products/{handle}` uses short server-side caching for repeat product detail access.
- Historical deploy warning: GitHub Actions previously failed during `vite build` with exit code 137 under memory pressure. Production has since been resized and routine manual SSH deployment is retired. Keep the GitHub main-branch test/build gate and Forge atomic release path; use the audited emergency path only when explicitly approved.

## Everbranch Readiness Operating Rule (2026-05-21)

### Field operations v6 rule (2026-07-21)

- Preserve `field_service_tasks.assigned_user_id` for one compatibility release, but use `field_service_task_assignees` and `FieldServiceTaskAssignmentService` as the canonical multi-person responsibility path.
- Task handoffs must be tenant-scoped, idempotent, recorded in `field_service_task_events`, and limited for employees to job participants, the lead, or workspace managers.
- Owner Home financial values come from cash-basis QuickBooks reporting snapshots through `FieldServiceOwnerHomeMetricsService`. Never calculate them on the client or return them to non-financial roles.
- Add onboarding behavior through `TenantModuleActivationPolicyService` and the canonical module catalog. Recommendations do not install paid, beta, internal, or integration-dependent modules.

- Everbranch is the product/platform brand.
- Modern Forestry is the flagship tenant and must remain stable; do not treat Modern Forestry-specific code as generic platform capability unless that generalization is explicitly implemented and tested.
- Product display labels live in `config/everbranch.php`; prefer those labels over new hardcoded platform-brand strings.
- Route/page ownership for brand and navigation work is tracked in `docs/operations/everbranch-route-page-ownership-inventory.md`.
- Agents must update the relevant README, `SYSTEM_SNAPSHOT.md`, readiness doc, runbook, or changelog after meaningful work. UI-affecting work must also update `docs/ui/UI_CHANGELOG.md`.
- Shopify is the flagship integration path, but Everbranch must not require Shopify for every customer. Setup/readiness work should account for Shopify, Square, CSV import, manual import, and future connector paths.
- Android and iOS mobile app readiness are product requirements. Modern Forestry customer catalog APIs remain product-specific; the separate cross-tenant Everbranch contract lives under `/api/mobile/v1` and follows the Everbranch tenant mobile rule below.
- Module store work must use `config/module_catalog.php`, `TenantModuleCatalogService`, and `TenantModuleAccessResolver`. Discovery never activates access; only verified, audited commercial fulfillment may change paid entitlements while lifecycle flags are enabled.
- Custom module requests are intake/triage records only. Do not convert them into modules, entitlements, quotes, invoices, billing, or mobile/job/photo/messaging implementations without a separate approved PR.
- Plan selection is commercial intent only until a future approved billing activation PR. Do not turn plan interest or billing lane interest into checkout, subscriptions, quotes, invoices, payment links, module installs, or entitlements.
- The landlord commercial intent gate is decision support only. Do not add charge, checkout, subscription, invoice, module install, or entitlement activation actions to it without a separate approved billing activation PR.
- Do not activate checkout or broad subscription lifecycle automation until the billing readiness gates pass and the activation is explicitly requested.
- Prepare or verify an unsigned managed-website agreement idempotently with `php artisan everbranch:prepare-managed-website-agreement <tenant-slug> --actor-email=<operator-email> --json`. The result reports only safe readiness booleans and blocker labels; it never prints provider secrets or sends the agreement.
- Production agreement-checkout allowlisting uses the confirmation-gated `maintenance-allow-agreement-checkout.yml` workflow. It preserves and backs up the complete production environment, rejects wildcard allowlists, adds one validated tenant slug idempotently, refreshes the Laravel config cache with rollback on failure, and verifies the unsigned agreement readiness without sending or charging it.
- Billing lane rule: Shopify App Store merchant app charges should use Shopify App Pricing/Billing in a future approved PR; keep Stripe direct billing separate for direct SaaS, custom, service, manual contract, or non-Shopify lanes.
- Shopify privacy webhook rule: compliance webhooks must verify `X-Shopify-Hmac-Sha256`, record minimal auditable evidence, and avoid destructive deletion/anonymization unless a separate tested privacy policy/runbook explicitly approves it.
- Shopify external evidence rule: do not mark Partner Dashboard, Shopify CLI deploy/release, dev-store install/reinstall, app proxy, or live privacy webhook delivery evidence complete unless artifacts are stored under `docs/operations/evidence/shopify/`.
- Shopify scope/branding rule: do not change TOML scopes, app name, or handle until `docs/operations/shopify-scope-branding-decision-record.md` has an approved decision and matching tests/evidence.
- Current Shopify evidence target: use Partner/dev dashboard app `Modern Forestry Backstage`, handle `modernforestrybackstage`, and dev store `modernforestry.myshopify.com` until a future public Everbranch Shopify app branding decision is approved.
- Current Shopify evidence packet: `docs/operations/evidence/shopify/2026-05-21/` contains PR 18 read-only CLI app-info evidence and partial app-proxy health evidence. Partner Dashboard screenshots, deploy/release output, dev-store install/reinstall, and live privacy webhook delivery rows are still pending.
- Current Shopify screenshot pack: use `docs/operations/evidence/shopify/2026-05-21/screenshot-manifest.md` and `docs/operations/evidence/shopify/2026-05-21/operator-checklist.md` before any deploy/release decision.
- Everbranch brand assets live in `public/brand/everbranch-*.svg` and are referenced through `config('everbranch.brand_assets')`; do not reintroduce hardcoded Forestry Backstage brand assets on Laravel platform surfaces.
- Uploaded tenant brand marks resolve through the public application asset route so atomic releases do not depend on a web-server `/storage` symlink. Branding is public identity data only; private workspace files continue to use authenticated asset delivery.
- Retired Backstage visual assets (`public/brand/forestry-backstage-*`) are intentionally removed. Do not restore them for app-shell, auth, public, email, or share-image fallbacks.
- Do not confuse retired Backstage visual branding with the current Modern Forestry Shopify app identity. Shopify is still the flagship integration path, and the TOML/Partner app name/handle must remain unchanged until `docs/operations/shopify-scope-branding-decision-record.md` approves a rename with matching evidence.
- Evergrove is the broader company/ecosystem name; Everbranch is the product/platform name. Use `config/everbranch.php` for product/company/landlord/flagship/legacy labels instead of scattering literal brand strings.
- User-facing copy should prefer human labels from `config('everbranch.display_language')`: workspace address/type, setup, feature, access, status, setup plan, and plan interest. Avoid showing technical terms like `tenant slug`, `metadata`, `entitlement`, `canonical`, `module key`, or `provisioning` unless the surface is explicitly internal/operator-focused.
- Self-service readiness dashboard rule: `/landlord/readiness` is status/control visibility only. Do not use it to approve launch, activate billing, install modules, change entitlements, or imply generic mobile readiness.
- Access lane rule: keep the four doors distinct. Public Everbranch explains/request-access/demo/login; tenant app users work in their workspace and use `/start` only for setup status; landlord operators manage provisioning/control under `/landlord`; demo/sandbox tenants must be visibly labeled and must not be confused with Modern Forestry production-alpha usage.
- Test access rule: `php artisan everbranch:seed-access-surfaces` is the safe local/staging entry for standard landlord, Modern Forestry, demo, and sandbox accounts. Do not add direct impersonation or login bypass controls unless they are audited, reversible, visibly bannered, and separately tested.
- Tenant blueprint rule: landlord-created tenant blueprints live in `TenantAccessProfile.metadata.tenant_blueprint` plus existing `tenant_setup_statuses` fields. Use `config/tenant_blueprints.php` and `TenantBlueprintProfileService`; do not create industry-specific route forks, install modules, activate entitlements, or start connector/billing automation from blueprint choices.
- Work management blueprint rule: project/task/assignment/communication/photo/file/mobile-capture fields are blueprint intent only. Do not create project/task/upload/message tables, storage flows, notifications, mobile APIs, modules, billing, or entitlements from these fields without a separate approved implementation PR.
- Blueprint review rule: landlord blueprint review/edit state lives in `TenantAccessProfile.metadata.tenant_blueprint` and is landlord-only. Do not expose `blueprint_internal_notes` or `blueprint_next_action` on tenant-facing pages; use `onboarding_next_action` for tenant-facing guidance.
- Blueprint module recommendation rule: use `TenantBlueprintModuleRecommendationService` for blueprint-driven module display states. Recommendations, requested/planned/future labels, and work-management module families are display-only; they must not create modules, install modules, grant entitlements, start billing, run imports, enable uploads/messaging, or create mobile APIs.
- Do not add new modules, expose roadmap/internal modules, or rewrite navigation/UI broadly during readiness work. Prefer audits, guardrail tests, documentation, and narrow coherence fixes.

## UI Editing Guide (Required)

Read these before any UI/theme change:
1. `docs/ui/UI_SYSTEM.md`
2. `docs/ui/UI_CHANGELOG.md`

The **Operational UI Standard** in `docs/ui/UI_SYSTEM.md` is mandatory for all
authenticated tenant, landlord, and Shopify-embedded changes. Do not add a
page-local card/hero treatment when one of its canonical page shapes applies.

Canonical UI ownership:
- Canonical backstage shell: `resources/views/layouts/app/sidebar.blade.php`
- Public landing pages: `resources/views/platform/promo.blade.php` and `resources/views/platform/contact.blade.php`
- Auth branding shell: `resources/views/layouts/auth/simple.blade.php`
- Shared tokens + component styles: `resources/css/forestry-ui.css` (imported via `resources/css/app.css`)
- Embedded shell primitives:
  - `resources/views/components/shopify-embedded-shell.blade.php`
  - `resources/views/components/app-shell.blade.php`
  - `resources/views/components/app-topbar.blade.php`
  - `resources/views/components/app-sidebar.blade.php`

UI maintenance rules:
- Do not add large inline `<style>` blocks to shell/layout files.
- Prefer shared tokenized classes and reusable components.
- Landlord/operator pages use the full canvas beside the navigation rail;
  tenant reading surfaces retain their established width unless wide layout is
  selected.
- Every UI-affecting change must update `docs/ui/UI_CHANGELOG.md`.

## Dated capability/release notes (read the current checkpoint first)

### Customer Loop and Bud handoff (2026-08-07)

- Customer Loop is a tenant-scoped, review-only follow-up queue. It may create
  Everbranch-owned activities and draft actions only; it must never send email
  or SMS, publish social content, alter consent, or write to Shopify, Website
  Commerce, or Modern Forestry lanes without a separately approved delivery
  capability.
- Use the existing Workflow Studio for advanced if/then customization. The
  `everbranch.customer_loop.draft.prepare` action is intentionally
  idempotent and draft-only. Do not build another automation editor.
- Bud Core is deterministic and included. It is available to authenticated
  workspaces whenever the global Core incident switch is on; never gate it on
  a tenant request, operator approval, paid plan, provider credential, or the
  legacy `tenant_bud_settings.status` field. Every new tenant-facing capability
  must update `BudCapabilityRegistry`, add a minimal tenant-scoped context path
  in `BudWorkspaceContextService` only when needed, and add regression tests
  before saying Bud understands it.
- Bud Core and future Bud AI must never edit files, execute code, access
  credentials, cross tenant boundaries, send/publish automatically, or bypass
  typed UI permissions. Every delivery or publish action needs a visible final
  human confirmation.
- Bud AI and voice flags default off. Do not add a provider call, speech stream,
  or paid claim until tenant pricing, budget caps, audit logs, data minimization,
  authorization, and safety tests are implemented. The governing architecture
  is `docs/architecture/customer-loop-and-bud.md`.

Current implemented shell/diagnostics checkpoint:
- Phase 2 instrumentation hardening checkpoint (2026-04-20):
  - fixed embedded React runtime crash on messaging analytics path by stabilizing action-search store snapshots
  - baseline storefront funnel tracking is no longer campaign-gated (direct/organic sessions can post session, landing, product, cart events)
  - explicit payload attribution fields now flow through funnel ingestion (UTM + Meta `fbclid`/`fbc`/`fbp`)
  - `purchase` is now a distinct storefront event type (not aliased to `checkout_completed`)
  - Shopify order ingest now records deterministic storefront linkage + confidence and writes a durable purchase lineage event (`shopify_storefront_purchase`)
  - migration required: `2026_04_20_150000_add_storefront_linkage_columns_to_orders_table`
  - authenticated click-path coverage config added: `tests/e2e/click-path-routes-auth.json` (`npm run qa:click-path:auth`)
- Phase 3 analytics usefulness checkpoint (2026-04-20):
  - messaging analytics home is now decision-first with four panels:
    - Attribution Quality
    - Acquisition Funnel
    - Retention
    - Action Queue
  - panel computation lives in `MessageAnalyticsService` (`decision_panels` payload)
  - controller now loads decision panels on home tab only (`include_decision_panels`)
  - legacy message-only cards are still present but visually demoted as secondary operational detail
  - QA/handoff notes: `docs/qa/phase-3-analytics-decision-useful.md`
- Phase 4 workflow rollout checkpoint (2026-04-20):
  - lifecycle rollout service is live: `app/Services/Marketing/LifecycleWorkflowRolloutService.php`
  - Flows (`/marketing/automations`) now renders workflow-by-workflow status with blockers + one-click staging actions
  - first three workflows are stageable into manual approval queues:
    - `welcome`
    - `winback`
    - `post_purchase_cross_sell`
  - staging route/action:
    - `POST /marketing/automations/{workflow}/prepare`
    - `MarketingPagesController::prepareAutomationWorkflow`
  - lifecycle staging writes auditable rows to `marketing_automation_events` (queued/skipped/suppressed with reasons + cooldown checks)
  - wishlist remains operator/manual-first through existing queue primitives; cart/checkout abandonment remain blocked until token/profile continuity thresholds are met
  - QA/handoff notes: `docs/qa/phase-4-workflow-rollout.md`
  - regression coverage:
    - `tests/Feature/Marketing/LifecycleWorkflowRolloutServiceTest.php`
- Phase 5 AI budget readiness checkpoint (2026-04-20):
  - advisory-only budget readiness service is live:
    - `app/Services/Marketing/AiBudgetReadinessService.php`
    - `app/Services/Marketing/AiBudgetRecommendationService.php`
  - Meta spend ingestion baseline is live:
    - `app/Services/Marketing/MetaAdsSpendSyncService.php`
    - `app/Console/Commands/MarketingSyncMetaAdsSpend.php`
    - `app/Models/MarketingPaidMediaDailyStat.php`
    - migration: `2026_04_20_200000_create_marketing_paid_media_daily_stats_table.php`
  - Message Analytics Home now includes `AI Budget Readiness (Advisory only)`:
    - readiness tier + scorecard + guardrail matrix + recommendation queue + next-fix list
  - autonomous budget control remains blocked by policy:
    - no auto budget mutation
    - no auto pausing
    - no auto channel reallocation
  - QA/handoff notes: `docs/qa/phase-5-ai-budget-readiness.md`
  - regression coverage:
    - `tests/Feature/Marketing/AiBudgetReadinessServiceTest.php`
    - `tests/Feature/Marketing/MetaAdsSpendSyncServiceTest.php`
- Phase 7 live storefront activation checkpoint (2026-04-21):
  - production app proxy runtime is healthy (`/apps/forestry/health` returns 200 from storefront context)
  - Shopify Customer Events pixel is connected (`gid://shopify/WebPixel/2117271811`) but recent `web_pixel` event flow is still absent
  - published theme embed for Forestry tracking is currently inactive on the live main theme (`settings_data.json` has no Forestry app-embed block)
  - organic funnel emission remains sparse until merchant-side app embed activation is completed in Shopify Theme Editor
  - diagnostics/verification commands:
    - `php artisan marketing:diagnose-storefront-tracking --tenant-id=1 --store=retail --days=30 --json`
  - latest runtime hardening shipped in extension assets:
    - checkout_started now also emits on checkout form submit paths (not only click hooks)
    - checkout token extraction now supports checkout URLs before navigation
  - phase-7 verification notes: `docs/qa/phase-7-live-storefront-activation.md`
- Phase 8 linkage continuity checkpoint (2026-04-21):
  - storefront runtime now injects `_mf_*` linkage properties into add-to-cart forms to improve checkout/order continuity.
  - order linkage service now reads fallback continuity signals from Shopify order `line_items[].properties[]`.
  - attribution source-meta builder now records `line_item_property_signals` for diagnostics.
  - diagnostics command remains:
    - `php artisan marketing:diagnose-storefront-tracking --tenant-id=1 --store=retail --days=30 --json`
  - phase-8 verification notes: `docs/qa/phase-8-linkage-continuity.md`
- Embedded product shell is live and navigable:
  - `/shopify/app` (overview/dashboard)
  - `/shopify/app/start`
  - `/shopify/app/plans`
  - `/shopify/app/integrations`
  - `/shopify/app/assistant` (`AI Assistant`)
  - `/shopify/app/assistant/opportunities`
  - `/shopify/app/assistant/drafts`
  - `/shopify/app/assistant/setup`
  - `/shopify/app/assistant/activity`
- AI Assistant foundation status (2026-04-10):
  - tenant-aware/tier-aware access and page rendering are driven from canonical module access services
  - `Start Here` is tenant-facing and intentionally fast (welcome + state strip + next-click actions + what-it-helps-with)
  - `Top Opportunities` is recommendation-backed and tenant-facing (top 5 paginated cards with explainable why-lines, plain-English priority labels, and one next action per card)
  - `Setup` is tenant-facing and checklist-based (up to 6 plain-English readiness cards with one obvious action each)
  - `Draft Campaigns` is now a tenant-facing human-review page (recent/pending drafts list + simple `Review Draft` editor + recommendation-to-draft creation actions)
  - `Activity` is now a tenant-facing history page (recent opportunities, drafts, approvals/rejections, and key status changes with paginated older history)
  - stage 6 hardening is in place:
    - tier matrix: `Starter` preview-only, `Growth` (`Start Here`/`Top Opportunities`/`Setup`), `Pro` (+`Draft Campaigns` + `Activity`)
    - AI surface gating is capability-driven (`required_capability`) and routes fail closed when locked
    - assistant nav/search hides locked/coming-soon child surfaces for non-eligible tenants
    - landlord entitlement/module overrides still flow through canonical resolver decisions
    - embedded shell uses tenant-scoped cached capability summaries to avoid repeated resolver work
  - tenant-facing module state labels are standardized (`Ready`, `Needs Setup`, `Locked`, `Coming Soon`)
  - Modern Forestry alpha override remains centralized in `ModernForestryAlphaBootstrapService` and explicitly configures `ai` module state
  - no autonomous send behavior is implemented
- Public product surfaces are implemented:
  - `/platform/promo`
  - `/platform/contact`
- Landlord commercial config surface is implemented:
  - `/landlord/commercial` (host-locked, pricing-first admin controls)
- Authenticated onboarding wizard is implemented (2026-04-12):
  - UI surface:
    - `/onboarding` (tenant-aware; shared Shopify/direct wizard shell)
  - Wizard API seams consumed by the UI (backend-driven; no duplicate client canon):
    - `GET /api/onboarding/wizard-contract`
    - `POST /api/onboarding/blueprint-draft` (autosave)
    - `POST /api/onboarding/blueprint-finalize`
    - `GET /api/onboarding/blueprint-post-provisioning-summary` (read-only orchestration; gated)
  - Internal-only harness/debug surface (gated by `app.debug` + feature flag):
    - `/internal/onboarding/harness`
  - Workflow UI primitives (shared polish layer):
    - `resources/css/forestry-ui.css` (`fb-workflow-*`, `fb-panel*`, `fb-stepper*`, `fb-state*`, `fb-module-card*`)
- Diagnostics/operator surfaces are implemented and test-covered:
  - customer email timeline provider-context filters + CSV export parity
  - birthday analytics/reporting/export/comparison
  - campaign delivery diagnostics/provider-context visibility
  - storefront tracking diagnostics hardening (2026-04-09):
    - Messaging Setup now provides explicit tracking inventory + health summary (theme embed inference, pixel connection, scope verification, recent events, last event, checkout completion seen/not-seen)
    - Messaging Analytics now includes a storefront tracking health card and raw diagnostics payload
    - message-level storefront funnel summary now renders `checkout_completed`
    - Shopify-native analytics/report scope status is surfaced, but native analytics/report API reads are still not wired
- Integrations is placeholder-first:
  - setup drawer exists
  - read-only deterministic status registry exists
  - entitlement-aware states (`connected`, `setup_needed`, `locked`, `coming_soon`) exist
  - no real connector sync/OAuth/jobs/webhooks/API writes exist
- Agentic discovery + brand graph backend release is merged and live (2026-04-10):
  - tenant-scoped discovery persistence now exists (`tenant_discovery_profiles`, `tenant_discovery_pages`)
  - backend discovery services now resolve canonical brand/domain/audience/trust/merchant signals
  - machine-readable endpoints are available:
    - `/.well-known/brand-discovery.json`
    - `/api/public/discovery/brand/{tenant}`
    - `/api/public/discovery/structured/{tenant?}`
    - `/sitemaps/discovery.xml`
  - audit command is available:
    - `php artisan modern-forestry:audit:domains`
  - Modern Forestry defaults seed via existing alpha bootstrap flow (idempotent/non-destructive)
  - deployment status:
    - `main` commit `cdfce8d` deployed by GitHub Actions run `24220680927` (`Deploy Production`: `success`)
  - known external operational blocker remains:
    - `theforestrystudio.com` custom-domain stale render/cache mismatch is still outside backend-only control (diagnostics are now in place)
- Billing remains guarded and landlord-controlled:
  - guarded Stripe customer-reference sync action exists on `/landlord/commercial`
  - guarded Stripe subscription-prep metadata sync action exists on `/landlord/commercial`
  - guarded Stripe live subscription create/sync action exists on `/landlord/commercial` (landlord-only, explicit trigger, disabled-by-default config flag)
  - guarded Stripe preflight requires HTTPS for remote `services.stripe.api_base` endpoints (HTTP is loopback-only for local testing on `localhost`/`127.0.0.1`/`::1`)
  - staging validation for the 3-step guarded Stripe sequence is documented and evidence-driven:
    - `docs/operations/staging-commercial-uat-runbook.md`
    - `docs/operations/staging-commercial-uat-evidence-template.md`
  - latest repo-side validation status (2026-03-29):
    - real staging landlord operator evidence is attached for a guarded run on tenant `modern-forestry`
    - blocked-run record: `docs/operations/staging-commercial-uat-blocked-run-2026-03-28.md`
    - staging Stripe sandbox + operator follow-up: runtime Stripe auth succeeds and all required recurring lookup-key prices are present/verified (`tier_starter_monthly`, `tier_growth_monthly`, `tier_pro_monthly`, `addon_referrals_monthly`, `addon_sms_monthly`, `addon_additional_channels_monthly`, `addon_bulk_email_marketing_monthly`, `addon_future_niche_modules_monthly`), and the landlord operator account `modernforestryteam@gmail.com` is route-ready
    - tenant-row unblock follow-up (2026-03-29): existing `TenantSeeder` was executed on staging; `/landlord/commercial` now renders one selectable tenant row (`Modern Forestry`, slug `modern-forestry`)
    - guarded run evidence artifacts (2026-03-29): `docs/operations/evidence/2026-03-29/guarded-stripe-run-2026-03-29T16-23-07.524Z/`
    - guarded step outcomes from the real run:
      - step 1 customer sync: `PASS` (`cus_UEpZQoP8cJadrs`)
      - step 2 subscription-prep sync: `PASS` (`eaaddd980cf88b07e7f52f3ce7db5856a7394ff9eb08c602ee87afeb4b6ad563`)
      - step 3 live subscription create/sync: `FAIL` (`Missing email. In order to create invoices that are sent to the customer, the customer must have a valid email.`)
    - full guarded 3-step PASS evidence is still not attached because step 3 failed in real staging execution
    - follow-up commit `9c2502c` (CI assertion alignment after dotenv bootstrap fix) is pushed to `main`
    - local CI-equivalent rerun for this pass:
      - `php -d memory_limit=512M ./vendor/bin/pest` => `845 passed`, `0 failed`
    - GitHub Actions results for commit `9c2502c`:
      - `linter`: `success`
      - `tests`: `success` (`ci (8.4)` and `ci (8.5)` passed)
      - `Deploy Production`: initial `failure` on push, then `success` on rerun `23687500356` after deploy-ops unblock
    - deploy-ops unblock completed in GitHub `production` environment:
      - configured `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT`, `DEPLOY_PATH`, `DEPLOY_SSH_KEY`
      - corrected server checkout branch at `DEPLOY_PATH` to `main` so workflow `git checkout main` succeeds
    - latest known production rollout for `dbf0762` was manual before deploy automation was restored:
      - `ssh forge@129.212.138.111 'bash /home/forge/deploy_backstage.sh'`
      - `curl -sS https://backstage.theforestrystudio.com/up` => `Application up.`
    - manual SSH deploy remains available as fallback, but is no longer the primary required path while deploy secrets stay configured
  - checkout and broad subscription lifecycle mutation flows remain intentionally disabled
- multi-tenant completion estimate is currently `45%`
- Landlord/admin Phase 1 host foundation is now in place:
  - pre-auth host tenant context is globally resolved via middleware
  - canonical landlord host (production): `app.theeverbranch.com`
  - canonical tenant host pattern (production): `<slug>.theeverbranch.com`
  - legacy domains are edge-redirect sources only (Cloudflare), not runtime-accepted hosts
  - landlord routes (host-locked): `/landlord`, `/landlord/commercial`, `/landlord/tenants`, `/landlord/tenants/{tenant}`
  - landlord directory pages remain read-only
  - landlord commercial writes are limited to safe configuration scope (plan/add-on/template catalog, tenant assignment/overrides)
  - unknown hosts do not silently fallback to first tenant

Commercial model normalization now in repo:
- public tiers: `Starter`, `Growth`, `Pro`
- add-ons: `referrals`, `sms`, `additional_channels`, `bulk_email_marketing`, `future_niche_modules`
- template library: `Candle`, `Law`, `Landscaping`, `Apparel`, `Generic`
- billing lifecycle remains guarded-first (Stripe primary with landlord-only guarded actions, Braintree secondary readiness, no checkout activation)

Production DNS/TLS status (2026-03-27):
- canonical Everbranch TLS must exist for `theeverbranch.com`, `app.theeverbranch.com`, and `*.theeverbranch.com`
- legacy domains must be redirected at edge with path/query preservation
- `_acme-challenge` CNAME must remain `DNS only` in Cloudflare
- wildcard tenant DNS is active (`* -> 129.212.138.111`) and tenant HTTPS reaches app login routes

Current execution priority:
- deploy/verify/stabilize this shell and diagnostics release
- avoid new feature sprawl unless a concrete regression requires it

Strict near-term execution order (current operator rule):
1. Candle Cash verified live and trustworthy for Modern Forestry.
2. Email reliability fixed for launch-critical reward/customer workflows.
3. Only then broader platform expansion.

Canonical customer alias invariant:
- Retained `marketing_profiles` merge aliases are audit records, not separate
  customer matches.
- Resolve every tenant-scoped email/phone match through
  `CanonicalMarketingProfileResolver` before counting matches. Deduplicate
  aliases that reach the same final survivor, including multi-level chains.
- Preserve fail-closed review behavior for distinct survivors, broken or cyclic
  chains, cross-tenant targets, and email/phone conflicts.
- Storefront identity and review payloads must reference canonical survivors.
  Do not move, recalculate, or re-import Candle Cash to repair alias ambiguity.

Canonical Candle Cash drift-repair sequence (tenant-scoped, live-safe):
1. `php artisan marketing:audit-candle-cash-composition --tenant-id=1`
2. `php artisan marketing:reconcile-candle-cash-balances --tenant-id=1` (preview-only)
3. `php artisan marketing:reconcile-candle-cash-balances --tenant-id=1 --apply`
4. `php artisan marketing:audit-candle-cash-composition --tenant-id=1`
5. `php artisan marketing:validate-candle-cash-legacy-conversion --json --limit=10`

Operator notes:
- Preview returns non-zero when drift is detected.
- Use `--profile-id={id}` for isolated repair and `--chunk={n}` for large-scope tuning.

Legacy Growave duplicate-profile rehome sequence (retail-only, live-safe):
1. `php artisan marketing:rehome-legacy-growave-candle-cash --tenant-id=1 --store=retail` (preview)
2. Require: `ambiguous_old_profiles=0` and `ambiguous_target_profiles=0` before apply.
3. `php artisan marketing:rehome-legacy-growave-candle-cash --tenant-id=1 --store=retail --apply`
4. Re-run the canonical drift-repair sequence immediately after apply.

If points import appears missing again, use this exact SOP:
1. Diagnose duplicate-profile drift via rehome preview counters.
2. Run retail-only rehome apply (do not include wholesale in broad pass).
3. Reconcile + audit Candle Cash (`marketing:audit-candle-cash-composition`, `marketing:reconcile-candle-cash-balances`, `marketing:validate-candle-cash-legacy-conversion`).
4. Verify top non-wholesale customers by order count and Candle Cash balance.
5. Keep wholesale-touched profiles quarantined unless a separate manually reviewed pass is approved.

Do not start yet:
- broad multi-tenant refactors
- Shopify App Store packaging
- speculative AI automation work

Current backend release-order rule:
- use `docs/architecture/backend-release-order-2026-04-01.md` before promoting the waiting backend branch
- Release A is stabilization-only for Shopify / rewards / storefront / marketing-manager behavior
- split later commercialization work into smaller releases instead of pushing the mixed branch to `main`
- prepared split branches now exist and should stay scoped:
  - `release-a-stabilization`
  - `release-b-commercial-core`
  - `release-c-module-discovery`
  - `release-d-unified-shell`
  - `release-e-polish-docs-assets`
- Releases A through E are now merged on `main`.
- Active standalone follow-up work is the deferred email/provider reliability pass; do not mix it back into module, shell, or commercialization scope.

## Landlord Host Foundation (2026-03-26)

Reference implementation paths:
- `app/Services/Tenancy/PreAuthTenantContextResolver.php`
- `app/Support/Tenancy/HostTenantContext.php`
- `app/Http/Middleware/ResolveHostTenantContext.php`
- `app/Http/Controllers/Landlord/LandlordTenantDirectoryController.php`
- `resources/views/landlord/*`
- `tests/Feature/Tenancy/LandlordHostFoundationTest.php`

Config:
- `TENANCY_CANONICAL_BASE_DOMAIN`
- `TENANCY_TENANT_BASE_DOMAINS` (comma-separated list for allowed tenant subdomain bases)
- `TENANCY_LEGACY_BASE_DOMAINS`
- `TENANCY_LANDLORD_PRIMARY_HOST`
- `TENANCY_LANDLORD_HOSTS` (comma-separated list)
- `TENANCY_LANDLORD_OPERATOR_ROLES` (comma-separated list, default `admin`)
- `TENANCY_LANDLORD_OPERATOR_EMAILS` (optional comma-separated allowlist)
- `config('tenancy.domains.canonical.*')`
- `config('tenancy.domains.legacy.*')`
- `config('tenancy.domains.tenant_base_domains')`
- `config('tenancy.domains.public_redirect.*')`
- `config('tenancy.landlord.primary_host')`
- `config('tenancy.landlord.hosts')`
- `config('tenancy.landlord.alias_hosts')`
- `config('tenancy.landlord.operator_roles')`
- `config('tenancy.landlord.operator_emails')`

Local routing note:
- `config('tenancy.landlord.primary_host')` is canonical (`TENANCY_LANDLORD_PRIMARY_HOST`) and is used for named route generation.
- Distinguish host examples in docs:
  - production canonical host: `app.theeverbranch.com`
  - local example host set: `app.theeverbranch.test`
- Keep local examples explicit in docs/config comments so operators do not assume the full `hosts` list is domain-bound in routing.
- Fast local auth bootstrap path already exists and should be preferred over ad-hoc DB edits:
  - `php artisan users:ensure-approved your-email@example.com 'your-password' --name='Your Name' --role=admin`
  - this sets role, password, active/approved state, and verified email for local login.

Authorization note:
- Landlord routes use dedicated middleware `landlord.operator` instead of tenant-facing `role:admin,manager`.
- Interim safety model: default `admin` role access only, with optional landlord operator email allowlist.
- TODO for future hardening: replace role/email interim rules with a first-class landlord operator role/flag.

Operational runbooks:
- `docs/operations/domain-cutover-everbranch-runbook.md`
- `docs/operations/domain-cutover-everbranch-rollback.md`
- `docs/operations/domain-cutover-everbranch-smoke-checklist.md`

Shopify cutover guardrails:
- ship URL changes from source `shopify.app.toml` (do not trust stale generated deploy artifacts)
- reauthorize required stores after cutover
- verify/repair webhook callbacks after cutover
- keep Shopify App Store readiness evidence in sync with `docs/operations/everbranch-shopify-readiness-audit.md`
- do not claim Shopify App Store readiness until Partner Dashboard values, privacy webhooks, scopes, and live install/reinstall evidence are verified
- do not expose Stripe checkout inside Shopify embedded/App Store merchant flows unless a future compliance decision explicitly approves a non-App-Store distribution lane

Important guardrails for future edits:
- Do not bypass host-locked landlord routing by making landlord pages globally available.
- Keep post-auth `tenant.access` middleware behavior unchanged unless explicitly requested.
- Keep landlord writes constrained to commercial configuration scope only.
- Keep Shopify embedded/storefront/proxy behavior unchanged while evolving landlord/admin surfaces.

Architecture references for this pass:
- `docs/architecture/business-concept-and-product-architecture.md`
- `docs/architecture/multi-tenant-inventory-2026-03-27.md`
- `docs/operations/staging-commercial-uat-runbook.md` (operator UAT sequence for landlord commercial assignment propagation)

## Auth Findings (2026-03-25)

Production login verification findings:
- A local password reset does not affect production auth.
- Live user `johncollinsemail@gmail.com` was confirmed active/approved and production password reset verified (`PASSWORD_MATCH=1`).
- Google login failure is currently due to Google OAuth credential rejection, with production logs showing:
  - `401 invalid_client`
  - `The provided client secret is invalid.`

What to check first when this recurs:
1. Run diagnostics first:
   - `php artisan auth:doctor-google`
   - `php artisan auth:doctor-google --token-smoke`
2. Verify login uses `GOOGLE_CLIENT_ID` + `GOOGLE_CLIENT_SECRET` (not `GOOGLE_GBP_*` keys).
3. Confirm ID+secret are from the same Google OAuth client credential.
4. After env edits on production, run:
   - `php artisan config:clear`
   - `php artisan config:cache`
   - `php artisan queue:restart`
5. Re-test in incognito and inspect `storage/logs/laravel.log`.

Smoke test interpretation:
- `invalid_client` => wrong/revoked/mismatched OAuth pair
- `invalid_grant` => credentials accepted, grant intentionally invalid/expired
- `redirect_uri_mismatch` => callback URL mismatch in Google Console

## Dual-Track Strategy (Hard Guardrail)

This platform has two product tracks that must evolve together without breaking each other:

1. Shopify Product Track (flagship wedge)
- Remains first-class and commercially useful for Shopify merchants.
- Existing Shopify proof-of-concept behavior is sacrosanct during architecture and docs work:
  - storefront widgets and signed proxy flows
  - embedded dashboard/customers/settings flows
  - Candle Cash, birthdays, reviews, wishlist
  - canonical marketing identity pipeline
- Do not genericize away Shopify-specific value.

2. Broader Business Systems Track (expansion path)
- Extends the same canonical backend into direct business onboarding, integrations, uploads/imports, and tiered access.
- This is an expansion path, not a rewrite.
- Reuse shared core primitives only when real use cases justify extraction.

Authoritative direction docs:
- `docs/architecture/dual-track-product-direction.md`
- `docs/architecture/operational-multi-tenant-direction.md`
- `docs/architecture/tenant-entitlements-foundation.md`

This repo has important architectural and deployment realities that must be understood first:
- Laravel backend is the canonical system of truth
- Shopify theme repo is separate from backend repo
- Production deploys from GitHub `main`, not from local dirty branches
- Storefront features typically use signed app-proxy endpoints
- Customer identity must reuse canonical marketing identity models
- Do not create parallel systems for reviews, rewards, wishlist, or identity

Before building anything:
1. Audit existing models, services, controllers, routes, migrations, commands, and views
2. Confirm whether the work belongs in backend repo, theme repo, or both
3. Confirm whether the code is only local or actually deployed
4. Reuse existing architecture before inventing new systems
5. For customer/inventory/order/ops changes, read:
   - `docs/architecture/operational-multi-tenant-direction.md`

## Platform Product Doctrine

- Historical ownership wording retained from earlier documentation: Fire Forge Tech is the platform owner. This is not a newly verified legal-entity statement. Use the current map for product branding; do not change legal/payment entities or recorded agreements from marketing copy.
- The Forestry Studio is the flagship tenant, not the architectural center of the platform.
- Flagship customization is allowed; platform coupling is not.
- Platform nouns, config keys, service names, and data models should remain domain-neutral unless a constraint is truly tenant-specific.

## Required Before Implementation (Hard Gate)

Do not implement a feature until all of the following are explicitly written:
- Classification: core platform capability / tenant configuration option / purchasable add-on / temporary tenant-specific override
- Tenant scope: what varies per tenant
- Entitlement/billing model: if applicable
- Canonical services/contracts reused
- Whether it works for a non-Forestry tenant without code changes

If this is not defined, stop and define it before coding.

## Forestry Bias Warning

- The Forestry Studio is the flagship tenant, but it is not the architectural center of the platform.
- Do not assume Forestry structure, naming, or UX is globally correct.
- Do not encode Forestry-specific language into platform models, services, config keys, or data models.
- Do not skip tenant abstraction "just for now."
- If a feature works for Forestry but not for a generic tenant, redesign the abstraction before implementing.

## Override -> Platform Graduation Rule

- Evaluate every tenant-specific override for promotion into:
  1. a tenant configuration option, or
  2. a shared add-on module.
- Do not let overrides accumulate as permanent parallel logic.
- Document the expected graduation path when creating an override.

## Storefront Sidecar Status (Recorded)

The current rewards storefront "sidecar" work was implemented in the separate Shopify theme repo:
- Theme repo: `/Users/johncollins/projects/modernforestry-live-theme`
- Runtime files:
  - `assets/forestry-rewards.js`
  - `assets/forestry-rewards.css`

Scope completed in that sidecar:
- Theme selector is hidden by default behind a compact top-right toggle
- Top summary/status clutter was removed from `/pages/rewards`
- Birthday experience was consolidated into a single expandable card
- Reward opportunity cards are collapsible by default for compact scanning
- Task/Rewards history blocks were removed from the rewards page UI
- Layout hierarchy and spacing were refined for mobile + desktop

## Storefront Sidecar Boundary (Strict)

- Theme-side JS/CSS may render UI, trigger actions, and consume backend responses.
- Theme-side JS/CSS must not implement business logic.
- Theme-side JS/CSS must not perform validation that diverges from backend rules.
- Theme-side JS/CSS must not create parallel state systems.
- Backend remains the single source of truth.

## Modern Forestry Mobile Live Deploy Cohesion (2026-06-30)

- The Modern Forestry mobile app is now sensitive to partial live deploys in two specific places:
  - Home boot: the cached-home flow requires both `app/Services/Mobile/ModernForestryMobileProductCatalogService.php` and `app/Jobs/RefreshModernForestryMobileHomeCache.php`. If the service expects the queued job but the job is missing, Home can 503 before the shell payload returns.
  - Checkout error handling: `app/Http/Controllers/Mobile/ModernForestryProductCatalogController.php`, `app/Services/Mobile/ModernForestryMobileCheckoutService.php`, and `app/Services/Mobile/ModernForestryMobileCheckoutException.php` must stay in sync. If the controller calls `$exception->diagnostics()` but the exception class is still old, Bag shows a raw HTTP 500 even though the intended checkout recovery path may be fine.
- Treat these files as one deployment unit for Modern Forestry mobile changes:
  - `app/Http/Controllers/Mobile/ModernForestryProductCatalogController.php`
  - `app/Services/Mobile/ModernForestryMobileProductCatalogService.php`
  - `app/Services/Mobile/ModernForestryMobileCheckoutService.php`
  - `app/Services/Mobile/ModernForestryMobileCheckoutException.php`
  - `app/Jobs/RefreshModernForestryMobileHomeCache.php`
- Real-device QA after syncing those files confirmed:
  - Home recovered and warmed from shell to fresh payload.
  - Account loaded signed-in data again.
  - Rewards resumed snapshot-first behavior.
  - Bag stopped surfacing the stale HTTP 500 after a successful refresh.
  - Native checkout opened Shopify successfully from the bag.

## Add-On Module Requirements

Every add-on must explicitly define:
- Canonical data ownership
- Tenant scope boundary
- Entitlement/billing check
- Admin configuration surface
- Storefront interaction surface
- Integration points (`API`, app proxy, theme, etc.)
- Canonical services/contracts reused

Add-ons are attachable capabilities, not isolated systems or feature flags.

Add-on implementation rules:
- Keep canonical identity in `marketing_profiles` (+ existing link tables/pipelines).
- Reuse existing signed storefront contracts (`/apps/forestry/...`, `/shopify/marketing/v1/...`) before adding endpoints.
- Gate availability by tenant-scoped feature/billing state, not hardcoded store/email checks.
- Prefer one shared module architecture with tenant configuration over per-tenant forks.
- Avoid creating sidecar data models for rewards/reviews/wishlist/identity.
- Tenant-specific UI/presentation is allowed, but it must sit on top of shared module logic and canonical backend contracts.

## Productization Principle

- Evaluate every feature built for The Forestry Studio as a future product for other tenants.
- Build for Forestry, but name, structure, and scope features so they can be reused or sold without architectural rework.

## Customization Ladder (Implementation Order)

Use this order for feature work:
1. Tenant content/config only
2. Tenant UI/theme composition
3. Shared module option
4. Shared module extension
5. Tenant-specific override
6. New bespoke code path (last resort only)

Do not skip upward on this ladder without documenting why the simpler level was insufficient.

## Historical TODO Backlog (Not Current Release Focus)

### Immediate launch goal
- [ ] Verify Candle Cash is visibly working on the live storefront and in the Laravel admin/backstage system (no date assumptions)
- [ ] Confirm the full customer-facing reward loop is functioning end to end:
  - earn behavior occurs
  - reward state updates correctly
  - customer-visible storefront state is accurate
  - admin/backstage visibility reflects the same truth
- [ ] Keep Growave-replacement parity in place for the launch-critical features already being replaced, so the program can be observed working in a real customer flow

### Current execution priority
- [ ] Finish/verify the launch-critical Candle Cash flow before expanding scope
- [ ] Prioritize operational visibility over new feature invention
- [ ] Prefer concrete verification of live behavior over additional architecture changes

### Next task after Candle Cash launch
- [ ] Get email working correctly
- [ ] Audit the current email flow end to end:
  - what sends
  - what triggers sends
  - what provider/config is in use
  - where failures or gaps exist
- [ ] Make email operational enough to support the customer/reward workflow after launch
- [x] Customer email timeline provider-context diagnostics now include:
  - row labels + summary chips
  - filters for provider resolution/readiness context
  - CSV export parity with active filters

### Platform direction (important but NOT current focus)
- [ ] Expand beyond Shopify into a general small-business operating system
- [ ] Support in-person onboarding for non-Shopify clients
- [ ] Allow flexible data models per business type (example: lawn care company storing:
  - customer
  - property photos
  - service history
  - plants installed / materials used)
- [ ] Ensure the system can adapt to different verticals without creating separate systems per industry
- [ ] Keep Laravel backend as the canonical system of truth across all verticals

### Scope discipline for agents
- [ ] Stay inside the current priority scope unless explicitly told otherwise
- [ ] Do not start broad multi-tenant refactors yet
- [ ] Do not start Shopify App Store packaging yet
- [ ] Do not expand into speculative AI automation work yet
- [ ] Reuse existing Candle Cash / marketing / identity architecture before creating anything new

### Operating principle
- [ ] The immediate business goal is not abstract architecture progress
- [ ] The immediate goal is a working, visible, revenue-adjacent customer system:
  - storefront
  - admin/backstage
  - email follow-through

## Modern Forestry Mobile Rewards Latency Note (2026-06-30)

- If Rewards or Account suddenly feels like it takes around 8-10 seconds to become live after sign-in, check whether mobile requests are repeatedly calling Shopify Customer Account GraphQL just to resolve the same customer identity.
- `ModernForestryMobileCustomerSessionService` now caches resolved customer identity by token hash for a short TTL clamped to JWT expiry. Preserve that behavior unless you are intentionally changing the auth trust model.
- Signed-in mobile flows should continue omitting buyer phone in checkout identity and should continue treating Laravel as the canonical profile store after identity resolution.
- On the client side, the app now kicks Account and Rewards refreshes together. Do not reintroduce a single serialized dashboard bootstrap path unless you also accept slower Rewards first paint.

## Modern Forestry Mobile Customer Login Readiness Rule (2026-07-25)

- The live Headless storefront is a public authorization-code/PKCE client. Production must keep `SHOPIFY_CUSTOMER_ACCOUNT_CLIENT_ID`; a Customer Account client secret is optional and must not be inferred as required from Shopify's discovery capability list.
- `/auth/config`, token exchange, production `/ready`, and `config:doctor --env=production` must agree on that public-client contract. Losing the public client ID must fail deployment readiness instead of shipping a healthy-looking backend with customer login disabled.
- Customer-login configuration and readiness checks are read-only with respect to `marketing_profiles`, Candle Cash balances/transactions, birthday profiles/rewards, Shopify customers, and identity links.

## Everbranch Tenant Mobile Rule (2026-07-10)

- `../everbranch-mobile` is the cross-tenant Everbranch app (`com.everbranch.app`) and its canonical remote is the private `johncollinsgit/everbranch-mobile` repository. Do not merge its concerns into the Modern Forestry SwiftUI customer app or its product-catalog APIs.
- Everbranch app 2.3.0 build 11 consumes bootstrap contract v3, Field Service contract v7, and My Day v5. Say Branch in tenant-facing discovery copy, keep canonical `module_key` identifiers internally, return `branches`, and preserve `modules` plus `/modules/...` only as compatibility aliases.
- Primary tenant navigation is server-driven and role-aware. Field managers receive Home/Work/Customers/Team/More; employees receive Home/Work/Clock In/Out/Messages/Account. Keep Field Agent capabilities cohesive and do not add tenant-name checks or duplicate Work/Field Service entries.
- Field Operations clock, channel, invitation, material, fleet, Job Draft, office-handoff, and asset-upload mutations must remain tenant-scoped and idempotent. Job visibility is entitlement-configured (`assignment_only` by default, `all_operational` for Collins). Active field-service members may create self-assigned jobs; editing existing jobs and progress still follow manager or assignment permissions. Only managers may assign others, attach vehicles, or link invoices at creation. Owner financial context must be omitted server-side for every other role. New asset writes use the configured private workspace disk and retain dual local reads until migration is complete.
- Operational Branches must provide a meaningful native workflow. Do not restore generic Share controls or expose summary-only/placeholder catalog entries.
- Work Branch uses canonical `work_core`; resolve orders/jobs/clients from tenant blueprint and experience signals. Never accept a client-selected vertical.
- Messaging Branch aggregates store keys server-side and reuses `MessagingResponseInboxService` plus channel readiness rules. Modern Forestry App threads are only `source_type=modern_forestry_app`, `store_key=retail`, tenant 1, and require an eligible authorized app device.
- Mobile APIs live under `/api/mobile/v1`. Use browser Fortify + one-time S256 PKCE exchange and Sanctum device tokens; never accept password credentials in the app or persist tokens outside Keychain/Android Keystore.
- Resolve `{tenant}` only through authenticated memberships with `EnsureMobileTenantAccess`, then use `TenantContext`, canonical roles, and `TenantModuleAccessResolver`. Scope every referenced job, customer, store, channel, module, and billing record again on the server.
- A module is absent unless its canonical `mobile.status` is `ready` or `beta`, its contract version is supported, and the tenant is entitled. Payloads may use only the finite primitives in `TenantMobileModuleRegistry`; they may not send JavaScript or arbitrary remote UI.
- Branches is the mobile name for the module store. Its discovery and gates must continue through `TenantModuleCatalogService` and `TenantModuleAccessResolver`. A client assertion never grants access.
- Tenant header branding comes from `TenantDiscoveryProfile`, falls back to the tenant name, and uses the published Modern Forestry wordmark for tenant 1 when no profile logo exists. Only the resolved tenant `admin` role may update mobile branding, and the change must stay audited.
- Trade Home metrics come from the tenant blueprint and tenant-scoped job records. Preserve the definitions for in-progress jobs, gross/contract value, distinct crew assignment, and potential/estimate/quoted pipeline work; never accept a client-selected work type or aggregate.
- Landlord access is not tenant membership. Authorized operators may reuse the current device session to switch context, but landlord APIs must still pass `MobileLandlordAccessService`. Landlord navigation is Home, Tenants, Tickets, Reports, and Account; never render tenant Work or Branches there.
- Tenant support tickets are a base mobile service. Tenant reads, creates, and replies must resolve through the current tenant; landlord assignment, replies, waiting, and resolution use the separate landlord routes and audit layer.
- Landlord reporting may expose catalog-derived MRR, tenant/user/activity totals, tenant mix/growth, per-tenant users/Branch readiness, and the explicitly sourced Modern Forestry operational-order summary only through landlord-authorized payloads. Do not add portfolio information to normal tenant bootstrap, and never relabel operational orders as payouts, profit, or accounting income.
- Every mobile-store Branch needs a purpose icon and useful owned-state product/setup copy. Do not restore generic Share actions, inert summaries, or client-only availability decisions.
- Mobile billing is a US-only, system-browser Stripe handoff behind existing checkout and lifecycle flags. Keep non-US purchase CTAs closed, maintain idempotent webhook/audit behavior, and recheck Apple/Google rules immediately before submission.
- Landlord direct Stripe invoices live at `/landlord/invoices` and are only for approved Everbranch service or Evergrove implementation/supplemental/milestone work. Keep email, text-only, and combined delivery behind `EVERBRANCH_STRIPE_INVOICING_ENABLED` plus tenant allowlisting, reject Shopify/third-party pass-through lines, mirror Stripe-confirmed totals/tax/receipt links, and never let invoice payment or reminders mutate module entitlements. Text-only delivery may finalize a non-auto-advancing Stripe invoice solely to obtain its hosted link; it must not call Stripe's email-send endpoint. Store billing phones encrypted; all invoice texts must use Stripe's freshly verified hosted invoice URL and current amount due, consent confirmation, opt-out checks, idempotency, and landlord audit evidence.
- Team Access must resolve through the active tenant and `tenant_user`; never restore a global `User::query()` directory, global account deletion, or legacy production roles on a tenant settings page. Pending rows must be tenant-addressed `platform_access` requests. Keep `wholesale_application` records, review routes, and Shopify wholesale synchronization inside the configured wholesale tenant's dedicated inbox.
- Field Service resource operations use `field_material_catalog_items` as warehouse truth, `field_service_vehicle_inventory` as van truth, and `field_inventory_movements` as the audit trail. Load, unload, adjustment, and job-consumption writes must be transactional, reject insufficient quantities, resolve every referenced record in the active tenant, and remain manager/admin-only. Members may read inventory and only the job deployments already visible through `FieldServiceAccessService`.
- Mobile job material requests are lightweight field-to-office requests stored in `field_service_materials`; they do not mutate warehouse or van inventory. Assigned/participating employees may submit them, while only owners/admins/managers may set `purchased` and add purchase notes.
- Mobile thumbnail reads must prefer `workspace_assets.thumbnail_*`, may create a missing derivative for a legacy image, use private cache headers, and must not record a full-file download audit event. Originals remain authorization checked and audited.
- Field Operations scheduling/timecards live under the canonical `time_tracking` add-on. Preserve the approved-hours CSV boundary: do not add payroll calculation, taxes, withholding, filing, remittance, or payment. Shift enforcement is tenant opt-in; employee correction requests must remain tenant/user scoped and retain before/requested/reviewer audit evidence.
- `fleet_tracking` is a separate default-disabled, internal-only tenant add-on. Its global flag, module entitlement/dependencies, tenant-scoped encrypted Bouncie OAuth connection, counsel-review record, policy version/hash, employee acknowledgement, active timer, and signed Bouncie webhook verification are independent fail-closed gates. The crew-map v2 contract exposes company vehicles separately from employees. Keep company-van Bouncie points separate from employee phone points; enforce a 30-day maximum raw retention, owner/admin-only viewing, and no personal/off-duty tracking, speed scoring, geofence alerting, or automated employment decisions. Follow `docs/operations/field-operations-location-tracking-runbook.md` for rollout and rollback.
- The fictional Green Shield Pest Control sales fixture is created only through `everbranch:prepare-pest-control-demo`; it is idempotent, isolates its public demo user to that tenant, and creates only fictional team, customer, job, task, shift, vehicle, policy, location, income, and cost records. Its routes and financial values are visibly fictional demo data and must never be presented as live GPS or QuickBooks data. It never represents a real customer, vehicle, policy, location, or legal review. Its public password is intentionally non-secret and documented only for this demo. Its public CTA is a CSRF-protected, demo-only POST that signs into this isolated account directly; do not reuse that mechanism for real tenants. The authenticated fleet surface remains fail-closed until `FLEET_TRACKING_ENABLED` and all ordinary rollout gates are complete.
- Field-service Customers use tenant-scoped `MarketingProfile` records, including QuickBooks-imported identities. Never source this screen from `wholesale_application` access requests or another tenant. SMS handoff must re-resolve the selected profile inside the active tenant and retain consent/provider/opt-out gates. `mailto:` may open the user's device email client but must be labeled untracked and must not create Everbranch delivery evidence.
- In the tenant sidebar, Settings is pinned immediately above the final Workspace Guide group; User Agreements and Account Help remain above Settings. User preferences may reorder other destinations but must not move Settings below or away from Workspace Guide. The Work grid opens jobs only from its explicit frozen `Open` control; normal row clicks do not navigate and editable cells retain single-click editing.
- New-module work is incomplete until the catalog declaration, tenant scoping, entitlement checks, provider/schema, supported actions, backend/client tests, phone screenshots, and relevant READMEs are updated. The exact checklist is in `docs/architecture/everbranch-mobile-platform.md`.

## QuickBooks Branch + Financial Access Rule (2026-07-13)

- QuickBooks is the shared `quickbooks` beta Branch. Do not create tenant-specific QuickBooks forks. Discovery in onboarding is interest only; it must never create an entitlement, begin OAuth, run an import, or activate billing.
- OAuth, full audits, and live sync require an enabled tenant entitlement plus a verified tenant owner/admin. Commands must fail closed when the Branch is not enabled.
- Keep the connector read-only and on demand. Do not add payments, QuickBooks write-back, webhooks, CDC, recurring sync, Estimator activation, or payroll activation without a separate approved release.
- Store OAuth tokens, realm identifiers, raw source snapshots, and audit payloads encrypted and tenant-scoped. Never print raw QuickBooks records, customer details, or invoice notes to logs, CI, chat, or support tickets.
- Do not equate a QuickBooks line description with a field job. Import every estimate/invoice as a financial document, classify job evidence through `QuickBooksJobEvidenceClassifier`, and leave insufficiently evidenced documents in owner/admin review. Team members must never receive standalone financial-document search results or detail routes.
- Financial documents and lines remain separate from field-service jobs. Link or create a job only from source evidence, preserve manual jobs, use provider/type/external-ID identities, and prove repeated imports remain duplicate-free.
- QuickBooks `PrivateNote` content is owner/admin-only. Team members may receive operational customer memos and work-line descriptions, but not financial reports, amounts, receivables, P&L wages, contract labor, price-book costs, billing controls, or integration credentials.
- Dashboard ranges are `1d`, `1w`, `1m`, `30d`, and `ytd`; `1m` means current calendar month and is the default. QuickBooks reports retain their requested report period and must not be relabeled to match a dashboard filter.
- Collins-specific ownership and workflow notes belong in `docs/collins-electric-access-and-quickbooks.md`; shared connector behavior belongs in canonical services/config/tests.
- Apple review data is created only through `everbranch:prepare-app-review-workspace`; it uses the `everbranch-review` tenant and fictional contacts/work. Never place reviewer passwords in source, logs, screenshots, or deployment output.

## Trajectory financial privacy and planning

- Read `docs/operations/trajectory-runbook.md` before touching this Branch.
  `FinanceAccess` requires global availability, enabled space, canonical
  entitlement, active verified user, and household membership or tenant financial
  permission. Never let platform-admin status bypass household privacy.
- Keep provider secrets/raw evidence encrypted; resolve all account, transaction,
  record, invitation, SMS, and cross-space IDs again on the server. Shared
  allocations must not disclose the original account or unsplit transaction.
- Use integer cents and decimal arithmetic. Reviewed rules win; source refreshes
  retain corrections. Transfers/asset movements and operational sales summaries
  must not duplicate consumption or accounting revenue. Never write back to QB.
- Trajectory Accounts is the sole connection surface. A connected feed must be
  visibly distinguishable from manual/imported accounts; direct Connect opens
  Plaid. Bulk review suggestions are proposals only: require explicit application,
  exact transaction/version checks, and keep broad merchants/transfers out.
- Forecasts and metal values expose missing/stale coverage and assumptions. Keep
  one-time shocks out of recurring baselines, linked debt bills out of duplicate
  payments, saved goals out of consumption, and linked business equity out of
  combined net worth. Keep payroll conclusions proportional to imported evidence.
- Public subscription packages are preparation only. Do not invent prices or
  remove checkout's hard gate without production access and Stripe lifecycle
  acceptance. Rollback disables the Branch and preserves financial records.

### Medical sharing in Trajectory

- Read the medical-sharing section in `docs/operations/trajectory-runbook.md`.
  Needs, bills, provider payments, member shares and monthly contributions are
  household-only encrypted records, not accounting or clinical records.
- Preserve `medical_payment`, `medical_share`, and `medical_membership` flow
  semantics: actual payments count in gross spending; receipts do not become
  earnings; none repeat from history. Monthly contribution matches use their
  existing confirmed recurring schedule. Never mutate a linked source through
  classification, split, reconciliation, SMS, or asset matching.
- Run MedicalSharingTest plus the existing Trajectory tests and browser smoke
  after changes. No schema change was needed for the sharing workflow.

Wholesale application reviewers may use the tenant membership role `wholesale_reviewer`. It authorizes only wholesale application decisions for that tenant and requires active membership; it does not grant a global admin role or platform-access approval. See the wholesale application reliability runbook.

Wholesale reviewer decisions resolve missing Shopify staff emails through verified online token exchange. Match the returned staff ID and verified email before looking up existing tenant reviewer membership; do not trust the store contact email as the acting staff identity.

### Trajectory finance controls (2026-09-19)

Income categories/overrides belong to a finance space, never global pilot defaults.
`PlanningController` mutations resolve finance access; company assignment also
requires the connection owner and revalidates linked records under locks. Never
reset `Account.space_id` from `Connection.space_id` during bank refreshes.
Anomalies separate semantic fit, within-space history, and general practice; do not
invent peer statistics. Normal exceptions are version-specific. P&L remains
QuickBooks-authoritative, with explicitly provisional bank evidence otherwise.
Bud Core is deterministic and private to the selected space; no automatic support
escalation, generative provider activation, or price invention. Follow the finance
controls section of `docs/operations/trajectory-runbook.md` and run focused tests,
full regression/build, and normal release gates.

## Team message files (2026-10-05)

Mobile conversations support up to five private photos/PDFs per message, 50 MB per file, through bounded 512 KiB chunk uploads. `team_message_attachments` owns these files on the private local disk. Channel access, current workspace membership, uploader ownership, exact checksums and detected MIME are checked server-side. Files become readable only after attachment to a visible message; workspace assets and Modern Forestry commerce are not involved.
# Tenant SMS provisioning safety (2026-10-19)

Create Twilio subaccounts with `POST /2010-04-01/Accounts.json`; posting to an
account-specific URL updates the parent. A tenant account must differ from the
platform SID, belong to that platform, and be exclusive to one tenant. The
provisioner durably reserves intent outside provider HTTP and must not be wrapped
in a caller transaction. Do not retry an ambiguous creation or reuse a parent
binding. Follow `docs/operations/wren-soccer.md` for reviewed Wren reconciliation.

## Teams connected operations candidate (2026-10-20)

Events/Wholesale operations are default-off extensions of the shared Teams Laravel application. Read `docs/architecture/everbranch-team-connected-operations.md` and `docs/operations/everbranch-team-connected-operations-runbook.md`. Preserve authoritative Asana/Sheets/mail/commerce sources, source-generation fences, per-user access and encrypted evidence. Do not send or enable external sequences from template publication or internal rule saves. Reuse the existing email composer, messaging controls and shared AI usage ledger. No legacy Event table reuse without tenant mapping. The complete Phase 2 plan is not yet accepted; live OAuth/source discovery and real MySQL/Linux/protected-release gates remain outstanding.

## SES migration preparation (2026-10-20)

Read `docs/operations/ses-migration.md`. Existing SES tenant sending is hardened
behind `SES_TENANT_SENDING_ENABLED` and `SES_TENANT_IDS`, independently of SMS.
Claims survive stale delivery saves; unknown outcomes require reconciliation and
cannot blind retry. Signed SNS events require the exact account/region/topic and
tenant/delivery binding. Attempt/cost gates default closed. `email:ses-readiness`
is read-only. SES claims bind the original tenant usage reservation; authenticated
acceptance settles it once without repricing. Zero-rate contracts retain usage
and provider-cost tracking without a customer charge. Review actual billing
exemptions before customer cutover. `email:ses-reconcile-usage` previews internal
settlement recovery; `email:ses-confirm-subscription` previews an encrypted,
authenticated pending SNS confirmation and requires explicit `--confirm`.
AWS account 894665396752/us-east-1 is sandbox on Essentials; theeverbranch.com
and DKIM are verified. Approved simulator resources are being configured.
Platform SMTP remains SendGrid; actual AWS delivery/application event acceptance,
runtime credentials, production access and a separate platform retry/event
contract remain required before completion.

## Enterprise separate application (source candidate, 2026-10-21)

Read `docs/operations/everbranch-enterprise.md` and the approved architecture in
`docs/architecture/everbranch-enterprise-boundary-proposal.md` before continuing
Enterprise. Its entry point, config, DB/schema installation, storage and release
boundary are independent of retail. Never copy production users/data/credentials
or run legacy membership/profile backfills. Reuse canonical tenant/Teams rails;
department restrictions must hold through every native graph access path. All
Enterprise rollout and writes remain off until reviewed; no new deployment exists.

The October 22 source increment is documented in
`docs/architecture/enterprise-next-slice.md`. Preserve the default-off feature
gates, internal discussion-task exclusion from operational progress, private file
scanner quarantine, separate owner/admin cost visibility and current-permission
Ask citations. Model transport reuse does not activate paid AI. Mailbox preview/
confirmation is fake-adapter tested; the default configuration has no connections or
sending capability. Do not substitute retail/owner credentials or create grants.
## Enterprise workflow workspace (October 22 source candidate)

The isolated Enterprise application now has a dedicated project workflow workspace,
screen/form builder, reviewed activation, durable human runs/history, authorized
Home briefing and regenerated one-page PDF. Build its assets separately with
`npm run build:enterprise`. Real-capable Responses/own-user Gmail and SMTP adapters and empty
mailbox schema require separately approved configuration; source installation grants
no access or provider connection. Read `docs/architecture/enterprise-workflow-workspace.md`
and `docs/operations/enterprise-integrations.md`. All new rollout flags default off;
neither draft PR nor fixture evidence establishes deployment or live acceptance.

Gmail uses its own fixed callback, original browser/user/tenant/department-bound
one-use OAuth state, encrypted refresh tokens and exact confirmed send receipts.
Read `docs/operations/enterprise-gmail.md`; Google login credentials and automatic
provisioning are not inherited. Connection and sending flags default off. The
intended own-user sender is john@evergrovesoftware.com. The subsequent reviewer
trial uses a verified service sender, no reviewer mailbox access and a $5 total
shared provider cap, superseding the earlier $1 AI-only allowance. Credentials,
OAuth grants, recipients, real sends and deployment still need exact approval.
## Enterprise job voice source prototype (2026-10-22)

Read `docs/operations/enterprise-voice-jobs.md` before integrating Talk into the
existing Ask pane. `enterprise.voice_jobs_enabled` defaults off. The new lookup
is record-backed, read-only and department/task scoped; it never dispatches a
model, email, SMS, payment or write. Reuse the existing shell/detail/map and let
the Enterprise owner place the actual accepted capability last in its tour.
Keep sample fixtures visibly fictional. No source/browser result establishes
physical speech, deployment or live provider readiness. Existing trial budgets
and other customers' credentials do not authorize a paid voice connection.
## Reusable service proposals (October 22; draft source, disabled)

Read [the proposal boundary](docs/architecture/reusable-proposals.md) before
mounting or activating the shared engine. Native business tenants, Enterprise
department areas, client tenants and HighLevel locations are distinct. No owner
binding is inferred by name. Keep published versions and exact selections,
review display names, final signature evidence and provider lifecycles separate.
Lees-McRae has client-only prices and no-markup usage; never copy that offer into
global rates. Both feature flags and binding/host lists default closed. The
campus header/tour and payment stack remain under their existing owners.

## Grounded follow-up, reviewer voice and private photos (source only)

Native record-backed answers use current saved building/project/task/member and
Planning permissions. Conversation sends only selected evidence and deterministic
integer currency/basis cost comparisons; full evidence changes invalidate history.
The private reviewer remains a separate closed fictional projection, without a
native User or real-campus grant. Its shared server-workspace seam is a separate
required acceptance step; browser snapshots are not authoritative hosted records.

Private reviewer follow-ups include at most two accepted same-session/evidence
receipts, displayed before each Send. They inherit the earliest ten-minute content
expiry; the final pre-provider read rechecks it. Speech uses that exact text client,
explicit per-turn sharing, Stop/Interrupt and typed fallback. Legitimately unlocked
computers/phones can independently use it; session receipt fences are not a device
allowlist. Persistent memory and server transcription remain out.

Private photos expose only clean-ready, currently authorized discussion-bound
JPEG/PNG bytes, preserving quarantine and unchanged attachment downloads. Nested
viewers retain ticket drafts, placement, focus and scroll; hidden tabs retire bytes
without false access denial, while real permission loss clears controls. Fictional
sample uploads remain quarantined metadata, not fabricated ready thumbnails.

All new behavior stays default-off/source-only until normal CI, explicit immutable
edition/access/comment transition and approved hosted acceptance. Simulated speech,
intercepted endpoints and phone viewports do not prove physical microphone/mobile,
real provider calls or live private-image/scanner readiness. See the reviewer AI
and attachment-gallery runbooks. Keep SES transactional source in its separate
packet; no provider/key/scanner/mail/global routing activation follows from this.


### 2026-10-24 scheduled cleanup source checkpoint

The default-off `proposals:prune-scheduled-workspace` source provides at most one
finite graph page and one comments page under a stable context MySQL advisory
lock. Exact fixed-file/config pins, current real-owner/context/Version checks,
per-kind predecessor lineage, two bounded durable cursors and conservative partial
outcomes retain the existing manual HTTP erasure behavior. See
`docs/operations/proposal-reviewer-workspace-scheduled-cleanup.md`.

The assembled local PHP8.5/SQLite/pure/refusal checks pass31 cases/865 assertions,
with one explicitly skipped actual-MySQL scheduled mutation case. CI-plan3 and
workflow lint pass. These focused checks include current capture configuration
and source-closure integration; prior broad/browser suites were not repeated.
Actual MySQL8.4/PHP8.4 exact-head CI, query/locking behavior and hosted cleanup
remain unverified. No runtime policy/delegation/ENV/cache/job was installed.
Recurring operation needs its own concrete finite-scope human approval and
observed installation; this source checkpoint never supplies that authority.


### 2026-10-24 CI fixture repair checkpoint

The first normal 97dce88e CI run exposed extracted JavaScript fixtures missing
current production declarations/teardown helpers, three PHP test-formatting
failures, a child configuration test missing Composer autoload, and parallel
source-fingerprint interference. The repair changes tests and documentation only.
Original cases and assertions remain. Five added JavaScript cases check actual
native/shared delegation, lifecycle fences and production privacy cleanup helpers;
the exact existing 31-file prepare command passes 230/230 locally, with no skips.

The unchanged locked Pint passes its three corrected tests. Required unused-import
removal and braces accompany whitespace; independent name-resolved whole-AST
proof preserves all 10 test methods, 81 assertion sites, control flow and comments.
The configuration child now loads the same Composer autoloader as its neighboring
fixtures; both original tests pass. No application/configuration behavior changes.

Two source-mutating suites now use a bounded private nonsecret source snapshot.
Real source/module drift still produces the original denials; separate readers
retain unchanged original bytes. Runtime ENV, storage, credentials and sessions
are never copied. Four synchronized two-process checks demonstrate the old race
and its isolation. Root's combined full-class cohort passes 106 tests and 1,623
assertions in four parallel processes on local PHP 8.5/SQLite. This targeted result
does not establish that every original CI failure had the same cause.

Application source, dependencies, formatter rules, workflows, all 23 browser lanes,
parallelism and strict source/access guards remain unchanged. The original general
MySQL recovery job passed; Enterprise-specific MySQL gates were skipped after
prepare failed and remain unverified. Final new-head normal PHP 8.4/MySQL/browser
CI is required. No runtime, publication, provider or deployment action occurred.


### 2026-10-24 fresh formatter and asynchronous test follow-up

Normal 5816 CI passed the 230-case JavaScript transport step, then exposed two
comment-UI waits exhausting 200 `setImmediate` turns in roughly 8–10 ms. The
test-only wait now uses a five-second monotonic deadline and an awaited 1 ms
timer. Every original test body/assertion is unchanged. A controlled 50 ms delay
around genuine WebCrypto reproduces the two CI failures with the old helper;
both pass after correction. All 307 cases of the affected command pass locally
on Node 26.3.1; missing transitions and predicate errors still fail. This does
not substitute for final exact-head Node 22/PHP 8.4/MySQL/browser CI.

The locked formatter's first pass left five comma spaces unfixed; its own cache
masked them. Fresh-cache checking reproduces the failure on local PHP 8.5 too.
The earlier local PASS did not establish fresh final-byte idempotence; that cache
assurance is superseded. Exactly five spaces correct the two tests, preserving
all non-whitespace tokens/comments and the full resolved AST. A new explicit
cache passes both files and another new cache passes the exact 321-file workflow
selection. No formatter rule, test case, application source, dependency, workflow,
parallelism, runtime or provider gate changed. New-head CI remains required.


### 2026-10-24 native MySQL and standalone tour fixture correction

Normal 4a0 CI passed quality, both general jobs (5,321 PHP tests / 48,248
assertions, eight skips) and 20 of 23 browser lanes. Enterprise MySQL exposed a
new unreleased collaboration receipt kind of 26 ASCII characters against the
unchanged released VARCHAR(24). Its sole product correction uses the unique
23-character ent_proposal_ws_cmt_cmd namespace. All store methods, encryption,
source/context/request/digest checks and once-only behavior are unchanged. No
schema/data migration, truncation, old-name fallback or live rename is authorized.
The collaboration class and both names are absent deployed main9a4 source; this
is source provenance, not a live database inventory.

Native test fixtures now order team_work_locks by its actual tenant_id primary
key, compare receipt objects with exact closed keys/types/values independently
of MySQL JSON key placement, and balance only their own Laravel handler layers.
All 200 original assertion sites, workers and refusal guards remain. Handler
observations use PHP8.4-compatible balanced set/restore operations. Native get_*
handler functions arrived in8.5, but the locked autoloader supplied their Symfony
polyfill on8.4; no actual8.4 failure from those names was observed. Real native
MySQL acceptance still requires the final normal CI gate, never local skips.

The standalone public tour fixture resolves exactly three known nested AI-false
expressions, each required once. Its unresolved-Blade/embedded-asset guards and
all existing browser cases remain unchanged; full standalone Chromium and WebKit
checks pass. Product templates and tour JavaScript are unchanged. Five focused
renderer cases and two native units are added to existing CI commands; every
original command, matrix lane and guard remains required.

The old native-WebKit wizard gate failed on hidden Next after a click. Two exact
local unchanged runs passed; an assumed catalog race was not reproduced. No
speculative product/test workaround is added. The repaired-head WebKit gate must
pass. No runtime, provider, publication, merge or deployment action occurred.
