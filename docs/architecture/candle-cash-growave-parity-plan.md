# Candle Cash Growave Parity Plan

Date: 2026-03-11

## Canonical update (2026-04-03)

- Legacy Growave conversion factor is now `0.3` (`candle_cash = legacy_points * 0.3`) across legacy import/sync/correction paths.
- Historical legacy-origin rows are rebased through `marketing:rebase-candle-cash-balances` with factor `0.3`.
- Converted legacy balances are grandfathered opening credit and are excluded from earned-limit scope.
- Canonical reference customer:
  - `Rynda Baker <bakery25@gmail.com>`
  - `legacy_points_total = 1494`
  - canonical converted balance at factor `0.3` = `448.200` Candle Cash.

## Current state (verified in code)

Implemented now:
- Candle Cash balance table: `candle_cash_balances`
- Candle Cash transaction ledger: `candle_cash_transactions`
- Reward catalog + redemption lifecycle: `candle_cash_rewards`, `candle_cash_redemptions`
- Shopify widget reward endpoints: balance, available rewards, history, redeem
- Reconciliation operations for unresolved storefront/public redemption issues
- Growave snapshot sync from Shopify metafields into `customer_external_profiles`

Still missing for Growave parity:
- No automatic import from `customer_external_profiles.points_balance` into Candle Cash ledger/balance
- No full Growave reward event ingestion pipeline (order earns, review earns, expirations, adjustments) into immutable Candle Cash events
- No review provider sync + review prompt execution flow in Marketing Reviews surface

## Why Candle Cash customers can appear empty

If a customer only exists in Growave snapshot rows and their points were never imported into Candle Cash tables, Candle Cash UI/widgets read as zero even though Growave reports points externally.

## Phase 1 delivery in this commit

Added command:
- `php artisan marketing:import-growave-opening-balances`

Behavior:
- Reads latest Growave snapshot per marketing profile from `customer_external_profiles`
- Imports snapshot into Candle Cash as immutable opening event:
  - `candle_cash_transactions.type = import_opening_balance`
  - `candle_cash_transactions.source = growave`
- Converts snapshot points using canonical legacy factor (`0.3`) and sets `candle_cash_balances.balance` to the converted value
- Idempotent guardrail: skips profiles that already have a Growave opening import
- Safety guardrail: skips profiles that already have any Candle Cash transactions
- Supports dry-run mode

Options:
- `--limit=500`
- `--store=retail|wholesale`
- `--profile-id={id}`
- `--dry-run`

## Rollout runbook

1. Preview import scope:

```bash
php artisan marketing:import-growave-opening-balances --dry-run --limit=500
```

2. Execute live import:

```bash
php artisan marketing:import-growave-opening-balances --limit=500
```

3. Reconcile sample customers from Growave activity logs:
- Melissa Orr
- Denise Wohlford
- Brittany Bisnett

4. Verify for each sample:
- Profile has latest Growave external snapshot row
- Profile has one `import_opening_balance` transaction
- Candle Cash balance matches imported snapshot

## Next implementation stages

1. Growave event ingestion:
- ingest earn/redeem/expire/adjust/review events into immutable Candle Cash ledger events
- map event source IDs for idempotent replay

2. Review parity:
- provider-backed review sync
- post-purchase review prompt queue
- one-time review reward issuance guardrails

3. Rule parity:
- earn rules, multipliers, thresholds, expiration policy, and campaign multipliers from settings-backed rule engine

4. Redemption parity hardening:
- refund/cancel reversal policies
- cross-channel double-spend prevention checks
