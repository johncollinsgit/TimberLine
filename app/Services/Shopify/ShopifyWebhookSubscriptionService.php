<?php

namespace App\Services\Shopify;

use App\Services\Marketing\IntegrationHealthEventRecorder;
use App\Support\Tenancy\TenantHostBuilder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShopifyWebhookSubscriptionService
{
    public function __construct(
        protected IntegrationHealthEventRecorder $healthEventRecorder,
        protected TenantHostBuilder $hostBuilder,
    ) {
    }

    /**
     * @param  array<string,mixed>  $store
     * @return array<string,mixed>
     */
    public function verifyStore(array $store, bool $repair = false): array
    {
        $storeKey = $this->nullableString($store['key'] ?? null);
        $shopDomain = $this->nullableString($store['shop'] ?? null);
        $token = $this->nullableString($store['token'] ?? null);
        $apiVersion = $this->nullableString($store['api_version'] ?? null) ?: (string) config('services.shopify.api_version', '2026-01');

        if ($storeKey === null) {
            return $this->result('failed', $repair, [
                'store_key' => null,
                'error' => 'store_key_missing',
                'topics' => [],
            ]);
        }

        if ($shopDomain === null || $token === null) {
            $this->recordHealthEvent(
                storeKey: $storeKey,
                tenantId: $this->positiveInt($store['tenant_id'] ?? null),
                eventType: 'shopify_auth_invalid',
                severity: 'error',
                context: [
                    'reason' => 'store_credentials_missing',
                    'repair_mode' => $repair,
                ]
            );

            return $this->result('failed', $repair, [
                'store_key' => $storeKey,
                'shop' => $shopDomain,
                'error' => 'store_credentials_missing',
                'topics' => [],
            ]);
        }

        $required = $this->requiredTopicsWithCallbacks();
        if ($required === []) {
            return $this->result('ok', $repair, [
                'store_key' => $storeKey,
                'shop' => $shopDomain,
                'topics' => [],
                'required_count' => 0,
            ]);
        }

        try {
            $existing = $this->listWebhooks($shopDomain, $token, $apiVersion);
        } catch (\Throwable $e) {
            Log::error('shopify webhook verification failed while listing subscriptions', [
                'store_key' => $storeKey,
                'shop' => $shopDomain,
                'error' => $e->getMessage(),
            ]);

            $this->recordHealthEvent(
                storeKey: $storeKey,
                tenantId: $this->positiveInt($store['tenant_id'] ?? null),
                eventType: 'webhook_verification_failed',
                severity: 'error',
                context: [
                    'reason' => 'list_failed',
                    'repair_mode' => $repair,
                    'error_message' => $e->getMessage(),
                ]
            );

            return $this->result('failed', $repair, [
                'store_key' => $storeKey,
                'shop' => $shopDomain,
                'error' => 'list_failed',
                'error_message' => $e->getMessage(),
                'topics' => [],
                'required_count' => count($required),
            ]);
        }

        $byTopic = [];
        foreach ($existing as $row) {
            $topic = strtolower(trim((string) ($row['topic'] ?? '')));
            if ($topic === '') {
                continue;
            }
            $byTopic[$topic] ??= [];
            $byTopic[$topic][] = $row;
        }

        $topicResults = [];
        $counts = [
            'ok' => 0,
            'missing' => 0,
            'mismatch' => 0,
            'created' => 0,
            'repaired' => 0,
            'failed' => 0,
            'duplicates' => 0,
        ];

        foreach ($required as $topic => $callbackUrl) {
            $desired = $this->normalizeAddress($callbackUrl);
            $rows = $byTopic[$topic] ?? [];
            $matching = array_values(array_filter($rows, fn (array $row): bool => $this->normalizeAddress($row['address'] ?? null) === $desired));

            if ($matching !== []) {
                $counts['ok']++;
                $duplicates = max(0, count($matching) - 1);
                $counts['duplicates'] += $duplicates;
                $topicResults[] = [
                    'topic' => $topic,
                    'status' => 'ok',
                    'callback' => $desired,
                    'existing_callback' => $matching[0]['address'] ?? null,
                    'webhook_id' => isset($matching[0]['id']) ? (string) $matching[0]['id'] : null,
                    'duplicates' => $duplicates,
                ];
                continue;
            }

            if ($rows === []) {
                if (! $repair) {
                    $counts['missing']++;
                    $this->recordHealthEvent(
                        storeKey: $storeKey,
                        tenantId: $this->positiveInt($store['tenant_id'] ?? null),
                        eventType: 'webhook_subscription_missing',
                        severity: 'warning',
                        context: [
                            'topic' => $topic,
                            'callback' => $desired,
                        ]
                    );
                    $topicResults[] = [
                        'topic' => $topic,
                        'status' => 'missing',
                        'callback' => $desired,
                    ];
                    continue;
                }

                try {
                    $created = $this->createWebhook($shopDomain, $token, $apiVersion, $topic, $desired);
                    $counts['created']++;
                    $this->resolveHealthEvents(
                        storeKey: $storeKey,
                        tenantId: $this->positiveInt($store['tenant_id'] ?? null),
                        eventTypes: ['webhook_subscription_missing'],
                        dedupeKey: $this->topicDedupeKey($storeKey, 'webhook_subscription_missing', $topic)
                    );
                    $topicResults[] = [
                        'topic' => $topic,
                        'status' => 'created',
                        'callback' => $desired,
                        'webhook_id' => isset($created['id']) ? (string) $created['id'] : null,
                    ];
                } catch (\Throwable $e) {
                    $counts['failed']++;
                    Log::error('shopify webhook create failed', [
                        'store_key' => $storeKey,
                        'shop' => $shopDomain,
                        'topic' => $topic,
                        'callback' => $desired,
                        'error' => $e->getMessage(),
                    ]);
                    $this->recordHealthEvent(
                        storeKey: $storeKey,
                        tenantId: $this->positiveInt($store['tenant_id'] ?? null),
                        eventType: 'webhook_verification_failed',
                        severity: 'error',
                        context: [
                            'topic' => $topic,
                            'operation' => 'create',
                            'callback' => $desired,
                            'error_message' => $e->getMessage(),
                        ]
                    );
                    $topicResults[] = [
                        'topic' => $topic,
                        'status' => 'failed_create',
                        'callback' => $desired,
                        'error' => $e->getMessage(),
                    ];
                }

                continue;
            }

            $existingCandidate = $rows[0];
            if (! $repair) {
                $counts['mismatch']++;
                $this->recordHealthEvent(
                    storeKey: $storeKey,
                    tenantId: $this->positiveInt($store['tenant_id'] ?? null),
                    eventType: 'webhook_subscription_mismatch',
                    severity: 'warning',
                    context: [
                        'topic' => $topic,
                        'callback' => $desired,
                        'existing_callback' => $existingCandidate['address'] ?? null,
                    ]
                );
                $topicResults[] = [
                    'topic' => $topic,
                    'status' => 'mismatch',
                    'callback' => $desired,
                    'existing_callback' => $existingCandidate['address'] ?? null,
                    'webhook_id' => isset($existingCandidate['id']) ? (string) $existingCandidate['id'] : null,
                ];
                continue;
            }

            try {
                $webhookId = $this->requiredWebhookId($existingCandidate, $topic);
                $updated = $this->updateWebhook($shopDomain, $token, $apiVersion, $webhookId, $topic, $desired);
                $counts['repaired']++;
                $this->resolveHealthEvents(
                    storeKey: $storeKey,
                    tenantId: $this->positiveInt($store['tenant_id'] ?? null),
                    eventTypes: ['webhook_subscription_mismatch'],
                    dedupeKey: $this->topicDedupeKey($storeKey, 'webhook_subscription_mismatch', $topic)
                );
                $topicResults[] = [
                    'topic' => $topic,
                    'status' => 'repaired',
                    'callback' => $desired,
                    'previous_callback' => $existingCandidate['address'] ?? null,
                    'webhook_id' => isset($updated['id']) ? (string) $updated['id'] : $webhookId,
                ];
            } catch (\Throwable $e) {
                $counts['failed']++;
                Log::error('shopify webhook repair failed', [
                    'store_key' => $storeKey,
                    'shop' => $shopDomain,
                    'topic' => $topic,
                    'callback' => $desired,
                    'existing_callback' => $existingCandidate['address'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $this->recordHealthEvent(
                    storeKey: $storeKey,
                    tenantId: $this->positiveInt($store['tenant_id'] ?? null),
                    eventType: 'webhook_verification_failed',
                    severity: 'error',
                    context: [
                        'topic' => $topic,
                        'operation' => 'repair',
                        'callback' => $desired,
                        'existing_callback' => $existingCandidate['address'] ?? null,
                        'error_message' => $e->getMessage(),
                    ]
                );
                $topicResults[] = [
                    'topic' => $topic,
                    'status' => 'failed_repair',
                    'callback' => $desired,
                    'existing_callback' => $existingCandidate['address'] ?? null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $driftCount = $counts['missing'] + $counts['mismatch'];
        $status = 'ok';
        if ($counts['failed'] > 0) {
            $status = 'failed';
        } elseif ($repair && ($counts['created'] > 0 || $counts['repaired'] > 0)) {
            $status = 'repaired';
        } elseif (! $repair && $driftCount > 0) {
            $status = 'drift';
        }

        if (in_array($status, ['ok', 'repaired'], true)) {
            $this->resolveHealthEvents(
                storeKey: $storeKey,
                tenantId: $this->positiveInt($store['tenant_id'] ?? null),
                eventTypes: [
                    'webhook_subscription_missing',
                    'webhook_subscription_mismatch',
                    'webhook_verification_failed',
                    'shopify_auth_invalid',
                ]
            );
        }

        return $this->result($status, $repair, [
            'store_key' => $storeKey,
            'shop' => $shopDomain,
            'required_count' => count($required),
            'topics' => $topicResults,
            'counts' => $counts,
            'drift_count' => $driftCount,
        ]);
    }

    /**
     * @param  array<string,mixed>  $store
     * @return array<string,mixed>
     */
    public function enforceStore(array $store): array
    {
        return $this->verifyStore($store, true);
    }

    /**
     * @return array<string,string>
     */
    public function requiredTopicsWithCallbacks(): array
    {
        $configured = config('shopify_webhooks.required_topics', []);
        if (! is_array($configured)) {
            return [];
        }

        $callbacks = [];
        foreach ($configured as $topic => $routeNameOrPath) {
            $normalizedTopic = strtolower(trim((string) $topic));
            if ($normalizedTopic === '') {
                continue;
            }

            $callback = $this->resolveCallback((string) $routeNameOrPath);
            if ($callback === null) {
                continue;
            }

            $callbacks[$normalizedTopic] = $callback;
        }

        return $callbacks;
    }

    /**
     * @return array<int,array{id:string|int|null,topic:string,address:string,format:?string}>
     */
    protected function listWebhooks(string $shopDomain, string $token, string $apiVersion): array
    {
        $url = $this->baseRestUrl($shopDomain, $apiVersion) . '/webhooks.json?limit=250';
        $rows = [];

        while ($url !== null) {
            $response = $this->requestWithRetry($token)->get($url);
            if ($response->failed()) {
                $response->throw();
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new RuntimeException('Shopify webhook list response was not valid JSON.');
            }

            $items = $payload['webhooks'] ?? null;
            if (! is_array($items)) {
                throw new RuntimeException('Shopify webhook list response missing webhooks payload.');
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $rows[] = [
                    'id' => $item['id'] ?? null,
                    'topic' => strtolower(trim((string) ($item['topic'] ?? ''))),
                    'address' => (string) ($item['address'] ?? ''),
                    'format' => isset($item['format']) ? strtolower(trim((string) $item['format'])) : null,
                ];
            }

            $url = $this->nextPageUrl($response->header('Link'));
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    protected function createWebhook(string $shopDomain, string $token, string $apiVersion, string $topic, string $address): array
    {
        $format = strtolower(trim((string) config('shopify_webhooks.format', 'json'))) ?: 'json';
        $response = $this->requestWithRetry($token)->post(
            $this->baseRestUrl($shopDomain, $apiVersion) . '/webhooks.json',
            [
                'webhook' => [
                    'topic' => $topic,
                    'address' => $address,
                    'format' => $format,
                ],
            ]
        );

        if ($response->failed()) {
            $response->throw();
        }

        $payload = $response->json();
        if (! is_array($payload) || ! is_array($payload['webhook'] ?? null)) {
            throw new RuntimeException('Shopify webhook create response was malformed.');
        }

        return (array) $payload['webhook'];
    }

    /**
     * @return array<string,mixed>
     */
    protected function updateWebhook(string $shopDomain, string $token, string $apiVersion, string $webhookId, string $topic, string $address): array
    {
        $format = strtolower(trim((string) config('shopify_webhooks.format', 'json'))) ?: 'json';
        $response = $this->requestWithRetry($token)->put(
            $this->baseRestUrl($shopDomain, $apiVersion) . '/webhooks/' . $webhookId . '.json',
            [
                'webhook' => [
                    'id' => $webhookId,
                    'topic' => $topic,
                    'address' => $address,
                    'format' => $format,
                ],
            ]
        );

        if ($response->failed()) {
            $response->throw();
        }

        $payload = $response->json();
        if (! is_array($payload) || ! is_array($payload['webhook'] ?? null)) {
            throw new RuntimeException('Shopify webhook update response was malformed.');
        }

        return (array) $payload['webhook'];
    }

    protected function requiredWebhookId(array $existing, string $topic): string
    {
        $id = $existing['id'] ?? null;
        if (is_numeric($id) && (int) $id > 0) {
            return (string) ((int) $id);
        }

        throw new RuntimeException("No webhook ID available to repair topic '{$topic}'.");
    }

    protected function resolveCallback(string $routeNameOrPath): ?string
    {
        $value = trim($routeNameOrPath);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $this->normalizeAddress($value);
        }

        $relativePath = null;
        if (str_starts_with($value, '/')) {
            $relativePath = $value;
        } else {
            try {
                $relativePath = route($value, [], false);
            } catch (\Throwable) {
                return null;
            }
        }

        if (is_string($relativePath) && $relativePath !== '') {
            $canonicalCallback = $this->hostBuilder->canonicalLandlordUrlForPath($relativePath);
            if (is_string($canonicalCallback) && $canonicalCallback !== '') {
                return $this->normalizeAddress($canonicalCallback);
            }
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl === '') {
            return null;
        }

        return $this->normalizeAddress($appUrl . '/' . ltrim($relativePath, '/'));
    }

    protected function normalizeAddress(mixed $address): string
    {
        $value = trim((string) $address);
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '?')) {
            [$path, $query] = explode('?', $value, 2);
            return rtrim($path, '/') . '?' . $query;
        }

        return rtrim($value, '/');
    }

    protected function baseRestUrl(string $shopDomain, string $apiVersion): string
    {
        return 'https://' . rtrim($shopDomain, '/') . '/admin/api/' . $apiVersion;
    }

    protected function requestWithRetry(string $token): PendingRequest
    {
        return Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout(30)->retry(
            3,
            500,
            function (\Exception $exception, PendingRequest $request): bool {
                if (! method_exists($exception, 'response')) {
                    return false;
                }

                /** @var Response|null $response */
                $response = $exception->response ?? null;
                if (! $response) {
                    return false;
                }

                return in_array($response->status(), [429, 500, 502, 503, 504], true);
            },
            throw: false
        );
    }

    protected function nextPageUrl(?string $linkHeader): ?string
    {
        if (! $linkHeader) {
            return null;
        }

        foreach (explode(',', $linkHeader) as $part) {
            $section = trim($part);
            if (! str_contains($section, 'rel="next"')) {
                continue;
            }

            if (preg_match('/<([^>]+)>/', $section, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    protected function recordHealthEvent(
        ?string $storeKey,
        ?int $tenantId,
        string $eventType,
        string $severity,
        array $context = []
    ): void {
        if ($storeKey === null) {
            return;
        }

        $topic = $this->nullableString($context['topic'] ?? null);

        $this->healthEventRecorder->record([
            'provider' => 'shopify',
            'event_type' => $eventType,
            'severity' => $severity,
            'status' => 'open',
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'context' => $context,
            'dedupe_key' => $this->topicDedupeKey($storeKey, $eventType, $topic),
        ]);
    }

    /**
     * @param  array<int,string>  $eventTypes
     */
    protected function resolveHealthEvents(
        ?string $storeKey,
        ?int $tenantId,
        array $eventTypes,
        ?string $dedupeKey = null
    ): void {
        if ($storeKey === null || $eventTypes === []) {
            return;
        }

        $this->healthEventRecorder->resolve([
            'provider' => 'shopify',
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'event_types' => $eventTypes,
            'dedupe_key' => $dedupeKey,
        ]);
    }

    protected function topicDedupeKey(?string $storeKey, string $eventType, ?string $topic = null): string
    {
        return sha1(json_encode([
            'provider' => 'shopify',
            'store_key' => $storeKey,
            'event_type' => $eventType,
            'topic' => $topic,
        ]));
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    protected function result(string $status, bool $repair, array $extra = []): array
    {
        return array_replace([
            'status' => $status,
            'repair' => $repair,
        ], $extra);
    }
}
