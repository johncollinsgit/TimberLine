<?php

namespace App\Services\Shopify;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\RequestException;
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

    protected const STATUS_SCOPE_VERIFICATION_PENDING = 'scope_verification_pending';

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
            return $this->buildErrorStatus($error, 'Shopify rejected the pixel connection request.');
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
            return $this->buildErrorStatus($error, 'Could not verify Shopify Customer Events pixel status.');
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
        $scopeSnapshot = $this->resolveGrantedScopes($store);
        $grantedScopes = (array) ($scopeSnapshot['scopes'] ?? []);
        $missingScopes = array_values(array_diff($this->requiredScopes, $grantedScopes));
        $verified = (bool) ($scopeSnapshot['verified'] ?? false);
        $source = (string) ($scopeSnapshot['source'] ?? 'stored');
        $lookupError = $this->nullableString($scopeSnapshot['lookup_error'] ?? null);

        if ($missingScopes !== []) {
            if (! $verified) {
                return [
                    'ok' => true,
                    'status' => self::STATUS_SCOPE_VERIFICATION_PENDING,
                    'connected' => false,
                    'label' => 'Verification pending',
                    'can_connect' => true,
                    'granted_scopes' => $grantedScopes,
                    'missing_scopes' => $missingScopes,
                    'scope_source' => $source,
                    'scope_verified' => false,
                    'scope_lookup_error' => $lookupError,
                    'message' => 'Backstage could not confirm the newly granted Shopify pixel scopes yet. If you already reauthorized, try Connect Shopify Pixel now.',
                ];
            }

            return [
                'ok' => true,
                'status' => 'missing_scopes',
                'connected' => false,
                'label' => 'Verification pending',
                'can_connect' => true,
                'granted_scopes' => $grantedScopes,
                'missing_scopes' => $missingScopes,
                'scope_source' => $source,
                'scope_verified' => true,
                'scope_lookup_error' => $lookupError,
                'message' => 'Shopify still reports these pixel scopes as missing on the installed token: '.implode(', ', $missingScopes).'. If you already reauthorized, try Connect Shopify Pixel now and Backstage will verify it live.',
            ];
        }

        return [
            'ok' => true,
            'status' => 'scopes_ready',
            'connected' => false,
            'granted_scopes' => $grantedScopes,
            'scope_source' => $source,
            'scope_verified' => $verified,
            'scope_lookup_error' => $lookupError,
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
     * @return array{scopes:array<int,string>,source:string,verified:bool,lookup_error:?string}
     */
    protected function resolveGrantedScopes(array $store): array
    {
        try {
            $liveScopes = $this->queryGrantedScopes($this->client($store));
            if ($liveScopes !== []) {
                return [
                    'scopes' => $liveScopes,
                    'source' => 'live',
                    'verified' => true,
                    'lookup_error' => null,
                ];
            }
        } catch (Throwable $error) {
            return [
                'scopes' => $this->normalizeScopes($store['scopes'] ?? []),
                'source' => 'stored_fallback',
                'verified' => false,
                'lookup_error' => $error->getMessage(),
            ];
        }

        return [
            'scopes' => $this->normalizeScopes($store['scopes'] ?? []),
            'source' => 'stored_snapshot',
            'verified' => false,
            'lookup_error' => null,
        ];
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

    /**
     * @param  array<string,mixed>  $storeContext
     */
    public function flushStatusCache(array $storeContext): void
    {
        $storeKey = strtolower(trim((string) ($storeContext['key'] ?? '')));
        if ($storeKey === '') {
            return;
        }

        Cache::forget($this->cacheKey($storeKey));
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function buildErrorStatus(Throwable $error, string $fallbackMessage): array
    {
        $message = $error->getMessage() !== '' ? $error->getMessage() : $fallbackMessage;

        if ($this->isInvalidTokenError($error)) {
            return [
                'ok' => false,
                'status' => 'reauthorize_required',
                'connected' => false,
                'label' => 'Reconnect Shopify required',
                'can_connect' => false,
                'message' => 'Backstage is still using an old Shopify Admin token for this store. Reconnect Shopify so the new token and scopes are stored, then connect the pixel again.',
                'debug_message' => $message,
            ];
        }

        return [
            'ok' => false,
            'status' => 'shopify_error',
            'connected' => false,
            'label' => 'Unknown',
            'can_connect' => true,
            'message' => $message,
        ];
    }

    protected function isInvalidTokenError(Throwable $error): bool
    {
        if ($error instanceof RequestException) {
            $response = $error->response;
            if ($response !== null && $response->status() === 401) {
                $body = strtolower((string) $response->body());

                return str_contains($body, 'invalid api key')
                    || str_contains($body, 'access token')
                    || str_contains($body, 'unrecognized login')
                    || str_contains($body, 'wrong password');
            }
        }

        $message = strtolower($error->getMessage());

        return str_contains($message, 'http request returned status code 401')
            || str_contains($message, 'invalid api key')
            || str_contains($message, 'access token')
            || str_contains($message, 'unrecognized login')
            || str_contains($message, 'wrong password');
    }
}
