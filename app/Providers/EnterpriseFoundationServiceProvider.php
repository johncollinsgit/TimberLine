<?php

namespace App\Providers;

use Illuminate\Foundation\Providers\FormRequestServiceProvider;
use Illuminate\Foundation\Providers\FoundationServiceProvider;

/** Preserve framework foundations while keeping parallel view caches application-local. */
final class EnterpriseFoundationServiceProvider extends FoundationServiceProvider
{
    protected $providers = [
        FormRequestServiceProvider::class,
        EnterpriseParallelTestingServiceProvider::class,
    ];
}
