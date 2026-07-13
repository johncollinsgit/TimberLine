# Customer merge rollout

Customer merge discovery is visible by default only for tenants in `CUSTOMER_MERGE_TENANT_SLUGS`. The initial tenant allowlist is `modern-forestry`. Preview and execution remain fail-closed when the verified Shopify merge scopes are missing, and execution additionally requires an authenticated tenant owner/admin.

In the Modern Forestry embedded app, the entry point is `Customers` → `Merge duplicate customers`. The operator must enter a customer name, email, phone, or Shopify customer ID before candidate discovery opens.

## Deployment order

1. Deploy the customer merge migration with the tenant allowlist restricted to `modern-forestry`. Confirm the normalized-name backfill completed and archive columns are present.
2. Release `shopify.app.toml`. Reauthorize the retail store and retain evidence that both `read_customer_merge` and `write_customer_merge` appear in the stored Shopify scopes. The API rejects preview or execution while either required scope is absent.
3. Verify the `customers/merge` subscription points to `https://app.theeverbranch.com/webhooks/shopify/customers/merge` and run `php artisan shopify:webhooks:verify --required-only`. Retain its output with the release evidence.
4. Confirm `CUSTOMER_MERGE_ENABLED=true` with `CUSTOMER_MERGE_TENANT_SLUGS=modern-forestry`. Keep execution limited to authenticated tenant owners/admins.
5. Run preview-only checks for Megan Lawther and several known duplicates before approving any operation.

## Release verification

After the Laravel deployment succeeds:

```bash
npm run shopify:app:deploy
php artisan shopify:webhooks:verify --required-only
```

Open `https://app.theeverbranch.com/shopify/reinstall/retail` and complete Shopify's scope approval for `modernforestry.myshopify.com`. Then verify the stored retail token includes both merge scopes. The Backstage action may be visible before reauthorization, but preview and execution must remain blocked until the scopes are present.

The webhook feature test must configure a shop domain, client ID, and client secret explicitly. CI intentionally leaves production Shopify credentials empty; omitting any resolver-required fixture value can produce an `Unknown shop` 404 even though the webhook route exists.

The foundation migration uses an explicit MySQL-safe foreign-key name. If an earlier attempt stopped after creating the two foundation tables but before adding profile columns, a retry removes those tables only when both are empty. It aborts for operator review instead of deleting a partial table that contains any row.

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
