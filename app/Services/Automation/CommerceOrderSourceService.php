<?php

namespace App\Services\Automation;

use App\Models\IntegrationConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CommerceOrderSourceService
{
    public const LIVE_PROVIDERS = ['shopify', 'square', 'squarespace', 'wix'];

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

        return match ($provider) {
            'shopify' => $this->fetchShopify($connection, $modifiedSince, $pollLimit, $maxOrders),
            'square' => $this->fetchSquare($connection, $modifiedSince, $pollLimit, $maxOrders, $locationIds),
            'squarespace' => $this->fetchSquarespace($connection, $modifiedSince, $maxOrders),
            'wix' => $this->fetchWix($connection, $modifiedSince, $pollLimit, $maxOrders),
        };
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

    /** @return array<int,array<string,mixed>> */
    protected function fetchSquarespace(IntegrationConnection $connection, CarbonImmutable $modifiedSince, int $maxOrders): array
    {
        $accessToken = $this->connections->accessToken($connection);
        $endpoint = rtrim((string) config('services.squarespace.api_base', 'https://api.squarespace.com'), '/').'/1.0/commerce/orders';
        $cursor = null;
        $orders = [];

        do {
            $query = $cursor === null
                ? [
                    'modifiedAfter' => $modifiedSince->utc()->format('Y-m-d\TH:i:s.v\Z'),
                    'modifiedBefore' => CarbonImmutable::now()->utc()->addMinute()->format('Y-m-d\TH:i:s.v\Z'),
                    'paymentStates' => 'NOT_CHARGED,AUTHORIZED,PAID,PARTIALLY_PAID,REFUNDED',
                ]
                : ['cursor' => $cursor];
            $response = Http::acceptJson()->withToken($accessToken)
                ->withHeaders(['User-Agent' => (string) config('services.squarespace.user_agent', 'Everbranch Order Calendar/1.0')])
                ->timeout(25)->retry(2, 300, throw: false)
                ->get($endpoint, $query);
            $payload = $this->decode($response, 'Squarespace orders fetch failed.');
            foreach ((array) ($payload['result'] ?? []) as $order) {
                if (! is_array($order)) {
                    continue;
                }
                $orders[] = $this->normalizeSquarespace($order, (string) data_get($connection->metadata, 'site_url', ''));
                if (count($orders) >= $maxOrders) {
                    break 2;
                }
            }

            $cursor = (bool) data_get($payload, 'pagination.hasNextPage', false)
                ? trim((string) data_get($payload, 'pagination.nextPageCursor', '')) ?: null
                : null;
        } while ($cursor !== null);

        return $orders;
    }

    /** @return array<int,array<string,mixed>> */
    protected function fetchWix(IntegrationConnection $connection, CarbonImmutable $modifiedSince, int $pollLimit, int $maxOrders): array
    {
        $accessToken = $this->connections->accessToken($connection);
        $endpoint = rtrim((string) config('services.wix.api_base', 'https://www.wixapis.com'), '/').'/ecom/v1/orders/search';
        $cursor = null;
        $orders = [];

        do {
            $cursorPaging = ['limit' => min(100, max(1, $pollLimit))];
            if ($cursor !== null) {
                $cursorPaging['cursor'] = $cursor;
            }
            $response = Http::acceptJson()->asJson()->withToken($accessToken)
                ->timeout(25)->retry(2, 300, throw: false)
                ->post($endpoint, ['search' => [
                    'filter' => ['updatedDate' => ['$gte' => $modifiedSince->utc()->toIso8601String()]],
                    'sort' => [['fieldName' => 'updatedDate', 'order' => 'ASC']],
                    'cursorPaging' => $cursorPaging,
                ]]);
            $payload = $this->decode($response, 'Wix orders fetch failed.');
            foreach ((array) ($payload['orders'] ?? []) as $order) {
                if (! is_array($order)) {
                    continue;
                }
                $orders[] = $this->normalizeWix($order, (string) data_get($connection->metadata, 'site_url', ''));
                if (count($orders) >= $maxOrders) {
                    break 2;
                }
            }

            $cursor = trim((string) data_get($payload, 'metadata.cursors.next', '')) ?: null;
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

    /** @return array<string,mixed> */
    protected function normalizeSquarespace(array $order, string $siteUrl): array
    {
        $shipping = (array) ($order['shippingAddress'] ?? []);
        $billing = (array) ($order['billingAddress'] ?? []);
        $forms = array_values(array_filter((array) ($order['formSubmission'] ?? []), 'is_array'));
        $fulfillments = array_values(array_filter((array) ($order['fulfillments'] ?? []), 'is_array'));
        $fulfillmentStatus = strtoupper(trim((string) ($order['fulfillmentStatus'] ?? '')));
        $cancelled = $fulfillmentStatus === 'CANCELED';
        $orderNumber = trim((string) ($order['orderNumber'] ?? $order['externalOrderReference'] ?? $order['id'] ?? ''));

        return [
            'source_id' => trim((string) ($order['id'] ?? '')),
            'order_number' => $orderNumber,
            'updated_at' => (string) ($order['modifiedOn'] ?? ''),
            'schedule' => [
                'order_created' => $order['createdOn'] ?? null,
                'fulfillment' => $order['fulfilledOn'] ?? data_get($fulfillments, '0.shipDate'),
                'delivery' => $this->formDate($forms, ['delivery', 'deliver']),
                'pickup' => $this->formDate($forms, ['pickup', 'pick up', 'collection']),
            ],
            'source' => 'Squarespace',
            'customer_name' => trim(implode(' ', array_filter([$shipping['firstName'] ?? $billing['firstName'] ?? null, $shipping['lastName'] ?? $billing['lastName'] ?? null]))),
            'customer_email' => trim((string) ($order['customerEmail'] ?? '')),
            'customer_phone' => trim((string) ($shipping['phone'] ?? $billing['phone'] ?? '')),
            'items' => $this->squarespaceItems((array) ($order['lineItems'] ?? [])),
            'total' => $this->money(data_get($order, 'grandTotal.value'), data_get($order, 'grandTotal.currency')),
            'status' => $cancelled ? 'Cancelled' : trim(implode(' · ', array_filter([
                Str::headline((string) ($order['paymentState'] ?? '')),
                Str::headline($fulfillmentStatus),
            ]))),
            'notes' => collect((array) ($order['internalNotes'] ?? []))->filter(fn (mixed $note): bool => is_array($note))->pluck('content')->filter()->implode("\n"),
            'source_url' => rtrim($siteUrl, '/'),
            'shipping_address' => $this->squarespaceAddress($shipping),
            'billing_address' => $this->squarespaceAddress($billing),
            'pickup_location' => trim((string) ($order['shippingOptionName'] ?? '')),
            'cancelled' => $cancelled,
        ];
    }

    /** @return array<string,mixed> */
    protected function normalizeWix(array $order, string $siteUrl): array
    {
        $shippingInfo = (array) ($order['shippingInfo'] ?? []);
        $logistics = (array) ($shippingInfo['logistics'] ?? []);
        $destination = (array) ($logistics['shippingDestination'] ?? $order['recipientInfo'] ?? []);
        $contact = (array) ($destination['contactDetails'] ?? $shippingInfo['contactDetails'] ?? []);
        $address = (array) ($destination['address'] ?? []);
        $billingInfo = (array) ($order['billingInfo'] ?? []);
        $billingAddress = (array) ($billingInfo['address'] ?? []);
        $status = strtoupper(trim((string) ($order['status'] ?? '')));
        $cancelled = in_array($status, ['CANCELED', 'CANCELLED'], true);
        $deliveryTime = data_get($logistics, 'deliveryTimeSlot.from') ?? ($logistics['deliveryTime'] ?? null);

        return [
            'source_id' => trim((string) ($order['id'] ?? '')),
            'order_number' => trim((string) ($order['number'] ?? $order['id'] ?? '')),
            'updated_at' => (string) ($order['updatedDate'] ?? ''),
            'schedule' => [
                'order_created' => $order['purchasedDate'] ?? $order['createdDate'] ?? null,
                'fulfillment' => $deliveryTime,
                'delivery' => $deliveryTime,
                'pickup' => $deliveryTime,
            ],
            'source' => 'Wix',
            'customer_name' => trim(implode(' ', array_filter([$contact['firstName'] ?? null, $contact['lastName'] ?? null]))),
            'customer_email' => trim((string) data_get($order, 'buyerInfo.email', '')),
            'customer_phone' => trim((string) ($contact['phone'] ?? '')),
            'items' => $this->wixItems((array) ($order['lineItems'] ?? [])),
            'total' => $this->money(data_get($order, 'priceSummary.total.amount'), data_get($order, 'currency', data_get($order, 'priceSummary.total.currency'))),
            'status' => $cancelled ? 'Cancelled' : trim(implode(' · ', array_filter([
                Str::headline($status),
                Str::headline((string) ($order['paymentStatus'] ?? '')),
                Str::headline((string) ($order['fulfillmentStatus'] ?? '')),
            ]))),
            'notes' => trim((string) ($order['buyerNote'] ?? '')),
            'source_url' => rtrim($siteUrl, '/'),
            'shipping_address' => $this->wixAddress($address, $contact),
            'billing_address' => $this->wixAddress($billingAddress, (array) ($billingInfo['contactDetails'] ?? [])),
            'pickup_location' => trim(implode(', ', array_filter([
                data_get($order, 'businessLocation.name'),
                $shippingInfo['title'] ?? null,
                $this->wixAddress($address, []),
            ]))),
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

    protected function squarespaceItems(array $items): string
    {
        return collect($items)->filter(fn (mixed $item): bool => is_array($item))->map(fn (array $item): string => max(1, (int) ($item['quantity'] ?? 1)).' × '.trim((string) ($item['productName'] ?? $item['title'] ?? 'Item')))->implode(', ');
    }

    protected function wixItems(array $items): string
    {
        return collect($items)->filter(fn (mixed $item): bool => is_array($item))->map(function (array $item): string {
            $name = data_get($item, 'productName.translated') ?? data_get($item, 'productName.original');
            if (! is_string($name) || trim($name) === '') {
                $name = is_string($item['productName'] ?? null) ? $item['productName'] : 'Item';
            }

            return max(1, (int) ($item['quantity'] ?? 1)).' × '.trim($name);
        })->implode(', ');
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

    protected function squarespaceAddress(array $address): string
    {
        return trim(implode(', ', array_filter([
            trim(implode(' ', array_filter([$address['firstName'] ?? null, $address['lastName'] ?? null]))),
            $address['address1'] ?? null, $address['address2'] ?? null, $address['city'] ?? null,
            $address['state'] ?? null, $address['postalCode'] ?? null, $address['countryCode'] ?? null,
        ])));
    }

    protected function wixAddress(array $address, array $contact): string
    {
        return trim(implode(', ', array_filter([
            trim(implode(' ', array_filter([$contact['firstName'] ?? null, $contact['lastName'] ?? null]))),
            $contact['company'] ?? null, $address['addressLine'] ?? null, $address['addressLine2'] ?? null,
            data_get($address, 'streetAddress.name'), data_get($address, 'streetAddress.number'),
            $address['city'] ?? null, $address['subdivision'] ?? null, $address['postalCode'] ?? null, $address['country'] ?? null,
        ])));
    }

    protected function formDate(array $fields, array $needles): ?string
    {
        foreach ($fields as $field) {
            $label = Str::lower((string) ($field['label'] ?? ''));
            if (! collect($needles)->contains(fn (string $needle): bool => str_contains($label, $needle))) {
                continue;
            }
            $value = trim((string) ($field['value'] ?? ''));
            if ($this->date($value) !== null) {
                return $value;
            }
        }

        return null;
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
