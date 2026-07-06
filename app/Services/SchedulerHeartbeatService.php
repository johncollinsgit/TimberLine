<?php

namespace App\Services;

use App\Services\Marketing\IntegrationHealthEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Detects a stopped Laravel scheduler (dead cron). The scheduler stamps a heartbeat
 * every minute via pulse(); the staleness check runs on web traffic (which keeps
 * flowing even when cron is dead), so a stalled scheduler surfaces as an integration
 * health event instead of silently freezing every scheduled job (imports included).
 */
class SchedulerHeartbeatService
{
    public const EVENT_TYPE = 'scheduler_stalled';

    private const CACHE_KEY = 'scheduler:last_heartbeat';

    private const CHECK_LOCK_KEY = 'scheduler:heartbeat_check_lock';

    public function __construct(private readonly IntegrationHealthEventRecorder $healthEvents) {}

    public function pulse(): void
    {
        // Stored forever: if the scheduler dies for days the timestamp stays put, so the
        // age keeps growing and the stall remains detectable (a TTL would hide it).
        Cache::forever(self::CACHE_KEY, CarbonImmutable::now()->toIso8601String());
    }

    public function lastHeartbeatAt(): ?CarbonImmutable
    {
        $value = Cache::get(self::CACHE_KEY);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function minutesSinceHeartbeat(): ?int
    {
        $last = $this->lastHeartbeatAt();

        return $last === null ? null : abs((int) $last->diffInMinutes(CarbonImmutable::now()));
    }

    /**
     * Raise an alert when the heartbeat is stale; resolve it once it's fresh again.
     * Does nothing until at least one heartbeat exists (avoids a false alarm on a brand
     * new deploy that hasn't ticked yet).
     */
    public function evaluate(int $thresholdMinutes = 10): void
    {
        $age = $this->minutesSinceHeartbeat();
        if ($age === null) {
            return;
        }

        if ($age > $thresholdMinutes) {
            $this->healthEvents->record([
                'provider' => 'system',
                'event_type' => self::EVENT_TYPE,
                'severity' => 'error',
                'status' => 'open',
                'context' => [
                    'reason' => 'scheduler_heartbeat_stale',
                    'last_heartbeat_at' => $this->lastHeartbeatAt()?->toIso8601String(),
                    'age_minutes' => $age,
                    'threshold_minutes' => $thresholdMinutes,
                    'hint' => 'The Laravel scheduler cron (php artisan schedule:run) may have stopped; scheduled imports and jobs are not running.',
                ],
            ], dedupeWindowMinutes: 24 * 60);

            return;
        }

        $this->healthEvents->resolve([
            'provider' => 'system',
            'event_type' => self::EVENT_TYPE,
        ]);
    }

    /**
     * Cheap, throttled evaluation for per-request use: runs the real check at most once
     * per $throttleSeconds regardless of traffic, and never throws.
     */
    public function evaluateThrottled(int $thresholdMinutes = 10, int $throttleSeconds = 300): void
    {
        try {
            if (Cache::add(self::CHECK_LOCK_KEY, 1, max(1, $throttleSeconds))) {
                $this->evaluate($thresholdMinutes);
            }
        } catch (\Throwable) {
            // Heartbeat monitoring must never break a request.
        }
    }
}
