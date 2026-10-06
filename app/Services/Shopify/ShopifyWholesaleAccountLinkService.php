<?php

namespace App\Services\Shopify;

use App\Models\CustomerAccessRequest;
use RuntimeException;

class ShopifyWholesaleAccountLinkService
{
    public function forApplication(CustomerAccessRequest $request): string
    {
        $store = ShopifyStores::find('wholesale') ?? throw new RuntimeException('Wholesale store unavailable.');
        $client = new ShopifyGraphqlClient($store['shop'], $store['token'], $store['api_version']);
        $id = data_get($request->metadata, 'shopify_customer_gid');
        $data = $client->query('query WholesaleAccountState($id: ID!) { shop { primaryDomain { url } customerAccountsV2 { customerAccountsVersion } } customer(id: $id) { state } }', ['id' => $id]);
        $domain = rtrim((string) data_get($data, 'shop.primaryDomain.url'), '/');
        if (! str_starts_with($domain, 'https://')) {
            throw new RuntimeException('Wholesale storefront URL is unavailable.');
        }
        if (data_get($data, 'shop.customerAccountsV2.customerAccountsVersion') !== 'CLASSIC' || data_get($data, 'customer.state') === 'ENABLED') {
            return $domain.'/account/login';
        }
        $data = $client->query('mutation WholesaleAccountActivation($customerId: ID!) { customerGenerateAccountActivationUrl(customerId: $customerId) { accountActivationUrl userErrors { message } } }', ['customerId' => $id]);
        $url = data_get($data, 'customerGenerateAccountActivationUrl.accountActivationUrl');
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Shopify could not create the account activation link.');
        }

        return $url;
    }
}
