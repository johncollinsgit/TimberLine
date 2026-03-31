# Candle Cash CFO Scenario Template

Use this template to estimate Candle Cash economics for the latest non-wholesale order cohort.

## Purpose

This sheet answers:
- How much reward liability will be issued?
- How much is likely to be redeemed vs breakage?
- How many incremental repeat orders are required to break even?
- What is net GMV impact under conservative/base/aggressive assumptions?

## Program Defaults (Current Implementation)

- Second-order task reward: `$5`
- Redemption increment: `$10`
- Max redeemable per order: `$10`
- Open code cap: `1` active issued code

## Step 1: Pull Cohort Baseline

Use the most recent 150 non-wholesale orders:

```sql
WITH normalized_orders AS (
  SELECT o.id, o.ordered_at, o.shopify_customer_id, o.order_type, o.container_name
  FROM orders o
  WHERE COALESCE(LOWER(o.order_type), '') != 'wholesale'
    AND COALESCE(LOWER(o.container_name), '') NOT LIKE 'wholesale:%'
),
latest150 AS (
  SELECT *
  FROM normalized_orders
  ORDER BY datetime(ordered_at) DESC, id DESC
  LIMIT 150
),
seq AS (
  SELECT l.*,
         (
           SELECT COUNT(*)
           FROM normalized_orders n2
           WHERE n2.shopify_customer_id = l.shopify_customer_id
             AND (
               datetime(n2.ordered_at) < datetime(l.ordered_at)
               OR (datetime(n2.ordered_at) = datetime(l.ordered_at) AND n2.id <= l.id)
             )
         ) AS customer_order_number
  FROM latest150 l
),
line_values AS (
  SELECT ol.order_id,
         SUM(COALESCE(ol.quantity, 0) * COALESCE(s.retail_price, 0)) AS est_value
  FROM order_lines ol
  LEFT JOIN sizes s ON s.id = ol.size_id
  GROUP BY ol.order_id
)
SELECT
  (SELECT COUNT(*) FROM latest150) AS sample_orders,
  (SELECT COUNT(*) FROM seq WHERE customer_order_number = 2) AS second_orders,
  ROUND((SELECT SUM(est_value) FROM line_values lv JOIN latest150 l ON l.id = lv.order_id), 2) AS est_total_value,
  ROUND((SELECT AVG(COALESCE(lv.est_value, 0)) FROM latest150 l LEFT JOIN line_values lv ON lv.order_id = l.id), 2) AS est_aov
;
```

## Step 2: Input Cells

Create these input cells in a spreadsheet:

- `B2` = `Second-order reward amount` (default `5`)
- `B3` = `Second-order events in cohort` (from SQL)
- `B4` = `Estimated AOV` (from SQL)
- `B5` = `Estimated cohort GMV` (from SQL)
- `B6` = `Contribution margin` (e.g. `0.60`)

Derived:

- `B8` = `Issued credits` = `=B2*B3`

## Step 3: Scenario Grid

For each scenario row, use these columns:

- `A` Scenario name
- `B` Redemption rate assumption
- `C` Incremental repeat uplift assumption (applied to second-order customer count)
- `D` Realized discount cost
- `E` Breakage
- `F` Incremental orders
- `G` Incremental GMV
- `H` Net GMV after discounts
- `I` Break-even incremental orders (100% GMV)
- `J` Break-even incremental orders (contribution margin)

Formulas (row 12 shown; copy down):

- `D12` = `=$B$8*B12`
- `E12` = `=$B$8-D12`
- `F12` = `=$B$3*C12`
- `G12` = `=F12*$B$4`
- `H12` = `=G12-D12`
- `I12` = `=D12/$B$4`
- `J12` = `=D12/($B$4*$B$6)`

## Recommended Scenario Inputs

- Conservative: redemption `30%`, uplift `2%`
- Base: redemption `55%`, uplift `6%`
- Aggressive: redemption `80%`, uplift `10%`

## Filled Example (Latest 150 Non-Wholesale Orders, 2026-03-31)

Inputs used:

- Second-order reward amount: `$5`
- Second-order events: `57`
- Estimated AOV: `$53.41`
- Estimated cohort GMV: `$8,012.00`
- Contribution margin: `60%`
- Issued credits: `$285.00`

Results:

| Scenario | Redemption Rate | Uplift | Realized Cost | Breakage | Incremental Orders | Incremental GMV | Net GMV After Discounts | Break-even Orders @100% GMV | Break-even Orders @60% Margin |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Conservative | 30% | 2% | $85.50 | $199.50 | 1.14 | $60.89 | -$24.61 | 1.60 | 2.67 |
| Base | 55% | 6% | $156.75 | $128.25 | 3.42 | $182.66 | $25.91 | 2.93 | 4.89 |
| Aggressive | 80% | 10% | $228.00 | $57.00 | 5.70 | $304.44 | $76.44 | 4.27 | 7.11 |

## Monthly Operating Cadence

1. Run the cohort SQL for latest 150 non-wholesale orders.
2. Paste new `second_orders`, `AOV`, and `cohort GMV` into the input cells.
3. Keep scenario rates stable for trend comparability unless strategy changes.
4. Review actual redeemed amount from Candle Cash reporting and update redemption assumptions quarterly.
5. Track break-even threshold and compare against observed incremental repeat orders.

## Notes and Limits

- This is a decision model, not GAAP accounting.
- If order totals are unavailable, AOV/GMV may be estimated from line items (`order_lines x sizes.retail_price`).
- Use the same exclusion logic each month (`order_type != wholesale` and `container_name not like wholesale:%`) to keep results comparable.
