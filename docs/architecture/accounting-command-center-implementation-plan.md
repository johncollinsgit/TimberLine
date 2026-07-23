# Accounting Command Center implementation plan

## Product shape

`accounting_command_center` will be a reusable, tenant-scoped Everbranch Branch.
Modern Forestry is the first preset and rollout target, but no reusable service,
route, model, or view may branch on the Modern Forestry tenant name, slug, or ID.

QuickBooks Online remains the accounting source of truth. Everbranch may retain
configuration, classifications, reconciliation state, source provenance,
checklist progress, evidence metadata, snapshots, and audit history. It will not
post journal entries, write back to QuickBooks, process payroll, file taxes, or
make payments.

## Existing foundations to reuse

- `config/module_catalog.php`, `TenantModuleAccessResolver`,
  `TenantModuleEntitlement`, `TenantModuleState`, and `EnsureModuleAccess` for
  Branch registration, activation, and tenant-scoped access.
- `TenantFinancialAccess` for the owner/admin/platform-admin financial boundary.
- `DashboardDateRange` as the shared current-month date contract.
- `IntegrationConnection`, `QuickBooksOnlineClient`,
  `QuickBooksReportingSnapshotService`, `QuickBooksReportingSnapshot`,
  `QuickBooksSyncRun`, `FieldServiceFinancialDocument`, and its line and
  attachment records for read-only accounting evidence.
- `QuickBooksReportingSetting` and `QuickBooksOwnerReportingService` for reviewed
  wage, owner-compensation, supplies, and contract-labor mappings.
- Tenant-scoped `Order`, `ShopifyStore`, `SquareOrder`, and `SquarePayment`
  records for operational coverage and reconciliation support. These sources
  will never be added to QuickBooks P&L revenue.
- `WorkspaceAsset` for future owner-only evidence uploads.
- The shared Everbranch sidebar, tokens, typography, buttons, form patterns,
  module store, and navigation service.

## Additive implementation

1. Register `accounting_command_center` with the requested accounting
   capabilities, `integration_required` activation, QuickBooks dependency, and
   app-store visibility.
2. Add tenant-scoped accounting profiles, revenue rules, compliance tasks,
   monthly-close periods/items, debt snapshots, event-source imports, and audit
   events. Keep migrations additive and use explicit short index/foreign-key
   names.
3. Add an accounting date-range adapter with month, prior month, 30-day,
   quarter-to-date, year-to-date, calendar-year, prior-year, and custom ranges,
   while leaving existing dashboard consumers unchanged.
4. Build a read-only command-center service that:
   - reads income, expense, and net values only from QuickBooks P&L snapshots;
   - labels missing and stale values honestly;
   - uses Shopify and Square only for operational coverage/classification;
   - returns no financial payload until `TenantFinancialAccess` passes;
   - exposes setup blockers rather than inventing mappings or tax conclusions.
5. Add an owner/admin `/workspaces/{tenant}/accounting` surface with accessible
   summary, source freshness, revenue mix, payroll mapping state, recent
   transactions, debt setup, compliance work, monthly close, and event-source
   setup. Meaningful rows and cards receive real links or buttons.
6. Add a reusable preparation command. The `modern-forestry` preset supplies a
   South Carolina calendar-year S-corporation setup draft, wholesale/online/
   events stream labels, account counts, and review-required compliance tasks.
   Enabling still occurs through normal entitlement/state records.
7. Add feature/unit coverage for module registration, owner/admin access,
   member denial, tenant isolation, date ranges, QuickBooks-only ledger totals,
   operational-source non-double-counting, incomplete mapping/source states,
   monthly-close generation/reopening, and accessible screen structure.

## Event workbook audit

The local source `/Users/johncollins/Downloads/Event Team Member Compensation.xlsx`
was located, but it is treated only as a downloaded snapshot. The Google Drive
workbook is the intended source of truth because its live revision may be newer.
The canonical Drive file ID supplied by the owner is
`1V9FAzTg6FA7tzEnGyDQDQ-OYgHqbxiBot9PWlj7txDw`.
The first implementation therefore prefers a connected Drive source with file
ID, revision/modified time, sheet, row identifiers, checksum, and mapping
version. Until Drive access and spreadsheet inspection are available, the source
remains `mapping_required`; the downloaded file is an explicit upload fallback.
No workbook columns, event identifiers, compensation rules, or profitability
formulas will be guessed.

## Rollout and rollback

- Ship disabled by default.
- Prepare and enable Modern Forestry only with the reusable command and normal
  entitlement/state records after QuickBooks is connected.
- Treat every compliance item as review-required until an owner/accountant
  confirms applicability, cadence, source, and due date.
- Compare the resulting P&L totals directly with QuickBooks before changing the
  setup state to configured.
- Roll back by disabling the module entitlement/state; no source accounting data
  is mutated.
