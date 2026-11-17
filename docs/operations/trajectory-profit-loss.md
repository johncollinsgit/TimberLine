# Trajectory Profit & Loss

## Owner workflow and financial authority

Open a business in Trajectory and choose **Profit & loss** with **All accounts**.
The four cards show recognized revenue, gross profit, operating expenses and net
profit for the selected period. Gross and net margins use positive reported
revenue as the denominator. Select **View statement** to reach the supporting
account section; expand the hierarchy or select a leaf account to inspect its
saved source amount and request its individual QuickBooks General Ledger
postings. Bank evidence is a separately labeled supporting activity view.

QuickBooks saved ProfitAndLoss reports are the accounting authority. Income,
COGS, operating expenses, other income/expenses and reported net profit retain
their source sections. Gross profit is income less COGS. There is no fabricated
balancing line, category mapping or transaction. Loan proceeds, debt principal,
owner draws, transfers and tax principal are not added to P&L expenses or revenue.
Net bank sales collections are bank settlement cash, not recognized sales. Net
bank payroll is not added to QuickBooks accrual payroll. Shipping evidence,
native payroll and sales-channel reports remain separate supporting sources.

Access uses `FinanceAccess`: active verified users, enabled Trajectory access,
current active tenant membership and the existing financial reporting permission.
Owning a space ID or being a platform administrator does not grant another
tenant's report access. Household and individual bank-account views do not expose
a business P&L. All reads and exports are private and use `no-store` responses.

## Period, basis and source coverage

The selected start and end dates must exactly match a saved report, including its
accounting basis. Selecting cash never relabels an accrual report. Cash/accrual
controls distinguish saved report availability from the ability to load a source
report. With authorized, active QuickBooks access, both basis choices can request
their own exact report. Custom dates are validated and cannot run into the future.
Viewing or exporting remains cache-only. **Load report** / **Refresh report** is
an explicit action; it performs a provider report GET and saves only the admitted
report cache. It does not import transactions or write provider accounting data.

The explicit route is `POST /trajectory/spaces/{space}/profit-loss/refresh`, with
`from`, `through`, `accounting_basis` and optional `snapshot_id`. Existing report
refreshes pin their original snapshot connection, dates, basis and known
currency. Loading a missing report requires one current QuickBooks company;
ambiguous companies are not guessed. The provider response must declare exact
dates/basis, a valid currency and one total accounting column. Fresh financial
permissions, enabled QuickBooks access, unchanged company/realm, usable
credentials and any pinned statement revision are checked after provider
retrieval and before saving the cache. Revocation or a mismatched response saves
nothing. The action shares the QuickBooks operation lock and has its own bounded
request throttle. Active connections with refreshable credentials remain
available when their access token expires or approaches expiry. The normal
QuickBooks connector renews credentials through the OAuth token endpoint before
the financial report GET; this credential-maintenance POST does not modify
provider financial records, add scopes or start a sync. Credential rotation uses
a short database lock after OAuth, verifies unchanged company/status and source
credential version, and rechecks current caller permissions before persisting.
No database lock spans the OAuth network request. Missing or invalid refresh
credentials retain an honest failure/reconnection state; a disconnect or newer
credential rotation cannot be resurrected or overwritten by an older response.
The UI retains prior figures when a source request fails. After a successful
cache save it reloads the dashboard. If that reload fails, it reports that the
source save succeeded but the screen still needs a reload; retry does not
silently manufacture an updated screen or repeat a source request on render.

Comparisons use exact previous-year or equal-day-count previous-period reports
from the same connection, basis and declared currency. A missing report, unknown
basis or unknown currency cannot establish a comparison. An explicit source zero
remains zero; absent totals stay unavailable. Percentage changes are unavailable
when the comparison is missing or has a zero base.

Monthly, quarterly and annual charts use exact cached reports for each period.
They do not split a year-to-date total into invented months, combine overlapping
reports, extrapolate a partial month or render missing periods as zero. Sparklines
and charts preserve gaps. Available history is bounded for performance.

Complete coverage requires source totals and their reconciliation. Missing or
contradictory source totals and invalid account details retain explicit partial
coverage. Legacy saved reports may contain unsectioned `account_lines` only;
these appear separately and are never assigned to COGS or operating expenses by
account name. Source section totals can be available while hierarchy detail is
unavailable. Current interest and sales-tax cost coverage limits stay visible in
supporting activity and are not silently converted into accounting zeros.

When a source parent account has its own nonzero Header amount as well as child
accounts, its explicit own activity appears as a **(direct postings)** leaf with
the same source account ID. The parent's Summary remains its reported subtotal.
Positive postings and negative credits retain their source signs; empty/zero
Headers create no extra line. A missing amount is never derived from a subtotal
gap. Previously cached hierarchies need a targeted refresh to gain this detail.

After release, **Refresh report** for the chosen period and basis can populate
the new saved account hierarchy. Use this targeted action or the existing
authorized QuickBooks reporting workflow; do not run a full sync, clear caches,
reclassify production records or generate artificial transactions to fill a
missing hierarchy. A refresh still needs source coverage and normal permissions.

## Individual accounting transactions

`GET /trajectory/spaces/{space}/profit-loss/accounts/transactions` accepts
`snapshot_id` and `account_key`. The authorized snapshot must belong to the
business, and the key must identify one supported numeric leaf account in its
preserved accounting section. A client-provided account number cannot authorize
a provider query. Parent and unsectioned accounts do not inherit a guessed
classification. The original connected company must retain enabled QuickBooks
access and usable credentials.

The endpoint requests `GeneralLedger` for that exact account through the normal
QuickBooks connector after any required credential renewal, using financial GET
requests only. Current member/module/space and source identity are checked before
credential persistence, before the financial GET, and after retrieval. Each
request retains the selected period/basis and explicit debit/credit columns. Income and
other-income postings use credits minus debits; COGS and expense postings use
debits minus credits. Refunds and credits retain their negative amounts. No
natural amount, running balance or bank total is substituted. Opening balances
are excluded, and distinct postings sharing a transaction ID remain distinct.

The response must confirm source dates, basis, currency and account identity.
Unknown/foreign account results are withheld. Each account section's source
summary must match its recursively parsed subtree before activity can be
complete. For a direct-postings leaf only, the provider may include descendants
known from that same saved parent: their postings and summaries are validated
but excluded from the displayed own-account activity. Accounts from another
branch of the chart remain forbidden. Parent and descendant summaries are never
added together, and direct amounts are never derived from their difference. Missing,
malformed, impossible-date or truncated rows retain partial status; the response
is bounded to 2,000 postings. The live account total is compared with the selected
saved account amount. A difference or older provider timestamp remains visible
as a possible stale-source condition; the saved statement is not overwritten.
Fresh financial permissions, company identity and snapshot revision are checked
after retrieval before returning private rows.

This read does not persist or deduplicate transactions, trigger financial
provider writes or supply AI context. OAuth renewal only updates the existing
local credentials. Errors, revoked access, unrefreshable credentials and
unavailable source detail retain explicit states. The QuickBooks
operation lock and a separate request throttle bound reads.

## Financial Insights and provider quarantine

Financial Insights use the existing Bud / Ask Everbranch entry point with the
selected dates and `accounting_basis`. Business P&L questions are handled locally
by `FinancialInsightsService` before external AI dispatch. Responses include the
source figures and arithmetic, disclose missing comparative reports and never
invent a business cause. Reported expense section changes are available;
account-level growth requires comparable account detail. Payroll growth relative
to revenue remains unavailable until reconciled accounting payroll mappings and
comparative figures exist. Monthly margin and best-month answers describe their
available-report subset and missing months.

`TrajectoryAiService::providerContext` keeps its existing allowlist of separately
supported independent bank facts and reviewed owner plans. It does not receive
QuickBooks snapshots, P&L reports, source account labels, derived accounting
insights or mixed dashboard aggregates. Local report answers do not reserve AI
usage, call an external provider or create financial mutations. This preserves
the existing provider-data quarantine; it does not constitute provider/legal
approval for new retrieval, memory, training or model-input use. See
[provider terms and publishing](provider-terms-and-publishing.md).

## CSV, PDF and printing

CSV, PDF and Print share the screen's server-calculated report through
`DashboardService`. The route is
`GET /trajectory/spaces/{space}/profit-loss/export/{format}` with `format` equal
to `csv`, `pdf` or `print`. Query controls are `range`, custom `from` / `through`,
`accounting_basis`, and `comparison` (`previous_period` or `previous_year`). Every
request rechecks financial permissions before reading the selected source.
Unsupported account, budget and dimension filters are rejected rather than
silently changing the scope.

Exports retain selected dates, actual/requested basis, currency, source coverage,
source statement hierarchy and available comparison totals. Unknown amounts are
blank CSV cells and explicitly unavailable in PDF/Print. CSV numeric negatives
remain numeric; untrusted labels and metadata are formula-escaped, including
leading whitespace/control characters. PDF/Print use accounting parentheses for
negative amounts. Missing account comparisons are not inferred from section
totals. Hierarchy rows and their children are not additive duplicates.

PDF uses the existing Dompdf dependency. Remote resources, URL protocols, PHP
and JavaScript execution are disabled; source labels are HTML-escaped. Rendering
uses a private temporary directory removed after completion. Excessively large
PDF statements direct the user to CSV. Print has an explicit print button and
responsive desktop/phone layout. No public export link or saved report file is
created by downloading.

## Current support and verification

Functional: exact saved-report accounting totals; explicit source report loading
and targeted refresh for custom dates/cash/accrual; expandable saved account
hierarchy and authorized individual accounting postings; source-backed KPI
cards/margins; compatible period comparisons;
monthly/quarterly/annual charts with missing-report gaps; local Financial
Insights; tenant-authorized CSV, PDF and printable statements; separate bank
evidence; loading, empty, error, unavailable and partial states.

Unavailable until actual source support exists: actual-versus-budget P&L,
location/department/business-unit reporting and reconciled payroll-account
comparisons. Historical comparisons and account hierarchy require matching saved
reports; individual accounting postings require ready original source access and
supported report identity. A source test or
browser fixture does not prove live report completeness or provider approval.

Focused checks include `ProfitLossReportTest`, `AccountingReportTest`,
`ProfitLossExportTest`, `ProfitLossRefreshTest`, `AccountLedgerTest`,
`FinancialInsightsTest`, the existing `TrajectoryAiTest`
and `TrajectoryBudTest`, and the accounting renderer/browser regressions. Tests
use fictional fixtures and disposable databases. Export verification checks
source reconciliation, date/basis behavior, negative/refund values, nulls,
formula-safe CSV labels, PDF text, escaping, permissions and tenant isolation.
Print/PDF visual QA uses fictional statements; application browser QA covers
Chromium and WebKit at phone and desktop widths. Normal protected CI, Forge
delivery and exact-SHA `/ready` verification remain mandatory before a live
claim.
