<?php

namespace App\Http\Middleware;

use App\Services\SchedulerHeartbeatService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the scheduler-heartbeat staleness check after the response is sent (terminate),
 * throttled to once every few minutes. Web traffic keeps flowing even if cron dies, so
 * this is what turns a stopped scheduler into a visible integration health event.
 */
class EvaluateSchedulerHeartbeat
{
    public function __construct(private readonly SchedulerHeartbeatService $heartbeat) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('scheduler_heartbeat.enabled', true)) {
            return;
        }

        $this->heartbeat->evaluateThrottled(
            (int) config('scheduler_heartbeat.threshold_minutes', 10),
            (int) config('scheduler_heartbeat.check_throttle_seconds', 300),
        );
    }
}
