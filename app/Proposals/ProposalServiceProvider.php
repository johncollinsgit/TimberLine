<?php

namespace App\Proposals;

use Illuminate\Support\ServiceProvider;

/** Route caching must not remove password/unlock throttles. */
final class ProposalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimits::register();
    }
}
