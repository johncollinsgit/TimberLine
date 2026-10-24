<?php

namespace Tests\Feature\Proposals;

use App\Proposals\CampusReviewManifest;
use App\Proposals\Version;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Real manifest and published-version checks against a private source fixture; no repository asset writes or SQL. */
final class CampusReviewManifestAssetsTest extends TestCase
{
    private string $runtime;

    private string|false $previousRoot;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/campus-review-assets-test-'.Str::uuid();
        foreach (['storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs', 'bootstrap/cache', 'source/resources/images/enterprise'] as $path) {
            mkdir($this->runtime.'/'.$path, 0700, true);
        }
        file_put_contents($this->runtime.'/.env', '');
        putenv('ENTERPRISE_RUNTIME_ROOT');
        $app = (static fn (string $path) => require $path)(dirname(__DIR__, 3).'/apps/enterprise/bootstrap/app.php');
        $app->useEnvironmentPath($this->runtime);
        $app->useStoragePath($this->runtime.'/storage');
        $app->useBootstrapPath($this->runtime.'/bootstrap');
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set([
            'app.env' => 'testing', 'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array', 'session.driver' => 'array',
            'service_proposals.campus_review' => ['enabled' => true, 'build_id' => 'synthetic-reviewed-logo-build'],
            'service_proposals.owner_workspaces' => [1 => 'enterprise'],
        ]);
        $app->setBasePath($this->runtime.'/source');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        DB::connection()->beforeExecuting(static function (): never {
            throw new \RuntimeException('Source-manifest checks must not execute SQL.');
        });
        foreach (['svg', 'png'] as $extension) {
            file_put_contents($this->logoPath($extension), 'Synthetic reviewed '.$extension.' bytes');
        }
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            parent::tearDown();
        } finally {
            putenv($this->previousRoot === false ? 'ENTERPRISE_RUNTIME_ROOT' : 'ENTERPRISE_RUNTIME_ROOT='.$this->previousRoot);
            if (str_starts_with($this->runtime, sys_get_temp_dir().'/campus-review-assets-test-')) {
                (new Filesystem)->deleteDirectory($this->runtime);
            }
        }
    }

    private function logoPath(string $extension): string
    {
        return $this->runtime.'/source/resources/images/enterprise/Everbranch-Rooted-Peak-Aligned.'.$extension;
    }

    private function publishedVersion(array $manifest): Version
    {
        return new Version(['tenant_id' => 1, 'expires_at' => now()->addDay(), 'revoked_at' => null,
            'payload' => ['client' => ['reference' => CampusReviewManifest::CLIENT_REFERENCE], 'audience' => 'reviewer', 'campus_review' => $manifest]]);
    }

    private function assertOldVersionRejected(CampusReviewManifest $real, Version $version): void
    {
        $this->assertFalse($real->available($version));
        try {
            $real->assertCurrent($version, 'home');
            $this->fail('Changed or missing logo bytes must invalidate the published source binding.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    public static function logoAssets(): array
    {
        return [
            'changed approved vector' => ['svg', 'changed'], 'missing approved vector' => ['svg', 'missing'],
            'changed approved raster fallback' => ['png', 'changed'], 'missing approved raster fallback' => ['png', 'missing'],
        ];
    }

    #[DataProvider('logoAssets')]
    public function test_changed_or_missing_exact_logo_asset_invalidates_a_published_review_and_restoration_recovers_it(string $extension, string $drift): void
    {
        $real = new CampusReviewManifest;
        $manifest = $real->current();
        $this->assertSame($manifest, $real->current());
        $version = $this->publishedVersion($manifest);
        $evidence = $version->getAttributes();
        $this->assertSame($manifest, $real->assertCurrent($version, 'home'));
        $this->assertTrue($real->available($version));
        $path = $this->logoPath($extension);
        $original = file_get_contents($path);

        if ($drift === 'changed') {
            file_put_contents($path, $original.' changed');
        } else {
            unlink($path);
        }
        $this->assertNotSame($manifest['source_hash'], $real->current()['source_hash']);
        $this->assertOldVersionRejected($real, $version);
        $this->assertSame($evidence, $version->getAttributes());

        file_put_contents($path, $original);
        $this->assertSame($manifest, $real->assertCurrent($version, 'home'));
        $this->assertSame($evidence, $version->getAttributes());
    }

    public function test_logo_binding_uses_fixed_paths_and_ignores_unlisted_image_files(): void
    {
        $real = new CampusReviewManifest;
        $manifest = $real->current();
        file_put_contents($this->runtime.'/source/resources/images/enterprise/unlisted.svg', 'Unlisted synthetic bytes');
        $this->assertSame($manifest, $real->current());
    }
}
