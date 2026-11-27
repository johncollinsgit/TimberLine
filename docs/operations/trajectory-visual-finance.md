# Trajectory visual finance

## Product contract

Trajectory opens on the current month. Home emphasizes budget allowance remaining,
incoming funds by source, and purchases by category. Analysis keeps comparison
bars and the selected period distinct: year/month/Monday–Sunday week, selected
period, category/source, subcategory, then a sortable editable ledger. Back and
Forward retain the selected period, filters, sort, scroll and authorized context.
Accounting, sales tax, medical sharing, debt and planning remain available under
the existing tools rather than crowding the Home page.

Charts use stable category colors, text amounts, touch controls and keyboard
alternatives. A negative net-refund category remains in the exact-value list; it
does not become a negative pie slice. Missing history is unavailable rather than
zero. Partial comparison periods end at the equivalent elapsed date; absent ISO
week 53 remains unavailable. No chart difference establishes savings or funding.

Tapping a donut slice first explains its recorded amount, share of visible slices,
period, financial basis and source. Only View recorded entries invokes the chart’s
existing drilldown. Balance-only charts have no fabricated transaction action;
navigation or a superseded chart closes the explanation without adding history.

`AnalysisService` builds one authorized projection for selected-period pies and
the largest-first ledger. The annual/monthly/weekly comparison buckets remain
independent of the nested selected period. `AnalysisCategoryService` provides
the presentation hierarchy while preserving historical category keys.

## Financial definitions and budgets

Purchases when made is the default spending basis, including credit-card
purchases and known interest/fees, net of purchase refunds. Card and loan
repayments, investments and transfers do not become duplicate consumption.
Bank cash movement retains full owned-bank amounts and separately names debt,
investments, borrowed cash, asset-sale proceeds and reimbursements. Household
company receipts use the existing confirmed source mappings. Shared allocations
do not expose another entity's unsplit bank amount or account identity.

Budget allowance is reviewed dated target plus explicitly approved carryover,
less posted economic spending and pending commitments in the same month.
Ordinary categories reset monthly. Explicit rollover funds need a start month
and an opening allowance; future carryover remains unknown until the prior month
closes. This allowance is not a bank balance or proof of cash funding. Draft,
expired, overlapping, partial or missing targets remain identified. A parent
target and child targets cannot be added twice. Program targets select effective
cohorts within their own program. Payroll retains owner, studio and event roles.

## Plan Trajectory

The Debt payoff view loads the household’s inventory on demand from
`debt-scenarios` with `view=household_plan`. Separate observations of an account
describe one liability. The view compares payoff orders with the same monthly
extra. The highest known rate can identify a conditional first target when
missing terms prevent a payoff projection. Saving a method and amount updates
one versioned monthly reservation. Budget applies it once to the remaining
allowance from its effective month. Required payments stay in scheduled costs.

The household Plan opens through the authorized plan-context endpoint. The
monthly take-home target combines scheduled bills, required debt service,
confirmed medical installments and variable spending. The default uses the available
history from the previous three complete calendar months. A saved estimate can
be selected independently. Optional
savings and extra debt payments are shown separately. Current-month spending
and the preceding two years use the same calendar cutoff. Coverage appears
beside each period.

Bank cash uses the selected balance sources and displays observation dates.
Plan shows recorded medical reserves and medical deposits awaiting a case match.
Received medical deposits can be spread over a chosen number of monthly
payments. A saved plan identifies one sender and receipt month. It records an allocation
schedule against the original receipts. The original total can differ
from the amount still on hand.

A cash-cushion scenario can include a chosen amount of cash and selected
estimates of asset sale proceeds. General assets require an entered net sale
estimate. Metal lots use the remaining quantity and an identified price or
dated owner offer. A temporary draw from medical cash includes a replacement
amount for the remaining payment months. Scenarios assume even monthly timing.

## Interfaces and attribution

All space routes require authentication, verified account and FinanceAccess.
Analysis and budget results are private, no-store:

- `GET /trajectory/spaces/{space}/analysis`: year, comparison_year, grain,
  from/through, basis, optional account_id and program_id. Returns selected
  summary, source/category hierarchies, comparison periods, exact source/detail
  entries, category/program options and coverage.
- `GET /trajectory/spaces/{space}/budget-envelopes`: month and optional program_id.
  Returns reviewed target, posted use, pending commitments, carryover, remaining,
  source record IDs/versions and actual-through metadata.
- Attribution and definition routes are documented in
  [trajectory-attribution.md](trajectory-attribution.md).

Programs share their parent organization's bank account without creating new
balances. Economic attribution expands detail lines only for views that need
them. Original source IDs stay numeric; entry_key identifies each economic
detail and cash/economic representation. Do not feed expanded entries into a
bank-cash aggregator that deduplicates original source IDs.

Personally funded organization costs remain the original personal bank debit
with organization economic attribution. Owner-confirmed reimbursement status
is a private annotation, not a synthetic receipt, income or second expense.
Future actual settlements must match real bank evidence. Do not reclassify all
historical reimbursements merely because a category or merchant looks similar.

## Ask Everbranch and mobile

Personal and Business use separate install identities at `/trajectory/personal/`
and `/trajectory/business/`. Business retains its authorized organization
selector. Neither service worker caches authenticated finance data.

On desktop, the header shows the current date range below the page title.
Account and Period share a toolbar with the Analysis grouping interval. Report
options opens the comparison years, spending basis, program when available,
category organizer and report actions. On phones, the calendar opens Dates &
view with the same controls and the workspace selector. Selecting Custom reveals
the start and end dates. Escape and Done return focus to the opener. Foreground
updates pause while either options panel is open.

Home, Analysis and Budget reuse one successful, authorized in-memory snapshot
when only the screen or client drilldown changes. The key includes the financial
context, data revision and every visual server input; there is no persistent or
multi-period cache. Back to another period fetches its visual projection only.
Explicit Refresh, context changes and successful edits invalidate the dashboard
revision; a failed reload cannot mark an older snapshot fresh. A current dashboard
with missing visual data fetches Analysis/Budget without downloading it again.

An initial current-month visual view requests its authorized Analysis/Budget pair
alongside the legacy Dashboard. It paints the real visual response and source
metadata first; no Dashboard financial fields are synthesized. Editing and legacy
views wait for native metadata. Space/account/server-date checks associate a late
Dashboard only with its matching visual cohort. Context changes abort superseded
initial requests, and failed projections remain errors rather than zero totals.
Canonical period snapshots stay in memory for five minutes during exploration;
each period switch still requests fresh source figures. Explicit refresh, edits,
source changes and lost access invalidate them. Nothing is cached in persistent storage.

Notifications confirms this device is enabled only after browser permission, a
local push subscription and authenticated server association agree. Failed checks
remain unknown with Retry; the member’s purchase-alert opt-in stays separate.

While visible and online, Home, Analysis and Budget reread saved backend figures
every minute and on return to the app. These are private network GETs, not Plaid
or provider refresh requests. Hidden/offline pages, bank linking, navigation
restoration, open Ask/edit dialogs or detail disclosures, the phone drawer, and focused form controls
suppress refresh. Responses are discarded after an intervening interaction,
scope/date/selection change or explicit reload. Successful updates retain the
drilldown, Back history, sort, page/table scroll and focused chart control. Failed background
reads retain the displayed dated snapshot; explicit Refresh shows request errors.

Ask Everbranch reviews one posted purchase at a time. When a destination is
unclear, the conversation asks for its name. The optional picker searches active
destinations in the selected workspace. Old conversations with a saved category
catalog open without rendering that catalog. The planning question page links to
the same transaction chat and retains its own budget draft workflow.

Ask Everbranch receives the current space, account/program, dates, basis,
category, selected source IDs and versions. The server resolves the figures and
permissions. Explicit categorization uses audited native services with Undo;
ambiguous destinations ask for clarification, suggested batches/splits expose
review, and stale versions cannot overwrite later edits. Existing provider
provenance, tenant AI approval and spend caps remain in force. Routine expense
alerts and deterministic classification do not require a paid model request.
There is no payment, trading, transfer or QuickBooks execution capability.

Expense alerts originate from new connected-feed expenses after opt-in, including
pending purchases. Initial history, backfill, provider retries, posting and
categorization cannot create another new-purchase alert for the same source.
Each eligible household member opts in independently. The inbox remains usable
without browser push. Delivery and open recheck ownership and access; withdrawn
access or an account reassignment cannot expose retained private payloads.

Configure Web Push through the normal application environment:

- `TRAJECTORY_WEB_PUSH_ENABLED=true`
- `TRAJECTORY_VAPID_PUBLIC_KEY` and `TRAJECTORY_VAPID_PRIVATE_KEY`: one stable
  server VAPID pair; never rotate during ordinary deploys or commit the keys.
- `TRAJECTORY_VAPID_SUBJECT`: a valid application contact URL or mailto URI.

Install on the phone, then enable purchase alerts and grant notification
permission through a user gesture. Notifications show merchant, amount,
category and known category allowance by default, with per-user privacy controls.
They open the exact purchase conversation; replies occur inside the installed
web app. Alerts arrive when the feed supplies an expense, not necessarily at
card-swipe time. Provider acceptance does not prove delivery to a physical phone.

## Verification and release

Relevant feature coverage: VisualFinanceAnalysisTest,
VisualFinanceAttributionReadTest, TrajectoryAttributionTest, MonthlyBudgetTest
and AssistantMobileTest. Browser coverage includes visual-finance, assistant,
navigation-history, household-mobile and existing money-flow suites. Fixtures
are fictional; do not commit owner financial extracts or production screenshots.

The attribution and assistant/alert migrations are additive and retain evidence
on rollback. Both are registered in the migration recovery manifest and executed
in the protected schema jobs with explicit disposable-MySQL guards. Rehearse
from the approved baseline, verify the updated data-free schema fingerprint and
blank-dump boot, and retain the ordinary protected PR/Forge exact-SHA release.

Live acceptance checks Home → comparison bar → pie → category → ledger → edit
→ Back on desktop and mobile, verifies separate installations, and proves actual
iPhone permission/background delivery independently of automated browser tests.


Budget cards expose the reason a remaining allowance cannot be calculated. Draft and dated
plans show their entered amount and effective dates, rather than an unavailable balance;
a reviewed target remains a plan, never bank cash. Parent/child overlaps, shared parent
allowances, missing rollover openings and missing history retain separate statuses.
Future months show plans without claiming zero actual spending or an available allowance.

Budget records may set `cash_spending_pending` or `category_review_pending` through the
normal validated, versioned record service. These flags preserve the approved target and
recorded costs but hold the exact remaining allowance until the actuals are resolved.
Category-review amounts are labeled recorded/estimated; source notes explain their scope.
Cash received is funding, not assumed spending. Clear a flag only after its source evidence
has been reconciled; changing it does not create or modify any bank transaction.

An unresolved sales-tax payment holds only interest, tax penalties, tax fees, and their
configured category ancestors in the same program, including affected rollover history.
Unrelated allowances keep their recorded remaining amounts; overall coverage and aggregate
remaining stay incomplete until the tax cost split is known. Sales-tax principal is never
reclassified as an operating cost by this display.


An optional `reported_cash_spent_cents` / `reported_cash_as_of` pair records a cumulative
owner cash-spending observation for that calendar month only. Native validation requires
both fields, nonnegative cents, and a real as-of date within the plan's effective dates
and no later than today. This is a budget observation, not a dated bank transaction.
Cards show bank-recorded and reported cash costs separately; the allowance subtracts the
report once. Later months never inherit the amount, multiple reports in one selected
category cohort hold the allowance for review, and unresolved cash/category flags still
hold exact remaining values. Transaction charts and account balances remain unchanged.


Transaction review uses the same real source rows in a desktop ledger and stacked mobile
cards. Phone category controls, source details and Confirm/Change actions remain inside
the viewport. Proposed categories are read-only, based on exact reviewed native merchant
history in the current entity/account/program or a low-confidence title hint. Mixed-use
and conflicting history remain explicit. A user must Confirm or choose a different
category; Decline leaves the purchase unreviewed. No proposal writes, learns a rule or
changes bank cash. Confirm uses the original versioned classification or complete native
attribution bridge, preserving source amount, flow, flags and other split lines. Undo
uses the returned source version/event; stale source or revoked access requires a fresh
review. Requests/responses from a previous scope cannot paint or save in a new context.

When the viewer currently has financial access to both sides of a shared cost, the
merchant, paying entity and actual source account are readable. Restricted sources retain
a meaningful shared-cost/payment label with account/card details hidden. Bank account
masks stay account labels. A physical card's last four requires a reviewed native
`transaction_source_identity` record bound to the current bank fingerprint; duplicate or
stale evidence displays as needing review. This evidence never changes ownership, money
movement or the allocated amount. Source access is rechecked per projection in batches.

Primary-view calendar changes reuse only a short-lived, in-memory authorized projection
when the server's canonical range lies inside its exact recorded rows. Dates come from
the server; entry keys preserve economic split identity. Fresh Analysis, budget and
legacy detail reads run concurrently for every supported period, and advanced details
wait for real metadata. The cache clears after edits, explicit refresh, source/account
changes or denied access; a late response cannot restore a denied projection. An empty
Today view explains that no purchases/receipts are recorded yet and shows the latest
recorded date instead of inventing zero actuals.


Home’s bills card reuses the background Dashboard cohort; it never delays the early
charts or reloads ledger history. Its partial monthly cash total uses calendar-month
obligations, with paid, processing, estimated and payment-status-review amounts kept
separate. A receipt’s obligation/due month, optional verified service month and actual
bank-posting month are distinct. Paid-in-advance invoices do not become due again;
older shares paid now are cash paid now, not new current-month obligations. Card-funded
service charges and lender requirements beside a reviewed weekly cash schedule are
listed separately rather than added to the same cash payment twice. Giving commitments
sit inside the Giving allowance; savings goals and asset transfers are not bills.

Versioned `bill_observation` records bind reviewed invoice/receipt evidence to an
existing recurring schedule, debt or medical plan and optionally an exact owned bank
source. Saving validates ownership, amount, dates and pending/posted status under locks;
replaying identical evidence is idempotent, and conflicting duplicate references or
bank-source links require review. These records never create a bank transaction or
initiate a payment. A stale variable schedule needs an explicit unknown-future flag or
reviewed schedule change, so a credit-adjusted share is not repeated at its old amount.
Missing dates/amounts remain named review items. Cross-scope details and late modal
handoffs are rejected; ordinary native record/evidence navigation preserves Back.

Analysis category charts preserve a purpose level before merchant groups. Merchant
keys are presentation-only; exact economic entry keys determine the final editable
ledger, and native category/program edits and Ask context never receive synthetic
merchant categories. Direct parent-category purchases remain available alongside
subcategories, and either path can return through its own chart and breadcrumb.

Ordinary spending allowances use the latest eligible previous monthly cap when a
month has no explicit plan. This is a read-only default shared by monthly planning
and the category envelopes. Explicit current plans win; `carry_forward: false`,
partial-period plans, rollover funds, mapped reserve goals and later parent/child
replacements do not revive old caps. Owner cash reports never repeat into a new
month. The previous calendar month's recorded spending is a separate suggestion,
marked partial when coverage is incomplete; using it previews an amount and only
Save creates or edits a native versioned target. Current-month overrides preserve
the original plan, with `supersedes_record_id` / `supersedes_record_ids` preventing additive double
counting of single or combined source lines and `month_only` retaining explicitly
planned future months. Undo removes that
override or restores the exact prior version. No budget read or edit moves cash.

Budget rows form a single list sorted by numeric allowance, highest first, on phone and desktop. Each clickable row shows its name, limit, one progress bar and spent/left figures, with no inline notes or setup actions. Estimated allowance
differences are distinct from exact remaining amounts; unresolved parent spending
shows Needs split rather than asserting a child's empty category proves zero costs.
Detailed coverage, reported-cash timing and source notes are collapsed in the tapped
plan. Desktop retains advanced import/setup. Typed household income-percentage
policies replace inherited/imported fixed caps, count recorded giving within the
same allowance, and exclude borrowing, internal transfers, medical shares and asset
principal from take-home. Other currencies are not silently added. A current reviewed
fixed cap retains precedence over the formula. Future receipts and unobserved cash
income remain unknown; known fixed plans remain separate from the variable formula.
Inherited typed source-quality flags stay explicit rather than being silently cleared.

Primary Home/Analysis/Budget reads use Analysis and BudgetEnvelope projections,
followed by the explicit `visual_context` metadata endpoint. This context supplies
owned editing catalogs and the unchanged MonthlyBills evidence builder, not a
partial imitation of Dashboard totals or forecasts. Full Dashboard is loaded only
when a legacy view or advanced action needs it. Context responses recheck current
space authorization and selected-account ownership, are private/no-store, and are
discarded after a context change or revocation. Primary foreground refreshes use
the same bounded metadata route. The closed assistant badge uses the authorized
current member's unread-count summary; it does not rebuild historical expense
budget payloads until the inbox or a deep-linked expense is opened.

Budget envelopes reuse request-local monthly projections and native-version context
hashes. Ordinary plans read the current and prior month needed for spending and
suggestions; an explicit rollover keeps its full required history. No global cache,
budget default, source record or financial calculation changes as part of this reuse.

### Budget amount controls (November 18)

Monthly budget and fund-contribution editors use $10 minus/plus steps and a
comma-formatted direct-entry field. Steps stop at zero, preserve existing cents,
and update the preview only; Save retains the native version/context guards.
No slider remains. The category list omits repeated budget wording, while dated
and estimated amounts and fund goals stay distinct. Evidence stays under Details.

### Compact budgets, transaction lists and visual Plan (November 19)

The budget page starts with Total budget and Budget left. Both exclude native
savings goals and shared-parent duplicate rows. Known-only subtotals show Partial;
estimates retain their tag. Category icons replace dots, and spent/allowance/left
figures are inside each bar. Savings goals live in their own collapsed section
below the monthly budget and are absent from Home's allowance preview. Saving a
plan no longer leaves a persistent explanatory banner.

Phone ledgers are compact merchant/date/amount lists. A row opens a native dialog
containing the existing category/suggestion/split controls. The dialog reuses the
rendered authorized row, closes when its source version or view changes, and adds
no mutation path. Unknown physical-card boilerplate and repetitive ledger captions
are hidden. Desktop retains the table. Back is compact and integrated with the
page title. Selecting Analysis starts its overview; Back from a Budget-originated
transaction view returns to Analysis's overview rather than bouncing to Budget.

Plan uses `GET /trajectory/spaces/{space}/plan-context`, a private/no-store
projection of the same existing planning calculation and editing catalogs. It
stops before unrelated dashboard history, tax, metals, and reconciliation reports.
The response is marked `plan_context`, never a full Dashboard. Actions requiring
full evidence still fetch that evidence through the existing full-data guard.

Plan presents expected income, core expenses, funding gap, income-source donut,
monthly income/expense stacks and three monthly funding tiles. Current monthly
assumptions remain explicit; seasonal or confirmed income is not invented.
Optional extra debt and goals are displayed only within projected surplus. This
is a display allocation, not a changed saved forecast, automatic transfer or new
budget target. Donuts retain explanation-first behavior, and chart/metric taps
open details and existing edits. Advanced assumptions and debt options are collapsed.

The diagonal page sheen is CSS-only and pointer-transparent, behind the content.
Reduced motion stops its animation. It introduces no network or data requests.

Ask recognizes requests to review uncategorized purchases before destination
parsing. It presents one owned, posted purchase and the existing classification
service's grounded suggestion. Yes sends the explicit, versioned category command
with Undo; No asks for purpose; Explain presents the suggestion evidence without
writing. Empty or uncertain suggestions never become automatic classifications.

### Recorded progress with unresolved parent costs

A category with known positive spending retains its proportional progress bar even
when other parent-category costs need splitting. The displayed spending has a +
marker and remaining stays an estimated upper bound (≤); changing the allowance
updates that bar immediately. A zero/unknown child under an unresolved parent still
has no zero-percent progress claim. This is presentation only; no spending, review
flags or saved allowance is changed.

### Editing categories inside a ledger

An inline category edit re-reads the current Analysis and budget projections and
updates the mounted ledger. Removing its last row leaves the current drilldown
open. Amounts and seasonal spending come from the committed household allocations.
A failed refresh after a successful save is reported separately from a rejected edit.

If Laravel rejects a write because the page’s CSRF token expired, the client
requests a fresh token for the signed-in viewer and retries the same payload once.
The original transaction version still applies. Authentication changes and
uncertain write outcomes require a fresh user action.

### Purchase returns, budget accounts and seasonal spending

The transaction ledger groups a uniquely matched purchase and full return into a
net row when both entries are present in the view. Opening the pair shows each
posting, its date and its original controls. The other posting’s date remains
visible when a filter selects just one side. Matching uses the posted merchant,
account, opposite amounts and a 30-day window. Conflicting candidates remain
available for individual review. Ask can open the transfer reconciliation screen
when the user asks to match a transfer.

The split editor accepts positive amounts or percentages and calculates the
remainder in cents. Each allocation can carry its own category, workspace and
budget account. The spending ledger’s Budget account filter follows that
designation and otherwise uses an owned funding account. Bank activity and
balances continue to use the posted source account.

Starting in November 2026, an active household Edisto expense category enables
its November spending card. An active Christmas expense category enables a
November–December card whose total starts on November 1 each year. Home and
Budget use posted economic amounts, including category splits and refunds.
General Gifts purchases count toward Christmas only when classified to Christmas.
