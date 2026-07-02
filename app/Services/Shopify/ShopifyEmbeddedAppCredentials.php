<?php

namespace App\Services\Shopify;

class ShopifyEmbeddedAppCredentials
{
    /**
     * @param  array<string,mixed>  $store
     * @return array<int,array{client_id:string,secret:string,source:string}>
     */
    public function credentialsForStore(array $store): array
    {
        $storeKey = strtolower(trim((string) ($store['key'] ?? '')));
        $storeClientId = $this->normalizeString($store['client_id'] ?? null);
        $storeSecret = $this->normalizeString($store['secret'] ?? null);

        $candidates = [];

        $embeddedClientId = $this->embeddedClientIdForStore($storeKey)
            ?? $this->tomlClientIdForStore($storeKey);
        $embeddedSecret = $this->embeddedSecretForStore($storeKey);

        if ($embeddedClientId !== null) {
            $resolvedEmbeddedSecret = $embeddedSecret
                ?? ($embeddedClientId === $storeClientId ? $storeSecret : null);

            if ($resolvedEmbeddedSecret !== null) {
                $candidates[] = [
                    'client_id' => $embeddedClientId,
                    'secret' => $resolvedEmbeddedSecret,
                    'source' => 'embedded_app',
                ];
            }
        }

        if ($storeClientId !== null && $storeSecret !== null) {
            $candidates[] = [
                'client_id' => $storeClientId,
                'secret' => $storeSecret,
                'source' => 'store_config',
            ];
        }

        return $this->uniqueCredentials($candidates);
    }

    /**
     * @param  array<string,mixed>  $store
     */
    public function clientIdForStore(array $store): ?string
    {
        $credentials = $this->credentialsForStore($store);

        return $credentials[0]['client_id'] ?? null;
    }

    protected function embeddedClientIdForStore(string $storeKey): ?string
    {
        if ($storeKey === '') {
            return null;
        }

        return $this->normalizeString(config("services.shopify.stores.{$storeKey}.embedded_client_id"));
    }

    protected function embeddedSecretForStore(string $storeKey): ?string
    {
        if ($storeKey === '') {
            return null;
        }

        return $this->normalizeString(config("services.shopify.stores.{$storeKey}.embedded_client_secret"));
    }

    protected function tomlClientIdForStore(string $storeKey): ?string
    {
        if ($storeKey !== 'wholesale') {
            return null;
        }

        $configPath = base_path('shopify.app.wholesale.toml');
        if (! is_file($configPath)) {
            return null;
        }

        $contents = @file_get_contents($configPath);
        if (! is_string($contents)) {
            return null;
        }

        if (preg_match('/^\s*client_id\s*=\s*"([^"]+)"/m', $contents, $matches) !== 1) {
            return null;
        }

        return $this->normalizeString($matches[1] ?? null);
    }

    /**
     * @param  array<int,array{client_id:string,secret:string,source:string}>  $credentials
     * @return array<int,array{client_id:string,secret:string,source:string}>
     */
    protected function uniqueCredentials(array $credentials): array
    {
        $seen = [];
        $unique = [];

        foreach ($credentials as $credential) {
            $key = $credential['client_id']."\n".$credential['secret'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $credential;
        }

        return $unique;
    }

    protected function normalizeString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
