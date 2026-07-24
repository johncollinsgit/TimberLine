<?php

namespace App\Services\Automation\V2\Providers;

use App\Models\IntegrationConnection;
use App\Services\Automation\AutomationWorkflowException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Read-only order polling extracted from the proven commerce-calendar branch.
 * It intentionally supports only the providers in the executable v2 catalog.
 */
class CommerceOrderSourceClient
{
    public function __construct(
        protected TenantIntegrationConnectionResolver $connections,
        protected ProviderAccessTokenService $tokens,
    ) {}

    /**
     * @param  array<int,string>  $locationIds
     * @return array{orders:list<array<string,mixed>>,truncated:bool}
     */
    public function fetch(
        string $provider,
        int $tenantId,
        ?int $connectionId,
        CarbonImmutable $modifiedSince,
        int $pollLimit,
        int $maxOrders,
        array $locationIds = [],
    ): array {
        if (! in_array($provider, ['shopify', 'square'], true)) {
            throw new AutomationWorkflowException('This commerce source does not have a live v2 trigger.');
        }

        $connection = $this->connections->resolve($tenantId, $connectionId, $provider);

        return match ($provider) {
            'shopify' => $this->fetchShopify($connection, $modifiedSince, $pollLimit, $maxOrders),
            'square' => $this->fetchSquare($connection, $modifiedSince, $pollLimit, $maxOrders, $locationIds),
        };
    }

    /**
     * @return array{orders:list<array<string,mixed>>,truncated:bool}
     */
    protected function fetchShopify(
        IntegrationConnection $connection,
        CarbonImmutable $modifiedSince,
        int $pollLimit,
        int $maxOrders
    ): array {
        $shop = strtolower(trim((string) (data_get($connection->metadata, 'shop_domain') ?: $connection->external_account_secret)));
        if (preg_match('/^[a-z0-9][a-z0-9-]*\\.myshopify\\.com$/', $shop) !== 1) {
            throw new AutomationWorkflowException('The Shopify connection has an invalid store domain. Reconnect Shopify.');
        }

        $endpoint = 'https://'.$shop.'/admin/api/'
            .(string) config('services.shopify.automation_api_version', config('services.shopify.api_version', '2026-01'))
            .'/graphql.json';
        $after = null;
        $orders = [];
        $truncated = false;
        $pageSize = min(100, max(1, $pollLimit));
        $query = <<<'GRAPHQL'
query EverbranchOrders($first: Int!, $after: String, $query: String!) {
  orders(first: $first, after: $after, query: $query, sortKey: UPDATED_AT) {
    edges {
      node {
        id legacyResourceId name createdAt updatedAt processedAt cancelledAt
        displayFinancialStatus displayFulfillmentStatus email phone note tags statusPageUrl
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
                ->withHeaders(['X-Shopify-Access-Token' => $this->tokens->token($connection)])
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
                throw new AutomationWorkflowException(
                    'Shopify orders fetch failed: '.Str::limit((string) data_get($graphqlErrors, '0.message', 'GraphQL request failed.'), 300)
                );
            }

            $edges = array_values((array) data_get($payload, 'data.orders.edges', []));
            foreach ($edges as $index => $edge) {
                if (! is_array($edge) || ! is_array($edge['node'] ?? null)) {
                    continue;
                }
                $orders[] = $this->normalizeShopify((array) $edge['node'], $shop);
                if (count($orders) >= $maxOrders) {
                    $truncated = $index < count($edges) - 1
                        || (bool) data_get($payload, 'data.orders.pageInfo.hasNextPage');
                    break 2;
                }
            }

            $after = (bool) data_get($payload, 'data.orders.pageInfo.hasNextPage')
                ? trim((string) data_get($payload, 'data.orders.pageInfo.endCursor'))
                : '';
        } while ($after !== '');

        return ['orders' => $orders, 'truncated' => $truncated];
    }

    /**
     * @param  array<int,string>  $locationIds
     * @return array{orders:list<array<string,mixed>>,truncated:bool}
     */
    protected function fetchSquare(
        IntegrationConnection $connection,
        CarbonImmutable $modifiedSince,
        int $pollLimit,
        int $maxOrders,
        array $locationIds,
    ): array {
        $locations = $this->squareLocations($connection);
        $allowedIds = array_column($locations, 'id');
        $locationIds = array_values(array_intersect(array_map('strval', $locationIds), $allowedIds));
        if ($locationIds === []) {
            $locationIds = array_slice($allowedIds, 0, 10);
        }
        if ($locationIds === []) {
            throw new AutomationWorkflowException('No active Square locations are available. Test the Square connection again.');
        }

        $locationsById = collect($locations)->keyBy('id');
        $cursor = null;
        $orders = [];
        $truncated = false;

        do {
            $body = [
                'location_ids' => array_slice($locationIds, 0, 10),
                'limit' => min(1000, max(1, $pollLimit)),
                'return_entries' => false,
                'query' => [
                    'filter' => [
                        'date_time_filter' => [
                            'updated_at' => ['start_at' => $modifiedSince->utc()->toIso8601String()],
                        ],
                    ],
                    'sort' => ['sort_field' => 'UPDATED_AT', 'sort_order' => 'ASC'],
                ],
            ];
            if ($cursor !== null) {
                $body['cursor'] = $cursor;
            }

            $response = $this->squareRequest($this->tokens->token($connection))
                ->post(rtrim((string) config('services.square.api_base', 'https://connect.squareup.com'), '/').'/v2/orders/search', $body);
            $payload = $this->decode($response, 'Square orders fetch failed.');
            $pageOrders = array_values((array) ($payload['orders'] ?? []));
            foreach ($pageOrders as $index => $order) {
                if (! is_array($order)) {
                    continue;
                }
                $orders[] = $this->normalizeSquare(
                    $order,
                    (array) $locationsById->get((string) ($order['location_id'] ?? ''), [])
                );
                if (count($orders) >= $maxOrders) {
                    $truncated = $index < count($pageOrders) - 1
                        || filled($payload['cursor'] ?? null);
                    break 2;
                }
            }
            $cursor = trim((string) ($payload['cursor'] ?? '')) ?: null;
        } while ($cursor !== null);

        return ['orders' => $orders, 'truncated' => $truncated];
    }

    /**
     * @return array<int,array{id:string,label:string,status:?string,address:?string}>
     */
    protected function squareLocations(IntegrationConnection $connection): array
    {
        $stored = array_values(array_filter(
            (array) data_get($connection->metadata, 'locations', []),
            static fn (mixed $row): bool => is_array($row) && filled($row['id'] ?? null)
        ));
        if ($stored !== []) {
            return $stored;
        }

        $response = $this->squareRequest($this->tokens->token($connection))
            ->get(rtrim((string) config('services.square.api_base', 'https://connect.squareup.com'), '/').'/v2/locations');
        $payload = $this->decode($response, 'Square location discovery failed.');
        $locations = collect((array) ($payload['locations'] ?? []))
            ->filter(fn (mixed $location): bool => is_array($location))
            ->map(function (array $location): array {
                $address = (array) ($location['address'] ?? []);

                return [
                    'id' => trim((string) ($location['id'] ?? '')),
                    'label' => trim((string) ($location['name'] ?? '')) ?: 'Square location',
                    'status' => filled($location['status'] ?? null) ? (string) $location['status'] : null,
                    'address' => trim(implode(', ', array_filter([
                        $address['address_line_1'] ?? null,
                        $address['locality'] ?? null,
                        $address['administrative_district_level_1'] ?? null,
                        $address['postal_code'] ?? null,
                    ]))) ?: null,
                ];
            })
            ->filter(fn (array $location): bool => $location['id'] !== '' && ($location['status'] === null || $location['status'] === 'ACTIVE'))
            ->values()
            ->all();

        $metadata = (array) $connection->metadata;
        $metadata['locations'] = $locations;
        $connection->forceFill(['metadata' => $metadata, 'last_synced_at' => now()])->save();

        return $locations;
    }

    /**
     * @param  array<string,mixed>  $order
     * @return array<string,mixed>
     */
    protected function normalizeShopify(array $order, string $shop): array
    {
        $fulfillments = array_values(array_filter((array) ($order['fulfillments'] ?? []), 'is_array'));
        $attributes = array_values(array_filter((array) ($order['customAttributes'] ?? []), 'is_array'));
        $legacyId = trim((string) ($order['legacyResourceId'] ?? ''));
        $cancelled = filled($order['cancelledAt'] ?? null);

        $normalized = [
            'source_id' => trim((string) ($order['id'] ?? $legacyId)),
            'order_number' => ltrim(trim((string) ($order['name'] ?? $legacyId)), '#'),
            'created_at' => $order['createdAt'] ?? null,
            'updated_at' => (string) ($order['updatedAt'] ?? ''),
            'processed_at' => $order['processedAt'] ?? null,
            'schedule' => [
                'order_created' => $order['createdAt'] ?? null,
                'fulfillment' => data_get($fulfillments, '0.createdAt'),
                'delivery' => $this->firstDate(array_column($fulfillments, 'estimatedDeliveryAt'))
                    ?? $this->attributeDate($attributes, ['delivery']),
                'pickup' => $this->attributeDate($attributes, ['pickup', 'collection']),
            ],
            'source' => 'Shopify',
            'customer_name' => trim((string) data_get($order, 'customer.displayName', data_get($order, 'shippingAddress.name', ''))),
            'customer_email' => trim((string) ($order['email'] ?? '')),
            'customer_phone' => trim((string) ($order['phone'] ?? data_get($order, 'shippingAddress.phone', ''))),
            'items' => array_values(array_map(static fn (array $item): array => [
                'name' => trim((string) ($item['name'] ?? 'Item')),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'sku' => trim((string) ($item['sku'] ?? '')),
            ], array_filter((array) data_get($order, 'lineItems.nodes', []), 'is_array'))),
            'total' => [
                'amount' => (string) data_get($order, 'currentTotalPriceSet.shopMoney.amount', ''),
                'currency' => (string) data_get($order, 'currentTotalPriceSet.shopMoney.currencyCode', ''),
            ],
            'status' => [
                'financial' => Str::headline((string) ($order['displayFinancialStatus'] ?? '')),
                'fulfillment' => Str::headline((string) ($order['displayFulfillmentStatus'] ?? '')),
                'cancelled' => $cancelled,
            ],
            'notes' => trim((string) ($order['note'] ?? '')),
            'source_url' => $legacyId !== ''
                ? 'https://'.$shop.'/admin/orders/'.$legacyId
                : (string) ($order['statusPageUrl'] ?? ''),
            'shipping_address' => $this->shopifyAddress((array) ($order['shippingAddress'] ?? [])),
            'billing_address' => $this->shopifyAddress((array) ($order['billingAddress'] ?? [])),
        ];

        $normalized['order_id'] = $normalized['source_id'];
        $normalized['line_items'] = $normalized['items'];
        $normalized['fulfillment_at'] = $normalized['schedule']['fulfillment']
            ?? $normalized['schedule']['delivery']
            ?? $normalized['schedule']['pickup']
            ?? null;

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $order
     * @param  array<string,mixed>  $location
     * @return array<string,mixed>
     */
    protected function normalizeSquare(array $order, array $location): array
    {
        $fulfillments = array_values(array_filter((array) ($order['fulfillments'] ?? []), 'is_array'));
        $fulfillment = $fulfillments[0] ?? [];
        $recipient = (array) (
            data_get($fulfillment, 'pickup_details.recipient')
            ?? data_get($fulfillment, 'delivery_details.recipient')
            ?? data_get($fulfillment, 'shipment_details.recipient')
            ?? []
        );
        $shipping = (array) (
            data_get($fulfillment, 'delivery_details.recipient.address')
            ?? data_get($fulfillment, 'shipment_details.recipient.address')
            ?? []
        );
        $state = strtoupper(trim((string) ($order['state'] ?? '')));
        $fulfillmentState = strtoupper(trim((string) ($fulfillment['state'] ?? '')));

        $normalized = [
            'source_id' => trim((string) ($order['id'] ?? '')),
            'order_number' => trim((string) ($order['reference_id'] ?? $order['ticket_name'] ?? $order['id'] ?? '')),
            'created_at' => $order['created_at'] ?? null,
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
            'items' => array_values(array_map(static fn (array $item): array => [
                'name' => trim((string) ($item['name'] ?? 'Item')),
                'quantity' => is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : 1,
                'catalog_object_id' => trim((string) ($item['catalog_object_id'] ?? '')),
            ], array_filter((array) ($order['line_items'] ?? []), 'is_array'))),
            'total' => [
                'amount' => is_numeric(data_get($order, 'total_money.amount'))
                    ? ((int) data_get($order, 'total_money.amount')) / 100
                    : null,
                'currency' => (string) data_get($order, 'total_money.currency', ''),
            ],
            'status' => [
                'order' => Str::headline($state),
                'fulfillment' => Str::headline($fulfillmentState),
                'cancelled' => in_array($state, ['CANCELED', 'CANCELLED'], true)
                    || in_array($fulfillmentState, ['CANCELED', 'CANCELLED', 'FAILED'], true),
            ],
            'notes' => trim((string) (
                data_get($fulfillment, 'pickup_details.note')
                ?? data_get($fulfillment, 'delivery_details.note')
                ?? ''
            )),
            'source_url' => '',
            'shipping_address' => $this->squareAddress($shipping),
            'pickup_location' => [
                'id' => $location['id'] ?? null,
                'name' => $location['label'] ?? null,
                'address' => $location['address'] ?? null,
            ],
        ];

        $normalized['order_id'] = $normalized['source_id'];
        $normalized['line_items'] = $normalized['items'];
        $normalized['fulfillment_at'] = $normalized['schedule']['fulfillment']
            ?? $normalized['schedule']['delivery']
            ?? $normalized['schedule']['pickup']
            ?? null;

        return $normalized;
    }

    protected function shopifyAddress(array $address): array
    {
        return array_filter([
            'name' => $address['name'] ?? null,
            'company' => $address['company'] ?? null,
            'address_1' => $address['address1'] ?? null,
            'address_2' => $address['address2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['provinceCode'] ?? null,
            'postal_code' => $address['zip'] ?? null,
            'country' => $address['countryCodeV2'] ?? null,
            'phone' => $address['phone'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function squareAddress(array $address): array
    {
        return array_filter([
            'address_1' => $address['address_line_1'] ?? null,
            'address_2' => $address['address_line_2'] ?? null,
            'city' => $address['locality'] ?? null,
            'state' => $address['administrative_district_level_1'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'country' => $address['country'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
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

    protected function squareRequest(string $accessToken)
    {
        return Http::acceptJson()->asJson()->withToken($accessToken)
            ->withHeaders(['Square-Version' => (string) config('services.square.api_version', '2026-05-20')])
            ->timeout(25)->retry(2, 300, throw: false);
    }

    /**
     * @return array<string,mixed>
     */
    protected function decode(Response $response, string $message): array
    {
        $payload = $response->json();
        $json = is_array($payload) ? $payload : [];
        if ($response->successful()) {
            return $json;
        }

        $detail = trim((string) data_get(
            $json,
            'errors.0.detail',
            data_get($json, 'errors.0.message', data_get($json, 'error.message', ''))
        ));

        throw new AutomationWorkflowException(
            $message.' (HTTP '.$response->status().($detail !== '' ? ': '.Str::limit($detail, 300) : '').')'
        );
    }
}
