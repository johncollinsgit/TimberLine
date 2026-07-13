# Collins Electric Access and QuickBooks Guardrails

## Workspace ownership

- Collins Electric is tenant-scoped under `collins-electric`.
- Nathan Collins is the business owner and the intended owner/admin user.
- John Collins has admin access for implementation and mobile testing.
- Do not create or attach Nathan's login until his verified email or Google identity is known.

## Access model

Owner/admin users may access operational data, QuickBooks connection health, imported financial documents, receivables, reports, price-book analysis, and future estimator administration.

Team members are operational users. They may access customers, jobs, service addresses, lock-box codes, calendars, job assignments, tasks, job posts/notes, photos, mentions, and job communication. They must not receive financial reports, document totals, receivables, profit-and-loss data, wage totals, price-book costs, billing controls, or integration credentials.

Field-service routes permit tenant members because the surface is operational. Financial data remains in separate models and must only be exposed from an owner/admin guarded controller or mobile contract. Do not add financial fields to the general job payload.

## Collins workflow requirements

- Job posts should support team mentions and preserve an activity history.
- Team members need upcoming-job reminders by text after consent, provider, and delivery controls are verified.
- Owner/admin users need a controlled reminder for employees when hours are due.
- SMS remains disabled until provider readiness, employee consent, quiet hours, opt-out handling, and delivery logs are proven.
- Nathan needs customer, job, calendar, assignment, notes, photos, and communication workflows before financial enhancements.

## QuickBooks handling

- The integration is read-only, on-demand, tenant-scoped, and uses encrypted tokens and encrypted source snapshots.
- Invoice private notes, customer memos, and line descriptions are important Collins context. Import them as source-attributed job activity without overwriting employee posts. Mark `PrivateNote` activity owner-only; customer memos and work-line descriptions may remain team-visible when they are operational.
- Profit-and-loss wage lines are an aggregate labor-cost signal, not QuickBooks Payroll records. Track wages/payroll separately from contract labor/subcontractors. Collins reports roughly $100,000 of contract labor year to date; confirm the amount and report period through the API before displaying it. Both may support owner-only labor percentages but cannot establish employee-level pay or job profitability by themselves.
- The discovery command requests Profit and Loss explicitly from January 1 through the current date and records that period alongside the aggregate. Dashboard date controls must not silently relabel a year-to-date QuickBooks report as a shorter window.
- Do not enable payments, write-back, webhooks, CDC, scheduled sync, Estimator, or payroll features from discovery alone.
- QuickBooks documents remain separate from jobs unless source evidence supports a job link. Preserve manual jobs and imported document identities.
- Production discovery on 2026-07-13 verified the Collins OAuth connection and completed a full read-only audit. It found a mature accounting history with hundreds of customers, extensive estimates/invoices, a large purchase history, repeated price patterns, open receivables, and owner-level wage/contract-labor signals. Keep exact source records and financial totals in encrypted tenant data and owner-facing reports rather than this repository.
- The first legacy sync preview was intentionally blocked because description text alone would have classified nearly every accounting document as a job. `QuickBooksJobEvidenceClassifier` now requires a QuickBooks job/subcustomer, project reference, distinct service address, explicit memo/private note, or service-dated line. Description-only documents import into the owner review queue and remain searchable without creating jobs.
- Before live import, deploy the classifier, rerun both full audit and sync dry runs, review candidate/review counts, take a database backup, persist the encrypted audit snapshot, then run the live sync twice to prove stable identities and row counts.
- QuickBooks is the shared opt-in `quickbooks` beta Branch. Collins is the first enabled tenant, not a custom implementation. Onboarding interest never enables the Branch; OAuth, audit, and sync require an explicit tenant entitlement and owner/admin access.
- All Everbranch operational dashboard metrics use the shared `1d`, `1w`, `1m`, `30d`, and `ytd` selector, defaulting to the current calendar month. This does not change the explicit YTD period used by the QuickBooks P&L audit.

## Commands

```bash
php artisan field-service:audit-quickbooks --tenant=collins-electric --full --dry-run
php artisan field-service:audit-quickbooks --tenant=collins-electric --full
php artisan field-service:sync-quickbooks --tenant=collins-electric --dry-run
php artisan field-service:sync-quickbooks --tenant=collins-electric
```

Run the full dry-run audit first, review aggregate counts and errors, take a database backup, then run the snapshot and live sync. Run the live sync a second time and confirm stable row counts with no duplicates.
