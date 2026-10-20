<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Connection;
use App\Models\Marketplace\Operation;
use App\Models\Marketplace\OrderLine;

class MarketplaceFulfillment
{
    public function __construct(private MarketplaceShopify $shopify, private MarketplaceOrders $orders) {}

    public function pickupCode(OrderLine $line): string
    {
        return strtoupper(substr(hash_hmac('sha256', 'marketplace-pickup:'.$line->id, config('app.key')), 0, 8));
    }

    public function fulfill(OrderLine $line, string $action, array $data, string $uuid): void
    {
        if ($line->order->provider === 'stripe') {
            app(MarketplaceNativeCheckout::class)->fulfill($line, $action, $data, $uuid);

            return;
        }
        $order = $line->order;
        abort_unless(! $order->review_required && $line->quantity > $line->refunded_quantity && $line->fulfillment_status !== 'fulfilled', 422, 'This order needs review or has already been fulfilled.');
        $pickup = $order->group_key !== 'shipping';
        abort_unless(in_array($action, $pickup ? ['ready', 'collected'] : ['shipped'], true), 422);
        if ($action === 'collected') {
            abort_unless($line->fulfillment_status === 'ready' && hash_equals($this->pickupCode($line), strtoupper((string) ($data['pickup_code'] ?? ''))), 422, 'Enter the pickup code shown on the customer’s order.');
        }
        if (! $pickup) {
            abort_unless(! empty($data['tracking_number']), 422, 'Add the shipment tracking number.');
        }
        $connection = Connection::query()->findOrFail($order->connection_id);
        $provider = $this->orders->read($connection, $order->provider_order_gid);
        $fulfillments = [];
        foreach ($provider['fulfillmentOrders']['nodes'] as $fo) {
            $items = [];
            foreach ($fo['lineItems']['nodes'] as $item) {
                if ($item['lineItem']['id'] === $line->provider_line_gid && $item['remainingQuantity'] > 0) {
                    $items[] = ['id' => $item['id'], 'quantity' => $item['remainingQuantity']];
                }
            }
            if ($items) {
                $fulfillments[] = ['fulfillmentOrderId' => $fo['id'], 'fulfillmentOrderLineItems' => $items];
            }
        }
        abort_unless($fulfillments !== [], 422, 'Shopify has no remaining fulfillment for this item. Reconcile the order first.');
        if ($action === 'ready') {
            $this->shopify->operation($connection, $uuid, 'pickup_ready', $line->id, ['fulfillments' => $fulfillments], fn () => $this->shopify->graph($connection, 'mutation MarketplacePickup($input: FulfillmentOrderLineItemsPreparedForPickupInput!) { fulfillmentOrderLineItemsPreparedForPickup(input: $input) { userErrors { field message } } }', ['input' => ['lineItemsByFulfillmentOrder' => $fulfillments]]));
            $line->forceFill(['fulfillment_status' => 'ready'])->save();

            return;
        }
        $input = ['notifyCustomer' => true, 'lineItemsByFulfillmentOrder' => $fulfillments];
        if (! $pickup) {
            $input['trackingInfo'] = array_filter(['number' => $data['tracking_number'], 'url' => $data['tracking_url'] ?? null]);
        }
        $this->shopify->operation($connection, $uuid, 'fulfill', $line->id, ['input' => $input], fn () => $this->shopify->graph($connection, 'mutation MarketplaceFulfill($input: FulfillmentInput!) { fulfillmentCreate(fulfillment: $input) { fulfillment { id status } userErrors { field message } } }', ['input' => $input]));
        $this->orders->reconcile($connection, $order->provider_order_gid);
    }

    public function refundPreview(OrderLine $line, int $quantity, int $shipping): array
    {
        if ($line->order->provider === 'stripe') {
            return app(MarketplaceNativeCheckout::class)->refundPreview($line, $quantity, $shipping);
        }
        abort_unless($quantity > 0 && $quantity <= $line->quantity - $line->refunded_quantity && $shipping >= 0 && $shipping <= $line->order->shipping_cents, 422);
        $connection = Connection::query()->findOrFail($line->order->connection_id);
        $input = [['lineItemId' => $line->provider_line_gid, 'quantity' => $quantity, 'restockType' => 'NO_RESTOCK']];
        $result = $this->shopify->graph($connection, 'query MarketplaceRefundPreview($id: ID!, $lines: [RefundLineItemInput!], $shipping: Money) { order(id: $id) { suggestedRefund(refundLineItems: $lines, shippingAmount: $shipping) { amountSet { shopMoney { amount currencyCode } } suggestedTransactions { gateway parentTransaction { id } amountSet { shopMoney { amount } } } } } }', ['id' => $line->order->provider_order_gid, 'lines' => $input, 'shipping' => MarketplaceShopify::decimal($shipping)]);
        $suggested = $result['order']['suggestedRefund'] ?? null;
        abort_unless($suggested && $suggested['amountSet']['shopMoney']['currencyCode'] === 'USD', 422);

        return ['line_id' => $line->id, 'quantity' => $quantity, 'shipping_cents' => $shipping, 'amount_cents' => MarketplaceShopify::cents($suggested['amountSet']['shopMoney']['amount']), 'suggested' => $suggested, 'lines' => $input];
    }

    public function refund(OrderLine $line, int $quantity, int $shipping, int $expected, string $uuid, int $actor): void
    {
        if ($line->order->provider === 'stripe') {
            app(MarketplaceNativeCheckout::class)->refund($line, $quantity, $shipping, $expected, $uuid, $actor);

            return;
        }
        abort_if(Operation::query()->where('subject_id', $line->id)->where('kind', 'refund')->whereIn('status', ['sending', 'unknown'])->exists(), 409, 'An earlier refund needs reconciliation.');
        $preview = $this->refundPreview($line, $quantity, $shipping);
        abort_unless($expected === $preview['amount_cents'] && $expected > 0, 409, 'The refund amount changed. Review the updated provider preview.');
        $connection = Connection::query()->findOrFail($line->order->connection_id);
        $transactions = [];
        foreach ($preview['suggested']['suggestedTransactions'] as $tx) {
            abort_unless(! empty($tx['parentTransaction']['id']), 422, 'No original payment transaction is available.');
            $transactions[] = ['orderId' => $line->order->provider_order_gid, 'parentId' => $tx['parentTransaction']['id'], 'gateway' => $tx['gateway'], 'kind' => 'REFUND', 'amount' => $tx['amountSet']['shopMoney']['amount']];
        }
        abort_unless($transactions !== [], 422);
        $input = ['orderId' => $line->order->provider_order_gid, 'currency' => 'USD', 'notify' => true, 'note' => 'Markets and Makers refund '.$uuid, 'refundLineItems' => $preview['lines'], 'transactions' => $transactions, 'shipping' => ['amount' => MarketplaceShopify::decimal($shipping)]];
        $this->shopify->operation($connection, $uuid, 'refund', $line->id, ['input' => $input, 'expected_cents' => $expected], fn () => $this->shopify->graph($connection, 'mutation MarketplaceRefund($input: RefundInput!) { refundCreate(input: $input) { refund { id } userErrors { field message } } }', ['input' => $input]), $actor);
        $this->orders->reconcile($connection, $line->order->provider_order_gid);
    }
}
