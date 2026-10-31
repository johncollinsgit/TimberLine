# Finished-goods stock and Everbranch Pay

The finished-goods lane is additive to materials inventory and existing commerce
adapters. It tracks sellable units, not oil/wax grams, production demand, pouring
completion or historical show forecasts. See the [operating procedure](../operations/everbranch-pay-owned-inventory.md).

`StockLedger` serializes mutations using a tenant mutex and immutable operation
input hashes. Request retries return the original result; conflicting retries
fail. Every balance movement retains a before/after audit. Real MySQL contention
must prove one winner for the last unit and reject a stale physical count. Mutex
creation does not perform duplicate insert/lock upgrades inside each operation.

`commerce_stock_items` defines canonical tenant item identities. Provider mappings
are exact variant/inventory-item/store/location identities, never guesses by SKU
or name. Store-specific prices/revisions prevent retail/wholesale price bleed.
`StockCatalogSync` fences paginated imports by generation; catalog imports never
copy physical quantities. Missing stock stays visible and unavailable.

Pools represent warehouse/shared or a specific show. Registers and Shopify stores
have explicit channel bindings. Balances track on-hand, reserved, version and
physical-count evidence. Reservations hold stock at quote time; collection locks
keep accepted online orders from preempting issued financial attempts. An accepted
online order can retire an unissued quote under the same mutex, requiring a fresh
basket. Financial holds never expire blindly. Paid settlement consumes only the
original reservation even after a product is archived; canonical failure releases
it. Refund and merchandise-return operations are separate.

`StockEvents` records reviewed external event references and a stable Everbranch
event. Asana→Everbranch Teams migration can add a reference without moving stock.
`StockEventProduction` reviews ownership and exact expanded production order
lines, then receives explicitly confirmed new ready units. A bridge for older
null-tenant internal plans preserves original records and prevents cross-tenant
adoption. Counts and physical transfers establish current or moved goods;
planned quantities alone never add to stock.

Reviewed mix-and-match bundle definitions contain the unit count and eligible
canonical components. Quote selections include each exact component/revision.
The server aggregates components across bundles and ordinary basket lines before
reserving, preventing overlap overselling. The parent catalog price is charged
once. An unreviewed bundle has no sellable shortcut or independent parent count.
Native Pay composition does not establish Shopify's online bundle stock contract.

New POS sales freeze `everbranch_inventory_v1`. `StockPosCommerce` has no Shopify
order mutation. Shopify calculates current price/tax at basket review only.
`/collect` persists original financial intent before native collection; there is
no inventory/order network round-trip in that handoff. Square callbacks are hints;
fresh original-merchant retrieval remains financial authority. Confirmed receipts
remain available while nonfinancial finalization is pending.

`StockOnlineOpening` requires owner-reviewed physical versions, fresh exact
mappings and complete matching provider commitments. It seeds old order counters
without deducting past shipments twice. `StockOnlineOrders` reserves unshipped
units, consumes dispatched units and adds explicit merchandise returns. It never
copies an online order into the in-person lane. Financial refunds without restock
proof cannot increase availability. Older, conflicting, unmapped or pre-count
movements require review. The encrypted durable inbox blocks publication until
resolved. Shared native Website stock remains blocked pending its own adapter;
Website invoices/subscriptions retain their original domain behavior.

`StockShopifyPublisher` projects available units only from reviewed channel
bindings and acknowledged quantities using Shopify 2026-10 compare-and-swap and
persisted idempotency keys. Unsent snapshots coalesce; unknown sent mutations keep
their original key. Missing commitments cannot be overwritten by an increase.
Online deltas arriving during an unknown mutation remain separately tracked.
Global publication is off by default. Scheduler recovery drains bounded inbox and
projection batches; inventory delays never authorize another charge.

`PosReceiptCustomers` holds encrypted receipt contact and audited canonical
customer association separately from the financial snapshot. Association and
refund effects use the existing rewards ledger, frozen program terms and original
time. Ambiguous historical eligibility requires explicit owner evidence. Provider
card names may be confirmed for receipt contact, never used for automatic identity
merging or marketing profiling. Existing unified customer projections connect
explicitly linked purchases without duplicating provider/mirror revenue.

The additive schema retains stock/payment/customer evidence on rollback and
repairs/verifies concise named indexes after durable partial DDL. Existing legacy
attempts remain bound to their original adapter. Reviewed closure of an uncollected
legacy attempt retires its register and releases only verified unpaid commercial
holds; it deliberately retains the unknown financial record and late recovery.
