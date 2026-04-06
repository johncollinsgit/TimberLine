<?php

namespace App\Services\Shopify;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class ShopifyWebPixelConnectionService
{
    /**
     * @var array<int,string>
     */
    protected array $requiredScopes = [
        'read_pixels',
        'write_pixels',
        'read_customer_events',
    ];

    /**
     * @param  array<string,mixed>  $storeContext
     * @return array<string,mixed>
     */
    public function status(array $storeContext): array
    {
        $resolved = $this->resolveStore($storeContext);
        if (! $resolved['ok']) {
            return $resolved;
        }

        $store = $resolved['store'];
        $cacheKey = $this->cacheKey((string) ($store['key'] ?? 'storefront'));

        return Cache::remember($cacheKey, now()->addSeconds(30), function () use ($store): array {
            return $this->fetchStatus($store);
        });
    }

    /**
     * @param  array<string,mixed>  $storeContext
     * @return array<string,mixed>
     */
    public function connect(array $storeContext): array
    {
        $resolved = $this->resolveStore($storeContext);
        if (! $resolved['ok']) {
            return $resolved;
        }

        $store = $resolved['store'];
        $cacheKey = $this->cacheKey((string) ($store['key'] ?? 'storefront'));
        Cache::forget($cacheKey);

        $scopeCheck = $this->scopeStatus($store);
        if (! $scopeCheck['ok']) {
            return $scopeCheck;
        }

        try {
            $client = $this->client($store);
            $existing = $this->queryWebPixel($client);
            $settings = $this->pixelSettings();

            if ($existing !== null) {
                $currentSettings = $this->decodeSettings((string) ($existing['settings'] ?? ''));
                if ($currentSettings === $settings) {
                    $status = $this->buildConnectedStatus($store, $existing, 'Pixel already connected.');
                    Cache::put($cacheKey, $status, now()->addSeconds(30));

                    return $status;
                }

                $updated = $this->updateWebPixel($client, (string) ($existing['id'] ?? ''), $settings);
                $status = $this->buildConnectedStatus($store, $updated, 'Pixel connected and settings updated.');
                Cache::put($cacheKey, $status, now()->addSeconds(30));

                return $status;
            }

            $created = $this->createWebPixel($client, $settings);
            $status = $this->buildConnectedStatus($store, $created, 'Pixel connected in Shopify Customer Events.');
            Cache::put($cacheKey, $status, now()->addSeconds(30));

            return $status;
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'status' => 'shopify_error',
                'connected' => false,
                'message' => $error->getMessage() !== ''
                    ? $error->getMessage()
                    : 'Shopify rejected the pixel connection request.',
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $store
     * @return array<string,mixed>
     */
    protected function fetchStatus(array $store): array
    {
        $scopeCheck = $this->scopeStatus($store);
        if (! $scopeCheck['ok']) {
            return $scopeCheck;
        }

        try {
            $pixel = $this->queryWebPixel($this->client($store));
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'status' => 'shopify_error',
                'connected' => false,
                'message' => $error->getMessage() !== ''
                    ? $error->getMessage()
                    : 'Could not verify Shopify Customer Events pixel status.',
            ];
        }

        if ($pixel === null) {
            return [
                'ok' => true,
                'status' => 'disconnected',
                'connected' => false,
                'label' => 'Disconnected',
                'message' => 'The web pixel extension is deployed, but this shop has not connected the app pixel yet.',
                'can_connect' => true,
                'settings' => $this->pixelSettings(),
            ];
        }

        return $this->buildConnectedStatus($store, $pixel, 'Shopify Customer Events pixel is connected.');
    }

    /**
     * @param  array<string,mixed>  $storeContext
     * @return array{ok:bool,status:string,message:string,store?:array<string,mixed>,connected?:bool}
     */
    protected function resolveStore(array $storeContext): array
    {
        $storeKey = trim((string) Arr::get($storeContext, 'key'));
        $shopDomain = trim((string) Arr::get($storeContext, 'shop'));

        $store = $storeKey !== ''
            ? ShopifyStores::find($storeKey)
            : ($shopDomain !== '' ? ShopifyStores::findByShopDomain($shopDomain) : null);

        if (! is_array($store)) {
            return [
                'ok' => false,
                'status' => 'store_not_installed',
                'connected' => false,
                'message' => 'This Shopify store does not have a usable Backstage install/token yet.',
            ];
        }

        return [
            'ok' => true,
            'status' => 'resolved',
            'message' => 'Store resolved.',
            'store' => $store,
        ];
    }

    /**
     * @param  array<string,mixed>  $store
     * @return array<string,mixed>
     */
    protected function scopeStatus(array $store): array
    {
        $grantedScopes = $this->resolveGrantedScopes($store);
        $missingScopes = array_values(array_diff($this->requiredScopes, $grantedScopes));

        if ($missingScopes !== []) {
            return [
                'ok' => false,
                'status' => 'missing_scopes',
                'connected' => false,
                'label' => 'Scope update needed',
                'granted_scopes' => $grantedScopes,
                'missing_scopes' => $missingScopes,
                'message' => 'This shop must reauthorize Backstage with: '.implode(', ', $missingScopes).'.',
            ];
        }

        return [
            'ok' => true,
            'status' => 'scopes_ready',
            'connected' => false,
            'granted_scopes' => $grantedScopes,
            'message' => 'Pixel scopes are ready.',
        ];
    }

    /**
     * @param  array<string,mixed>  $store
     */
    protected function client(array $store): ShopifyGraphqlClient
    {
        $shop = trim((string) ($store['shop'] ?? ''));
        $token = trim((string) ($store['token'] ?? ''));
        $apiVersion = trim((string) ($store['api_version'] ?? config('services.shopify.api_version', '2026-01')));

        if ($shop === '' || $token === '') {
            throw new RuntimeException('Storefront tracking cannot connect the pixel because the Shopify token is unavailable.');
        }

        return new ShopifyGraphqlClient($shop, $token, $apiVersion !== '' ? $apiVersion : '2026-01');
    }

    protected function queryWebPixel(ShopifyGraphqlClient $client): ?array
    {
        $data = $client->query(<<<'GRAPHQL'
query BackstageWebPixelStatus {
  webPixel {
    id
    settings
  }
}
GRAPHQL);

        $pixel = $data['webPixel'] ?? null;

        return is_array($pixel) ? $pixel : null;
    }

    /**
     * @param  array<string,mixed>  $settings
     */
    protected function createWebPixel(ShopifyGraphqlClient $client, array $settings): array
    {
        $data = $client->query(<<<'GRAPHQL'
mutation BackstageCreateWebPixel($webPixel: WebPixelInput!) {
  webPixelCreate(webPixel: $webPixel) {
    userErrors {
      field
      message
      code
    }
    webPixel {
      id
      settings
    }
  }
}
GRAPHQL, [
            'webPixel' => [
                'settings' => $settings,
            ],
        ]);

        return $this->assertMutationSuccess((array) ($data['webPixelCreate'] ?? []), 'connect');
    }

    /**
     * @param  array<string,mixed>  $settings
     */
    protected function updateWebPixel(ShopifyGraphqlClient $client, string $id, array $settings): array
    {
        if (trim($id) === '') {
            throw new RuntimeException('Shopify returned an invalid web pixel identifier.');
        }

        $data = $client->query(<<<'GRAPHQL'
mutation BackstageUpdateWebPixel($id: ID!, $webPixel: WebPixelInput!) {
  webPixelUpdate(id: $id, webPixel: $webPixel) {
    userErrors {
      field
      message
      code
    }
    webPixel {
      id
      settings
    }
  }
}
GRAPHQL, [
            'id' => $id,
            'webPixel' => [
                'settings' => $settings,
            ],
        ]);

        return $this->assertMutationSuccess((array) ($data['webPixelUpdate'] ?? []), 'update');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function assertMutationSuccess(array $payload, string $verb): array
    {
        $errors = array_values(array_filter((array) ($payload['userErrors'] ?? []), 'is_array'));
        if ($errors !== []) {
            $messages = [];
            foreach ($errors as $error) {
                $message = trim((string) ($error['message'] ?? 'Shopify returned an unknown pixel error.'));
                $code = trim((string) ($error['code'] ?? ''));
                $messages[] = $code !== '' ? "{$message} ({$code})" : $message;
            }

            throw new RuntimeException('Shopify could not '.$verb.' the pixel: '.implode(' | ', $messages));
        }

        $pixel = $payload['webPixel'] ?? null;
        if (! is_array($pixel)) {
            throw new RuntimeException('Shopify did not return a web pixel record after the mutation.');
        }

        return $pixel;
    }

    /**
     * @param  array<string,mixed>  $store
     * @param  array<string,mixed>  $pixel
     * @return array<string,mixed>
     */
    protected function buildConnectedStatus(array $store, array $pixel, string $message): array
    {
        return [
            'ok' => true,
            'status' => 'connected',
            'connected' => true,
            'label' => 'Connected',
            'message' => $message,
            'can_connect' => false,
            'pixel_id' => (string) ($pixel['id'] ?? ''),
            'settings' => $this->decodeSettings((string) ($pixel['settings'] ?? '')),
            'store_key' => (string) ($store['key'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function pixelSettings(): array
    {
        return [
            'appProxyBase' => '/apps/forestry',
        ];
    }

    /**
     * @param  string|array<int|string,mixed>|null  $scopes
     * @return array<int,string>
     */
    protected function normalizeScopes(string|array|null $scopes): array
    {
        if (is_array($scopes)) {
            $values = $scopes;
        } else {
            $values = explode(',', (string) $scopes);
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($scope): string => strtolower(trim((string) $scope)),
            $values
        ))));
    }

    /**
     * @param  array<string,mixed>  $store
     * @return array<int,string>
     */
    protected function resolveGrantedScopes(array $store): array
    {
        try {
            $liveScopes = $this->queryGrantedScopes($this->client($store));
            if ($liveScopes !== []) {
                return $liveScopes;
            }
        } catch (Throwable) {
            // Fall back to the last stored scope snapshot when Shopify scope lookup fails.
        }

        return $this->normalizeScopes($store['scopes'] ?? []);
    }

    /**
     * @return array<int,string>
     */
    protected function queryGrantedScopes(ShopifyGraphqlClient $client): array
    {
        $data = $client->query(<<<'GRAPHQL'
query BackstageGrantedScopes {
  currentAppInstallation {
    accessScopes {
      handle
    }
  }
}
GRAPHQL);

        $rows = $data['currentAppInstallation']['accessScopes'] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        $handles = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $handle = trim(strtolower((string) ($row['handle'] ?? '')));
            if ($handle !== '') {
                $handles[] = $handle;
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * @return array<string,mixed>
     */
    protected function decodeSettings(string $settings): array
    {
        if (trim($settings) === '') {
            return [];
        }

        $decoded = json_decode($settings, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function cacheKey(string $storeKey): string
    {
        return 'shopify:web-pixel-status:'.strtolower(trim($storeKey));
    }
}
