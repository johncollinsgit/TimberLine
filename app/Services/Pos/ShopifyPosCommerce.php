<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\ShopifyStore;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Facades\Http;

/** Dedicated POS adapter. It never reuses native Website stock or Shopify checkout. */
final class ShopifyPosCommerce
{
    private const ORDER_FIELDS = 'id customer{id} displayFinancialStatus cancelledAt totalPriceSet{shopMoney{amount currencyCode}} totalTaxSet{shopMoney{amount currencyCode}} totalOutstandingSet{shopMoney{amount currencyCode}} tags transactions{id kind status gateway amountSet{shopMoney{amount currencyCode}}} fulfillmentOrders(first:50){pageInfo{hasNextPage} nodes{id status assignedLocation{location{id}} lineItems(first:100){pageInfo{hasNextPage} nodes{id remainingQuantity totalQuantity lineItem{variant{id}}}}}}';

    public function __construct(private PosStore $store) {}

    public function catalog(array $scope): array
    {
        $settings = $this->settings($scope);
        $ids = $settings['variant_ids'] ?? [];
        if (! is_array($ids) || ! $ids || count($ids) > 200 || count(array_unique($ids)) !== count($ids)) {
            throw new PosException('shopify_catalog_unreviewed', 503);
        }
        $data = $this->graphql($scope, 'query PosCatalog($ids:[ID!]!,$location:ID!){nodes(ids:$ids){... on ProductVariant{id title sku price updatedAt inventoryPolicy product{id title status isGiftCard} inventoryItem{tracked inventoryLevel(locationId:$location){quantities(names:["available"]){name quantity}}}}}}',
            ['ids' => $ids, 'location' => $settings['location_id']]);
        $items = [];
        foreach ($data['nodes'] ?? [] as $variant) {
            if (! $variant || ! in_array($variant['id'], $ids, true) || ($variant['product']['status'] ?? '') !== 'ACTIVE'
                || ($variant['product']['isGiftCard'] ?? true) || ! isset($variant['inventoryItem']['tracked'])) {
                continue;
            }
            $quantity = $variant['inventoryItem']['inventoryLevel']['quantities'][0]['quantity'] ?? null;
            if ($variant['inventoryItem']['tracked'] && (($variant['inventoryPolicy'] ?? '') !== 'DENY' || ! is_int($quantity) || $quantity < 1)) {
                continue;
            }
            $price = self::minor($variant['price']);
            $items[] = ['item_id' => $variant['id'], 'sku' => $variant['sku'],
                'title' => $variant['product']['title'].' / '.$variant['title'], 'kind' => 'merchandise',
                'unit_amount_minor' => $price, 'revision' => Contract::hash([$variant['id'], $variant['updatedAt'], $price, $settings['location_id']]),
                'available_quantity' => $variant['inventoryItem']['tracked'] ? $quantity : null];
        }

        return $items;
    }

    public function quote(array $scope, array $selections): array
    {
        $catalog = array_column($this->catalog($scope), null, 'item_id');
        $settings = $this->settings($scope);
        $input = ['lineItems' => [], 'shippingAddress' => $settings['tax_address'], 'acceptAutomaticDiscounts' => false];
        if (! empty($scope['customer']['shopify_customer_id'])) {
            $input['purchasingEntity'] = ['customerId' => $scope['customer']['shopify_customer_id']];
        }
        $display = [];
        foreach ($selections as $selection) {
            $item = $catalog[$selection['item_id']] ?? null;
            if (! $item || $item['revision'] !== $selection['revision']
                || ($item['available_quantity'] !== null && $item['available_quantity'] < $selection['quantity'])) {
                throw new PosException('shopify_catalog_changed');
            }
            $input['lineItems'][] = ['variantId' => $item['item_id'], 'quantity' => $selection['quantity']];
            $display[] = ['title' => $item['title'], 'sku' => $item['sku'], 'quantity' => $selection['quantity'],
                'subtotal_minor' => $item['unit_amount_minor'] * $selection['quantity']];
        }
        $result = $this->graphql($scope, 'mutation PosCalculate($input:DraftOrderInput!){draftOrderCalculate(input:$input){calculatedDraftOrder{taxesIncluded totalPriceSet{shopMoney{amount currencyCode}} totalTaxSet{shopMoney{amount currencyCode}} taxLines{title rate priceSet{shopMoney{amount currencyCode}}} lineItems{variant{id} quantity originalUnitPriceSet{shopMoney{amount currencyCode}}}} userErrors{field message}}}', ['input' => $input]);
        $value = $this->result($result, 'draftOrderCalculate')['calculatedDraftOrder'] ?? null;
        if (! $value || ($value['taxesIncluded'] ?? true) !== false || ($value['totalPriceSet']['shopMoney']['currencyCode'] ?? '') !== 'USD') {
            throw new PosException('shopify_tax_unavailable', 503);
        }
        $total = self::minor($value['totalPriceSet']['shopMoney']['amount']);
        $tax = self::minor($value['totalTaxSet']['shopMoney']['amount']);
        $orderLines = [];
        foreach ($value['lineItems'] as $line) {
            $id = $line['variant']['id'] ?? '';
            if (! isset($catalog[$id])) {
                throw new PosException('shopify_quote_mismatch');
            }
            $orderLines[] = ['variantId' => $id, 'quantity' => $line['quantity'], 'priceSet' => ['shopMoney' => $line['originalUnitPriceSet']['shopMoney']]];
        }
        $expected = array_column($selections, 'quantity', 'item_id');
        $actual = array_column($orderLines, 'quantity', 'variantId');
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual || $total <= $tax) {
            throw new PosException('shopify_quote_mismatch');
        }

        return ['source' => 'shopify', 'selections' => $selections, 'shop_id' => $settings['shop_id'], 'shop_domain' => $settings['shop_domain'],
            'shopify_location_id' => $settings['location_id'], 'order_lines' => $orderLines, 'tax_lines' => $value['taxLines'],
            'total_minor' => $total, 'tax_minor' => $tax, 'tax_address' => $settings['tax_address'],
            'lines' => [['line_id' => 'shopify_basket', 'kind' => 'merchandise', 'quantity' => 1,
                'unit_amount_minor' => $total - $tax, 'tax_amount_minor' => $tax]], 'stock' => [], 'display' => $display];
    }

    public function prepare(array $scope, object $sale, array $snapshot): void
    {
        $scope['customer'] = $snapshot['customer'] ?? null;
        $reference = $this->reference($sale);
        $tag = 'EverbranchPOS-'.$sale->id;
        $order = null;
        if (isset($reference['order_id'])) {
            $order = $this->order($scope, $reference['order_id']);
        } elseif ($reference) {
            $data = $this->graphql($scope, 'query PosRecover($q:String!){orders(first:2,query:$q){nodes{'.self::ORDER_FIELDS.'}}}', ['q' => 'tag:'.$tag]);
            $matches = $data['orders']['nodes'] ?? [];
            if (count($matches) !== 1) {
                throw new PosException('shopify_creation_unconfirmed', 503);
            }
            $order = $matches[0];
        } else {
            $terms = $snapshot['terms'];
            $fresh = $this->quote($scope, $terms['selections']);
            if ($fresh['total_minor'] !== $terms['total_minor'] || $fresh['tax_minor'] !== $terms['tax_minor']) {
                throw new PosException('shopify_price_or_tax_changed');
            }
            // Commit create intent BEFORE HTTP. A lost response cannot cause a replacement.
            $claimed = $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->whereNull('source_reference')
                ->update(['source_reference' => $this->store->encrypt(['create_claimed' => true, 'tag' => $tag])]);
            if ($claimed !== 1) {
                throw new PosException('shopify_creation_in_progress');
            }
            $taxLines = array_map(fn ($line) => ['title' => $line['title'], 'rate' => (string) $line['rate'],
                'priceSet' => ['shopMoney' => $line['priceSet']['shopMoney']]], $terms['tax_lines']);
            $payload = ['currency' => 'USD', 'financialStatus' => 'PENDING', 'lineItems' => $terms['order_lines'],
                'taxLines' => $taxLines, 'taxesIncluded' => false, 'shippingAddress' => $terms['tax_address'],
                'sourceIdentifier' => $sale->id, 'sourceName' => 'everbranch_pos', 'tags' => [$tag],
                'note' => 'Counter collection pending. Do not fulfill or collect separately.'];
            if (! empty($snapshot['customer']['shopify_customer_id'])) {
                $payload['customer'] = ['toAssociate' => ['id' => $snapshot['customer']['shopify_customer_id']]];
            }
            $data = $this->graphql($scope, 'mutation PosOrder($order:OrderCreateOrderInput!,$options:OrderCreateOptionsInput){orderCreate(order:$order,options:$options){order{'.self::ORDER_FIELDS.'} userErrors{field message}}}',
                ['order' => $payload, 'options' => ['inventoryBehaviour' => 'DECREMENT_OBEYING_POLICY', 'sendReceipt' => false, 'sendFulfillmentReceipt' => false]]);
            $order = $this->result($data, 'orderCreate')['order'] ?? null;
        }
        if (! $order) {
            throw new PosException('shopify_order_unconfirmed', 503);
        }
        $reference = [...$reference, 'order_id' => $order['id'], 'tag' => $tag,
            'initial_transaction_ids' => $reference['initial_transaction_ids'] ?? array_column($order['transactions'] ?? [], 'id')];
        if (! preg_match('~^gid://shopify/Order/([0-9]+)$~D', $order['id'], $identity)) {
            throw new PosException('shopify_order_identity_invalid');
        }
        $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->update(['source_reference' => $this->store->encrypt($reference),
            'commerce_order_id' => $identity[1], 'commerce_store_id' => $snapshot['configuration']['shopify']['store_id']]);
        $this->verifyOrder($scope, $sale, $snapshot, $order);
        if ($order['displayFinancialStatus'] !== 'PENDING' || self::minor($order['totalOutstandingSet']['shopMoney']['amount']) !== (int) $sale->amount_minor) {
            throw new PosException('shopify_order_already_collected');
        }
        foreach ($order['fulfillmentOrders']['nodes'] as $fulfillment) {
            if ($fulfillment['status'] !== 'OPEN') {
                throw new PosException('shopify_fulfillment_already_started');
            }
        }
        $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->update(['stock_state' => 'held']);
    }

    public function finalize(array $scope, object $sale, array $snapshot, array $operation): void
    {
        $reference = $this->reference($sale);
        $order = $this->order($scope, $reference['order_id']);
        $this->verifyOrder($scope, $sale, $snapshot, $order);
        if ($order['displayFinancialStatus'] === 'PENDING') {
            $this->claimPhase($scope, $sale, 'paid');
            $data = $this->graphql($scope, 'mutation PosPaid($input:OrderMarkAsPaidInput!){orderMarkAsPaid(input:$input){order{id} userErrors{field message}}}',
                ['input' => ['id' => $order['id']]]);
            $this->result($data, 'orderMarkAsPaid');
            $order = $this->order($scope, $order['id']);
        }
        $newTransactions = array_values(array_filter($order['transactions'] ?? [], fn ($transaction) => ! in_array($transaction['id'], $reference['initial_transaction_ids'], true) && $transaction['status'] === 'SUCCESS'
            && $transaction['kind'] === 'SALE' && $transaction['gateway'] === 'manual'
            && self::minor($transaction['amountSet']['shopMoney']['amount']) === (int) $sale->amount_minor));
        if ($order['displayFinancialStatus'] !== 'PAID' || count($newTransactions) !== 1) {
            throw new PosException('shopify_external_payment_review');
        }
        $reference = $this->reference($this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->firstOrFail());
        $reference['manual_transaction_id'] = $newTransactions[0]['id'];
        $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->update(['source_reference' => $this->store->encrypt($reference)]);
        $open = array_values(array_filter($order['fulfillmentOrders']['nodes'], fn ($f) => $f['status'] === 'OPEN'));
        if ($open) {
            $this->claimPhase($scope, $sale, 'fulfilled');
            $data = $this->graphql($scope, 'mutation PosFulfill($fulfillment:FulfillmentInput!){fulfillmentCreate(fulfillment:$fulfillment){fulfillment{id status} userErrors{field message}}}',
                ['fulfillment' => ['notifyCustomer' => false, 'lineItemsByFulfillmentOrder' => array_map(fn ($f) => ['fulfillmentOrderId' => $f['id']], $open)]]);
            $this->result($data, 'fulfillmentCreate');
        }
        $confirmed = $this->order($scope, $order['id']);
        $this->verifyOrder($scope, $sale, $snapshot, $confirmed);
        foreach ($confirmed['fulfillmentOrders']['nodes'] as $fulfillment) {
            if ($fulfillment['status'] !== 'CLOSED') {
                throw new PosException('shopify_fulfillment_unconfirmed', 503);
            }
        }
        $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->update(['stock_state' => 'committed']);
    }

    public function release(array $scope, object $sale): void
    {
        if (! in_array($sale->state, ['failed', 'cancelled'], true)) {
            throw new PosException('unpaid_confirmation_required');
        }
        $reference = $this->reference($sale);
        $order = $this->order($scope, $reference['order_id']);
        $this->cancelPendingOrder($scope, $sale, $order);
    }

    /** Operational closure only, after a separate device/provider review; never payment evidence. */
    public function reviewUncollected(array $scope, object $sale, array $snapshot, bool $cancel = false): array
    {
        if ($snapshot['terms']['source'] !== 'shopify' || $sale->state !== 'unknown') {
            throw new PosException('legacy_uncollected_review_required');
        }
        $reference = $this->reference($sale);
        if (empty($reference['order_id'])) {
            throw new PosException('shopify_original_order_required');
        }
        $order = $this->order($scope, $reference['order_id']);
        if (! in_array('EverbranchPOS-'.$sale->id, $order['tags'] ?? [], true) || ! empty($order['transactions'])) {
            throw new PosException('shopify_cancel_payment_review');
        }
        if (! $order['cancelledAt']) {
            $this->verifyOrder($scope, $sale, $snapshot, $order);
            if ($order['displayFinancialStatus'] !== 'PENDING' || ($order['totalOutstandingSet']['shopMoney']['currencyCode'] ?? '') !== 'USD'
                || self::minor($order['totalOutstandingSet']['shopMoney']['amount'] ?? '0') !== (int) $sale->amount_minor) {
                throw new PosException('shopify_cancel_payment_review');
            }
            foreach ($order['fulfillmentOrders']['nodes'] as $fulfillment) {
                if (! in_array($fulfillment['status'], ['OPEN', 'SCHEDULED'], true)) {
                    throw new PosException('shopify_fulfillment_review');
                }
                foreach ($fulfillment['lineItems']['nodes'] as $line) {
                    if ($line['remainingQuantity'] !== $line['totalQuantity']) {
                        throw new PosException('shopify_fulfillment_review');
                    }
                }
            }
        }
        if ($cancel) {
            $this->cancelPendingOrder($scope, $sale, $order);
        }

        return ['order_id' => $order['id'], 'already_cancelled' => (bool) $order['cancelledAt'], 'unpaid_order_reviewed' => true];
    }

    private function cancelPendingOrder(array $scope, object $sale, array $order): void
    {
        if (! $order['cancelledAt']) {
            if ($order['displayFinancialStatus'] !== 'PENDING') {
                throw new PosException('shopify_cancel_payment_review');
            }
            $this->claimPhase($scope, $sale, 'cancelled');
            $data = $this->graphql($scope, 'mutation PosCancel($id:ID!){orderCancel(orderId:$id,notifyCustomer:false,reason:OTHER,refund:false,restock:true){job{id done} userErrors{field message}}}', ['id' => $order['id']]);
            $this->result($data, 'orderCancel');
            if (! $this->order($scope, $order['id'])['cancelledAt']) {
                throw new PosException('shopify_cancellation_pending', 503);
            }
        }
        $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->update(['stock_state' => 'released', 'active_source_claim' => null]);
    }

    public function refund(array $scope, object $sale, array $refund): void
    {
        $reference = $this->reference($sale);
        $note = 'EverbranchPOS refund '.$refund['id'];
        $data = $this->graphql($scope, 'query PosRefunds($id:ID!){order(id:$id){refunds(first:100){id note transactions(first:100){nodes{kind status gateway parentTransaction{id} amountSet{shopMoney{amount currencyCode}}}}}}}', ['id' => $reference['order_id']]);
        foreach ($data['order']['refunds'] ?? [] as $existing) {
            if (($existing['note'] ?? '') === $note) {
                $transactions = $existing['transactions']['nodes'] ?? [];
                if (count($transactions) !== 1 || ($transactions[0]['parentTransaction']['id'] ?? null) !== ($reference['manual_transaction_id'] ?? null)
                    || ($transactions[0]['gateway'] ?? '') !== 'manual' || ($transactions[0]['kind'] ?? '') !== 'REFUND'
                    || ($transactions[0]['status'] ?? '') !== 'SUCCESS' || ($transactions[0]['amountSet']['shopMoney']['currencyCode'] ?? '') !== 'USD'
                    || self::minor($transactions[0]['amountSet']['shopMoney']['amount'] ?? '0') !== $refund['amount_minor']) {
                    throw new PosException('shopify_refund_mismatch');
                }

                return;
            }
        }
        if (count($data['order']['refunds'] ?? []) >= 100) {
            throw new PosException('shopify_refund_search_incomplete');
        }
        if (! isset($reference['manual_transaction_id'])) {
            throw new PosException('shopify_original_payment_unconfirmed');
        }
        $this->claimPhase($scope, $sale, 'refund_'.$refund['id']);
        // The parent is the verified manual bookkeeping transaction. Square has
        // already refunded the money; Shopify must never contact a card gateway.
        $data = $this->graphql($scope, 'mutation PosRefund($input:RefundInput!){refundCreate(input:$input){refund{id} userErrors{field message}}}', ['input' => [
            'orderId' => $reference['order_id'], 'note' => $note, 'notify' => false,
            'transactions' => [['orderId' => $reference['order_id'], 'parentId' => $reference['manual_transaction_id'],
                'amount' => self::decimal($refund['amount_minor']), 'kind' => 'REFUND', 'gateway' => 'manual']],
        ]]);
        $this->result($data, 'refundCreate');
    }

    private function claimPhase(array $scope, object $sale, string $phase): void
    {
        $this->store->db->transaction(function () use ($scope, $sale, $phase): void {
            $row = $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            $value = $this->reference($row);
            if (isset($value['claimed'][$phase])) {
                throw new PosException('shopify_'.$phase.'_unconfirmed', 503);
            }
            $value['claimed'][$phase] = true;
            $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->update(['source_reference' => $this->store->encrypt($value)]);
        });
    }

    private function verifyOrder(array $scope, object $sale, array $snapshot, array $order): void
    {
        if ($order['cancelledAt'] || ! in_array('EverbranchPOS-'.$sale->id, $order['tags'], true)
            || ($order['totalPriceSet']['shopMoney']['currencyCode'] ?? '') !== 'USD'
            || self::minor($order['totalPriceSet']['shopMoney']['amount']) !== (int) $sale->amount_minor
            || ($order['totalTaxSet']['shopMoney']['currencyCode'] ?? '') !== 'USD'
            || self::minor($order['totalTaxSet']['shopMoney']['amount'] ?? '0') !== $snapshot['terms']['tax_minor']
            || ($order['fulfillmentOrders']['pageInfo']['hasNextPage'] ?? true)) {
            throw new PosException('shopify_order_mismatch');
        }
        if (isset($snapshot['customer']['id']) && ($order['customer']['id'] ?? null) !== ($snapshot['customer']['shopify_customer_id'] ?? null)) {
            throw new PosException('shopify_customer_mismatch');
        }
        $actual = [];
        foreach ($order['fulfillmentOrders']['nodes'] as $fulfillment) {
            if (($fulfillment['assignedLocation']['location']['id'] ?? '') !== $snapshot['terms']['shopify_location_id']
                || ($fulfillment['lineItems']['pageInfo']['hasNextPage'] ?? true)) {
                throw new PosException('shopify_location_mismatch');
            }
            foreach ($fulfillment['lineItems']['nodes'] as $line) {
                $id = $line['lineItem']['variant']['id'] ?? '';
                $actual[$id] = ($actual[$id] ?? 0) + $line['totalQuantity'];
            }
        }
        $expected = array_column($snapshot['terms']['order_lines'], 'quantity', 'variantId');
        ksort($actual);
        ksort($expected);
        if ($actual !== $expected) {
            throw new PosException('shopify_inventory_commitment_mismatch');
        }
    }

    private function order(array $scope, string $id): array
    {
        return $this->graphql($scope, 'query PosOrderStatus($id:ID!){order(id:$id){'.self::ORDER_FIELDS.'}}', ['id' => $id])['order']
            ?? throw new PosException('shopify_order_unavailable', 503);
    }

    private function reference(object $sale): array
    {
        return $sale->source_reference ? $this->store->decrypt($sale->source_reference) : [];
    }

    private function settings(array $scope): array
    {
        $value = $this->store->decrypt($scope['register']->configuration)['shopify'] ?? [];
        if (! isset($value['shop_id'], $value['shop_domain'], $value['location_id'], $value['store_id'], $value['tax_address'])
            || ! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $value['shop_domain'])
            || ! ShopifyStore::forTenantId($scope['tenant_id'])->whereKey($value['store_id'])->where('shop_domain', $value['shop_domain'])->exists()) {
            throw new PosException('shopify_binding_unverified', 503);
        }

        return $value;
    }

    private function graphql(array $scope, string $query, array $variables): array
    {
        $this->store->outsideTransaction();
        if (str_starts_with($query, 'mutation')) {
            $this->graphql($scope, 'query PosShop{shop{id currencyCode}}', []);
        } else {
            $query = preg_replace('/\{/', '{shop{id currencyCode} ', $query, 1);
        }
        $settings = $this->settings($scope);
        $token = ShopifyStore::forTenantId($scope['tenant_id'])->whereKey($settings['store_id'])->firstOrFail()->access_token;
        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $token])->acceptJson()->connectTimeout(5)->timeout(20)
                ->post('https://'.$settings['shop_domain'].'/admin/api/2026-10/graphql.json',
                    ['query' => $query, ...($variables ? ['variables' => $variables] : [])]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            throw new PosException('shopify_operation_unconfirmed', 503);
        }
        if (collect($response->json('errors') ?? [])->contains(fn ($error) => data_get($error, 'extensions.code') === 'ACCESS_DENIED')) {
            throw new PosException('shopify_permissions_required', 503);
        }
        if (! $response->successful() || $response->json('errors') || ! is_array($response->json('data'))) {
            throw new PosException('shopify_operation_unconfirmed', 503);
        }

        if (! str_starts_with($query, 'mutation') && ($response->json('data.shop.id') !== $settings['shop_id'] || $response->json('data.shop.currencyCode') !== 'USD')) {
            throw new PosException('shopify_shop_identity_mismatch');
        }

        return $response->json('data');
    }

    private function result(array $data, string $key): array
    {
        if (! isset($data[$key]) || ! empty($data[$key]['userErrors'])) {
            throw new PosException('shopify_'.$key.'_rejected');
        }

        return $data[$key];
    }

    public static function minor(string $value): int
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D', $value, $matches)) {
            throw new PosException('money_invalid');
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    public static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
