# Customer merge rollout

Customer merge execution is fail-closed behind `CUSTOMER_MERGE_ENABLED` and `CUSTOMER_MERGE_TENANT_SLUGS`. The initial tenant allowlist is `modern-forestry`.

## Deployment order

1. Deploy the customer merge migration with execution disabled. Confirm the normalized-name backfill completed and archive columns are present.
2. Release `shopify.app.toml`. Reauthorize the retail store and retain evidence that both `read_customer_merge` and `write_customer_merge` appear in the stored Shopify scopes. The API rejects preview or execution while either required scope is absent.
3. Verify the `customers/merge` subscription points to `https://app.theeverbranch.com/webhooks/shopify/customers/merge` and run `php artisan shopify:webhooks:verify --required-only`. Retain its output with the release evidence.
4. Set `CUSTOMER_MERGE_ENABLED=true` with `CUSTOMER_MERGE_TENANT_SLUGS=modern-forestry`. Keep execution limited to authenticated tenant owners/admins.
5. Run preview-only checks for Megan Lawther and several known duplicates before approving any operation.

## Megan acceptance evidence

The preview must show four Everbranch profiles collapsed to two distinct Shopify customer IDs, 98 orders, and a unique-ledger Candle Cash result of exactly 332. Confirm the Shopify preview has no blockers and explicitly record Shopify's resulting customer ID and consent result. After approval, verify:

- one visible Everbranch survivor;
- three archived aliases that redirect to the survivor;
- all 98 orders use Shopify's resulting customer ID;
- the surviving Candle Cash ledger recomputes to exactly 332;
- replaying the operation or webhook does not change the result.

Do not approve an ambiguous opening balance until it is marked either proven distinct or a duplicate import. Do not use an Everbranch-only consolidation when two live Shopify IDs exist and Shopify rejects their merge.

## Monitoring and repair

Monitor `customer_merge_operations` for `blocked`, `partial_failure`, and long-running `processing` statuses; inspect `errors`, `shopify_preview`, and `after_state.conflict_resolutions`. Webhook lag can be measured from operation timestamps. A partial pairwise operation must be resumed from its recorded sequence; it must never be represented as atomic or automatically rolled back in Shopify.
