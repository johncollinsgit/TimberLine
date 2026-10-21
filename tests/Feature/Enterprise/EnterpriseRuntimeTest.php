<?php

namespace Tests\Feature\Enterprise;

use App\Foundation\EnterpriseApplication;
use App\Http\Middleware\EnsureEnterpriseRuntime;
use App\Providers\EnterpriseServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Route;

/** Boots the dedicated application, never tests the retail bootstrap as a proxy. */
final class EnterpriseRuntimeTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_runtime_paths_and_generated_caches_are_separate_from_retail(): void
    {
        $normalize = fn (string $path) => str_replace('\\', '/', $path);
        $runtime = $normalize($this->app->basePath('apps/enterprise'));
        $this->assertInstanceOf(EnterpriseApplication::class, $this->app);
        $this->assertSame($runtime, $normalize($this->app->environmentPath()));
        $this->assertSame($runtime.'/storage', $normalize($this->app->storagePath()));
        $this->assertSame($runtime.'/config', $normalize($this->app->configPath()));
        $this->assertSame($runtime.'/public', $normalize($this->app->publicPath()));
        foreach ([$this->app->getCachedConfigPath(), $this->app->getCachedRoutesPath(), $this->app->getCachedServicesPath(), $this->app->getCachedPackagesPath(), $this->app->getCachedEventsPath()] as $path) {
            $this->assertStringStartsWith($this->app->bootstrapPath('cache'), $path);
        }
        $this->assertSame($this->app->storagePath('framework/views'), config('view.compiled'));
        $this->assertSame($this->app->storagePath('app/team-private'), config('filesystems.disks.team_private.root'));
    }

    public function test_only_explicit_enterprise_providers_routes_and_commands_are_composed(): void
    {
        $loaded = $this->app->getLoadedProviders();
        $this->assertTrue($loaded[EnterpriseServiceProvider::class] ?? false);
        foreach ([\App\Providers\AppServiceProvider::class, \App\Providers\FortifyServiceProvider::class, \App\Providers\PlatformMailServiceProvider::class, \Laravel\Fortify\FortifyServiceProvider::class] as $provider) {
            $this->assertArrayNotHasKey($provider, $loaded);
        }
        $this->assertSame([], $this->app->make(PackageManifest::class)->providers());
        $this->assertTrue(config('enterprise.runtime'));
        $this->assertTrue(Route::has('login'));
        $this->assertTrue(Route::has('verification.notice'));
        foreach (['register', 'password.email', 'retail.plan', 'shopify.app', 'billing.checkout', 'team.asana.connect', 'team.operations.dispatch'] as $name) {
            $this->assertFalse(Route::has($name), $name.' must stay outside Enterprise');
        }
        foreach (Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('~^(shopify|retail|billing|payments|api/mobile|platform/demos)(/|$)~', $route->uri());
        }
        $commands = $this->app->make(Kernel::class)->all();
        $this->assertArrayNotHasKey('everbranch:prepare-collins-electric', $commands);
        $this->assertSame([], $this->app->make(Schedule::class)->events());
    }

    public function test_database_sessions_and_delivery_have_no_retail_fallback(): void
    {
        $this->assertSame('enterprise', config('database.default'));
        $this->assertSame(['enterprise'], array_keys(config('database.connections')));
        $this->assertNull(config('database.connections.enterprise.url'));
        $this->assertSame('everbranch_enterprise_session', config('session.cookie'));
        $this->assertNull(config('session.domain'));
        $this->assertTrue(config('session.secure'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame(['array'], array_keys(config('mail.mailers')));
        $this->assertSame('null', config('queue.default'));
        $this->assertSame(['null'], array_keys(config('queue.connections')));
        $this->assertSame(['local', 'team_private'], array_keys(config('filesystems.disks')));
    }

    public function test_http_fails_closed_until_explicit_isolation_and_database_configuration(): void
    {
        config(['enterprise_runtime.isolated' => false]);
        $this->get('/login')->assertStatus(503)->assertSeeText('Enterprise runtime is not configured.');
        config(['enterprise_runtime.isolated' => true, 'app.key' => null]);
        $this->get('/login')->assertStatus(503);
        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=', 'database.connections.enterprise.database' => '']);
        $this->get('/login')->assertStatus(503);
    }

    public function test_isolated_runtime_never_exposes_retail_http_surfaces(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');
        config([
            'enterprise_runtime.isolated' => true,
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        foreach (['/shopify/app', '/retail/plan', '/payments', '/register', '/platform/demos/lees-mcrae'] as $uri) {
            $this->get($uri)->assertNotFound()->assertSeeText('Page not found.')->assertDontSee('build/manifest.json');
        }
    }

    public function test_file_sqlite_is_limited_to_the_disposable_browser_directory_in_testing(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $fixture = $directory.'/enterprise-browser-runtime-fixture.sqlite';
        file_put_contents($fixture, '');
        try {
            config([
                'enterprise_runtime.isolated' => true,
                'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
                'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => $fixture],
            ]);
            $this->assertTrue(EnsureEnterpriseRuntime::configured());
            config(['database.connections.enterprise.database' => base_path('database/database.sqlite')]);
            $this->assertFalse(EnsureEnterpriseRuntime::configured());
            config(['database.connections.enterprise.database' => $fixture]);
            $this->app->detectEnvironment(fn () => 'production');
            $this->assertFalse(EnsureEnterpriseRuntime::configured());
        } finally {
            unlink($fixture);
        }
    }

    public function test_production_runtime_refuses_an_unrecognized_host_before_database_access(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'enterprise_runtime.isolated' => true,
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'app.url' => 'https://synthetic-enterprise.invalid',
            'database.connections.enterprise' => ['driver' => 'mysql', 'database' => 'synthetic_enterprise', 'host' => '127.0.0.1', 'username' => 'synthetic'],
        ]);
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->get('https://unrecognized.invalid/login')->assertNotFound();
        $this->assertSame(0, $queries);
    }
}
