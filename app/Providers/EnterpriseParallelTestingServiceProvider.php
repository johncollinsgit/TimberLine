<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Testing\ParallelTestingServiceProvider;

/** The framework's process-static original view path may belong to the retail app. */
final class EnterpriseParallelTestingServiceProvider extends ParallelTestingServiceProvider
{
    protected function parallelSafeCompiledViewPath(): ?string
    {
        $token = ParallelTesting::token();
        if (! $token) {
            return null;
        }
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', (string) $token)) {
            throw new \RuntimeException('Invalid Enterprise parallel test token.');
        }
        $path = $this->app->storagePath('framework/views/test_'.$token);
        // This app can be booted after worker-process setup ran for the retail app.
        File::ensureDirectoryExists($path);

        return $path;
    }
}
