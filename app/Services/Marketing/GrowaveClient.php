<?php

namespace App\Services\Marketing;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GrowaveClient
{
    protected ?string $accessToken = null;

    protected ?CarbonImmutable $accessTokenExpiresAt = null;

    protected ?float $lastRequestStartedAt = null;

    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $clientId = null,
        protected ?string $clientSecret = null,
        protected ?string $scope = null,
        protected ?string $shop = null,
        protected ?int $timeoutSeconds = null,
        protected ?int $retryAttempts = null,
        protected ?int $requestMinIntervalMs = null,
        protected ?int $requestJitterMs = null,
        protected ?int $backoffBaseMs = null,
        protected ?int $backoffMaxMs = null
    ) {
        $config = (array) config('marketing.growave', []);

        $this->baseUrl = rtrim((string) ($this->baseUrl ?: ($config['base_url'] ?? 'https://api.growave.io')), '/');
        $this->clientId = $this->nullableString($this->clientId ?? ($config['client_id'] ?? null));
        $this->clientSecret = $this->nullableString($this->clientSecret ?? ($config['client_secret'] ?? null));
        $this->scope = $this->nullableString($this->scope ?? ($config['scope'] ?? null)) ?: 'read_customer read_review read_reward';
        $this->shop = $this->nullableString($this->shop ?? ($config['shop'] ?? null));
        $this->timeoutSeconds = max(1, (int) ($this->timeoutSeconds ?: ($config['timeout_seconds'] ?? 20)));
        $this->retryAttempts = max(0, (int) ($this->retryAttempts ?? ($config['retry_attempts'] ?? 3)));
        $this->requestMinIntervalMs = max(0, (int) ($this->requestMinIntervalMs ?? ($config['request_min_interval_ms'] ?? 300)));
        $this->requestJitterMs = max(0, (int) ($this->requestJitterMs ?? ($config['request_jitter_ms'] ?? 100)));
        $this->backoffBaseMs = max(100, (int) ($this->backoffBaseMs ?? ($config['backoff_base_ms'] ?? 1000)));
        $this->backoffMaxMs = max($this->backoffBaseMs, (int) ($this->backoffMaxMs ?? ($config['backoff_max_ms'] ?? 15000)));
    }

    /**
     * @param array{
     *   retry_attempts?:int|null,
     *   request_min_interval_ms?:int|null,
     *   request_jitter_ms?:int|null,
     *   backoff_base_ms?:int|null,
     *   backoff_max_ms?:int|null
     * } $runtime
     */
    public function configureRuntime(array $runtime): void
    {
        if (array_key_exists('retry_attempts', $runtime)) {
            $this->retryAttempts = max(0, (int) ($runtime['retry_attempts'] ?? 0));
        }

        if (array_key_exists('request_min_interval_ms', $runtime)) {
            $this->requestMinIntervalMs = max(0, (int) ($runtime['request_min_interval_ms'] ?? 0));
        }

        if (array_key_exists('request_jitter_ms', $runtime)) {
            $this->requestJitterMs = max(0, (int) ($runtime['request_jitter_ms'] ?? 0));
        }

        if (array_key_exists('backoff_base_ms', $runtime)) {
            $this->backoffBaseMs = max(100, (int) ($runtime['backoff_base_ms'] ?? 1000));
        }

        if (array_key_exists('backoff_max_ms', $runtime)) {
            $this->backoffMaxMs = max($this->backoffBaseMs, (int) ($runtime['backoff_max_ms'] ?? 15000));
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getCustomer(string $customerIdentifier): ?array
    {
        $response = $this->sendWithRetries(
            fn (): Response => $this->baseRequest()->withToken($this->accessToken())->get($this->url('/v2/customers/getCustomer'), [
                'customerIdentifier' => $customerIdentifier,
            ]),
            refreshTokenOnUnauthorized: true
        );

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array{totalCount:int,currentOffset:int,perPage:int,items:array<int,array<string,mixed>>}
     */
    public function getReviews(string $customerIdentifier, int $perPage = 50, int $offset = 0): array
    {
        $response = $this->sendWithRetries(
            fn (): Response => $this->baseRequest()->withToken($this->accessToken())->get($this->url('/v2/reviews/getReviews'), [
                'customerIdentifier' => $customerIdentifier,
                'perPage' => min(max($perPage, 1), 50),
                'offset' => max($offset, 0),
            ]),
            refreshTokenOnUnauthorized: true
        );

        $response->throw();

        $payload = $this->arrayPayload($response);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        return [
            'totalCount' => max(0, (int) ($payload['totalCount'] ?? count($items))),
            'currentOffset' => max(0, (int) ($payload['currentOffset'] ?? $offset)),
            'perPage' => max(1, (int) ($payload['perPage'] ?? $perPage)),
            'items' => $items,
        ];
    }

    /**
     * @return array{totalCount:int,currentOffset:int,perPage:int,items:array<int,array<string,mixed>>,notFound:bool}
     */
    public function getWishlists(string $customerIdentifier, int $perPage = 50, int $offset = 0): array
    {
        $response = $this->sendWithRetries(
            fn (): Response => $this->baseRequest()->withToken($this->accessToken())->get($this->url('/v2/wishlists/getWishlists'), [
                'customerIdentifier' => $customerIdentifier,
                'perPage' => min(max($perPage, 1), 50),
                'offset' => max($offset, 0),
            ]),
            refreshTokenOnUnauthorized: true
        );

        if ($response->status() === 404) {
            return [
                'totalCount' => 0,
                'currentOffset' => max($offset, 0),
                'perPage' => max(1, min($perPage, 50)),
                'items' => [],
                'notFound' => true,
            ];
        }

        $response->throw();

        $payload = $this->arrayPayload($response);
        $items = is_array($payload['items'] ?? null)
            ? $payload['items']
            : (is_array($payload['wishlists'] ?? null) ? $payload['wishlists'] : []);

        return [
            'totalCount' => max(0, (int) ($payload['totalCount'] ?? count($items))),
            'currentOffset' => max(0, (int) ($payload['currentOffset'] ?? $offset)),
            'perPage' => max(1, (int) ($payload['perPage'] ?? $perPage)),
            'items' => $items,
            'notFound' => false,
        ];
    }

    /**
     * @return array{totalCount:int,currentOffset:int,perPage:int,items:array<int,array<string,mixed>>,notFound:bool}
     */
    public function getWishlistItems(string $wishlistIdentifier, int $perPage = 50, int $offset = 0): array
    {
        $response = $this->sendWithRetries(
            fn (): Response => $this->baseRequest()->withToken($this->accessToken())->get($this->url('/v2/wishlists/getWishlistItems'), [
                'wishlistId' => $wishlistIdentifier,
                'wishlistIdentifier' => $wishlistIdentifier,
                'perPage' => min(max($perPage, 1), 50),
                'offset' => max($offset, 0),
            ]),
            refreshTokenOnUnauthorized: true
        );

        if ($response->status() === 404) {
            return [
                'totalCount' => 0,
                'currentOffset' => max($offset, 0),
                'perPage' => max(1, min($perPage, 50)),
                'items' => [],
                'notFound' => true,
            ];
        }

        $response->throw();

        $payload = $this->arrayPayload($response);
        $items = is_array($payload['items'] ?? null)
            ? $payload['items']
            : (is_array($payload['wishlistItems'] ?? null) ? $payload['wishlistItems'] : []);

        return [
            'totalCount' => max(0, (int) ($payload['totalCount'] ?? count($items))),
            'currentOffset' => max(0, (int) ($payload['currentOffset'] ?? $offset)),
            'perPage' => max(1, (int) ($payload['perPage'] ?? $perPage)),
            'items' => $items,
            'notFound' => false,
        ];
    }

    /**
     * @return array{totalCount:int,currentPage:int,perPage:int,activities:array<int,array<string,mixed>>}
     */
    public function getActivityHistory(string $customerIdentifier, int $perPage = 50, int $page = 1): array
    {
        $response = $this->sendWithRetries(
            fn (): Response => $this->baseRequest()->withToken($this->accessToken())->get($this->url('/v2/rewards/getActivityHistory'), [
                'customerIdentifier' => $customerIdentifier,
                'perPage' => min(max($perPage, 1), 250),
                'page' => max($page, 1),
            ]),
            refreshTokenOnUnauthorized: true
        );

        $response->throw();

        $payload = $this->arrayPayload($response);
        $activities = is_array($payload['activities'] ?? null) ? $payload['activities'] : [];

        return [
            'totalCount' => max(0, (int) ($payload['totalCount'] ?? count($activities))),
            'currentPage' => max(1, (int) ($payload['currentPage'] ?? $page)),
            'perPage' => max(1, (int) ($payload['perPage'] ?? $perPage)),
            'activities' => $activities,
        ];
    }

    protected function baseRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds);
    }

    protected function accessToken(): string
    {
        if (
            $this->accessToken !== null
            && $this->accessTokenExpiresAt !== null
            && now()->lt($this->accessTokenExpiresAt)
        ) {
            return $this->accessToken;
        }

        $clientId = $this->requiredString($this->clientId, 'Growave client ID is not configured.');
        $clientSecret = $this->requiredString($this->clientSecret, 'Growave client secret is not configured.');

        $payload = [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'grantType' => 'client_credentials',
            'scope' => $this->scope,
        ];

        if ($this->shop !== null) {
            $payload['shop'] = $this->shop;
        }

        $response = $this->sendWithRetries(
            fn (): Response => $this->baseRequest()->post($this->url('/v2/oauth/getAccessToken'), $payload),
            refreshTokenOnUnauthorized: false
        );
        $response->throw();

        $json = $this->arrayPayload($response);
        $token = $this->requiredString($json['accessToken'] ?? null, 'Growave OAuth response did not contain accessToken.');

        $this->accessToken = $token;
        $this->accessTokenExpiresAt = $this->parseTokenExpiry($json['expiresAt'] ?? null)
            ?: now()->addMinutes(50);

        return $token;
    }

    protected function sendWithRetries(callable $send, bool $refreshTokenOnUnauthorized): Response
    {
        $maxAttempts = max(1, $this->retryAttempts + 1);
        $attempt = 0;
        $lastResponse = null;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $this->paceRequest();

            try {
                $response = $send();
                $lastResponse = $response;
            } catch (Throwable $e) {
                $lastException = $e;

                if (! $this->shouldRetryException($e) || $attempt >= $maxAttempts) {
                    throw $e;
                }

                $this->sleepMilliseconds($this->retryDelayMilliseconds($attempt, null));

                continue;
            }

            if ($refreshTokenOnUnauthorized && $response->status() === 401 && $attempt < $maxAttempts) {
                $this->invalidateAccessToken();
                $this->sleepMilliseconds($this->retryDelayMilliseconds($attempt, $response));

                continue;
            }

            if ($this->shouldRetryResponse($response) && $attempt < $maxAttempts) {
                $this->sleepMilliseconds($this->retryDelayMilliseconds($attempt, $response));

                continue;
            }

            return $response;
        }

        if ($lastResponse instanceof Response) {
            return $lastResponse;
        }

        if ($lastException instanceof Throwable) {
            throw $lastException;
        }

        throw new RuntimeException('Growave request failed without a response.');
    }

    protected function shouldRetryException(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        $message = strtolower(trim($exception->getMessage()));

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'temporarily unavailable');
    }

    protected function shouldRetryResponse(Response $response): bool
    {
        return in_array($response->status(), [408, 425, 429, 500, 502, 503, 504], true);
    }

    protected function retryDelayMilliseconds(int $attempt, ?Response $response): int
    {
        $exponential = (int) min(
            $this->backoffMaxMs,
            $this->backoffBaseMs * (2 ** max(0, $attempt - 1))
        );

        $retryAfterMs = $this->retryAfterMilliseconds($response);
        $baseDelay = $retryAfterMs !== null ? max($retryAfterMs, $exponential) : $exponential;
        $jitter = $this->requestJitterMs > 0 ? random_int(0, $this->requestJitterMs) : 0;

        return min($this->backoffMaxMs, $baseDelay + $jitter);
    }

    protected function retryAfterMilliseconds(?Response $response): ?int
    {
        if (! $response instanceof Response) {
            return null;
        }

        $header = $this->nullableString($response->header('Retry-After'));
        if ($header !== null) {
            if (ctype_digit($header)) {
                return max(0, (int) $header * 1000);
            }

            try {
                $retryAt = CarbonImmutable::parse($header);

                return max(0, now()->diffInMilliseconds($retryAt, false));
            } catch (Throwable $e) {
                // Continue to body-based parsing.
            }
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }

        $message = strtolower(trim((string) ($payload['message'] ?? '')));
        if (preg_match('/retry\s+after\s+(\d+)\s*second/', $message, $matches) === 1) {
            return max(0, (int) ($matches[1] ?? 0) * 1000);
        }

        return null;
    }

    protected function paceRequest(): void
    {
        $targetGapMs = max(0, $this->requestMinIntervalMs);
        if ($this->requestJitterMs > 0) {
            $targetGapMs += random_int(0, $this->requestJitterMs);
        }

        if ($this->lastRequestStartedAt !== null && $targetGapMs > 0) {
            $elapsedMs = (microtime(true) - $this->lastRequestStartedAt) * 1000;
            $sleepMs = $targetGapMs - $elapsedMs;

            if ($sleepMs > 0) {
                $this->sleepMilliseconds((int) ceil($sleepMs));
            }
        }

        $this->lastRequestStartedAt = microtime(true);
    }

    protected function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        usleep($milliseconds * 1000);
    }

    protected function invalidateAccessToken(): void
    {
        $this->accessToken = null;
        $this->accessTokenExpiresAt = null;
    }

    protected function parseTokenExpiry(mixed $value): ?CarbonImmutable
    {
        $candidates = [];

        if (is_string($value) || is_numeric($value)) {
            $candidates[] = $value;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item) || is_numeric($item)) {
                    $candidates[] = $item;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                try {
                    $int = (int) $candidate;
                    if ($int > 0) {
                        return CarbonImmutable::createFromTimestampUTC($int);
                    }
                } catch (\Throwable $e) {
                    // Continue fallback parsing.
                }
            }

            try {
                return CarbonImmutable::parse((string) $candidate);
            } catch (\Throwable $e) {
                // Continue fallback parsing.
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    protected function arrayPayload(Response $response): array
    {
        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function requiredString(mixed $value, string $message): string
    {
        $string = $this->nullableString($value);
        if ($string === null) {
            throw new RuntimeException($message);
        }

        return $string;
    }

    protected function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }
}
