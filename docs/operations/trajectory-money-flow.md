# Trajectory recorded money flow

Money flow is available from the same phone drawer and desktop navigation. It
uses actual posted ledger entries in the selected date/account scope. It does
not import another ledger, create financial records, or include budgets,
forecast receipts, expected medical shares, or opening cash.

## Two bases

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
