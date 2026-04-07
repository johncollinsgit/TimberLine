# Growave Parity Acceptance Checklist (Sample Customers)

Use this to validate whether imported Backstage data is trustworthy enough to cancel Growave for normal operations.

## Canonical conversion and reporting notes (2026-04-03)

- Legacy Growave points convert to Candle Cash with `candle_cash = legacy_points * 0.3`.
- Converted legacy Growave balances are grandfathered opening credit and are excluded from earned-limit scope.
- Canonical reference customer for this migration:
  - `Rynda Baker <bakery25@gmail.com>`
  - `legacy_points_total = 1494`
- canonical converted balance at `0.3` = `448.200`.

## Candle Cash reconciliation sequence (2026-04-07)

When liability summaries, dashboard totals, or customer-visible balances disagree, run this exact sequence:

1) Audit scoped liability composition:

```bash
php artisan marketing:audit-candle-cash-composition --tenant-id=1
```

2) Preview drift between `candle_cash_balances` and ledger net totals:

```bash
php artisan marketing:reconcile-candle-cash-balances --tenant-id=1
```

3) Apply deterministic repair (ledger net -> `candle_cash_balances`):

```bash
php artisan marketing:reconcile-candle-cash-balances --tenant-id=1 --apply
```

4) Re-run audit and require `reconciled=yes`:

```bash
php artisan marketing:audit-candle-cash-composition --tenant-id=1
```

5) Confirm legacy conversion remains clean:

```bash
php artisan marketing:validate-candle-cash-legacy-conversion --json --limit=10
```

Notes:
- Reconciliation is preview-first by default (no writes without `--apply`).
- Use `--profile-id={id}` for a targeted profile repair.
- Use `--chunk={n}` to tune large-tenant reconciliation scans.

## Resumable full-import run strategy

1) Start uncapped pass (retail example):

```bash
php artisan marketing:sync-growave --store=retail --checkpoint-every=500
```

2) If interrupted, resume from the previous run checkpoint:

```bash
php artisan marketing:sync-growave --store=retail --resume-run-id=<RUN_ID> --checkpoint-every=500
```

3) Optional faster discovery pass for unsynced records only:

```bash
php artisan marketing:sync-growave --store=retail --only-missing --checkpoint-every=500
```

4) Re-link canonical profiles after each batch:

```bash
php artisan marketing:sync-profiles --source=growave --chunk=1000
```

## Acceptance thresholds

For a sample of at least 20 linked customers:

- points balance: exact match (`local == api`)
- referral link: exact match (`local == api`) or both empty
- review count: exact match (`local == api`)
- average rating: absolute delta `<= 0.01`
- loyalty activity count: local count must be `>=` Growave API count captured in import window
- redemption-related rows: if Growave activity includes redeem/expired/manual entries, corresponding local `candle_cash_transactions` rows must exist

Pass criteria:

- critical fields (points/referral/review_count/avg_rating) pass for `>= 95%` of sample rows
- no systemic mismatch pattern by store/integration
- any mismatches are explainable by sync recency and fixed by one rerun

## 1) Pick a sample set

```bash
php artisan tinker --execute="
\$rows = DB::table('customer_external_profiles as g')
  ->join('marketing_profiles as mp', 'mp.id', '=', 'g.marketing_profile_id')
  ->where('g.integration', 'growave')
  ->whereNotNull('g.marketing_profile_id')
  ->orderByDesc('g.points_balance')
  ->limit(10)
  ->get(['g.marketing_profile_id','mp.email','g.store_key','g.external_customer_id','g.points_balance']);
foreach (\$rows as \$row) { echo json_encode(\$row).PHP_EOL; }
"
```

## 2) Local snapshot (Backstage truth)

```bash
php artisan tinker --execute="
\$rows = DB::table('customer_external_profiles as g')
  ->leftJoin('marketing_review_summaries as rs', function (\$join) {
    \$join->on('rs.marketing_profile_id', '=', 'g.marketing_profile_id')
      ->where('rs.integration', 'growave');
  })
  ->where('g.integration', 'growave')
  ->whereNotNull('g.marketing_profile_id')
  ->orderByDesc('g.points_balance')
  ->limit(10)
  ->get([
    'g.marketing_profile_id',
    'g.external_customer_id',
    'g.points_balance',
    'g.referral_link',
    'rs.review_count',
    'rs.average_rating',
    'rs.source_synced_at',
  ]);
foreach (\$rows as \$row) {
  \$txCount = DB::table('candle_cash_transactions')
    ->where('marketing_profile_id', \$row->marketing_profile_id)
    ->where('source', 'growave_activity')
    ->count();
  echo json_encode(['local' => \$row, 'growave_activity_tx_count' => \$txCount]).PHP_EOL;
}
"
```

## 3) Live compare against Growave API (strict fields)

Requires configured Growave credentials (`MARKETING_GROWAVE_CLIENT_ID` / `MARKETING_GROWAVE_CLIENT_SECRET`).

```bash
php artisan tinker --execute="
\$client = app(\App\Services\Marketing\GrowaveClient::class);
\$samples = DB::table('customer_external_profiles as g')
  ->leftJoin('marketing_review_summaries as rs', function (\$join) {
    \$join->on('rs.marketing_profile_id', '=', 'g.marketing_profile_id')
      ->where('rs.integration', 'growave');
  })
  ->where('integration','growave')
  ->whereNotNull('marketing_profile_id')
  ->orderByDesc('points_balance')
  ->limit(20)
  ->get([
    'g.marketing_profile_id',
    'g.external_customer_id',
    'g.points_balance',
    'g.referral_link',
    'rs.review_count',
    'rs.average_rating',
  ]);

\$totals = ['sample' => 0, 'pass' => 0, 'fail' => 0];
foreach (\$samples as \$sample) {
  \$api = \$client->getCustomer((string) \$sample->external_customer_id);
  \$apiPoints = is_array(\$api) ? (int) (\$api['pointsBalance'] ?? 0) : null;
  \$apiReferral = is_array(\$api) ? (string) (\$api['referralLink'] ?? '') : null;
  \$reviews = \$client->getReviews((string) \$sample->external_customer_id, 50, 0);
  \$apiReviewCount = (int) (\$reviews['totalCount'] ?? 0);
  \$apiAvg = null;
  if (!empty(\$reviews['items'])) {
    \$sum = 0.0;
    \$count = 0;
    foreach ((array) \$reviews['items'] as \$item) {
      if (is_numeric(\$item['rate'] ?? null)) { \$sum += (float) \$item['rate']; \$count++; }
    }
    \$apiAvg = \$count > 0 ? round(\$sum / \$count, 2) : 0.0;
  } else {
    \$apiAvg = 0.0;
  }
  \$localAvg = round((float) (\$sample->average_rating ?? 0), 2);
  \$rowPass = (\$apiPoints === (int) \$sample->points_balance)
    && ((string) (\$sample->referral_link ?? '') === (string) \$apiReferral)
    && ((int) (\$sample->review_count ?? 0) === \$apiReviewCount)
    && (abs(\$localAvg - (float) \$apiAvg) <= 0.01);

  \$totals['sample']++;
  \$totals[\$rowPass ? 'pass' : 'fail']++;

  \$localActivityCount = DB::table('candle_cash_transactions')
    ->where('marketing_profile_id', \$sample->marketing_profile_id)
    ->where('source', 'growave_activity')
    ->count();
  \$apiActivityCount = (int) (app(\App\Services\Marketing\GrowaveClient::class)
    ->getActivityHistory((string) \$sample->external_customer_id, 250, 1)['totalCount'] ?? 0);

  echo json_encode([
    'marketing_profile_id' => (int) \$sample->marketing_profile_id,
    'external_customer_id' => (string) \$sample->external_customer_id,
    'pass' => \$rowPass,
    'local_points_balance' => (int) \$sample->points_balance,
    'api_points_balance' => \$apiPoints,
    'local_referral_link' => (string) (\$sample->referral_link ?? ''),
    'api_referral_link' => \$apiReferral,
    'local_review_count' => (int) (\$sample->review_count ?? 0),
    'api_review_count' => \$apiReviewCount,
    'local_average_rating' => \$localAvg,
    'api_average_rating' => \$apiAvg,
    'local_activity_count' => \$localActivityCount,
    'api_activity_count_page1_total' => \$apiActivityCount,
  ]).PHP_EOL;
}
echo json_encode(['totals' => \$totals]).PHP_EOL;
"
```

## 4) Redemption-related parity spot check

```bash
php artisan tinker --execute="
\$rows = DB::table('candle_cash_transactions')
  ->where('source','growave_activity')
  ->where(function(\$q){
    \$q->where('description','like','%(redeem)%')
      ->orWhere('description','like','%(expired)%')
      ->orWhere('description','like','%manual%');
  })
  ->orderByDesc('id')
  ->limit(25)
  ->get(['marketing_profile_id','points','description','created_at']);
foreach (\$rows as \$row) { echo json_encode(\$row).PHP_EOL; }
"
```

If acceptance thresholds fail, rerun `marketing:sync-growave` for the affected store and re-check.

## 5) Canonical Rynda verification (legacy conversion)

```bash
php artisan tinker --execute="
\$profile = DB::table('marketing_profiles')
  ->whereRaw('LOWER(first_name) = ?', ['rynda'])
  ->whereRaw('LOWER(last_name) = ?', ['baker'])
  ->whereRaw('LOWER(email) = ?', ['bakery25@gmail.com'])
  ->first(['id','email']);

if (! \$profile) {
  echo json_encode(['error' => 'canonical profile not found']).PHP_EOL;
  return;
}

\$legacyPoints = DB::table('candle_cash_transactions')
  ->where('marketing_profile_id', \$profile->id)
  ->where(function(\$q){
    \$q->whereIn('source', ['growave_activity', 'growave', 'legacy_rebase'])
      ->orWhere('type', 'import_opening_balance')
      ->orWhere('legacy_points_origin', true);
  })
  ->sum(DB::raw('COALESCE(legacy_points_value, points, 0)'));

echo json_encode([
  'marketing_profile_id' => (int) \$profile->id,
  'email' => (string) \$profile->email,
  'legacy_points_total' => (int) \$legacyPoints,
  'canonical_balance_at_0_3' => round(((float) \$legacyPoints) * 0.3, 3),
]).PHP_EOL;
"
```
