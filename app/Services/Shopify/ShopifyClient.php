<?php

namespace App\Services\Shopify;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ShopifyClient
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

    public function get(string $path, array $params = []): array
    {
        $url = $this->baseUrl().'/'.ltrim($path, '/');

        $response = $this->requestWithRetry($url, $params);

        $payload = $response->json() ?? [];
        $resourceKey = $this->resourceKey($payload);

        if (
            $resourceKey === null ||
            !is_array($payload[$resourceKey] ?? null) ||
            !array_is_list($payload[$resourceKey])
        ) {
            return $payload;
        }

        $items = $payload[$resourceKey];

        // Shopify REST pagination via Link header
        $nextUrl = $this->nextPageUrl($response->header('Link'));
        while ($nextUrl) {
            $pageResponse = $this->requestWithRetry($nextUrl);

            $pagePayload = $pageResponse->json() ?? [];
            $items = array_merge($items, $pagePayload[$resourceKey] ?? []);

            $nextUrl = $this->nextPageUrl($pageResponse->header('Link'));
        }

        return [$resourceKey => $items];
    }

    protected function request(): PendingRequest
    {
        return Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Accept' => 'application/json',
        ]);
    }

    protected function baseUrl(): string
    {
        return 'https://'.rtrim($this->shopDomain, '/').'/admin/api/'.$this->apiVersion;
    }

    protected function resourceKey(array $payload): ?string
    {
        if (count($payload) !== 1) {
            return null;
        }

        $key = array_key_first($payload);
        return is_string($key) ? $key : null;
    }

    protected function nextPageUrl(?string $linkHeader): ?string
    {
        if (!$linkHeader) {
            return null;
        }

        foreach (explode(',', $linkHeader) as $part) {
            $section = trim($part);
            if (!str_contains($section, 'rel="next"')) {
                continue;
            }

            if (preg_match('/<([^>]+)>/', $section, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    protected function requestWithRetry(string $url, array $params = []): \Illuminate\Http\Client\Response
    {
        $attempts = 0;
        $maxAttempts = 5;
        $baseDelayMs = 500;

        do {
            $attempts++;
            $response = $this->request()->get($url, $params);

            if (!$response->failed()) {
                return $response;
            }

            $status = $response->status();
            $retryAfter = (int) ($response->header('Retry-After') ?? 0);

            if (in_array($status, [429, 500, 502, 503], true) && $attempts < $maxAttempts) {
                $delayMs = $retryAfter > 0
                    ? $retryAfter * 1000
                    : ($baseDelayMs * (2 ** ($attempts - 1)));

                usleep($delayMs * 1000);
                continue;
            }

            $response->throw();
        } while ($attempts < $maxAttempts);

        $response->throw();
        return $response;
    }
}
