# Accounting Command Center rollout

## Purpose

The Accounting Command Center lets an owner understand the period, review
source freshness, and complete recurring accounting work without using
Everbranch as a ledger. QuickBooks Online is authoritative.

## Non-goals

The Branch does not implement double-entry accounting, payroll processing,
bank feeds, tax-return preparation, filing, payments, journal entries, or
QuickBooks write-back. Suggested mappings and compliance work always require
human review.

## Source-of-truth rules

- QuickBooks P&L snapshots: gross income, expenses, and net result.
- QuickBooks financial documents: owner/admin transaction evidence.
- Shopify and Square: operational counts, classification, payout matching, and
  exception support only.
- Event Team Member Compensation Google Drive workbook: event direct-cost and
  compensation source after its live sheets, identifiers, formulas, and
  overrides are mapped.
- Everbranch: configuration, review state, tasks, evidence metadata, snapshots,
  provenance, and audit history.

Operational sales are never added to QuickBooks revenue. Until approved stream
rules and reconciliation exist, revenue percentages remain unavailable.

## Security

Every route is protected by `tenant.access`, `module:accounting_command_center`,
and `TenantFinancialAccess`. Only tenant owner/admin users and platform admins
may see financial content. Cross-tenant records must always query by
`tenant_id`.

## Modern Forestry preparation

The preset is a setup draft for a calendar-year South Carolina S corporation
using QuickBooks, QuickBooks Payroll, Shopify, Square, and three income streams.
It identifies Google Drive file
`1V9FAzTg6FA7tzEnGyDQDQ-OYgHqbxiBot9PWlj7txDw` as the preferred event source.

```bash
php artisan everbranch:prepare-accounting-command-center \
  --tenant=modern-forestry \
  --preset=modern-forestry
```

Do not add `--enable` until the QuickBooks connection is confirmed. Then:

1. Run the command again with `--enable`.
2. Confirm the connected QuickBooks company and accounting basis.
3. Compare the selected-period P&L totals directly with QuickBooks.
4. Review payroll, revenue, financial-account, and debt mappings.
5. Confirm payroll automation and filing responsibility in QuickBooks Payroll.
6. Confirm every compliance obligation, cadence, due date, destination, and
   source with the owner/accountant.
7. Connect the Google Drive workbook, record its revision, map the real sheets
   and columns, and add sanitized fixture coverage.
8. Verify owner/admin access, employee denial, and cross-tenant isolation.

## Event spreadsheet recovery

Google Drive is preferred because it is expected to be the latest version. If
Drive is unavailable, accept an explicit owner-uploaded XLSX/CSV snapshot and
retain filename, sheet, row identifiers, checksum, import time, importer, and
mapping version. Never silently fall back to an older local file.

## Disconnect behavior

When QuickBooks disconnects or a snapshot becomes stale, keep the last observed
timestamp visible and mark the data stale/unavailable. Never replace missing
accounting values with operational sales or zero. Shopify, Square, and event
source failures remain visible as partial coverage.

## Deployment and rollback

Use the protected GitHub test/build gate and Forge zero-downtime release path.
The migration is additive. Roll back product access by disabling the
`accounting_command_center` entitlement/module state; no accounting source data
is mutated. A database rollback should be reserved for a separately reviewed
deployment rollback because it removes Everbranch-owned workflow records.

## Known limitations

- Live Google Drive sheet names, columns, formulas, and modification metadata
  still require connector access and review.
- Debt history begins when reviewed daily snapshots begin; it is not fabricated
  from current transactions.
- Compliance tasks are setup reminders until due dates and applicability are
  explicitly verified.
- The initial chart compares selected-period totals from the matching
  QuickBooks snapshot. Daily/monthly point generation awaits the queued
  reporting-snapshot expansion.
