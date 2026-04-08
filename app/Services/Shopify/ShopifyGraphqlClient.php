<?php

namespace App\Services\Shopify;

use App\Support\Diagnostics\ShopifyEmbeddedDeepProfile;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopifyGraphqlClient
{
    protected string $shopDomain;

    protected string $accessToken;

    protected string $apiVersion;

    public function __construct(string $shopDomain, string $accessToken, string $apiVersion = '2026-01')
    {
        $this->shopDomain = $shopDomain;
        $this->accessToken = $accessToken;
        $this->apiVersion = $apiVersion;
    }

    /**
     * @param  array<string,mixed>  $variables
     * @return array<string,mixed>
     */
    public function query(string $query, array $variables = []): array
    {
        $normalizedVariables = $variables === [] ? (object) [] : $variables;

        $response = $this->requestWithRetry($this->baseUrl(), [
            'query' => $query,
            'variables' => $normalizedVariables,
        ]);

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Shopify GraphQL response was not valid JSON.');
        }

        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            throw new RuntimeException('Shopify GraphQL returned errors: '.$this->formatErrors($errors));
        }

        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException('Shopify GraphQL response missing data payload.');
        }

        return $data;
    }

    protected function request(): PendingRequest
    {
        return Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout(30);
    }

    protected function baseUrl(): string
    {
        return 'https://'.rtrim($this->shopDomain, '/').'/admin/api/'.$this->apiVersion.'/graphql.json';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function requestWithRetry(string $url, array $payload): Response
    {
        $attempts = 0;
        $maxAttempts = 5;
        $baseDelayMs = 500;
        $startedAt = microtime(true);
        $status = null;
        $retried = false;
        $response = null;

        try {
            do {
                $attempts++;
                $response = $this->request()->post($url, $payload);

                if (! $response->failed()) {
                    return $response;
                }

                $status = $response->status();
                $retryAfter = (int) ($response->header('Retry-After') ?? 0);

                if (in_array($status, [429, 500, 502, 503, 504], true) && $attempts < $maxAttempts) {
                    $retried = true;
                    $delayMs = $retryAfter > 0
                        ? $retryAfter * 1000
                        : ($baseDelayMs * (2 ** ($attempts - 1)));
                    usleep($delayMs * 1000);

                    continue;
                }

                $response->throw();
            } while ($attempts < $maxAttempts);

            if ($response instanceof Response) {
                $response->throw();
            }

            throw new RuntimeException('Shopify GraphQL request failed before receiving a response.');
        } finally {
            ShopifyEmbeddedDeepProfile::addExternalHttp([
                'service' => 'shopify_graphql',
                'shop_domain' => $this->shopDomain,
                'api_version' => $this->apiVersion,
                'url' => $url,
                'status' => $status,
                'attempts' => $attempts,
                'retried' => $retried,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ]);
        }
    }

    /**
     * @param  array<int,mixed>  $errors
     */
    protected function formatErrors(array $errors): string
    {
        $parts = [];

        foreach ($errors as $error) {
            if (! is_array($error)) {
                $parts[] = trim((string) $error);

                continue;
            }

            $message = trim((string) ($error['message'] ?? 'unknown_error'));
            $path = is_array($error['path'] ?? null)
                ? implode('.', array_map('strval', $error['path']))
                : null;

            $parts[] = $path ? "{$message} (path={$path})" : $message;
        }

        return implode(' | ', array_filter($parts, fn (string $value): bool => $value !== ''));
    }
}
