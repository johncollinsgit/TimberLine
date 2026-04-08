<?php

namespace App\Services\Shopify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopifyEmbeddedPerformanceProbe
{
    protected const ORDERED_PHASES = [
        'context',
        'tenant_resolve',
        'shell_payload',
        'page_payload',
        'view_render',
        'total',
    ];

    /**
     * @var array<string,float>
     */
    protected array $phaseDurationsMs = [];

    protected ?Request $request = null;

    protected ?int $tenantId = null;

    /**
     * @var array<string,mixed>
     */
    protected array $context = [];

    protected float $totalStartedAt;

    protected bool $queryLogEnabled = false;
    protected bool $disableQueryLogOnFinish = false;
    protected int $queryLogStartOffset = 0;

    public function __construct(
        protected ?bool $enabled = null
    ) {
        $this->enabled = $enabled ?? (bool) config('shopify_embedded.perf_profiling_enabled', false);
        $this->totalStartedAt = microtime(true);

        if (! $this->enabled) {
            return;
        }

        $connection = DB::connection();
        $this->queryLogStartOffset = count($connection->getQueryLog());

        if (! $connection->logging()) {
            $connection->enableQueryLog();
            $this->disableQueryLogOnFinish = true;
        }

        $this->queryLogEnabled = true;
    }

    public function enabled(): bool
    {
        return (bool) $this->enabled;
    }

    public function forRequest(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function forTenant(?int $tenantId): self
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function addContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    /**
     * @template T
     *
     * @param  callable():T  $resolver
     * @return T
     */
    public function time(string $phase, callable $resolver)
    {
        if (! $this->enabled()) {
            return $resolver();
        }

        $startedAt = microtime(true);

        try {
            return $resolver();
        } finally {
            $this->addDuration($phase, round((microtime(true) - $startedAt) * 1000, 2));
        }
    }

    public function addDuration(string $phase, float|int $durationMs): void
    {
        if (! $this->enabled()) {
            return;
        }

        $normalizedPhase = strtolower(trim($phase));
        if ($normalizedPhase === '') {
            return;
        }

        $this->phaseDurationsMs[$normalizedPhase] = round(
            ($this->phaseDurationsMs[$normalizedPhase] ?? 0.0) + (float) $durationMs,
            2
        );
    }

    public function finish(Response|JsonResponse $response): Response|JsonResponse
    {
        if (! $this->enabled()) {
            return $response;
        }

        $this->phaseDurationsMs['total'] = round((microtime(true) - $this->totalStartedAt) * 1000, 2);
        $normalized = $this->normalizedDurations();
        $queryStats = $this->queryStats();

        $timingValues = collect($normalized)
            ->map(fn (float $ms, string $phase): string => sprintf('%s;dur=%s', str_replace('_', '-', $phase), number_format($ms, 2, '.', '')))
            ->values()
            ->all();
        if ((float) ($queryStats['total_ms'] ?? 0.0) > 0) {
            $timingValues[] = sprintf('db-query;dur=%s', number_format((float) $queryStats['total_ms'], 2, '.', ''));
        }

        if ($timingValues !== []) {
            $existing = trim((string) $response->headers->get('Server-Timing', ''));
            $combined = array_filter([$existing, implode(', ', $timingValues)]);
            $response->headers->set('Server-Timing', implode(', ', $combined));
        }

        Log::info('shopify.embedded.perf', array_merge([
            'route' => $this->request?->route()?->getName(),
            'method' => $this->request?->method(),
            'path' => $this->request?->path(),
            'tenant_id' => $this->tenantId,
            'timings_ms' => $normalized,
            'query_count' => (int) ($queryStats['count'] ?? 0),
            'query_time_ms' => (float) ($queryStats['total_ms'] ?? 0.0),
            'slow_queries' => (array) ($queryStats['slow_queries'] ?? []),
        ], $this->context));

        return $response;
    }

    /**
     * @return array<string,float>
     */
    protected function normalizedDurations(): array
    {
        $normalized = [];

        foreach (self::ORDERED_PHASES as $phase) {
            if (! array_key_exists($phase, $this->phaseDurationsMs)) {
                continue;
            }

            $normalized[$phase] = (float) $this->phaseDurationsMs[$phase];
        }

        foreach ($this->phaseDurationsMs as $phase => $durationMs) {
            if (array_key_exists($phase, $normalized)) {
                continue;
            }

            $normalized[$phase] = (float) $durationMs;
        }

        return $normalized;
    }

    /**
     * @return array{
     *   count:int,
     *   total_ms:float,
     *   slow_queries:array<int,array{time_ms:float,sql:string,bindings_count:int}>
     * }
     */
    protected function queryStats(): array
    {
        if (! $this->queryLogEnabled) {
            return [
                'count' => 0,
                'total_ms' => 0.0,
                'slow_queries' => [],
            ];
        }

        $queries = DB::connection()->getQueryLog();
        if ($this->queryLogStartOffset > 0) {
            $queries = array_slice($queries, $this->queryLogStartOffset);
        }

        $count = count($queries);
        $totalMs = round((float) collect($queries)->sum(fn (array $entry): float => (float) ($entry['time'] ?? 0.0)), 2);
        $slowQueryThresholdMs = max(0.0, (float) config('shopify_embedded.perf_slow_query_ms', 25));
        $slowQueries = collect($queries)
            ->filter(fn (array $entry): bool => (float) ($entry['time'] ?? 0.0) >= $slowQueryThresholdMs)
            ->sortByDesc(fn (array $entry): float => (float) ($entry['time'] ?? 0.0))
            ->take(5)
            ->map(fn (array $entry): array => [
                'time_ms' => round((float) ($entry['time'] ?? 0.0), 2),
                'sql' => $this->summarizeSql((string) ($entry['query'] ?? '')),
                'bindings_count' => count((array) ($entry['bindings'] ?? [])),
            ])
            ->values()
            ->all();

        DB::connection()->flushQueryLog();
        if ($this->disableQueryLogOnFinish) {
            DB::connection()->disableQueryLog();
        }
        $this->queryLogEnabled = false;

        return [
            'count' => $count,
            'total_ms' => $totalMs,
            'slow_queries' => $slowQueries,
        ];
    }

    protected function summarizeSql(string $sql): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $sql));
        if ($normalized === '') {
            return '';
        }

        if (strlen($normalized) <= 220) {
            return $normalized;
        }

        return substr($normalized, 0, 217).'...';
    }
}
