<?php

namespace App\Services\Automation;

use App\Models\IntegrationConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CommerceOrderSourceService
{
    public const LIVE_PROVIDERS = ['shopify', 'square'];

    public function __construct(protected CommerceWorkflowConnectionService $connections) {}

    /** @return array<int,array<string,mixed>> */
    public function fetch(
        string $provider,
        int $tenantId,
        int $connectionId,
        CarbonImmutable $modifiedSince,
        int $pollLimit,
        int $maxOrders,
        array $locationIds = [],
    ): array {
        if (! in_array($provider, self::LIVE_PROVIDERS, true)) {
            throw new AutomationWorkflowException('This commerce source does not have a live execution driver yet.');
        }

        $connection = $this->connections->connectionForTenant($tenantId, $connectionId, $provider);

        return $provider === 'shopify'
            ? $this->fetchShopify($connection, $modifiedSince, $pollLimit, $maxOrders)
            : $this->fetchSquare($connection, $modifiedSince, $pollLimit, $maxOrders, $locationIds);
    }

    /** @return array<int,array<string,mixed>> */
    public function sample(string $provider, int $tenantId, int $connectionId, array $locationIds = []): array
    {
        return $this->fetch($provider, $tenantId, $connectionId, CarbonImmutable::now()->subDays(30), 5, 5, $locationIds);
    }

    /** @return array<int,array<string,mixed>> */
    protected function fetchShopify(IntegrationConnection $connection, CarbonImmutable $modifiedSince, int $pollLimit, int $maxOrders): array
    {
        $shop = strtolower(trim((string) (data_get($connection->metadata, 'shop_domain') ?: $connection->external_account_secret)));
        if ($shop === '') {
            throw new AutomationWorkflowException('The Shopify connection is missing its store domain. Reconnect Shopify.');
        }

        $accessToken = $this->connections->accessToken($connection);
        $version = (string) config('services.shopify.automation_api_version', '2026-07');
        $endpoint = 'https://'.$shop.'/admin/api/'.$version.'/graphql.json';
        $after = null;
        $orders = [];
        $pageSize = min(100, max(1, $pollLimit));
        $query = <<<'GRAPHQL'
query EverbranchOrders($first: Int!, $after: String, $query: String!) {
  orders(first: $first, after: $after, query: $query, sortKey: UPDATED_AT) {
    edges {
      cursor
      node {
        id
        legacyResourceId
        name
        createdAt
        updatedAt
        processedAt
        cancelledAt
        displayFinancialStatus
        displayFulfillmentStatus
        email
        phone
        note
        tags
        statusPageUrl
        currentTotalPriceSet { shopMoney { amount currencyCode } }
        customer { displayName }
        shippingAddress { name company address1 address2 city provinceCode zip countryCodeV2 phone }
        billingAddress { name company address1 address2 city provinceCode zip countryCodeV2 phone }
        lineItems(first: 50) { nodes { name quantity sku } }
        customAttributes { key value }
        fulfillments(first: 10) { createdAt estimatedDeliveryAt deliveredAt displayStatus }
      }
    }
    pageInfo { hasNextPage endCursor }
  }
}
GRAPHQL;

        do {
            $response = Http::acceptJson()->asJson()
                ->withHeaders(['X-Shopify-Access-Token' => $accessToken])
                ->timeout(25)->retry(2, 300, throw: false)
                ->post($endpoint, [
                    'query' => $query,
                    'variables' => [
                        'first' => $pageSize,
                        'after' => $after,
                        'query' => 'updated_at:>\''.$modifiedSince->utc()->toIso8601String().'\'',
                    ],
                ]);
            $payload = $this->decode($response, 'Shopify orders fetch failed.');
            $graphqlErrors = array_values(array_filter((array) ($payload['errors'] ?? []), 'is_array'));
            if ($graphqlErrors !== []) {
                throw new AutomationWorkflowException('Shopify orders fetch failed: '.Str::limit((string) data_get($graphqlErrors, '0.message', 'GraphQL request failed.'), 300));
            }

            foreach ((array) data_get($payload, 'data.orders.edges', []) as $edge) {
                if (! is_array($edge) || ! is_array($edge['node'] ?? null)) {
                    continue;
                }
                $orders[] = $this->normalizeShopify((array) $edge['node'], $shop);
                if (count($orders) >= $maxOrders) {
                    break 2;
                }
            }

            $hasNext = (bool) data_get($payload, 'data.orders.pageInfo.hasNextPage', false);
            $after = $hasNext ? trim((string) data_get($payload, 'data.orders.pageInfo.endCursor', '')) : '';
        } while ($after !== '');

        return $orders;
    }

    /** @return array<int,array<string,mixed>> */
    protected function fetchSquare(
        IntegrationConnection $connection,
        CarbonImmutable $modifiedSince,
        int $pollLimit,
        int $maxOrders,
        array $locationIds,
    ): array {
        $accessToken = $this->connections->accessToken($connection);
        $options = $this->connections->sourceOptions($connection);
        $allowedLocationIds = array_column($options, 'id');
        $locationIds = array_values(array_intersect(array_map('strval', $locationIds), $allowedLocationIds));
        if ($locationIds === []) {
            $locationIds = array_slice($allowedLocationIds, 0, 10);
        }
        if ($locationIds === []) {
            throw new AutomationWorkflowException('No active Square locations are available. Test the Square connection again.');
        }

        $locations = collect($options)->keyBy('id');
        $cursor = null;
        $orders = [];
        do {
            $body = [
                'location_ids' => array_slice($locationIds, 0, 10),
                'limit' => min(1000, max(1, $pollLimit)),
                'return_entries' => false,
                'query' => [
                    'filter' => ['date_time_filter' => ['updated_at' => ['start_at' => $modifiedSince->utc()->toIso8601String()]]],
                    'sort' => ['sort_field' => 'UPDATED_AT', 'sort_order' => 'ASC'],
                ],
            ];
            if ($cursor !== null) {
                $body['cursor'] = $cursor;
            }
            $response = Http::acceptJson()->asJson()->withToken($accessToken)
                ->withHeaders(['Square-Version' => (string) config('services.square.api_version', '2026-05-20')])
                ->timeout(25)->retry(2, 300, throw: false)
                ->post(rtrim((string) config('services.square.api_base', 'https://connect.squareup.com'), '/').'/v2/orders/search', $body);
            $payload = $this->decode($response, 'Square orders fetch failed.');
            foreach ((array) ($payload['orders'] ?? []) as $order) {
                if (! is_array($order)) {
                    continue;
                }
                $orders[] = $this->normalizeSquare($order, (array) $locations->get((string) ($order['location_id'] ?? ''), []));
                if (count($orders) >= $maxOrders) {
                    break 2;
                }
            }
            $cursor = trim((string) ($payload['cursor'] ?? '')) ?: null;
        } while ($cursor !== null);

        return $orders;
    }

    /** @return array<string,mixed> */
    protected function normalizeShopify(array $order, string $shop): array
    {
        $fulfillments = array_values(array_filter((array) ($order['fulfillments'] ?? []), 'is_array'));
        $attributes = array_values(array_filter((array) ($order['customAttributes'] ?? []), 'is_array'));
        $legacyId = trim((string) ($order['legacyResourceId'] ?? ''));
        $cancelled = filled($order['cancelledAt'] ?? null);
        $financial = Str::headline((string) ($order['displayFinancialStatus'] ?? ''));
        $fulfillment = Str::headline((string) ($order['displayFulfillmentStatus'] ?? ''));

        return [
            'source_id' => trim((string) ($order['id'] ?? $legacyId)),
            'order_number' => ltrim(trim((string) ($order['name'] ?? $legacyId)), '#'),
            'updated_at' => (string) ($order['updatedAt'] ?? ''),
            'schedule' => [
                'order_created' => $order['createdAt'] ?? null,
                'fulfillment' => data_get($fulfillments, '0.createdAt'),
                'delivery' => $this->firstDate(array_column($fulfillments, 'estimatedDeliveryAt')) ?? $this->attributeDate($attributes, ['delivery']),
                'pickup' => $this->attributeDate($attributes, ['pickup', 'collection']),
            ],
            'source' => 'Shopify',
            'customer_name' => trim((string) data_get($order, 'customer.displayName', data_get($order, 'shippingAddress.name', ''))),
            'customer_email' => trim((string) ($order['email'] ?? '')),
            'customer_phone' => trim((string) ($order['phone'] ?? data_get($order, 'shippingAddress.phone', ''))),
            'items' => $this->shopifyItems((array) data_get($order, 'lineItems.nodes', [])),
            'total' => $this->money(data_get($order, 'currentTotalPriceSet.shopMoney.amount'), data_get($order, 'currentTotalPriceSet.shopMoney.currencyCode')),
            'status' => $cancelled ? 'Cancelled' : trim(implode(' · ', array_filter([$financial, $fulfillment]))),
            'notes' => trim((string) ($order['note'] ?? '')),
            'source_url' => $legacyId !== '' ? 'https://'.$shop.'/admin/orders/'.$legacyId : (string) ($order['statusPageUrl'] ?? ''),
            'shipping_address' => $this->shopifyAddress((array) ($order['shippingAddress'] ?? [])),
            'billing_address' => $this->shopifyAddress((array) ($order['billingAddress'] ?? [])),
            'pickup_location' => '',
            'cancelled' => $cancelled,
        ];
    }

    /** @return array<string,mixed> */
    protected function normalizeSquare(array $order, array $location): array
    {
        $fulfillments = array_values(array_filter((array) ($order['fulfillments'] ?? []), 'is_array'));
        $fulfillment = $fulfillments[0] ?? [];
        $recipient = (array) (data_get($fulfillment, 'pickup_details.recipient')
            ?? data_get($fulfillment, 'delivery_details.recipient')
            ?? data_get($fulfillment, 'shipment_details.recipient')
            ?? []);
        $shippingAddress = (array) (data_get($fulfillment, 'delivery_details.recipient.address')
            ?? data_get($fulfillment, 'shipment_details.recipient.address')
            ?? []);
        $state = strtoupper(trim((string) ($order['state'] ?? '')));
        $fulfillmentState = strtoupper(trim((string) ($fulfillment['state'] ?? '')));
        $cancelled = in_array($state, ['CANCELED', 'CANCELLED'], true) || in_array($fulfillmentState, ['CANCELED', 'CANCELLED', 'FAILED'], true);

        return [
            'source_id' => trim((string) ($order['id'] ?? '')),
            'order_number' => trim((string) ($order['reference_id'] ?? $order['ticket_name'] ?? $order['id'] ?? '')),
            'updated_at' => (string) ($order['updated_at'] ?? ''),
            'schedule' => [
                'order_created' => $order['created_at'] ?? null,
                'fulfillment' => data_get($fulfillment, 'pickup_details.ready_at')
                    ?? data_get($fulfillment, 'delivery_details.ready_at')
                    ?? data_get($fulfillment, 'shipment_details.expected_shipped_at'),
                'delivery' => data_get($fulfillment, 'delivery_details.deliver_at'),
                'pickup' => data_get($fulfillment, 'pickup_details.pickup_at'),
            ],
            'source' => 'Square',
            'customer_name' => trim((string) ($recipient['display_name'] ?? '')),
            'customer_email' => trim((string) ($recipient['email_address'] ?? '')),
            'customer_phone' => trim((string) ($recipient['phone_number'] ?? '')),
            'items' => $this->squareItems((array) ($order['line_items'] ?? [])),
            'total' => $this->moneyFromMinor(data_get($order, 'total_money.amount'), data_get($order, 'total_money.currency')),
            'status' => $cancelled ? 'Cancelled' : trim(implode(' · ', array_filter([Str::headline($state), Str::headline($fulfillmentState)]))),
            'notes' => trim((string) (data_get($fulfillment, 'pickup_details.note') ?? data_get($fulfillment, 'delivery_details.note') ?? '')),
            'source_url' => '',
            'shipping_address' => $this->squareAddress($shippingAddress),
            'billing_address' => '',
            'pickup_location' => trim(implode(', ', array_filter([$location['label'] ?? null, $location['address'] ?? null]))),
            'cancelled' => $cancelled,
        ];
    }

    protected function shopifyItems(array $items): string
    {
        return collect($items)->filter(fn (mixed $item): bool => is_array($item))->map(fn (array $item): string => max(1, (int) ($item['quantity'] ?? 1)).' × '.trim((string) ($item['name'] ?? 'Item')))->implode(', ');
    }

    protected function squareItems(array $items): string
    {
        return collect($items)->filter(fn (mixed $item): bool => is_array($item))->map(fn (array $item): string => trim((string) ($item['quantity'] ?? '1')).' × '.trim((string) ($item['name'] ?? 'Item')))->implode(', ');
    }

    protected function shopifyAddress(array $address): string
    {
        return trim(implode(', ', array_filter([
            $address['name'] ?? null, $address['company'] ?? null, $address['address1'] ?? null,
            $address['address2'] ?? null, $address['city'] ?? null, $address['provinceCode'] ?? null,
            $address['zip'] ?? null, $address['countryCodeV2'] ?? null,
        ])));
    }

    protected function squareAddress(array $address): string
    {
        return trim(implode(', ', array_filter([
            $address['address_line_1'] ?? null, $address['address_line_2'] ?? null, $address['locality'] ?? null,
            $address['administrative_district_level_1'] ?? null, $address['postal_code'] ?? null, $address['country'] ?? null,
        ])));
    }

    protected function attributeDate(array $attributes, array $needles): ?string
    {
        foreach ($attributes as $attribute) {
            $key = Str::lower((string) ($attribute['key'] ?? ''));
            if (! collect($needles)->contains(fn (string $needle): bool => str_contains($key, $needle))) {
                continue;
            }
            $value = trim((string) ($attribute['value'] ?? ''));
            if ($this->date($value) !== null) {
                return $value;
            }
        }

        return null;
    }

    protected function firstDate(array $values): ?string
    {
        foreach ($values as $value) {
            if ($this->date((string) $value) !== null) {
                return (string) $value;
            }
        }

        return null;
    }

    protected function date(string $value): ?CarbonImmutable
    {
        try {
            return trim($value) !== '' ? CarbonImmutable::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function money(mixed $amount, mixed $currency): string
    {
        $amount = trim((string) $amount);

        return $amount === '' ? '' : strtoupper(trim((string) $currency)).' '.$amount;
    }

    protected function moneyFromMinor(mixed $amount, mixed $currency): string
    {
        return is_numeric($amount) ? strtoupper(trim((string) $currency)).' '.number_format(((int) $amount) / 100, 2) : '';
    }

    /** @return array<string,mixed> */
    protected function decode(Response $response, string $message): array
    {
        $payload = $response->json();
        $json = is_array($payload) ? $payload : [];
        if ($response->successful()) {
            return $json;
        }

        $apiMessage = trim((string) data_get($json, 'errors.0.detail', data_get($json, 'errors.0.message', data_get($json, 'error.message', ''))));
        throw new AutomationWorkflowException($message.' (HTTP '.$response->status().($apiMessage !== '' ? ': '.Str::limit($apiMessage, 300) : '').')');
    }
}
