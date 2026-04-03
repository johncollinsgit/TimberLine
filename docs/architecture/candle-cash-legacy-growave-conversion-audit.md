# Candle Cash Legacy Growave Conversion Canon

Date: 2026-04-03

## Canonical policy

- Legacy Growave points convert to Candle Cash at a fixed factor of `0.3`.
- Conversion formula: `candle_cash = legacy_points * 0.3`.
- This conversion is applied consistently for legacy Growave-origin rows:
  - opening imports
  - Growave activity sync imports
  - Growave snapshot merge imports
  - legacy correction/rebase flow
  - model-level legacy fallback conversion
- Converted legacy Candle Cash is **earned-limit exempt** and is treated as grandfathered opening credit for earned-bucket analytics.
- Program-earned Candle Cash remains the only earned-limit eligible balance class.

## Runtime touchpoints aligned to this canon

- Conversion constant and helper:
  - `app/Support/Marketing/CandleCashMeasurement.php`
- Legacy correction and rebase execution:
  - `app/Services/Marketing/LegacyCandleCashCorrectionService.php`
  - `app/Console/Commands/MarketingRebaseCandleCashBalances.php`
- Import and sync paths:
  - `app/Console/Commands/MarketingImportGrowaveOpeningBalances.php`
  - `app/Services/Marketing/GrowaveMarketingSyncService.php`
  - `app/Console/Commands/MarketingMergeGrowaveSnapshot.php`
- Earned-limit eligibility/exemption contract:
  - `app/Services/Marketing/CandleCashLedgerNormalizationService.php`

## Canonical reference customer

Canonical reporting identity:

- `Rynda Baker <bakery25@gmail.com>`

As-of snapshot used for canon statement (2026-04-03):

- `legacy_points_total = 1494`
- prior converted legacy balance at old factor = `4.482`
- canonical converted legacy balance at `0.3` factor = `448.200`

Formula:

- `1494 * 0.3 = 448.200`

Canon note:

- This converted legacy balance is grandfathered opening credit and remains excluded from earned-limit scope.

## Rebase runbook

1. Preview:

```bash
php artisan marketing:rebase-candle-cash-balances --factor=0.3 --dry-run
```

2. Apply with idempotency key:

```bash
php artisan marketing:rebase-candle-cash-balances --factor=0.3 --run-key=<timestamped-key>
```

3. Validate:

- legacy rows corrected
- legacy rebase rows neutralized (`candle_cash_delta = 0`)
- balances recomputed from canonical transaction deltas
- earned analytics still exclude grandfathered/converted opening credit from earned buckets
