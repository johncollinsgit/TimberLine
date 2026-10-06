# Field Service invoices and streamlined job intake

## Classification

- **Capability:** shared Field Service extension.
- **Tenant scope:** every invoice, job, customer lookup, attachment, and task
  is resolved inside the active tenant. Collins Electric is the first rollout,
  not a code-path condition.
- **Entitlement:** included with the existing `field_service` module. The
  Invoice desk is owner/admin-only because it contains financial documents.
- **Canonical records:** `field_service_financial_documents` remain the
  invoice/estimate source of truth; `field_service_jobs` remain operational
  records. The nullable job foreign key is an intentional, user-controlled
  link, never a requirement for either record.
- **Reusable services/contracts:** `FieldServiceAccessService`,
  `TenantFinancialAccess`, `FieldServiceJobReadinessService`, the mobile Field
  Service API, and the existing tenant-scoped QuickBooks import.
- **Non-electrician applicability:** a Field Service tenant can use the same
  inbox, manual job intake, optional invoice linking, address suggestion, crew
  assignment, task ownership, and customer recency rule without code changes.

## Workflow

1. **Invoices** is a dedicated, owner/admin-only screen. It lists tenant-owned
   invoice documents independently, including unlinked invoices. It is not a
   job tab or a job creation prerequisite.
2. A manager can create a job with customer, visible service address, scope,
   scheduling, crew, first task, and optional selected invoice(s). A job can
   be created entirely manually with no invoice.
3. From an invoice, an owner/admin can attach it to an existing job or create
   a new job prefilled from its customer and operational context. The link is
   explicit and reversible; importing an invoice never manufactures a job.
4. The normal customer list shows customers with a paid invoice in the past
   12 months. Older customers and financial history are retained and remain
   available to authorized search/history surfaces; no records are deleted.
5. Employees receive only permitted jobs, their assigned tasks, and the job
   projects/tasks they participate in. Financial data and the Invoice desk do
   not enter employee payloads.

## UX guardrails

- The principal actions are reachable in three taps or fewer: open a job,
  create a job, add a task, add an update, assign crew, or review/attach an
  invoice.
- The job list displays customer and full service address on every row.
- Job status progression keeps its existing lifecycle controls but removes the
  literal “Start job” call to action. Time tracking uses its separate clock
  workflow.
- Address entry uses device address autocomplete when configured; users can
  always type or correct an address. The server receives normalized address
  fields, not a third-party provider identifier as a trust boundary.
- Financial document amounts and private notes are never returned to members.
