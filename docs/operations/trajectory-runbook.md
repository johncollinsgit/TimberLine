# Trajectory pilot and release runbook

Trajectory is an independent, default-disabled Everbranch Branch at `/trajectory`.
It has private household spaces, business spaces governed by existing owner/admin
financial access, and explicitly linked views. Public checkout is hard-disabled;
no price, subscription, bank link, or message is created by deployment.

## Prepare the unbilled pilot

Deploy the additive migration and assets through the normal GitHub test/build,
MySQL migration safety, and Forge atomic-release process. Do not use a direct
production push, copy local assets to the live release, or modify existing
Modern Forestry Shopify connections.

Resolve the existing, verified owner email and the actual Modern Forestry tenant
slug in the target environment. Then run the operator command with those values:

```sh
php artisan everbranch:prepare-trajectory --owner=OWNER_EMAIL --business=TENANT_SLUG --plan=both --enable
```

`--plan=personal` needs no company or QuickBooks workspace. `--plan=business`
creates no household. Omitting `--enable` prepares disabled spaces. Repeated
preparation reuses spaces and links; the audit records the zero billing impact.
Set `TRAJECTORY_ENABLED=true` and refresh configuration through the release
process only for the pilot. The flag does not enable any other tenant: a space
must also be enabled and its canonical Trajectory entitlement must allow access.

Partners receive a copyable, expiring invitation URL. They must sign in using the
invited verified email. Invitations never grant company membership. A business
link and cross-space allocation require access to both spaces. No personal
finance records are registered with global search or generic tenant exports.

## Provider onboarding

- Plaid uses `PLAID_ENVIRONMENT` (`sandbox` or `production`), `PLAID_CLIENT_ID`,
  `PLAID_SECRET`, `PLAID_WEBHOOK_URL`, and optional `PLAID_REDIRECT_URI`. Configure
  the webhook at `/api/trajectory/webhooks/plaid`. Link requests Transactions
  history for 730 days and optionally Liabilities. Actual history dates are shown
  per account. Verify USAA, Chase, and Relay institution/product availability in
  the provisioned Plaid environment before promising support. Relay uses this
  adapter, not an invented direct Relay API. Bank login occurs in Plaid Link.
- Sync is queued, locked, idempotent, and commits its cursor only after a complete
  pagination pass. Pending-to-posted changes retain corrections. An amended split
  amount returns to review. Disconnect removes the provider item and local token;
  it preserves transactions. Missing debt metadata remains manual setup.
- Available debt metadata is a review suggestion. Mortgage principal/interest
  must be separated from escrow/fees; a provider mortgage interest rate is not
  labeled origination APR. Multiple card rate tiers need a reviewed modeling
  rate. No provider value silently overwrites reviewed terms.
- QuickBooks is read-only, using exact-period accounting snapshots already
  available in Everbranch. Square/Shopify summaries describe operational sources
  and are never added to the QuickBooks ledger. Missing snapshots, fee/refund
  reconciliation, and missing material costs remain visible coverage gaps.
- GoldAPI uses `GOLDAPI_KEY` server-side. Quotes share a 60-second cache; an active
  Wealth view refreshes each minute. Last-known quotes carry source timestamps
  and stale/market-closed status. No key means no fabricated metal valuation.
- SMS also requires `TRAJECTORY_SMS_ENABLED`, existing Twilio configuration and
  delivery gates, verified phone ownership, and explicit opt-in. Configure signed
  inbound replies at `/api/trajectory/webhooks/sms`. Reply references expire, are
  sender-bound and single-use; STOP revokes consent. Provider submission does not
  prove handset delivery. Keep existing Twilio delivery monitoring active.

Provider references checked September 2026: [Plaid Transactions](https://plaid.com/docs/transactions/),
[Plaid Liabilities](https://plaid.com/docs/api/products/liabilities/),
[GoldAPI](https://www.goldapi.io/). Production credentials and real institution
access have not been validated by the local implementation tests.

## Imports and financial semantics

CSV/XLSX imports are previewed before confirmation (20-minute, single-use token,
5 MB/5,000 rows). Transaction columns are `id,date,merchant,amount,category`;
amounts are signed dollars, negative for money out. Dates use `YYYY-MM-DD`.
Maintain stable source IDs across repeat imports. Account balances are separate
observations; importing a historical statement does not invent today's balance.
Payroll columns come from `config/trajectory_records.php`: employee, period start
and end, wages, overtime, employer taxes, benefits, contractor costs, source ID,
and reviewed flag. Regular wages exclude separately supplied overtime. Monetary payroll columns are integer cents, not dollars;
missing costs must be supplied or left as missing evidence, never replaced by net
pay. The browser displays exact required column names before import.

Currency is integer cents; rates use basis points and decimal operations use
Brick Math. Only USD accounts are imported. Expense/refund allocations total the
original signed amount exactly. Forecast account movements retain the full source amount even when consumption is allocated. Shared rows expose only their authorized amount,
not the original account, merchant, or unsplit amount. Explicit merchant rules
precede provider suggestions; undoing a review also disables the rule it created.
Broad stores and uncertain money movements require review.

Face Punched affects actual spending but is excluded from repeating baselines.
Bullshit Spending is a reviewable category profile with purchase exceptions.
Transfers, card payments, reimbursements, asset movements, and confirmed
duplicates do not become consumption. Review classifications and transfer pairs
before relying on totals; equal amounts alone do not prove a transfer.

Forecasts use the latest 90 complete days, per-account history coverage,
confirmed schedules and reviewed debts. Refunds offset category spending before
scenario reductions and savings estimates; they are not earned income. Credit purchases accrue on debt until
payment. Essential-variable bills remain estimates; opt-in seasonal estimates
require at least a year and 12 matching records. Link debt terms to an existing
recurring bill to replace it rather than count the payment twice. Savings goals
reserve cash without reducing net worth; debt goals reduce the chosen loan.
Monthly amortization is an estimate, not a lender payoff quote. Debt-associated
escrow and fees stop at payoff; ongoing property taxes/insurance should be
separate bills. Baselines assume fixed asset prices and no automatic inflation.
Scenario changes never cancel services, move funds, or modify provider records.

Reliance inputs are reviewed estimates: fixed costs exclude existing owner gross
compensation, which is included once; owner net pay offsets household targets.
The retained distribution fraction and business reserves are explicit inputs,
not inferred tax advice. Company-to-household elimination requires matched pairs.
Combined net worth excludes equity linked to the included business; unlinked
business equity blocks the combined figure pending setup.

Metal lots include purchase cost plus acquisition fees. Fine content uses
31.1034768 grams per troy ounce and entered purity. Partial sales proportionally
remove basis and retain history. Cash matches must equal the lot's purchase cost
or sale proceeds and cannot be reused. The pilot supports one purchase statement
row per lot and one proceeds row per sale; split a multi-lot statement entry
before matching. A sold lot's acquisition history is immutable. Resale
adjustments are user-entered; collector premiums are never inferred.

## Verification and monitoring

```sh
php -d memory_limit=1G vendor/bin/pest tests/Feature/Trajectory --compact
php -d memory_limit=1G vendor/bin/pest --compact
npm run build
node --test tests/e2e/trajectory-money.test.mjs
php scripts/ci/lint-migrations.php --base=BASE_SHA
php artisan trajectory:status
```

Run MySQL recovery tests on an **empty disposable database** and the existing
migration rehearsal/baseline verification scripts on a database named with a
`ci`/`test` segment. The twelve-table migration is restartable and registered in
the recovery manifest. The checked-in schema dump is data-free.

The existing scheduler runs `trajectory:refresh --notify` daily; SMS still needs
all opt-in/delivery gates. Daily snapshots retain observed balances, completeness,
next-day forecast, and next-day forecast error. `trajectory:status` reports only
aggregate source/review/delivery health, without names, amounts, or credentials.
Review stale sources, failed queue jobs, incomplete snapshots, unreviewed
transactions and unmatched owner transfers before trusting forecasts.

For local browser QA, use a dedicated SQLite file at
`/tmp/trajectory-preview.sqlite`, local APP_ENV, generated APP_KEY, and
TRAJECTORY_ENABLED. Migrate, run `php tests/Support/trajectory-preview.php`, and
serve at `http://127.0.0.1:8096`. The isolated fictional fixture is
`owner@trajectory.test` / `Trajectory-local-preview-2026!` (intentionally public
local test credentials). `node tests/e2e/trajectory-smoke.cjs` exercises the UI
and writes ignored/local screenshots under `output/trajectory`. It refuses a
non-local host. Never load this fixture into a live or shared database.

Before actual pilot acceptance, reconcile selected household and company
statements, QuickBooks reports, debt terms, material costs and payroll evidence.
Verify representative refunds, mixed-purpose cards, distributions and processor
payouts, then compare observed cash against forecasts. Local fixtures are not
proof of real financial reconciliation.

## Public subscriptions and rollback

Personal, Business and Both packages are declared in the canonical module
catalog. Pilot access uses existing audited entitlement records, not a second
billing system. Public checkout remains closed until the owner supplies Stripe
prices, production provider access is verified, and existing Stripe subscription
fulfillment is extended and tested for package changes, renewal, failure,
cancellation, grace periods and household/business access. This release does not
activate public subscriptions or claim those lifecycle acceptance gates passed.

Rollback sets `TRAJECTORY_ENABLED=false` through release configuration and
refreshes worker configuration. For one pilot, disable its spaces and canonical
entitlement. Do not drop the new tables or reverse the migration in production;
retain bank history, corrections, lots and audit records. Feature-off stops
access, bank synchronization, snapshots and notification processing without
changing Shopify, Square, QuickBooks, or existing subscriptions.

## Medical sharing: Samaritan Ministries and provider bills

The household-only Medical sharing tab keeps ministry needs, provider invoices,
provider payments, incoming member shares, and monthly contribution matches in
encrypted Trajectory records. It does not connect to a Samaritan API or submit
bills. Members still manage submission and eligibility with the ministry; see
[Samaritan Ministries](https://samaritanministries.org/). No eligibility percentage,
sharing guarantee, or medical advice is inferred.

1. Create a need, record its ministry reference, submission date/status, and an
   optional reviewed sharing target. Do not enter diagnoses in financial labels.
2. Add each provider invoice once with its original amount, discounts, and
   payments made before tracking. Do not record an opening payment again as a
   tracked payment. The provider invoice reference prevents duplicate entry.
3. Review each invoice's remaining monthly payment and next unpaid date. These
   plans explicitly assume zero interest and payments from cash. Set the payment
   to the remaining balance for a planned upfront payment. Use an existing
   recurring-schedule replacement when applicable; do not also record the same
   provider balance as a separate debt. Financed lender balances belong in debt
   records after the provider is paid.
4. Record provider payments against one invoice, or choose a need/provider to
   allocate a consolidated payment to its oldest invoices. Explicit invoice
   matches allocate first. Amounts are integer cents and cannot exceed available
   invoices. Link the exact unsplit household statement row and posted date.
5. Record each expected member share, then change that row to received when the
   payment arrives. Multiple receipts can belong to one need. Link received
   deposits to bank evidence. Expected shares are excluded from income, assets,
   and all baseline/scenario cash forecasts; the expected amount remains visible.
6. Create a separate negative monthly recurring health contribution. Match each
   actual monthly payment using **Record monthly contribution**, even if the
   recipient changes. These matches use that schedule without adding a second
   historical-spending estimate and never pay down a provider invoice.

Received shares never reduce provider balances. By default, the need reserves
`min(unpaid bills, max(0, received shares - provider payments))` from available
cash. This reimburses prior upfront payments first and holds excess receipts
for unpaid bills. The household can disable the reserve per need. Provider
payments release the reserve as cash leaves, avoiding a double subtraction.
Provider balances enter net-worth liabilities. Payments stop at payoff, including
replaced recurring schedules, with final payments capped to the remaining bill.
Due/overdue confirmed payments are provisionally placed tomorrow and flagged for
review; unconfirmed plans flag forecast coverage rather than invent payments.

A manual payment/receipt record updates the medical tracker only. It never
fabricates an observed bank balance or adds a second imported expense. The UI
flags unmatched actuals; match statement rows and update observed balances.
Medical payment and contribution flows count once in gross spending, while
received sharing remains separate from earnings. Medical evidence cannot be
split into business books, reclassified by SMS, reused for an asset, or reconciled
as a transfer while linked. Edit/unlink or remove the medical record first;
unlinking restores its previous classification and preserves audit history.
Changed/removed bank evidence is flagged and excluded from medical payment
calculations until reviewed. Dependent references prevent orphaned records.

Acceptance: `tests/Feature/Trajectory/MedicalSharingTest.php` covers upfront
payments, delayed/expected receipts, reserve release, consolidated invoices,
monthly contribution recipients, exact import matching, source amendments,
corrections, duplicate/overpayment rejection, leap-day payoff, recurring
replacement, and household/business authorization. The local browser smoke
covers creating needs, bills and expected shares plus accessible chart tables.
No new migration or production medical data is required to enable this feature.

## Standalone shell and private household imports (2026-09-14)

Marketing lives at `/trajectory/welcome`; authenticated finance remains at `/trajectory` with its own navigation and a return link to Everbranch. No separate identity store or new public checkout is introduced. All API membership and entitlement checks remain server-side.

Concierge import (private files; default is a full validating rollback):

```sh
php artisan trajectory:import-household --owner=verified-owner@example.com --space=HOUSEHOLD_ID --transactions=/private/monarch.csv --observations=/private/observations.json --chase=/private/chase.csv --chase-account=card-key --balances=/private/monarch-balances.csv
```

Only add `--apply` after inspecting the validated private payload and verifying the intended household. Accounts require an explicit kind, a dated balance or null, and a source. The manifest supports dated budget targets, strictly validated observed statement transactions, evidence corrections to exact source IDs and amounts, and ordered record references. It does not create users, enable provider access, invite partners, activate billing, or send messages. Corrections replay without overwriting later user reviews. Never import the same account from two aggregators as independent ledgers; reconcile that source identity first.

Monarch CSV preserves its original row and source categories; Chase preserves posted activity with stable occurrence identities for identical legitimate purchases. Cash balance exports only include explicitly typed cash accounts. Unknown/manual liability rows are excluded, and cash observations never fabricate a historical net-worth series. Budget imports retain their source version; the latest dated version is the default comparison, not a sum of old plans. Receipts are evidence until matched to a posted transaction.

Subscriptions show confirmed schedules and unconfirmed receipt/history evidence separately. Expired/canceled evidence never establishes a future renewal. Payment notices add only the verified next payment when no linked schedule/debt already covers it. Missing APRs and future minimums remain coverage gaps, with unknown card purchases affecting debt rather than immediate cash. Recorded interest by account replaces overlapping lender period totals; a YTD total cannot fabricate individual monthly charges. Fine-weight metal lots avoid applying purity twice; gross-weight lots apply purity before troy-ounce valuation.

Validation: run `tests/Feature/Trajectory`, the complete existing suite including Shopify gates, `npm run build`, and `tests/e2e/trajectory-smoke.cjs` against the isolated fictional local fixture. The smoke script checks standalone accounts/history/budget navigation, subscription/payment cards, chart evidence, existing medical sharing, and mobile width. No schema change is required for these encrypted record additions. Run the migration linter anyway; production release remains GitHub test/build plus migration safety gate, then Forge atomic activation. Rollback disables the Branch without deleting records.
