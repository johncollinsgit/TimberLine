<?php

namespace App\Support\Diagnostics;

use Illuminate\Http\Request;

class ShopifyEmbeddedDeepProfile
{
    protected const ATTRIBUTE_KEY = '_shopify_embedded_deep_profile';

    public static function enabled(?Request $request = null): bool
    {
        if (! (bool) config('shopify_embedded.deep_profile_enabled', false)) {
            return false;
        }

        $request ??= self::request();
        if (! $request instanceof Request) {
            return false;
        }

        $routeName = strtolower(trim((string) ($request->route()?->getName() ?? '')));
        if ($routeName !== '' && str_starts_with($routeName, 'shopify.app')) {
            return true;
        }

        return $request->is('shopify/app*');
    }

    /**
     * @template T
     *
     * @param  callable():T  $resolver
     * @return T
     */
    public static function time(string $segment, callable $resolver)
    {
        if (! self::enabled()) {
            return $resolver();
        }

        $startedAt = microtime(true);

        try {
            return $resolver();
        } finally {
            self::addTiming($segment, round((microtime(true) - $startedAt) * 1000, 2));
        }
    }

    public static function addExternalHttp(array $payload): void
    {
        if (! self::enabled()) {
            return;
        }

        self::withPayload(function (array &$state) use ($payload): void {
            $entries = is_array($state['external_http'] ?? null)
                ? $state['external_http']
                : [];

            $entries[] = array_merge([
                'service' => 'unknown',
                'duration_ms' => null,
                'status' => null,
                'attempts' => 1,
            ], $payload);

            if (count($entries) > 25) {
                $entries = array_slice($entries, -25);
            }

            $state['external_http'] = $entries;
        });
    }

    public static function addCacheProbe(
        string $scope,
        bool $hit,
        string $key,
        ?int $tenantId = null,
        ?int $ttlSeconds = null
    ): void {
        if (! self::enabled()) {
            return;
        }

        self::withPayload(function (array &$state) use ($scope, $hit, $key, $tenantId, $ttlSeconds): void {
            $cache = is_array($state['cache'] ?? null)
                ? $state['cache']
                : ['hits' => 0, 'misses' => 0, 'events' => []];

            $bucket = $hit ? 'hits' : 'misses';
            $cache[$bucket] = (int) ($cache[$bucket] ?? 0) + 1;

            $events = is_array($cache['events'] ?? null) ? $cache['events'] : [];
            $events[] = [
                'scope' => trim($scope) !== '' ? $scope : 'unknown',
                'hit' => $hit,
                'key' => $key,
                'tenant_id' => $tenantId,
                'ttl_seconds' => $ttlSeconds,
            ];

            if (count($events) > 25) {
                $events = array_slice($events, -25);
            }

            $cache['events'] = $events;
            $state['cache'] = $cache;
        });
    }

    /**
     * @return array{
     *   timings:array<string,float>,
     *   external_http:array<int,array<string,mixed>>,
     *   cache:array{hits:int,misses:int,events:array<int,array<string,mixed>>}
     * }
     */
    public static function snapshot(?Request $request = null): array
    {
        $request ??= self::request();
        if (! $request instanceof Request) {
            return self::defaultPayload();
        }

        $payload = $request->attributes->get(self::ATTRIBUTE_KEY);

        if (! is_array($payload)) {
            return self::defaultPayload();
        }

        $timings = [];
        foreach ((array) ($payload['timings'] ?? []) as $segment => $duration) {
            if (! is_string($segment) || trim($segment) === '' || ! is_numeric($duration)) {
                continue;
            }

            $timings[strtolower(trim($segment))] = round((float) $duration, 2);
        }

        $cachePayload = is_array($payload['cache'] ?? null) ? $payload['cache'] : [];

        return [
            'timings' => $timings,
            'external_http' => array_values((array) ($payload['external_http'] ?? [])),
            'cache' => [
                'hits' => (int) ($cachePayload['hits'] ?? 0),
                'misses' => (int) ($cachePayload['misses'] ?? 0),
                'events' => array_values((array) ($cachePayload['events'] ?? [])),
            ],
        ];
    }

    public static function clear(?Request $request = null): void
    {
        $request ??= self::request();
        if (! $request instanceof Request) {
            return;
        }

        $request->attributes->remove(self::ATTRIBUTE_KEY);
    }

    protected static function addTiming(string $segment, float $durationMs): void
    {
        $normalized = strtolower(trim($segment));
        if ($normalized === '') {
            return;
        }

        self::withPayload(function (array &$state) use ($normalized, $durationMs): void {
            $timings = is_array($state['timings'] ?? null) ? $state['timings'] : [];
            $timings[$normalized] = round(((float) ($timings[$normalized] ?? 0.0)) + $durationMs, 2);
            $state['timings'] = $timings;
        });
    }

    protected static function withPayload(callable $mutator): void
    {
        $request = self::request();
        if (! $request instanceof Request) {
            return;
        }

        $payload = $request->attributes->get(self::ATTRIBUTE_KEY);
        if (! is_array($payload)) {
            $payload = self::defaultPayload();
        }

        $mutator($payload);

        $request->attributes->set(self::ATTRIBUTE_KEY, $payload);
    }

    protected static function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }

    /**
     * @return array{
     *   timings:array<string,float>,
     *   external_http:array<int,array<string,mixed>>,
     *   cache:array{hits:int,misses:int,events:array<int,array<string,mixed>>}
     * }
     */
    protected static function defaultPayload(): array
    {
        return [
            'timings' => [],
            'external_http' => [],
            'cache' => [
                'hits' => 0,
                'misses' => 0,
                'events' => [],
            ],
        ];
    }
}
