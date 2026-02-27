<?php

namespace App\Services;

use App\Models\MarketEventSyncState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MarketEventSyncCoordinator
{
    public const SYNC_KEY = 'asana_google_calendar_markets';

    public function queueStatus(): array
    {
        if (! $this->syncStateTableExists()) {
            return [
                'status' => 'unavailable',
                'weeks' => 4,
                'queued_at' => null,
                'started_at' => null,
                'finished_at' => null,
                'last_sync_at' => null,
                'last_sync_status' => null,
                'last_http_status' => null,
                'last_error' => 'market_event_sync_states table missing (run migrations).',
                'last_result' => [],
            ];
        }

        return $this->formatState($this->state());
    }

    public function canQueue(int $weeks, bool $force = false): array
    {
        if (! $this->syncStateTableExists()) {
            return ['allowed' => false, 'reason' => 'missing_table', 'state' => $this->queueStatus()];
        }

        $state = $this->state();
        $cooldownMinutes = max(1, (int) config('features.market_events_sync_cooldown_minutes', 10));
        $now = now();

        if (($state->status ?? null) === 'running') {
            return ['allowed' => false, 'reason' => 'running', 'state' => $this->formatState($state)];
        }

        if (($state->status ?? null) === 'queued') {
            return ['allowed' => true, 'reason' => null, 'state' => $this->formatState($state)];
        }

        if (! $force) {
            $recentAt = $state->queued_at ?: $state->started_at ?: $state->last_sync_at;
            if ($recentAt && $recentAt->gt($now->copy()->subMinutes($cooldownMinutes))) {
                return ['allowed' => false, 'reason' => 'cooldown', 'state' => $this->formatState($state)];
            }
        }

        return ['allowed' => true, 'reason' => null, 'state' => $this->formatState($state)];
    }

    public function markQueued(int $weeks = 4, ?int $userId = null): array
    {
        if (! $this->syncStateTableExists()) {
            return $this->queueStatus();
        }

        $state = $this->state();

        $state->fill([
            'status' => 'queued',
            'weeks' => max(1, $weeks),
            'queued_by_user_id' => $userId,
            'queued_at' => now(),
            'started_at' => null,
            'finished_at' => null,
        ]);
        $state->save();

        return $this->formatState($state);
    }

    /**
     * @return array<string,mixed>
     */
    public function runSync(int $weeks = 8, bool $force = false, ?string $trigger = null): array
    {
        $weeks = max(1, $weeks);

        if (! $this->syncStateTableExists()) {
            return [
                'ok' => false,
                'status' => 'missing_table',
                'error' => 'market_event_sync_states table missing (run migrations).',
                'state' => $this->queueStatus(),
            ];
        }

        if (! (bool) config('features.market_events_sync_enabled', true)) {
            $state = $this->state();
            $state->fill([
                'status' => 'skipped',
                'finished_at' => now(),
                'last_sync_status' => 'disabled',
                'last_error' => 'Market event sync disabled by feature flag MARKET_EVENTS_SYNC_ENABLED.',
            ])->save();

            return [
                'ok' => false,
                'status' => 'disabled',
                'state' => $this->formatState($state),
            ];
        }

        $gate = $this->canQueue($weeks, $force);
        if (! ($gate['allowed'] ?? false) && ($gate['reason'] ?? null) === 'running') {
            return [
                'ok' => false,
                'status' => 'running',
                'state' => $this->formatState($this->state()),
            ];
        }

        if (! ($gate['allowed'] ?? false) && ($gate['reason'] ?? null) !== 'running') {
            $state = $this->state();
            $state->fill([
                'status' => 'skipped',
                'finished_at' => now(),
                'last_sync_status' => 'skipped',
            ])->save();

            return [
                'ok' => false,
                'status' => 'cooldown',
                'state' => $this->formatState($state),
            ];
        }

        $state = $this->state();
        $state->fill([
            'status' => 'running',
            'weeks' => $weeks,
            'queued_at' => $state->queued_at ?: now(),
            'started_at' => now(),
            'finished_at' => null,
        ])->save();

        $startedAt = microtime(true);

        try {
            $result = app(UpcomingMarketEventsService::class)->syncUpcoming($weeks);

            $state->fill([
                'status' => 'success',
                'finished_at' => now(),
                'last_sync_at' => now(),
                'last_sync_status' => 'success',
                'last_http_status' => null,
                'last_error' => null,
                'last_result' => $result,
            ])->save();

            $this->bumpMatchingCacheVersion();

            Log::info('Market events sync completed', [
                'trigger' => $trigger,
                'weeks' => $weeks,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'fetched' => (int) ($result['fetched'] ?? 0),
                'upserted' => (int) ($result['upserted'] ?? 0),
            ]);

            return [
                'ok' => true,
                'status' => 'success',
                'result' => $result,
                'state' => $this->formatState($state),
            ];
        } catch (\Throwable $e) {
            $state->fill([
                'status' => 'failed',
                'finished_at' => now(),
                'last_sync_at' => now(),
                'last_sync_status' => 'failed',
                'last_http_status' => $this->extractHttpStatus($e),
                'last_error' => Str::limit($e->getMessage(), 4000, ''),
            ])->save();

            Log::error('Market events sync failed', [
                'trigger' => $trigger,
                'weeks' => $weeks,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'state' => $this->formatState($state),
            ];
        }
    }

    public function matchingCacheVersion(): int
    {
        return (int) Cache::get('market_events_match_cache_version', 1);
    }

    public function bumpMatchingCacheVersion(): int
    {
        $current = $this->matchingCacheVersion();
        $next = max(2, $current + 1);
        Cache::forever('market_events_match_cache_version', $next);

        return $next;
    }

    protected function state(): MarketEventSyncState
    {
        return MarketEventSyncState::query()->firstOrCreate(
            ['sync_key' => self::SYNC_KEY],
            ['status' => 'idle', 'weeks' => 4]
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function formatState(MarketEventSyncState $state): array
    {
        return [
            'status' => (string) ($state->status ?: 'idle'),
            'weeks' => (int) ($state->weeks ?: 4),
            'queued_at' => $state->queued_at?->toIso8601String(),
            'started_at' => $state->started_at?->toIso8601String(),
            'finished_at' => $state->finished_at?->toIso8601String(),
            'last_sync_at' => $state->last_sync_at?->toIso8601String(),
            'last_sync_status' => $state->last_sync_status,
            'last_http_status' => $state->last_http_status,
            'last_error' => $state->last_error,
            'last_result' => is_array($state->last_result) ? $state->last_result : [],
        ];
    }

    protected function extractHttpStatus(\Throwable $e): ?int
    {
        if (preg_match('/HTTP\s+(\d{3})/i', $e->getMessage(), $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function syncStateTableExists(): bool
    {
        static $exists = null;

        if ($exists === null) {
            $exists = Schema::hasTable('market_event_sync_states');
        }

        return $exists;
    }
}
