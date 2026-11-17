# Trajectory recorded money flow

Money flow is available from the same phone drawer and desktop navigation. It
uses actual posted ledger entries in the selected date/account scope. It does
not import another ledger, create financial records, or include budgets,
forecast receipts, expected medical shares, or opening cash.

## Lender-reported annual interest

Wealth & debt separately displays `lender_interest_ytd` source observations by
calendar year. Each owned credit account retains its lender's statement cutoff,
source currency and verified statement reference/hash. Only active, valid,
reviewed evidence at or before the report cutoff is usable. Future-only reports
have no actual total. The latest observation for each account/year replaces
earlier cumulative snapshots; it is never added to them. Conflicting same-cutoff
values are unavailable. A later account-currency change also makes the amount
unavailable instead of relabeling historical cents. No implicit FX is performed.

The compact table includes statement/table/page evidence and verification date.
Internal source IDs and hashes remain backend metadata. Missing or unreviewed
evidence is unavailable, while an explicit lender zero remains zero. A subtotal
names its covered account count and different cutoff dates; mixed currencies have
no subtotal. The calendar-year selector changes this source-context table only.

These observations are excluded from `InterestService`, economic costs, cash,
monthly history, question answers, budgets and all future projections. Existing
`interest_statement` cycle records still replace overlapping imported charges and
still return unavailable for partial windows. Annual observations must never be
entered as overlapping cycle records, distributed into monthly charges, or used
to infer cash paid. No lender observations or customer financial data are seeded
by the release. Native source-backed creation is a separate reviewed operation.

Focused coverage is in `LenderInterestYtdTest.php` and the annual-interest JS
tests, with enhanced-phone/desktop table/source/year-selector checks in the
authenticated Chromium/WebKit money-flow browser script. The financial regression
compares actual interest, unknown tax coverage, cash, histories, questions,
economic costs and nonempty debt projections before/after an annual observation.

## Owned credit-card activity

Wealth & debt begins with selected-period activity for each owned credit account.
It keeps reviewed other card purchases, gold/silver asset outlays and credits,
other asset movements, interest, fees, repayment credits and repayment debits
distinct. Unreviewed purposes and other adjustments remain visible. A merchant
name does not establish a metals purchase. Marked duplicates are inspectable but
excluded from recorded charges/credits and prevent a balance equation.
Reviewed interest and fee credits offset their own group rather than becoming
purchase credits.

These are full physical card amounts. The separate economic allocation for
classified other purchases is already within that amount, not an additional
cost. An explicit zero owner allocation remains zero. A missing owner allocation
or nonconserving split leaves its share unavailable. Other spaces' names,
accounts and source details are never included in this allocation summary.

The latest account balance always carries its actual observation date. A period
comparison requires consistent reviewed balance evidence at the opening boundary
and a balance at the exact report cutoff. A January 1 opening or the preceding
December 31 observation supports a January 1 comparison. A following January 1
is not silently used as a historical December 31 closing. Reconstructed record
names and source notes are displayed. Missing or conflicting evidence stays
unavailable. Matching recorded charges/credits can reconcile the dated balances
but does not establish complete history. No payment is attributed to prior-year
principal without separate evidence or an explicit accounting convention.
A wholly future report retains its requested dates with actual activity and
period balances unavailable. A current-period report stops actual activity at
today; a separately dated latest balance is never presented as a future closing.

Card evidence uses `credit_movement: true` plus an explicit owned credit
`account_id`. The server authorizes that space/account and returns only its
actual posted rows, full source amounts and a separately labeled economic
allocation. It excludes pending, removed, future and foreign sources, including
foreign sources allocated into this space. All dates includes older owned card
sources even when their owner allocation is missing; custom dates stay bounded.
Currency remains per account; no
cross-card or cross-currency household total is fabricated. Cash movement
continues to admit cash accounts only; default allocated evidence is unchanged.
Cash, credit-source and economic-cost evidence modes are mutually exclusive.
The posted-only caption does not claim that absent pending records mean zero
pending charges. This feature reads native records only and changes no lender
balances, source transactions, allocations, forecasts, repayment terms or plans.

Focused checks include `CardActivityTest.php`, the Trajectory JavaScript suite
and the existing authenticated money-flow browser check (Chromium/WebKit phone
and desktop), including zero-owner-allocation source evidence and Back navigation.

## Spending and cash bases

- **Spending** counts allocated economic purchases, including card purchases.
  Authorized shared allocations keep their allocated cents and the generic
  merchant label. Internal transfers, marked duplicates, card/debt repayments,
  asset transfers and owner distributions are excluded and remain inspectable.
- **Cash movement** counts full bank amounts on accounts owned by the current
  space whose kind is cash. It excludes foreign/shared cash and noncash
  accounts, counts debt repayments, and checks source account/date/version and
  posted status. Split allocations do not shrink actual bank withdrawals.

Reviewed income, borrowing, asset sales, refunds, medical bill reimbursements,
and unknown incoming money remain distinct. Unreviewed movements and ordinary
expenses/refunds without a category appear in Needs review. A reviewed
financing or support purpose does not require an expense category. Excluded records disclose their
review counts and gross/signed totals rather than disappearing.

Refunds are positive incoming bands. Only reviewed purchase/refund categories
receive a separate gross/refund/net table; a category match does not establish
a vendor or invoice match. Refunds may exceed purchases without being clamped
to zero. Zero-amount source rows remain inspectable. Missing activity is null;
observed zero remains zero.

## Evidence and conservation

The phone overview shows both source-purpose and outgoing-category columns
without horizontal panning. Tapping a category opens its merchant breakdown and
source records. Desktop retains all four stages with thin proportional anchors
and wrapped labels. The complete amount/refund/exclusion tables start closed.
Monthly charts show actual points even when only one month is available, with
a zero-based outflow axis; unavailable years do not create empty legend series.

Bands use nonnegative integer cents through sources → activity → categories →
merchants. Small branches can be grouped without dropping cents or source IDs.
Needs review remains explicit. A derived difference balances the diagram but
has no transaction IDs and is never called savings, a balance, or spendable
cash. Percentages use recorded inflows or recorded outflows separately and
exclude derived differences.

Every actual branch and table amount opens existing authorized transaction
evidence. Cash links submit `cash_movement: true` to the existing evidence
endpoint, which returns full owned bank cash plus the separately labeled
`allocated_amount_cents`. Default evidence retains allocated amounts and never
discloses a shared source's full cash. Large source-ID groups are read in
batches within the existing 5,000-ID request limit. Combined space mode requires
choosing Personal or Business to avoid double-counting company support.

An owned bank withdrawal remains cash even if allocating its entire cost
elsewhere removed the owner's allocation row. That case has no invented
economic share: the bank evidence shows the allocation as unavailable. Explicit
zero owner allocations retain their known zero. Cash evidence continues to
honor date/account filters and posted source identity.

## Prior years

The comparison uses the same calendar period one year earlier, with no-overflow
year shifts for leap days. Monthly outflow lines cover the report's ending year
and its prior two years, using whole calendar-year context rather than the
selected diagram date range; the table also provides incoming amounts and source
records. Outflow chart points open only outflow IDs; month-wide table actions
include both incoming and outgoing evidence. The current month is partial. Missing and future months remain null,
and no comparable growth percentage is calculated. The currently selected
account cohort and authorized allocation scope are reused, with explicit notes
that historical source coverage and classifications can differ. Actual flows
are separate from operating need, debt schedules, family-support targets, and
unverified historical debt baselines.

## Focused overview and review clarity

The overview keeps question cards, the monthly plan, scope warnings, review
queue and unresolved deposits visible. Legacy balance/spending/forecast charts
remain available under a default-closed “Explore balances, spending and
forecasts” disclosure and through their existing sidebar pages. Charts are
created only when that disclosure is open; monthly-plan controls work while it
is closed. Draft tax classifications also say “not confirmed” near both the
agency and tax type, so the mobile table cannot imply confirmation when its
review column is off screen.

Existing income summaries require an explicit reviewed flag before counting
income-like deposits as earned or named income. Unreviewed receipts remain in
the purpose-review bucket. Matched medical-share receipts remain included as
non-earned reimbursements, with their source IDs. Mixed Shopify/Sam's Club
charges do not inherit a bulk expense suggestion from one reviewed purchase;
explicit saved merchant rules keep their existing precedence.

## Validation

- PHP: `php -d memory_limit=1G vendor/bin/pest tests/Feature/Trajectory/MoneyFlowTest.php --compact`
- All Trajectory JavaScript: `npm run test:trajectory`
- Actual authenticated phone/desktop flow, Chromium and WebKit:
  `node tests/e2e/trajectory-money-flow.browser.mjs`

The browser fixture refuses any environment except a disposable SQLite testing
database under `/codex-money-flow-`. Fixtures contain fictional records only.

## Payroll attribution and business allocations

Owner payroll, Studio payroll and Event payroll are distinct transaction
categories alongside the existing Payroll category. They remain separate in
both diagram sizes even when small categories are grouped. Category charts,
provisional cost detail and transaction evidence use those same posted entries;
no gross payroll records are added to bank distributions. Use payroll evidence
to match net distributions before classifying them. Generic Payroll retains tax
funding and unassigned withdrawals until their purpose is supported. Owner
payroll is separate from owner distributions; category attribution does not
change the transaction flow or determine tax treatment.

Allocations can assign an economic share between two businesses only when the
current signed-in user owns both spaces and passes financial authorization on
both. Current space facts are locked before admission. Existing household to
business allocations still require the actual household/business link; a
business pair cannot use a malformed household link as an ownership bypass.
Amounts must conserve the source cents, retain its sign and use each space at
most once. Version checks, medical bindings and audit events remain in force.
The source bank account, source space and full cash withdrawal stay unchanged;
the other business sees only its privacy-safe allocated economic share. This
creates neither a bank transfer nor an intercompany loan or second expense.

For ongoing monthly need, a lone reviewed Payroll budget target is an umbrella:
it replaces history for all four payroll categories and deducts their scheduled
fixed costs once. Aggregate payroll history is rounded once so attribution alone
cannot change the estimate. A role-specific target replaces only that role.
Overlapping Payroll and role-specific targets, including drafts, leave monthly
budget totals and monthly need unavailable with a review explanation and source
record IDs. The check respects their purpose and actual effective intervals. An existing manual
aggregate flexible override still takes precedence and ignores category targets.
This changes neither planned budget records nor recorded payroll amounts. Zero
source transactions permit only zero allocations, preventing invented opposing
expense shares.


## Household receipt sources

Personal spaces also offer **Inflows** in the same Sankey renderer. It describes
only positive posted receipts already included in owned bank cash. Source and
receipt-type columns partition the same cents; they do not assign a receipt to
any purchase. The tables and incoming-only monthly trend retain other income,
refunds, medical bill reimbursements and Needs review alongside the named sources.

- Loans require a reviewed cash-leg `loan_draw`; debt-ledger movements and
  ordinary transfers do not establish new household borrowing.
- Metals require reviewed `asset_sale` receipts in `gold_and_silver`. These are
  sale proceeds, not gains or recurring earnings. Off-bank retained proceeds and
  planned sales do not create bank receipts.
- Named company receipts reuse confirmed `company_support_source` records and
  `SupportReceiptService` ambiguity/refinement rules for currently authorized,
  linked businesses. Net payroll, owner draws and other support remain separate.
  A company name or similar amount cannot establish the source.
- Odd jobs require reviewed income in `odd_jobs` or `mowing_lawns`. Generic
  deposits and unreviewed lookalikes remain Needs review.

Company-paid household purchases are not extra household bank receipts. Metal
purchases and card repayments do not become metal-sale proceeds. The existing
Spending and Cash movement bases remain separate; a card repayment does not by
itself establish that prior-year debt was paid off. Missing reviewed source
receipts are a coverage gap, not evidence of zero borrowing or earnings.

## Report navigation

The chart's category menu and transaction drilldowns use same-document browser
history, so browser Back closes the menu or returns to the preceding report
instead of skipping internal panels for a prior external page. The native Back
button uses those same steps. Mode, source focus, scroll and scoped filters stay
in session memory. Only random opaque navigation keys enter browser history;
no financial rows, amounts or source IDs are persisted there.

Restoration fetches current authorized report/evidence data, using the existing
5,000-ID batching limit. Changing space, business, account, report dates or
scenario invalidates historical report snapshots; old private evidence is not
restored into a different context. Business and combined scopes normalize the
household-only Inflows mode. Provider OAuth callback state is left untouched.
Unrelated edit forms do not acquire history steps.

## Undo one mistaken bulk review

The existing transaction Undo action can restore one row's prior unreviewed
classification from its latest bulk-review audit. Other rows in that suggestion
remain unchanged. The server requires current financial access, exact version
and complete matching review snapshots, an unchanged owned posted source, and
one full allocation to that space. Pending, removed, split or bound tax/medical
rows cannot use this bulk undo. Snapshots that changed spending flags require
individual review; this path changes no flags or merchant rules.

Restoration increments the version and records an undo audit. Replaying the old
version or undoing the same audit again fails. Raw source, amount, account balance
and allocations stay intact. Restoring a purchase to Needs review does not
establish its household/business beneficiary or recurring cost.
