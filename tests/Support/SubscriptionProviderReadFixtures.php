<?php

function subscriptionReadExpected(): array
{
    return ['shop_gid' => 'gid://shopify/Shop/1001', 'app_gid' => 'gid://shopify/App/2001',
        'app_installation_gid' => 'gid://shopify/AppInstallation/3001', 'public_client_id' => 'subscription-client'];
}

function subscriptionReadIdentity(array $scopes = ['read_own_subscription_contracts', 'read_customers', 'read_customer_payment_methods', 'read_products']): array
{
    return ['shop' => ['id' => 'gid://shopify/Shop/1001', 'myshopifyDomain' => 'merchant-one.myshopify.com', 'ianaTimezone' => 'America/New_York'],
        'currentAppInstallation' => ['id' => 'gid://shopify/AppInstallation/3001',
            'app' => ['id' => 'gid://shopify/App/2001', 'apiKey' => 'subscription-client', 'webhookApiVersion' => '2026-10'],
            'accessScopes' => array_map(fn ($handle) => ['handle' => $handle], $scopes)]];
}

function subscriptionReadContract(string $id = '101', string $revision = '18446744073709551615'): array
{
    return ['id' => 'gid://shopify/SubscriptionContract/'.$id, 'app' => ['id' => 'gid://shopify/App/2001'], 'revisionId' => $revision,
        'status' => 'ACTIVE', 'createdAt' => '2026-08-01T14:00:00Z', 'updatedAt' => '2026-10-15T14:00:00Z',
        'currencyCode' => 'USD', 'nextBillingDate' => '2026-11-01T14:15:16-05:00', 'customer' => ['id' => 'gid://shopify/Customer/501'],
        'billingPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1, 'minCycles' => 3, 'maxCycles' => null, 'anchors' => []],
        'deliveryPolicy' => ['interval' => 'MONTH', 'intervalCount' => 1, 'anchors' => []],
        'deliveryMethod' => ['__typename' => 'SubscriptionDeliveryMethodShipping'], 'deliveryPrice' => ['amount' => '5.1234', 'currencyCode' => 'USD'],
        'customerPaymentMethod' => ['id' => 'gid://shopify/CustomerPaymentMethod/601', 'customer' => ['id' => 'gid://shopify/Customer/501'], 'revokedAt' => null, 'revokedReason' => null,
            'instrument' => ['__typename' => 'CustomerCreditCard', 'brand' => 'VISA', 'lastDigits' => '4242', 'expiryMonth' => 12, 'expiryYear' => 2030]]];
}

function subscriptionReadLine(string $id = '701'): array
{
    return ['id' => 'gid://shopify/SubscriptionLine/'.$id, 'title' => 'Example subscription', 'variantTitle' => null, 'sku' => null, 'quantity' => 1,
        'productId' => 'gid://shopify/Product/801', 'variantId' => 'gid://shopify/ProductVariant/901', 'sellingPlanId' => 'gid://shopify/SellingPlan/1001', 'sellingPlanName' => 'Monthly',
        'requiresShipping' => true, 'taxable' => true, 'currentPrice' => ['amount' => '20.000', 'currencyCode' => 'USD'],
        'lineDiscountedPrice' => ['amount' => '18.000', 'currencyCode' => 'USD'], 'discountAllocations' => []];
}

function subscriptionReadAttempt(string $id = '1101', ?array $state = null): array
{
    return ['id' => 'gid://shopify/SubscriptionBillingAttempt/'.$id, 'createdAt' => '2026-10-01T12:00:00Z', 'completedAt' => '2026-10-01T12:00:01Z', 'originTime' => null,
        'subscriptionContract' => ['id' => 'gid://shopify/SubscriptionContract/101', 'app' => ['id' => 'gid://shopify/App/2001']],
        'state' => $state ?? ['__typename' => 'SubscriptionBillingAttemptSuccessState', 'order' => ['id' => 'gid://shopify/Order/1201', 'customer' => ['id' => 'gid://shopify/Customer/501']]]];
}

function subscriptionReadCycle(int $index = 1): array
{
    return ['cycleIndex' => $index, 'billingAttemptExpectedDate' => '2026-11-01T19:00:00Z', 'cycleStartAt' => '2026-11-01T19:00:00Z', 'cycleEndAt' => '2026-12-01T19:00:00Z',
        'edited' => false, 'skipped' => false, 'status' => 'UNBILLED', 'sourceContract' => array_intersect_key(subscriptionReadContract(), array_flip(['id', 'app', 'revisionId']))];
}

function subscriptionReadConnection(array $nodes, bool $next = false, ?string $cursor = null): array
{
    return ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $next, 'endCursor' => $cursor]];
}

/** Synthetic closed fixture, never a provider call. Optional callback may replace data or return a Response. */
function subscriptionReadFake(array $contracts = [], ?callable $change = null, array $children = []): void
{
    \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake(function ($request) use ($contracts, $change, $children) {
        if ($request->url() !== 'https://merchant-one.myshopify.com/admin/api/2026-10/graphql.json') {
            throw new RuntimeException('Unexpected fixture host.');
        }
        $operation = $request['operationName'];
        $variables = (array) $request['variables'];
        $contract = collect($contracts)->firstWhere('id', $variables['id'] ?? null);
        $identity = $contract ? array_intersect_key($contract, array_flip(['id', 'app', 'revisionId'])) : null;
        $data = match ($operation) {
            'SubscriptionInstallationIdentity' => subscriptionReadIdentity(),
            'SubscriptionOwnedContracts' => ['subscriptionContracts' => subscriptionReadConnection(
                array_map(fn ($c) => array_intersect_key($c, array_flip(['id', 'app', 'revisionId'])), array_slice($contracts, (int) str_replace('fixture-index:', '', $variables['after'] ?? '0'), 25)),
                (int) str_replace('fixture-index:', '', $variables['after'] ?? '0') + 25 < count($contracts),
                'fixture-index:'.((int) str_replace('fixture-index:', '', $variables['after'] ?? '0') + 25)
            )],
            'SubscriptionContractCore' => ['subscriptionContract' => $contract],
            'SubscriptionContractLines' => ['subscriptionContract' => $identity + ['lines' => subscriptionReadConnection($children['lines'] ?? [])]],
            'SubscriptionContractDiscounts' => ['subscriptionContract' => $identity + ['discounts' => subscriptionReadConnection($children['discounts'] ?? [])]],
            'SubscriptionContractAttempts' => ['subscriptionContract' => $identity + ['billingAttempts' => subscriptionReadConnection($children['attempts'] ?? [])]],
            'SubscriptionContractCycles' => ['subscriptionBillingCycles' => subscriptionReadConnection($children['cycles'] ?? [])],
            default => throw new RuntimeException('Unexpected fixture operation.'),
        };
        $changed = $change ? $change($operation, $variables, $data, $request) : null;
        if ($changed instanceof \Illuminate\Http\Client\Response || $changed instanceof \GuzzleHttp\Promise\PromiseInterface) {
            return $changed;
        }

        return \Illuminate\Support\Facades\Http::response(['data' => $changed ?? $data], 200, ['X-Shopify-API-Version' => '2026-10']);
    });
}
