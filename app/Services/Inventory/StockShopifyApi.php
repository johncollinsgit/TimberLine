<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ShopifyStore;
use Illuminate\Support\Facades\Http;

/** Catalog, calculation and stock publication only. This adapter cannot create orders. */
final class StockShopifyApi
{
    public const CATALOG = 'query StockCatalog($cursor:String,$location:ID!){shop{id currencyCode} productVariants(first:100,after:$cursor){pageInfo{hasNextPage endCursor} nodes{id title sku price updatedAt taxable inventoryPolicy requiresComponents product{id title status isGiftCard featuredMedia{preview{image{url}}}} inventoryItem{id tracked inventoryLevel(locationId:$location){quantities(names:["available","committed"]){name quantity}}}}}}';

    public const CALCULATE = 'mutation StockCalculate($input:DraftOrderInput!){draftOrderCalculate(input:$input){calculatedDraftOrder{taxesIncluded totalPriceSet{shopMoney{amount currencyCode}} totalTaxSet{shopMoney{amount currencyCode}} taxLines{title rate priceSet{shopMoney{amount currencyCode}}} lineItems{variant{id} quantity originalUnitPriceSet{shopMoney{amount currencyCode}}}} userErrors{field message}}}';

    public const PUBLISH = 'mutation StockPublish($input:InventorySetQuantitiesInput!,$key:String!){inventorySetQuantities(input:$input) @idempotent(key:$key){inventoryAdjustmentGroup{referenceDocumentUri changes{name delta}} userErrors{field message code}}}';

    public const LEVEL = 'query StockLevel($item:ID!,$location:ID!){inventoryItem(id:$item){id tracked inventoryLevel(locationId:$location){quantities(names:["available","committed"]){name quantity}}}}';

    public function __construct(private StockLedger $stock) {}

    /** Fresh stock-only evidence for a reviewed opening order; never imports a commerce order. */
    public function order(int $tenant, int $storeId, string $id): array
    {
        $store = ShopifyStore::forTenantId($tenant)->find($storeId);
        if ($this->stock->db->transactionLevel() !== 0 || ! ctype_digit($id) || ! $store
            || ! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $store->shop_domain)) {
            throw new StockException('stock_store_unavailable');
        }
        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $store->access_token])->acceptJson()->connectTimeout(5)->timeout(20)
                ->get('https://'.$store->shop_domain.'/admin/api/2026-10/orders/'.$id.'.json',
                    ['fields' => 'id,created_at,updated_at,source_name,cancelled_at,line_items,fulfillments,refunds,fulfillment_status']);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new StockException('stock_provider_unconfirmed', 503);
        }
        $order = $response->json('order');
        if (! $response->successful() || ! is_array($order) || (string) ($order['id'] ?? '') !== $id) {
            throw new StockException('stock_order_unconfirmed');
        }

        return $order;
    }

    public function request(int $tenant, int $storeId, string $query, array $variables): array
    {
        if ($this->stock->db->transactionLevel() !== 0) {
            throw new StockException('stock_network_in_transaction');
        }
        $store = ShopifyStore::forTenantId($tenant)->find($storeId);
        if (! $store || ! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $store->shop_domain) || ! $store->access_token) {
            throw new StockException('stock_store_unavailable', 503);
        }
        if (! in_array($query, [self::CATALOG, self::CALCULATE, self::PUBLISH, self::LEVEL], true)) {
            throw new StockException('stock_operation_not_allowed');
        }
        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $store->access_token])->acceptJson()->connectTimeout(5)->timeout(20)
                ->post('https://'.$store->shop_domain.'/admin/api/2026-10/graphql.json', ['query' => $query, ...($variables ? ['variables' => $variables] : [])]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new StockException('stock_provider_unconfirmed', 503);
        }
        if (! $response->successful() || $response->json('errors') || ! is_array($response->json('data'))) {
            throw new StockException('stock_provider_unconfirmed', 503);
        }

        return $response->json('data');
    }
}
