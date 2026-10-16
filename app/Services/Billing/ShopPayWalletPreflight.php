<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Operator diagnostics only: never submits payments or enables a tenant. */
class ShopPayWalletPreflight
{
    public const API_VERSION = '2026-10';

    private string $host;

    private string $shopId;

    public function __construct(#[\SensitiveParameter] private array $credentials)
    {
        $this->host = strtolower(trim((string) ($credentials['shop_domain'] ?? '')));
        $this->shopId = (string) ($credentials['shop_id'] ?? '');
        if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.myshopify\.com\z/D', $this->host)
            || ! preg_match('/\A[1-9][0-9]{0,19}\z/D', $this->shopId)) {
            throw new RuntimeException('A canonical myshopify.com host and numeric Shop ID are required.');
        }
        foreach (['admin_access_token', 'storefront_access_token'] as $key) {
            if (! is_string($credentials[$key] ?? null) || trim($credentials[$key]) === '' || strlen($credentials[$key]) > 4096
                || preg_match('/[\x00-\x20\x7f]/', $credentials[$key])) {
                throw new RuntimeException('Both merchant-owned Wallet API credentials are required.');
            }
        }
    }

    public function inspect(): array
    {
        $data = $this->graphql('admin', 'query EverbranchWalletIdentity { shop { id name myshopifyDomain } currentAppInstallation { app { title } accessScopes { handle } } }');
        if (data_get($data, 'shop.id') !== 'gid://shopify/Shop/'.$this->shopId
            || strtolower((string) data_get($data, 'shop.myshopifyDomain')) !== $this->host) {
            throw new RuntimeException('The Wallet credentials do not match the expected Shop ID and domain.');
        }

        return ['shop_id' => $this->shopId, 'shop_domain' => $this->host, 'shop_name' => data_get($data, 'shop.name'),
            'app_title' => data_get($data, 'currentAppInstallation.app.title'), 'api_identity_verified' => true,
            'checkout_ready' => false];
    }

    public function probeSession(): array
    {
        // A generic Storefront shop query may return "Channel not found" for
        // Shop Component tokens even when Wallet session creation is supported.
        // Test the documented Wallet operation without a buyer or payment method.
        $this->inspect();
        $source = 'everbranch-setup-probe:'.Str::uuid();
        $money = ['amount' => '1.00', 'currencyCode' => 'USD'];
        $request = ['locale' => 'en', 'presentmentCurrency' => 'USD', 'subtotal' => $money, 'total' => $money,
            'lineItems' => [['label' => 'Fictional integration probe — no payment submitted', 'quantity' => 1,
                'requiresShipping' => false, 'originalItemPrice' => $money, 'originalLinePrice' => $money,
                'finalItemPrice' => $money, 'finalLinePrice' => $money]]];
        $data = $this->graphql('storefront', 'mutation EverbranchWalletProbe($source: String!, $request: ShopPayPaymentRequestInput!) { shopPayPaymentRequestSessionCreate(sourceIdentifier: $source, paymentRequest: $request) { shopPayPaymentRequestSession { token sourceIdentifier checkoutUrl } userErrors { field message } } }', ['source' => $source, 'request' => $request]);
        $result = (array) ($data['shopPayPaymentRequestSessionCreate'] ?? []);
        $session = (array) ($result['shopPayPaymentRequestSession'] ?? []);
        $url = (string) ($session['checkoutUrl'] ?? '');
        if (! empty($result['userErrors']) || ! is_string($session['token'] ?? null) || $session['token'] === ''
            || ($session['sourceIdentifier'] ?? '') !== $source || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_HOST) !== 'shop.app' || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null || parse_url($url, PHP_URL_PORT) !== null
            || ! str_starts_with((string) parse_url($url, PHP_URL_PATH), '/checkout/'.$this->shopId.'/')) {
            throw new RuntimeException('Shopify did not return a valid Wallet session for the expected merchant.');
        }

        // Do not return bearer session tokens or URLs to logs/terminal output.
        return ['session_created' => true, 'source_identifier' => $source, 'payment_submitted' => false,
            'checkout_ready' => false];
    }

    private function graphql(string $api, string $query, array $variables = []): array
    {
        $admin = $api === 'admin';
        $path = $admin ? '/admin/api/'.self::API_VERSION.'/graphql.json' : '/api/'.self::API_VERSION.'/graphql.json';
        $payload = ['query' => $query];
        if ($variables !== []) {
            $payload['variables'] = $variables;
        }
        try {
            $response = Http::withoutRedirecting()->asJson()->acceptJson()->connectTimeout(5)->timeout(20)
                ->withHeaders([$admin ? 'X-Shopify-Access-Token' : 'X-Shopify-Storefront-Access-Token' => $this->credentials[$admin ? 'admin_access_token' : 'storefront_access_token']])
                ->post('https://'.$this->host.$path, $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('Shopify could not be reached. No payment was submitted.');
        }
        if (! $response->successful() || ! empty($response->json('errors')) || ! is_array($response->json('data'))) {
            throw new RuntimeException('Shopify rejected the Wallet '.$api.' check. Verify the merchant credentials and app permissions. No payment was submitted.');
        }

        return $response->json('data');
    }
}
