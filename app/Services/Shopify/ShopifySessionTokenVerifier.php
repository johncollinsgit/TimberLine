<?php

namespace App\Services\Shopify;

use Illuminate\Support\Carbon;

class ShopifySessionTokenVerifier
{
    /**
     * @return array{
     *   ok:bool,
     *   status:string,
     *   shop_domain:?string,
     *   host:?string,
     *   signed_query:array<string,mixed>,
     *   auth_source:string,
     *   store?:array<string,mixed>,
     *   shopify_admin_user_id?:?string,
     *   shopify_admin_session_id?:?string
     * }
     */
    public function verify(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return $this->failure('missing_session_token');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return $this->failure('invalid_session_token');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeJwtSegment($encodedHeader);
        $payload = $this->decodeJwtSegment($encodedPayload);

        if (! is_array($header) || ! is_array($payload)) {
            return $this->failure('invalid_session_token');
        }

        if (strtoupper(trim((string) ($header['alg'] ?? ''))) !== 'HS256') {
            return $this->failure('invalid_session_token');
        }

        $shopDomain = $this->normalizeShopDomain((string) ($payload['dest'] ?? ''));
        if ($shopDomain === '') {
            return $this->failure('missing_shop');
        }

        $store = ShopifyStores::findByShopDomain($shopDomain);
        if (! is_array($store)) {
            return $this->failure('unknown_shop', $shopDomain);
        }

        $secret = trim((string) ($store['secret'] ?? ''));
        $clientId = trim((string) ($store['client_id'] ?? ''));

        if ($secret === '' || $clientId === '') {
            return $this->failure('invalid_session_token', $shopDomain);
        }

        if (trim((string) ($payload['aud'] ?? '')) !== $clientId) {
            return $this->failure('invalid_session_token', $shopDomain);
        }

        if (! $this->hasValidIssuer((string) ($payload['iss'] ?? ''), $shopDomain)) {
            return $this->failure('invalid_session_token', $shopDomain);
        }

        $timestampStatus = $this->validateTimestamps($payload);
        if ($timestampStatus !== 'ok') {
            return $this->failure($timestampStatus, $shopDomain);
        }

        $computedSignature = $this->base64UrlEncode(hash_hmac(
            'sha256',
            $encodedHeader . '.' . $encodedPayload,
            $secret,
            true
        ));

        if (! hash_equals($computedSignature, $encodedSignature)) {
            return $this->failure('invalid_session_token', $shopDomain);
        }

        return [
            'ok' => true,
            'status' => 'ok',
            'shop_domain' => $shopDomain,
            'host' => null,
            'signed_query' => [],
            'auth_source' => 'session_token',
            'store' => $store,
            'shopify_admin_user_id' => $this->normalizedNullableString($payload['sub'] ?? null),
            'shopify_admin_session_id' => $this->normalizedNullableString($payload['sid'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function validateTimestamps(array $payload): string
    {
        $now = Carbon::now()->timestamp;
        $clockSkew = 5;

        $exp = $this->timestampClaim($payload, 'exp');
        if ($exp === null || $exp <= ($now - $clockSkew)) {
            return 'expired_session_token';
        }

        $nbf = $this->timestampClaim($payload, 'nbf');
        if ($nbf === null || $nbf > ($now + $clockSkew)) {
            return 'invalid_session_token';
        }

        $iat = $this->timestampClaim($payload, 'iat');
        if ($iat !== null && $iat > ($now + $clockSkew)) {
            return 'invalid_session_token';
        }

        return 'ok';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function timestampClaim(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;

            return $timestamp > 0 ? $timestamp : null;
        }

        return null;
    }

    protected function hasValidIssuer(string $issuer, string $shopDomain): bool
    {
        $issuerHost = $this->normalizeShopDomain($issuer);

        return $issuerHost !== ''
            && in_array($issuerHost, [$shopDomain, 'admin.shopify.com'], true);
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function decodeJwtSegment(string $segment): ?array
    {
        $decoded = $this->base64UrlDecode($segment);
        if ($decoded === null) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    protected function base64UrlDecode(string $value): ?string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        return is_string($decoded) ? $decoded : null;
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function normalizeShopDomain(string $value): string
    {
        $candidate = trim($value);
        if ($candidate === '') {
            return '';
        }

        if (! str_contains($candidate, '://')) {
            $candidate = 'https://' . $candidate;
        }

        $host = (string) parse_url($candidate, PHP_URL_HOST);
        if ($host === '') {
            return '';
        }

        return strtolower(rtrim($host, '/'));
    }

    protected function normalizedNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function failure(string $status, ?string $shopDomain = null): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'shop_domain' => $shopDomain,
            'host' => null,
            'signed_query' => [],
            'auth_source' => 'session_token',
        ];
    }
}
