<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ThrottlePasswordResetEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->routeIs('password.email')) {
            return $next($request);
        }
        // Count unknown addresses too. Keys never contain a raw address or IP.
        $email = $request->input('email');
        $email = is_string($email) ? strtolower(trim($email)) : '';
        $hash = fn (string $value): string => hash_hmac('sha256', $value, (string) config('app.key'));
        $limits = [
            'auth:reset:ip:minute:'.$hash((string) $request->ip()) => [5, 60],
            'auth:reset:ip:hour:'.$hash((string) $request->ip()) => [20, 3600],
            'auth:reset:recipient:'.$hash($email) => [3, 3600],
        ];
        $limiter = new RateLimiter(Cache::store());
        // Serialize admission before the password broker generates or sends a
        // token; simultaneous requests cannot each consume the final slot.
        $retryAfter = Cache::lock('auth:reset:admission', 10)->block(2, function () use ($limits, $limiter): int {
            foreach ($limits as $key => [$max, $seconds]) {
                if ($limiter->tooManyAttempts($key, $max)) {
                    return max(1, $limiter->availableIn($key));
                }
            }
            foreach ($limits as $key => [$max, $seconds]) {
                $limiter->hit($key, $seconds);
            }

            return 0;
        });
        if ($retryAfter > 0) {
            abort(429, 'Too many password reset requests. Please try again later.', ['Retry-After' => (string) $retryAfter]);
        }

        return $next($request);
    }
}
