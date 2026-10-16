<?php

function rechargeExpected(): array
{
    return ['provider_store_id' => '9001', 'platform' => 'shopify', 'identifier' => 'reviewed-merchant'];
}

function rechargeShadowFixtures(): array
{
    return [
        'store' => ['store' => ['id' => 9001, 'external_platform' => 'shopify', 'identifier' => 'reviewed-merchant', 'checkout_platform' => 'shopify', 'currency' => 'USD', 'timezone' => ['iana_name' => 'America/New_York'], 'owner_email' => 'private-owner@example.test']],
        'token_information' => ['token_information' => ['scopes' => ['read_store', 'read_customers', 'read_subscriptions', 'read_payment_methods'], 'contact_email' => 'private-token-owner@example.test']],
        'customers' => [['id' => 101, 'external_customer_id' => ['ecommerce' => '555'], 'first_name' => 'Fictional', 'last_name' => 'Subscriber', 'email' => 'fictional@example.test', 'phone' => null, 'tax_exempt' => false, 'subscriptions_active_count' => 1, 'subscriptions_total_count' => 1, 'created_at' => '2025-01-01T09:00:00+00:00', 'updated_at' => '2026-10-16T09:00:00+00:00', 'hash' => 'PRIVATE_PORTAL_BEARER_HASH', 'analytics_data' => ['private' => 'DONT_COPY_ANALYTICS']]],
        'addresses' => [['id' => 201, 'customer_id' => 101, 'payment_method_id' => 401, 'address1' => '123 Fictional Lane', 'city' => 'Example', 'country_code' => 'US', 'zip' => '12345', 'presentment_currency' => 'USD', 'discounts' => [], 'shipping_lines_override' => [], 'updated_at' => '2026-10-16T09:00:00+00:00']],
        'subscriptions' => [['id' => 301, 'customer_id' => 101, 'address_id' => 201, 'status' => 'active', 'quantity' => 2, 'price' => '19.9950', 'presentment_currency' => 'USD', 'external_variant_id' => ['ecommerce' => '777'], 'next_charge_scheduled_at' => '2026-11-01', 'order_interval_unit' => 'month', 'order_interval_frequency' => 1, 'charge_interval_frequency' => 1, 'properties' => [['name' => 'Scent', 'value' => 'Fictional'], ['name' => 'secret_token', 'value' => 'PRIVATE_PROPERTY_SECRET']], 'updated_at' => '2026-10-16T09:00:00+00:00']],
        'payment_methods' => [['id' => 401, 'customer_id' => 101, 'payment_type' => 'CREDIT_CARD', 'processor_name' => 'shopify_payments', 'payment_details' => ['brand' => 'visa', 'last4' => 4242, 'exp_month' => 12, 'exp_year' => 2030, 'token' => 'PRIVATE_NESTED_VAULT_TOKEN'], 'processor_customer_token' => 'PRIVATE_VAULT_CUSTOMER_TOKEN', 'processor_payment_method_token' => 'PRIVATE_VAULT_PAYMENT_TOKEN', 'billing_address' => ['address1' => 'DO_NOT_COPY_BILLING'], 'updated_at' => '2026-10-16T09:00:00+00:00']],
    ];
}

function rechargeFake(?array $fixtures = null, ?callable $change = null): void
{
    $fixtures ??= rechargeShadowFixtures();
    \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory);
    \Illuminate\Support\Facades\Http::preventStrayRequests();
    \Illuminate\Support\Facades\Http::fake(function ($request) use ($fixtures, $change) {
        if ($request->method() !== 'GET' || parse_url($request->url(), PHP_URL_HOST) !== 'api.rechargeapps.com') {
            throw new RuntimeException('Unexpected synthetic Recharge request.');
        }
        $path = trim(parse_url($request->url(), PHP_URL_PATH), '/');
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
        $parts = explode('/', $path);
        $stream = $parts[0];
        if (in_array($path, ['store', 'token_information'], true)) {
            $data = $fixtures[$path];
        } elseif (isset($parts[1])) {
            $key = match ($stream) {
                'customers' => 'customer', 'addresses' => 'address', 'subscriptions' => 'subscription', 'payment_methods' => 'payment_method'
            };
            $row = collect($fixtures[$stream] ?? [])->first(fn ($row) => (string) $row['id'] === $parts[1]);
            $data = [$key => $row];
        } else {
            $offset = (int) ($query['cursor'] ?? 0);
            $limit = (int) ($query['limit'] ?? 50);
            $all = $fixtures[$stream] ?? [];
            $data = [$stream => array_slice($all, $offset, $limit), 'next_cursor' => $offset + $limit < count($all) ? (string) ($offset + $limit) : null, 'previous_cursor' => null];
        }
        $changed = $change ? $change($path, $query, $data, $request) : null;
        if ($changed instanceof \Illuminate\Http\Client\Response || $changed instanceof \GuzzleHttp\Promise\PromiseInterface) {
            return $changed;
        }

        return \Illuminate\Support\Facades\Http::response($changed ?? $data, 200);
    });
}
