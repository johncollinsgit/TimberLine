# Collins Electric Access and QuickBooks Guardrails

## Workspace ownership

- Collins Electric is tenant-scoped under `collins-electric`.
- Nathan Collins is the business owner. `collinselectric91@gmail.com` is provisioned as the active, verified tenant owner until Nathan supplies a different verified identity.
- John Collins has admin access for implementation and mobile testing.
- Preserve John's other workspace memberships. Collins access must never be inferred from his email or from membership in another tenant.
- Future customer workspaces must explicitly grant `johncollinsemail@gmail.com` implementation-admin access during provisioning so John can build, support, and test them without a second login. This is an explicit membership requirement, not an email-based tenant-ownership shortcut.

## Launch status

- The Collins Electric core launch was approved on 2026-07-20. The business profile, guided import, final production blueprint, owner/admin roles, Work 2.0 entitlement, field-service calendar, reporting, private documents, estimator drafts, and iOS photo-upload path are ready.
- Historical QuickBooks data remains available read-only. Scheduled synchronization is paused until verified production Intuit client credentials are configured and the connection is reauthorized. Existing imported records must not be deleted or rewritten while it is paused.
- The Collins launch-partner agreement is prepared as an unsigned draft with editable defaults of $299 once, $59/month for six cycles, and $149/month beginning in cycle seven. Acceptance and collection remain blocked until the operator sends the immutable proposal and live Stripe readiness passes.
- SMS is requested/enabled for Collins, but delivery remains fail-closed until the tenant sender/provider, quiet hours, opt-out ingestion, and delivery test are verified. This does not block the core workspace.

## Access model

Owner/admin users may access operational data, QuickBooks connection health, imported financial documents, receivables, reports, price-book analysis, and future estimator administration.

Team members are operational users. They may access customers, jobs, service addresses, lock-box codes, calendars, job assignments, tasks, job posts/notes, photos, mentions, and job communication. They must not receive financial reports, document totals, receivables, profit-and-loss data, wage totals, price-book costs, billing controls, or integration credentials.

Field-service routes permit active tenant members because the surface is operational. Collins sets `member_job_visibility=all_operational`, so employees may browse every current and past operational job; edits, lifecycle changes, and task completion remain assignment-based. Financial data remains in separate models and must only be exposed from an owner/admin guarded controller or contract. Do not add financial fields to the general job payload.

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
- SMS delivery remains blocked until provider readiness, employee/customer consent, quiet hours, opt-out handling, and delivery logs are proven.
- Nathan needs customer, job, calendar, assignment, notes, photos, and communication workflows before financial enhancements.
- `Field Service` is Collins' canonical Work experience. Do not restore separate visible `work_core` and `field_service` Branches. The old Work URLs remain compatibility aliases only.
- QuickBooks invoices and estimates create owner/admin-only Job Drafts, not automatic employee-visible jobs. Nathan reviews operational details and uses Publish Job or Link to Existing Job. Archive/restore changes only the draft and survives later sync; the accounting document stays immutable.
- Job Draft and employee payloads must omit invoice/estimate labels, source identifiers, document numbers, amounts, balances, receivables, private notes, and dollar-formatted values. Existing generated titles containing “Invoice” may be normalized only when they were not manually renamed.
- Import service addresses in this order: QuickBooks shipping address, QuickBooks billing address, then the existing tenant customer address. Publishing copies the structured address to the job.
- Jobs carry an external project manager name, company, phone, and email entered by Nathan. Employees receive a Project Manager card with direct Call and Text actions plus explicit Apple Maps and Google Maps links for the service address.
- Send to Office targets one active owner/admin/manager, idempotently reassigns the task, marks it waiting, records history, and notifies the selected office person. New tasks may target an office person directly.
- Drawings are PDF files selected from iOS Files or Documents, limited to 25 MB, stored as authenticated tenant/job assets, audited, and team-visible by default. All authorized Collins employees may view them; team visibility is not public access.
- Job comments, tasks, participants, mentions, and photos are tenant-owned. SMS notifications remain suppressed unless the employee has a tenant-specific verified phone and operational opt-in and the Collins sender passes its delivery smoke test.
- Collins confirmed on 2026-07-20 that written SMS consent is held for its customers. Import that evidence only through `collins-electric:import-written-sms-consent --confirm-written-consent --source-reference="..."`; the command creates per-customer consent evidence, skips profiles without a phone, and preserves explicit opt-outs.
- Collins inventory uses tenant-owned catalog items for warehouse quantity, reorder level, unit, SKU, and cost. Van stock is a separate tenant-owned quantity; loading and unloading move stock between those two locations without changing QuickBooks.
- A deployment connects one active job, one work van, and one or more active Collins members. The selected employees also become job participants. Members may see only deployments attached to jobs they are authorized to view; owner/admin/manager users manage assignments.
- Material recorded as used on a job is deducted from the assigned van, added to the job's material usage, and written to the inventory movement history in one transaction. Insufficient van or warehouse stock must fail without a partial change.
- The Customers link searches Collins-owned `MarketingProfile` records, including identities created by the read-only QuickBooks import. It must never include wholesale applications or another workspace's profiles. `Text customer` opens the Everbranch composer with that tenant-owned profile preselected and retains consent/provider/opt-out gates. `Email customer` opens the device mail client and is not an Everbranch-tracked email delivery.

## Equipment maintenance and payroll hours

- `equipment_maintenance` is an explicit audited Collins entitlement layered on Field Service. Equipment belongs to a tenant-scoped customer and records type, name, manufacturer, model, serial number, installation date, assigned technician, service interval, last service, next due date, status, and notes.
- The daily 09:05 maintenance scan creates one idempotent field-service job and task for equipment due within 30 days. The job is visible in the calendar/list and its notes, technician, tasks, and photos are the service record. Completing the job advances the next due date from the completion date.
- Maintenance customer SMS uses the canonical consent and delivery ledger. It requires recorded SMS consent, a usable phone, no opt-out, an enabled reminder setting, and a verified Collins provider/sender. Assigned-team SMS separately requires a tenant-member verified phone and operational opt-in.
- Payroll hours are job-linked work-date, start/end, unpaid-break, employee, notes, review status, reviewer, and approved CSV export records. Everbranch does not calculate wages/overtime, withhold taxes, classify workers, file payroll returns, or remit payroll.

## Work 2.0 pilot guardrails

- Collins is the first `trades` Field Operations v7 tenant. Enable it through `field_service` entitlement metadata `experience_version=3`, `field_service_contract_version=7`, and `member_job_visibility=all_operational`; do not infer reusable runtime behavior from the Collins slug.
- Owners/admins can archive a job from the native list with a left swipe. Archive is a guarded move into History, not deletion, and Reopen restores the normal readiness-derived state.
- Missing schedule, job-site address, description, customer contact, or crew assignment appears as a neutral setup action on job detail. Each action opens the exact field instead of showing a generic field-readiness alert.
- The canonical job statuses are `quote`, `needs_details`, `scheduled`, `active`, `blocked`, `complete`, `canceled`, and `history`. A blocked transition requires a reason. Lead technicians and participants may progress their own jobs; cancellation/reopen, assignments, and job editing remain manager/admin actions.
- Ready for field means schedule + service address + work description + customer phone/email + at least one lead/participant. Readiness is computed, never a second mutable status flag.
- My Day is role-aware and tenant-scoped. Nathan, John, and Collins managers see team attention queues; active members can browse all operational jobs but My Day remains focused on their assigned schedule and tasks. Financial payloads continue through the separate owner/admin financial gate.
- Everbranch APNs is dedicated to `com.everbranch.app`. Never reuse Modern Forestry keys or push-device rows. Assignment, schedule, mention, comment, task, status, 24-hour, and 2-hour events may create in-app/push records; operational SMS stays fail-closed.
- iCloud and Shared Album photos are user-selected through the system picker, resized on device, uploaded sequentially, and stored as authenticated team-visible Everbranch assets. Photos and documents have separate counts and must not render twice.

## QuickBooks handling

- The integration is read-only and tenant-scoped, and uses encrypted tokens, reporting snapshots, sync summaries, and source snapshots. Hourly synchronization is available only after an owner explicitly enables it for that connection; all other tenants remain off by default.
- Invoice private notes, customer memos, and line descriptions are important Collins context. Import them as source-attributed job activity without overwriting employee posts. Mark `PrivateNote` activity owner-only; customer memos and work-line descriptions may remain team-visible when they are operational.
- Profit-and-loss wage lines are an aggregate labor-cost signal, not QuickBooks Payroll records. Track wages/payroll separately from contract labor/subcontractors. Collins reports roughly $100,000 of contract labor year to date; confirm the amount and report period through the API before displaying it. Both may support owner-only labor percentages but cannot establish employee-level pay or job profitability by themselves.
- The discovery command requests Profit and Loss explicitly from January 1 through the current date and records that period alongside the aggregate. Dashboard date controls must not silently relabel a year-to-date QuickBooks report as a shorter window.
- Do not enable payments, write-back, webhooks, CDC, QuickBooks payroll, or estimator write-back from discovery alone. The hourly scheduler may be enabled for Collins only after the dry-run, backup, two stable live imports, mapping review, and owner/team permission smoke tests pass.
- QuickBooks documents remain separate from jobs unless source evidence supports a job link. Preserve manual jobs and imported document identities.
- Production discovery on 2026-07-13 verified the Collins OAuth connection and completed a full read-only audit. It found a mature accounting history with hundreds of customers, extensive estimates/invoices, a large purchase history, repeated price patterns, open receivables, and owner-level wage/contract-labor signals. Keep exact source records and financial totals in encrypted tenant data and owner-facing reports rather than this repository.
- The first legacy sync preview was intentionally blocked because description text alone would have classified nearly every accounting document as a job. `QuickBooksJobEvidenceClassifier` now requires a QuickBooks job/subcustomer, project reference, distinct service address, explicit memo/private note, or service-dated line. Description-only documents import into the owner review queue and remain searchable without creating jobs.
- Once a transaction independently qualifies as operational work, its job site uses QuickBooks Ship To when present, preserves an already confirmed job address when Ship To is absent, and then falls back to Bill To or the imported customer address. That fallback does not itself qualify a document as a job.
- Before live import, deploy the classifier, rerun both full audit and sync dry runs, review candidate/review counts, take a database backup, persist the encrypted audit snapshot, then run the live sync twice to prove stable identities and row counts.
- QuickBooks is the shared opt-in `quickbooks` beta Branch. Collins is the first enabled tenant, not a custom implementation. Onboarding interest never enables the Branch; OAuth, audit, and sync require an explicit tenant entitlement and owner/admin access.
- All Everbranch operational dashboard metrics use the shared `1d`, `1w`, `1m`, `30d`, and `ytd` selector, defaulting to the current calendar month. This does not change the explicit YTD period used by the QuickBooks P&L audit.
- Owner reporting uses P&L Total Income as the denominator for employee, contract, and combined labor percentages. Supplies, wages, contract labor, and Nathan's owner compensation remain blank until an owner reviews exact QuickBooks account mappings. A monthly owner-adjustment fallback is supported when owner compensation cannot be separated by account.
- QuickBooks invoices are labeled `work billed`; only Everbranch jobs with `completed_at` are labeled `jobs completed`. Historical pending estimates live in quote aging and do not create current follow-up tasks automatically.
- The shared `documents` Branch stores authenticated tenant-scoped copies. Team uploads—including PDF drawings—default to team-visible; QuickBooks attachments default to owner-only. One asset can link to several jobs, text/CSV contents and tags are searchable, and upload/link/download/delete activity is audited. Collins' initial QuickBooks sweep returned zero attachments, so an empty Documents screen is expected until the team uploads job photos or files.
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
php artisan field-service:normalize-job-drafts collins-electric
php artisan field-service:normalize-job-drafts collins-electric --apply
php artisan quickbooks:sync-enabled --tenant=collins-electric
php artisan quickbooks:sync-enabled --tenant=collins-electric --full
php artisan field-service:scan-equipment-maintenance --tenant=collins-electric
php artisan collins-electric:import-written-sms-consent --confirm-written-consent --source-reference="Written consent file retained by Collins Electric; owner confirmed 2026-07-20."
```

Run the full dry-run audit first, review aggregate counts and errors, take a database backup, then run the snapshot and live sync. Run the live sync a second time and confirm stable row counts with no duplicates.

The scheduler runs enabled connections hourly at minute 35 and performs a rate-limited full reconciliation Sunday at 02:50. Each connection has an overlap lock and checkpoint; failed runs do not advance the checkpoint.
