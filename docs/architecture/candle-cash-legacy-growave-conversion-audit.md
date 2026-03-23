# Candle Cash Legacy Growave Conversion Audit

Date: 2026-03-20

## Clarified intended rule

This audit captures the intended behavior clarified during investigation:

- Growave points are legacy input only.
- Growave points should be converted once into starting Candle Cash / initial balance.
- Candle Cash is canonical and should be stored as Candle Cash, not in legacy points.
- Legacy `points` columns are compatibility fields only and should not be treated as canonical Candle Cash storage.
- Growave sync is read-only against Growave itself.

In plain terms:

- `30%` of `100` points = `30`
- `30` points per `1` Candle Cash = `3.333...` Candle Cash from `100` points
- current historical conversion rule in code = `100 * 0.003 = 0.3` Candle Cash

Those are three different outcomes. Only one can be the source of truth.

## Existing touchpoints

### 1. Historical Growave-to-Candle-Cash conversion helper

File:
- `app/Support/Marketing/CandleCashMeasurement.php`

Current behavior:
- Defines `LEGACY_STARTING_CANDLE_CASH_PER_POINT = 0.003`
- Converts legacy Growave points into Candle Cash with `legacyPointsToStartingCandleCash()`

Assessment:
- Potentially aligned only if this rule is strictly limited to one-time starting balance conversion.

### 2. Legacy compatibility helper using 30 points per Candle Cash

File:
- `app/Services/Marketing/CandleCashService.php`

Current behavior:
- Defines `DEFAULT_LEGACY_POINTS_PER_CANDLE_CASH = 30`
- Exposes `legacyPointsFromCandleCash()` and `candleCashFromLegacyPoints()`

Assessment:
- This is a separate conversion model from `0.003`.
- It conflicts with the clarified intent if it is used for canonical Candle Cash math rather than compatibility-only display or migration helpers.

### 3. Opening-balance import from Growave snapshots

File:
- `app/Console/Commands/MarketingImportGrowaveOpeningBalances.php`

Current behavior:
- Reads `customer_external_profiles.points_balance`
- Converts that snapshot into a Candle Cash starting balance
- Writes an `import_opening_balance` transaction and updates `candle_cash_balances`

Assessment:
- This is the most aligned path with the clarified rule.
- It treats legacy Growave points as a one-time initial balance conversion.

### 4. Legacy correction / rebase flow

Files:
- `app/Console/Commands/MarketingRebaseCandleCashBalances.php`
- `app/Services/Marketing/LegacyCandleCashCorrectionService.php`

Current behavior:
- Rewrites legacy Growave-origin rows using the fixed `0.003` conversion rule
- Recomputes balances from corrected Candle Cash values

Assessment:
- Reasonable only as a one-time historical cleanup.
- Not aligned if the business intent was merely a 30 percent haircut or a 30-points-per-1-Candle-Cash mapping.

### 5. Growave activity import path

File:
- `app/Services/Marketing/GrowaveMarketingSyncService.php`

Current behavior:
- Imports Growave activity rows
- Converts each legacy Growave point delta to Candle Cash using `legacyPointsToStartingCandleCash()`
- Also stores legacy `points` values alongside the imported transaction

Assessment:
- This extends the legacy conversion beyond starting balance.
- It may conflict with the clarified intent if Growave points were only supposed to seed an initial balance rather than define ongoing canonical Candle Cash ledger math.

### 6. Legacy transaction model fallback

File:
- `app/Models/CandleCashTransaction.php`

Current behavior:
- For Growave-origin rows, falls back to deriving `candle_cash_delta` from legacy `points`
- Treats Growave-origin legacy rows as canonical Candle Cash when the canonical column is absent

Assessment:
- Useful as a temporary compatibility bridge.
- Conflicts with the clarified intent once canonical Candle Cash storage is expected to be independent from points.

### 7. Customer UI diagnostics

File:
- `app/Http/Controllers/Marketing/MarketingCustomersController.php`

Current behavior:
- Surfaces `legacy_growave_points` separately from Candle Cash

Assessment:
- Aligned.
- This preserves Growave points as diagnostic/source data rather than canonical loyalty currency.

### 8. Tests that currently encode the contradiction

Files:
- `tests/Unit/Marketing/CandleCashServiceTest.php`
- `tests/Feature/Marketing/CandleCashLegacyRebaseCommandTest.php`
- `tests/Feature/Marketing/CandleCashLegacyConversionValidationTest.php`

Current behavior:
- Tests explicitly assert both:
  - `30` legacy points per Candle Cash compatibility helpers
  - `0.003` historical Growave conversion behavior

Assessment:
- The codebase currently preserves two different legacy conversion stories.
- This is the main reason the current behavior is hard to reason about.

## Live database observations from this investigation

Environment date:
- 2026-03-20

Observed state:
- `candle_cash_transactions` still only has the legacy `points` column in this local database.
- The canonical `candle_cash_delta` column is not present yet.
- No `growave` opening-balance imports were recorded here.
- No `legacy_rebase` rows were recorded here.
- No `candle_cash_legacy_points_correction` runs were recorded here.
- March 12, 2026 Growave sync runs imported local `growave_activity` rows.

Sample observed profile:
- `marketing_profile_id = 1414`
- latest Growave snapshot `points_balance = 8859`
- imported local Growave activity points total = `8859`
- local stored `candle_cash_balances.balance = 8859`

Interpretation:
- In this local database, Growave-derived values are still effectively stored as raw legacy totals.
- The more aggressive `0.003` conversion logic exists in code, but it has not been physically applied in this database.

## Recommended source-of-truth rule set

If the clarified intent is correct, the repo should converge on this model:

1. Growave points remain source data only.
2. Growave `points_balance` may be converted once into starting Candle Cash.
3. Candle Cash balances and ledger deltas are stored canonically in Candle Cash columns only.
4. Legacy `points` columns remain compatibility or audit fields only.
5. Ongoing Candle Cash math should not depend on legacy point storage.

## Places likely needing follow-up alignment

- Decide whether `0.003` is the approved one-time starting-balance factor.
- Confirm whether Growave activity import should convert into canonical Candle Cash history or remain source-only audit data.
- Remove or isolate the `30 points per Candle Cash` helper if it is no longer intended to inform loyalty math.
- Complete the canonical Candle Cash migration so runtime behavior no longer depends on legacy `points` fallback reads.

## Bottom line

The clarified business rule is simple:

- convert legacy Growave points only for starting Candle Cash
- do not treat Candle Cash as points-backed storage

The current codebase does not consistently enforce that rule yet. It contains:

- a `0.003` historical conversion rule
- a `30 points per Candle Cash` compatibility rule
- a legacy storage fallback that still treats points as runtime truth in older schemas

That mismatch should be resolved before any new Growave-to-Candle-Cash migration or rebasing work is run.
