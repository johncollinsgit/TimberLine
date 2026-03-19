<?php

namespace App\Services\Marketing;

use Illuminate\Http\Request;

class MarketingStorefrontRequestVerifier
{
    /**
     * @return array{ok:bool,mode:?string,reason:?string}
     */
    public function verify(Request $request): array
    {
        $proxy = $this->verifyAppProxySignature($request);
        if ($proxy['ok']) {
            return $proxy;
        }

        $signed = $this->verifyHmacSignature($request);
        if ($signed['ok']) {
            return $signed;
        }

        return [
            'ok' => false,
            'mode' => null,
            'reason' => $signed['reason'] ?? $proxy['reason'] ?? 'invalid_signature',
        ];
    }

    /**
     * @return array{ok:bool,mode:?string,reason:?string}
     */
    protected function verifyAppProxySignature(Request $request): array
    {
        if (! (bool) config('marketing.shopify.app_proxy_enabled', false)) {
            return ['ok' => false, 'mode' => null, 'reason' => 'app_proxy_disabled'];
        }

        $secret = $this->appProxySecret();
        if ($secret === '') {
            return ['ok' => false, 'mode' => null, 'reason' => 'app_proxy_secret_not_configured'];
        }

        $provided = trim((string) $request->query('signature', ''));
        if ($provided === '') {
            return ['ok' => false, 'mode' => null, 'reason' => 'missing_app_proxy_signature'];
        }

        $timestamp = trim((string) $request->query('timestamp', ''));
        if ($timestamp !== '') {
            if (! ctype_digit($timestamp)) {
                return ['ok' => false, 'mode' => null, 'reason' => 'invalid_app_proxy_timestamp'];
            }

            $ttl = max(30, (int) config('marketing.shopify.app_proxy_ttl_seconds', 900));
            if (abs(time() - (int) $timestamp) > $ttl) {
                return ['ok' => false, 'mode' => null, 'reason' => 'signature_expired'];
            }
        }

        $params = $request->query();
        unset($params['signature']);

        $base = $this->appProxyCanonicalString($params);
        $expected = hash_hmac('sha256', $base, $secret);

        if (! hash_equals(strtolower($expected), strtolower($provided))) {
            // Secondary tolerance: treat query as canonicalized URL-encoded payload.
            $fallbackBase = $this->canonicalize($params);
            $fallbackExpected = hash_hmac('sha256', $fallbackBase, $secret);
            if (! hash_equals(strtolower($fallbackExpected), strtolower($provided))) {
                return ['ok' => false, 'mode' => null, 'reason' => 'signature_mismatch'];
            }
        }

        return ['ok' => true, 'mode' => 'app_proxy', 'reason' => null];
    }

    /**
     * @return array{ok:bool,mode:?string,reason:?string}
     */
    protected function verifyHmacSignature(Request $request): array
    {
        $secret = $this->signingSecret();
        if ($secret === '') {
            return ['ok' => false, 'mode' => null, 'reason' => 'signing_secret_not_configured'];
        }

        $timestamp = trim((string) $request->header('X-Marketing-Timestamp', ''));
        $provided = trim((string) $request->header('X-Marketing-Signature', ''));
        if ($timestamp === '' || $provided === '') {
            return ['ok' => false, 'mode' => null, 'reason' => 'missing_signature_headers'];
        }

        if (! ctype_digit($timestamp)) {
            return ['ok' => false, 'mode' => null, 'reason' => 'invalid_signature_timestamp'];
        }

        $now = time();
        $ttl = max(30, (int) config('marketing.shopify.signature_ttl_seconds', 300));
        if (abs($now - (int) $timestamp) > $ttl) {
            return ['ok' => false, 'mode' => null, 'reason' => 'signature_expired'];
        }

        $provided = str_starts_with($provided, 'sha256=')
            ? substr($provided, 7)
            : $provided;
        $provided = strtolower(trim($provided));

        $expectedSignatures = $this->expectedSignatures($request, $timestamp, $secret);
        $matched = collect($expectedSignatures)->contains(
            fn (string $expected): bool => hash_equals($expected, $provided)
        );

        if (! $matched) {
            return ['ok' => false, 'mode' => null, 'reason' => 'signature_mismatch'];
        }

        return ['ok' => true, 'mode' => 'hmac', 'reason' => null];
    }

    protected function signaturePayload(Request $request, string $timestamp): string
    {
        $method = strtoupper($request->getMethod());
        $path = '/' . ltrim((string) $request->path(), '/');

        $query = $request->query();
        unset($query['token'], $query['signature'], $query['hmac']);
        $queryString = $this->canonicalize($query);

        $bodyHash = hash('sha256', (string) $request->getContent());

        return implode("\n", [$timestamp, $method, $path, $queryString, $bodyHash]);
    }

    /**
     * @return array<int,string>
     */
    protected function expectedSignatures(Request $request, string $timestamp, string $secret): array
    {
        $method = strtoupper($request->getMethod());

        $paths = array_values(array_unique(array_filter([
            '/' . ltrim((string) $request->path(), '/'),
            (string) $request->getPathInfo(),
            (string) parse_url((string) $request->fullUrl(), PHP_URL_PATH),
        ])));

        $query = $request->query();
        unset($query['token'], $query['signature'], $query['hmac']);
        $queryString = $this->canonicalize($query);

        $rawBody = (string) $request->getContent();
        $bodyCandidates = array_values(array_unique([
            $rawBody,
            trim($rawBody),
            '',
            '{}',
        ]));

        $signatures = [];
        foreach ($paths as $path) {
            foreach ($bodyCandidates as $body) {
                $payload = implode("\n", [$timestamp, $method, $path, $queryString, hash('sha256', $body)]);
                $signatures[] = hash_hmac('sha256', $payload, $secret);
            }
        }

        return array_values(array_unique($signatures));
    }

    /**
     * @param array<string,mixed> $payload
     */
    protected function canonicalize(array $payload): string
    {
        if ($payload === []) {
            return '';
        }

        ksort($payload);
        $parts = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $value = $this->canonicalize($value);
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif ($value === null) {
                $value = '';
            } else {
                $value = (string) $value;
            }

            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $parts);
    }

    /**
     * @param array<string,mixed> $payload
     */
    protected function appProxyCanonicalString(array $payload): string
    {
        if ($payload === []) {
            return '';
        }

        ksort($payload);
        $parts = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $value = $this->appProxyCanonicalString($value);
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif ($value === null) {
                $value = '';
            } else {
                $value = (string) $value;
            }

            $parts[] = (string) $key . '=' . (string) $value;
        }

        return implode('', $parts);
    }

    protected function appProxySecret(): string
    {
        $marketingSecret = trim((string) config('marketing.shopify.app_proxy_secret', ''));
        if ($marketingSecret !== '') {
            return $marketingSecret;
        }

        return $this->signingSecret();
    }

    protected function signingSecret(): string
    {
        $marketingSecret = trim((string) config('marketing.shopify.signing_secret', ''));
        if ($marketingSecret !== '') {
            return $marketingSecret;
        }

        return trim((string) (
            config('services.shopify.stores.retail.client_secret')
            ?? config('services.shopify.retail.client_secret')
            ?? ''
        ));
    }
}
