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
