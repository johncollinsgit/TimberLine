# Collins Electric Access and QuickBooks Guardrails

## Workspace ownership

- Collins Electric is tenant-scoped under `collins-electric`.
- Nathan Collins is the business owner. `collinselectric91@gmail.com` is provisioned as the active, verified tenant owner until Nathan supplies a different verified identity.
- John Collins has admin access for implementation and mobile testing.
- Preserve John's other workspace memberships. Collins access must never be inferred from his email or from membership in another tenant.

## Access model

Owner/admin users may access operational data, QuickBooks connection health, imported financial documents, receivables, reports, price-book analysis, and future estimator administration.

Team members are operational users. They may access customers, jobs, service addresses, lock-box codes, calendars, job assignments, tasks, job posts/notes, photos, mentions, and job communication. They must not receive financial reports, document totals, receivables, profit-and-loss data, wage totals, price-book costs, billing controls, or integration credentials.

Field-service routes permit tenant members because the surface is operational. Financial data remains in separate models and must only be exposed from an owner/admin guarded controller or mobile contract. Do not add financial fields to the general job payload.

Members can see only jobs where they are the lead assignee, a participant/follower, a task assignee, or an explicitly mentioned teammate. Owners, admins, managers, and platform operators may see the tenant-wide operational list. This relationship check is a server boundary, not a client filter.

## Operational lifecycle

- `Quote`: a current pending estimate without acceptance evidence.
- `Active`: a current unpaid invoice, an accepted/converted estimate awaiting invoicing, an invoice total below the accepted estimate total, or a manually scheduled/in-progress job.
- `Needs details`: active accounting evidence without the scheduling, address, or assignment needed by the field team.
- `Complete`: paid invoices meet or exceed the accepted estimate, or a user manually completes the job.
- `History`: closed/rejected work or financial activity older than one year.

Lifecycle values are derived alongside QuickBooks source records; they do not rewrite QuickBooks. Manual operational overrides win over future syncs. Current screens exclude History by default but retain it in owner History filters and authorized search. Calendar is the default Field Service view and includes an unscheduled tray so imported work cannot disappear.

## Collins workflow requirements

- Job posts should support team mentions and preserve an activity history.
- Team members need upcoming-job reminders by text after consent, provider, and delivery controls are verified.
- Owner/admin users need a controlled reminder for employees when hours are due.
- SMS remains disabled until provider readiness, employee consent, quiet hours, opt-out handling, and delivery logs are proven.
- Nathan needs customer, job, calendar, assignment, notes, photos, and communication workflows before financial enhancements.
- `Field Service` is Collins' canonical Work experience. Do not restore separate visible `work_core` and `field_service` Branches. The old Work URLs remain compatibility aliases only.
- Job comments, tasks, participants, mentions, and photos are tenant-owned. SMS notifications remain suppressed unless the employee has a tenant-specific verified phone and operational opt-in and the Collins sender passes its delivery smoke test.

## QuickBooks handling

- The integration is read-only and tenant-scoped, and uses encrypted tokens, reporting snapshots, sync summaries, and source snapshots. Hourly synchronization is available only after an owner explicitly enables it for that connection; all other tenants remain off by default.
- Invoice private notes, customer memos, and line descriptions are important Collins context. Import them as source-attributed job activity without overwriting employee posts. Mark `PrivateNote` activity owner-only; customer memos and work-line descriptions may remain team-visible when they are operational.
- Profit-and-loss wage lines are an aggregate labor-cost signal, not QuickBooks Payroll records. Track wages/payroll separately from contract labor/subcontractors. Collins reports roughly $100,000 of contract labor year to date; confirm the amount and report period through the API before displaying it. Both may support owner-only labor percentages but cannot establish employee-level pay or job profitability by themselves.
- The discovery command requests Profit and Loss explicitly from January 1 through the current date and records that period alongside the aggregate. Dashboard date controls must not silently relabel a year-to-date QuickBooks report as a shorter window.
- Do not enable payments, write-back, webhooks, CDC, QuickBooks payroll, or estimator write-back from discovery alone. The hourly scheduler may be enabled for Collins only after the dry-run, backup, two stable live imports, mapping review, and owner/team permission smoke tests pass.
- QuickBooks documents remain separate from jobs unless source evidence supports a job link. Preserve manual jobs and imported document identities.
- Production discovery on 2026-07-13 verified the Collins OAuth connection and completed a full read-only audit. It found a mature accounting history with hundreds of customers, extensive estimates/invoices, a large purchase history, repeated price patterns, open receivables, and owner-level wage/contract-labor signals. Keep exact source records and financial totals in encrypted tenant data and owner-facing reports rather than this repository.
- The first legacy sync preview was intentionally blocked because description text alone would have classified nearly every accounting document as a job. `QuickBooksJobEvidenceClassifier` now requires a QuickBooks job/subcustomer, project reference, distinct service address, explicit memo/private note, or service-dated line. Description-only documents import into the owner review queue and remain searchable without creating jobs.
- Before live import, deploy the classifier, rerun both full audit and sync dry runs, review candidate/review counts, take a database backup, persist the encrypted audit snapshot, then run the live sync twice to prove stable identities and row counts.
- QuickBooks is the shared opt-in `quickbooks` beta Branch. Collins is the first enabled tenant, not a custom implementation. Onboarding interest never enables the Branch; OAuth, audit, and sync require an explicit tenant entitlement and owner/admin access.
- All Everbranch operational dashboard metrics use the shared `1d`, `1w`, `1m`, `30d`, and `ytd` selector, defaulting to the current calendar month. This does not change the explicit YTD period used by the QuickBooks P&L audit.
- Owner reporting uses P&L Total Income as the denominator for employee, contract, and combined labor percentages. Supplies, wages, contract labor, and Nathan's owner compensation remain blank until an owner reviews exact QuickBooks account mappings. A monthly owner-adjustment fallback is supported when owner compensation cannot be separated by account.
- QuickBooks invoices are labeled `work billed`; only Everbranch jobs with `completed_at` are labeled `jobs completed`. Historical pending estimates live in quote aging and do not create current follow-up tasks automatically.
- The shared `documents` Branch stores authenticated private copies. Team uploads default to team-visible; QuickBooks attachments default to owner-only. One asset can link to several jobs, text/CSV contents and tags are searchable, and upload/link/download/delete activity is audited. Collins' initial QuickBooks sweep returned zero attachments, so an empty Documents screen is expected until the team uploads job photos or files.
- iCloud and Shared Albums remain source pickers, not canonical storage. The iOS system picker copies only user-selected photos into private Everbranch storage and leaves the originals untouched.
- The shared `estimator` Branch is default-disabled platform-wide but enabled for Collins owner/admin users in draft-only mode. It proposes candidates only from repeated detailed invoice descriptions, excludes broad service items, snapshots approved prices into Everbranch drafts, and never writes estimates back to QuickBooks.

## Commands

```bash
php artisan field-service:audit-quickbooks --tenant=collins-electric --full --dry-run
php artisan field-service:audit-quickbooks --tenant=collins-electric --full
php artisan field-service:sync-quickbooks --tenant=collins-electric --dry-run
php artisan field-service:sync-quickbooks --tenant=collins-electric
php artisan field-service:reconcile-lifecycle --tenant=collins-electric --dry-run
php artisan field-service:reconcile-lifecycle --tenant=collins-electric
php artisan quickbooks:sync-enabled --tenant=collins-electric
php artisan quickbooks:sync-enabled --tenant=collins-electric --full
```

Run the full dry-run audit first, review aggregate counts and errors, take a database backup, then run the snapshot and live sync. Run the live sync a second time and confirm stable row counts with no duplicates.

The scheduler runs enabled connections hourly at minute 35 and performs a rate-limited full reconciliation Sunday at 02:50. Each connection has an overlap lock and checkpoint; failed runs do not advance the checkpoint.
