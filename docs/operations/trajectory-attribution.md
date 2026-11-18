# Trajectory category and program attribution

Economic detail is additive to the original bank transaction. `trajectory_allocations`
retains its unique transaction/space amount; `trajectory_allocation_details` partitions
that amount into category/subcategory/program lines. There is no copied bank credit,
bank-account ownership change, QuickBooks writeback or invented reimbursement.

## Definitions and edits

`GET /trajectory/spaces/{space}/attribution/definitions` returns the scoped category
keys, category definitions and programs. POST creates a `category_definition` or
`program` record; PATCH `/definitions/{record}` requires its current version.
Category keys and program keys are permanent; names and active state are versioned.
Subcategories reference an active same-direction top-level category in that space.
Historical details retain their record references after a definition is archived.

GET `/transactions/{transaction}/attribution` returns the exact current version and
all economic lines to the authorized source owner. PUT replaces the whole partition
atomically. Each line has `space_id`, signed `amount_cents`, category,
`subcategory_record_id`, `program_record_id`, `settlement_status` and optional
`settlement_note`. The lines must conserve the nonzero bank amount and sign.
Cross-space targets require current financial access to both spaces and the existing
household/business link or common business ownership. Currency must agree.

`settlement_status=reimbursed` requires an owner explanation. It records an attestation
about a previously settled cost; it does not create cash, income, a transfer match,
a scheduled reimbursement or proof of a specific bank receipt.
Pending purchases may receive category/program detail so an alert can reserve the
right budget immediately. They cannot attest open or completed settlement. Pending
detail remains outside posted expense reports and appears only in pending reserves.

PUT returns the new transaction version and `event_id`. POST `/attribution/undo` uses
that exact current version and event ID. A later edit, provider amendment or prior
undo rejects the stale request. Replaced and undone detail rows remain as inactive
history. Existing allocation rows are retained with zero share when no longer used.

## Reading and refresh behavior

`LedgerService::entries` remains one entry per transaction and allocated space. It
adds `allocation_id`, `allocation_details` and `attribution_status` without flattening
bank entries. Cash movement must continue to use original owned bank cash amounts.

`AttributionService::expandEntries(space, entries, programId?, viewer?)` explicitly
projects economic rows. Each has a unique `entry_key`, original `id` and
`source_transaction_id`, effective leaf `category`, `parent_category`, optional
subcategory/program record IDs, and `attribution_detail_id`. The sum equals the
space allocation. With a program filter, unassigned lines are excluded.
Passing the current viewer enables `attribution_editable` and `source_space_id`
only when the viewer may currently inspect both source and target. Business-only
viewers retain redacted shared merchants/accounts and do not receive settlement notes.

Provider changes to identity, amount, date, flow or removed state invalidate active
detail and return the source to review. A pending-to-posted promotion retains detail
only when the same source or the provider's exact `pending_id` proves continuity and
account, amount, date and flow are unchanged. The journal records the new source
fingerprint. Amended postings require review. Repeated unchanged imports preserve
reviewed detail. A legacy allocation split invalidates newer detail history before
its existing split operation runs. Whole-source classification is blocked while
active detail exists; edit its exact partition instead.

## Program budgets and verification

Budget records support an optional active same-space `program_record_id`, optional
`rollover`, `rollover_start_month` and signed `opening_rollover_cents`. Nonzero opening
rollover needs an explicitly reviewed budget. Monthly targets remain plans, not bank
balances or receipt evidence. The budget projection owns same-month actuals and
rollover calculations.
Latest effective monthly cohorts are selected independently by purpose, program and
category. Payroll/role-target overlap is checked within the same program; a Soccer
replacement cannot silently replace Co-op's target or revive Soccer's expired one.

Run `TrajectoryAttributionTest` and affected ledger/scope tests. The detail-table
migration is guarded and retains data on rollback. Its registered recovery test
requires `TRAJECTORY_DISPOSABLE_MYSQL=1` with localhost disposable `everbranch_ci`
or `codex_trajectory_test`; production is never a recovery-test target. Normal
protected migration validation and exact-release verification still apply.
