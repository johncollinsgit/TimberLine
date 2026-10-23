<?php

namespace App\Proposals;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

/** Registered outside cached route files; both runtimes use the same public boundaries. */
final class RateLimits
{
    public static function register(): void
    {
        RateLimiter::for('service-proposals-activity', fn ($request) => Limit::perMinute(90)
            ->by('sp-activity:'.hash('sha256', $request->getHost().'|'.$request->ip().'|'.$request->route('token'))));
        RateLimiter::for('service-proposals-public', fn ($request) => Limit::perMinute(30)
            ->by('sp-public:'.hash('sha256', $request->getHost().'|'.$request->ip())));
        RateLimiter::for('service-proposals-unlock', fn ($request) => Limit::perMinute(6)
            ->by('sp-unlock:'.hash('sha256', $request->getHost().'|'.$request->ip().'|'.$request->route('token'))));
    }
}
