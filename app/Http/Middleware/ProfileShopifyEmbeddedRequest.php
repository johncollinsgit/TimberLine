<?php

namespace App\Http\Middleware;

use App\Support\Diagnostics\ShopifyEmbeddedDeepProfile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ProfileShopifyEmbeddedRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ShopifyEmbeddedDeepProfile::enabled($request)) {
            return $next($request);
        }

        $startedAt = microtime(true);
        $memoryStart = memory_get_usage(true);

        $response = $next($request);

        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
        $memoryPeakMb = round(memory_get_peak_usage(true) / 1048576, 2);
        $memoryDeltaKb = round((memory_get_usage(true) - $memoryStart) / 1024, 2);

        $route = $request->route();
        $routeName = $route?->getName();
        $action = method_exists($route, 'getActionName') ? $route->getActionName() : null;
        $middleware = method_exists($route, 'gatherMiddleware') ? array_values($route->gatherMiddleware()) : [];
        $snapshot = ShopifyEmbeddedDeepProfile::snapshot($request);

        Log::info('shopify.embedded.deep_profile', [
            'route' => $routeName,
            'path' => $request->path(),
            'method' => $request->method(),
            'status_code' => $response->getStatusCode(),
            'controller_action' => is_string($action) ? $action : null,
            'middleware' => $middleware,
            'request_total_ms' => $durationMs,
            'memory_peak_mb' => $memoryPeakMb,
            'memory_delta_kb' => $memoryDeltaKb,
            'service_timings_ms' => $snapshot['timings'],
            'external_http_count' => count($snapshot['external_http']),
            'external_http' => $snapshot['external_http'],
            'cache' => $snapshot['cache'],
        ]);

        $timingValue = sprintf('request-total;dur=%s', number_format($durationMs, 2, '.', ''));
        $existing = trim((string) $response->headers->get('Server-Timing', ''));
        $combined = array_filter([$existing, $timingValue]);
        $response->headers->set('Server-Timing', implode(', ', $combined));

        ShopifyEmbeddedDeepProfile::clear($request);

        return $response;
    }
}
