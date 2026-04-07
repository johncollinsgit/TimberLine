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
        $page = $this->getPage($path, $params);
        $resourceKey = $page['resource_key'] ?? null;
        $payload = $page['payload'] ?? [];

        if ($resourceKey === null || !($page['is_list_resource'] ?? false)) {
            return is_array($payload) ? $payload : [];
        }

        $items = $page['items'];
        $nextUrl = $page['next_url'] ?? null;

        while ($nextUrl) {
            $nextPage = $this->getPage($nextUrl);
            $items = array_merge($items, is_array($nextPage['items'] ?? null) ? $nextPage['items'] : []);
            $nextUrl = $nextPage['next_url'] ?? null;
        }

        return [$resourceKey => $items];
    }

    /**
     * @return array{
     *   payload: array<string,mixed>,
     *   resource_key: ?string,
     *   items: array<int,mixed>,
     *   is_list_resource: bool,
     *   next_url: ?string
     * }
     */
    public function getPage(string $pathOrUrl, array $params = []): array
    {
        $response = $this->requestWithRetry($this->resolveUrl($pathOrUrl), $params);
        $payload = $response->json() ?? [];
        $resourceKey = $this->resourceKey($payload);

        $items = [];
        $isListResource = false;
        if (
            $resourceKey !== null &&
            is_array($payload[$resourceKey] ?? null) &&
            array_is_list($payload[$resourceKey])
        ) {
            $items = $payload[$resourceKey];
            $isListResource = true;
        }

        return [
            'payload' => is_array($payload) ? $payload : [],
            'resource_key' => $resourceKey,
            'items' => $items,
            'is_list_resource' => $isListResource,
            'next_url' => $this->nextPageUrl($response->header('Link')),
        ];
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

    protected function resolveUrl(string $pathOrUrl): string
    {
        if (preg_match('#^https?://#i', $pathOrUrl) === 1) {
            return $pathOrUrl;
        }

        return $this->baseUrl().'/'.ltrim($pathOrUrl, '/');
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
        if (! $linkHeader) {
            return null;
        }

        if (preg_match_all('/<([^>]+)>\s*;\s*rel="([^"]+)"/i', $linkHeader, $matches, PREG_SET_ORDER) === 1 || ! empty($matches)) {
            foreach ($matches as $match) {
                if (strtolower((string) ($match[2] ?? '')) !== 'next') {
                    continue;
                }

                return html_entity_decode((string) ($match[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
