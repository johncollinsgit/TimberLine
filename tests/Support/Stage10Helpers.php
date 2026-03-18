<?php

declare(strict_types=1);

/**
 * @param array<string,mixed> $query
 */
function stage10CanonicalQuery(array $query): string
{
    if ($query === []) {
        return '';
    }

    ksort($query);
    $parts = [];
    foreach ($query as $key => $value) {
        if (is_array($value)) {
            $value = stage10CanonicalQuery($value);
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
 * @param array<string,mixed> $query
 * @return array<string,string>
 */
function stage10SignedHeaders(
    string $method,
    string $path,
    array $query,
    string $body,
    string $secret,
    ?int $timestamp = null
): array {
    $timestamp = $timestamp ?? time();
    $canonicalQuery = stage10CanonicalQuery($query);
    $bodyHash = hash('sha256', $body);
    $payload = implode("\n", [$timestamp, strtoupper($method), $path, $canonicalQuery, $bodyHash]);
    $signature = hash_hmac('sha256', $payload, $secret);

    return [
        'X-Marketing-Timestamp' => (string) $timestamp,
        'X-Marketing-Signature' => $signature,
    ];
}

/**
 * @param array<string,mixed> $query
 * @return array<string,mixed>
 */
function stage10AppProxySignedQuery(array $params, string $secret): array
{
    $canonical = stage10AppProxyCanonical($params);
    $signature = hash_hmac('sha256', $canonical, $secret);

    return [...$params, 'signature' => $signature];
}

/**
 * @param array<string,mixed> $params
 */
function stage10AppProxyCanonical(array $params): string
{
    if ($params === []) {
        return '';
    }

    ksort($params);
    $parts = [];
    foreach ($params as $key => $value) {
        if (is_array($value)) {
            $value = stage10AppProxyCanonical($value);
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
