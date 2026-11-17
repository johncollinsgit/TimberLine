# Trajectory sales-tax bank evidence

Sales tax is a separate Trajectory navigation view. It uses the selected financial
space, report dates and bank account filter. Combined mode continues to require
choosing a single space for detailed financial records.

`SalesTaxService` presents posted bank movements with optional `tax_remittance`
records. Agency names and the `taxes` category surface review candidates; neither
establishes sales tax. The record holds the linked transaction ID, exact posting
date and signed bank amount, agency, jurisdiction, verified tax type, movement,
optional filing period, source note and reviewed status. Filing periods are not
inferred from bank dates. Income, payroll and other tax types remain separate.

RecordService checks that the transaction belongs to the current space, is posted
and not removed, and matches the date, amount and movement sign. One active tax
record is allowed per bank transaction. Corrections use the existing versioned
record edit and audit path; duplicate creation is rejected. A server-derived fingerprint binds space, account, transaction, date, amount and
merchant without binding editable category/version flags. A changed bank source
invalidates the displayed match until reviewed again. Saving requires the fingerprint
of the bank facts shown when review opened; changed evidence rejects the stale
submission. Fresh review shows the current source and resets the reviewed box.
Conflicting legacy
records do not contribute confirmed totals. No bank or QuickBooks writes occur.

Payments are negative bank amounts. Returned payments and refunds are positive
credits, displayed separately and deducted from net cash remittances. They are
never counted as payments. Optional tax principal, interest, penalty and other
fee components must all be supplied together and sum to the absolute bank
amount. An absent split remains unknown rather than zero.

The four headline amounts are reviewed sales-tax remittance cash, returned
payments, refunds and net cash remittances. They do not certify tax principal,
liability, filing acceptance, deadlines or complete jurisdiction coverage. A
remittance can include interest or penalties. When no reviewed sales-tax evidence
is present, totals remain unavailable rather than showing an unsupported zero.

Tests cover ownership, source amounts/signs/dates, filing periods, duplicate
creation, versioned edits, source changes, account filtering, credits and unknown
agency payments, plus escaped source text and desktop/mobile table navigation. Fast rendering tests
run during the asset build; the Chromium navigation test runs in the workspace
browser gate after browser installation.

The feature has no customer identity master or customer data. Financial-space
ownership remains the authority. Tests use fictional fixtures; actual financial
evidence and import proposals remain outside the repository.

## Bank purpose and economic costs

Filing evidence and bank-purpose review remain separate explicit decisions.
`Review bank purpose` opens the ordinary transaction review with
`sales_tax_remittance` and Taxes selected; saving requires one active reviewed
sales-tax annotation bound to the exact current owned cash-bank fingerprint.
The full source movement must remain allocated to its own space. This purpose
cannot be bulk applied, learned as an agency merchant rule, or reallocated across
spaces. A verified binding also prevents reviewing the same payment as an
internal transfer, lender repayment or ordinary gross expense. Unconfirm or
correct the evidence first if the tax purpose was wrong.

`EconomicActivityService` provides one shared read projection for financial
summaries, history, operating questions, provisional P&L and comparisons. It
never creates ledger rows. Sales-tax principal settles a liability; only the
known interest, penalties and other fees become economic costs. Positive returns
and refunds reverse their own supported component amounts and never become
earned income. Ancillary costs retain the original bank ID, gross bank amount
and component basis. Evidence can show the selected cost component or principal
alongside the original bank movement. Cross-space evidence remains private.

Unknown components are not zero: economic totals become unavailable, the known
subset and unresolved gross cash remain inspectable, and a visible warning stays
outside collapsed overview details. Money flow Spending excludes known principal;
Cash movement preserves the full external agency payment or credit. Source,
annotation type/review changes or archive invalidate the old purpose and require
fresh bank review. Ordinary source reimports cannot propagate or silently replace
this receipt-bound classification.

Historical remittance principal and one-time ancillary charges are excluded from
automatically learned recurring/seasonal operating needs. Future tax cash requires
an explicit reviewed payment/budget plan. This exclusion does not establish zero
future tax liability or release a tax reserve. Explicit existing schedules and
budget targets retain their original values; no records are silently recoded.

Regression coverage uses fictional sources for principal-only zero costs, complete
and unknown splits, signed returns/refunds, source and annotation changes,
authorization, account filtering, allocation/privacy guards, component evidence,
and matching consumer totals. Browser acceptance checks the warning and component
drilldown at 390px and desktop in Chromium and WebKit. No provider writeback,
liability calculation, migration or real customer fixture is introduced.
