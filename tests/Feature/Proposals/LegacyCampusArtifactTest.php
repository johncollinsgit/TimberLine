<?php

namespace Tests\Feature\Proposals;

use App\Proposals\CampusReviewManifest;
use App\Proposals\LegacyCampusArtifact;
use App\Proposals\LegacyCampusRegistry;
use App\Proposals\LegacyCampusV5Definition;
use App\Proposals\Version;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Private native bootstrap, synthetic models, no SQL/HTTP and no repository artifact edits. */
final class LegacyCampusArtifactTest extends TestCase
{
    private string $runtime;

    private string|false $previousRoot;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/legacy-campus-test-'.Str::uuid();
        foreach (['storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs', 'bootstrap/cache'] as $path) {
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
        $app['config']->set(['app.env' => 'testing', 'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'database.default' => 'enterprise', 'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array', 'session.driver' => 'array', 'service_proposals.campus_review' => ['enabled' => true, 'build_id' => 'synthetic-new-build', 'legacy_versions' => []],
            'service_proposals.owner_workspaces' => [1 => 'enterprise']]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        DB::connection()->beforeExecuting(static function (): never {
            throw new \RuntimeException('No SQL is allowed in public artifact/registry checks.');
        });
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            parent::tearDown();
        } finally {
            putenv($this->previousRoot === false ? 'ENTERPRISE_RUNTIME_ROOT' : 'ENTERPRISE_RUNTIME_ROOT='.$this->previousRoot);
            if (str_starts_with($this->runtime, sys_get_temp_dir().'/legacy-campus-test-')) {
                (new Filesystem)->deleteDirectory($this->runtime);
            }
        }
    }

    private function copyArtifact(): string
    {
        $path = $this->runtime.'/artifact';
        (new Filesystem)->copyDirectory(base_path('resources/legacy-campus/v5'), $path);

        return realpath($path);
    }

    private function version(): Version
    {
        return new Version(['id' => (string) Str::uuid(), 'tenant_id' => 1, 'content_hash' => str_repeat('a', 64),
            'expires_at' => now()->addDay(), 'revoked_at' => null, 'payload' => ['client' => ['reference' => 'lees-mcrae-college'],
                'audience' => 'reviewer', 'campus_review' => (new LegacyCampusArtifact)->manifest()]]);
    }

    private function allow(Version $version): void
    {
        config(['service_proposals.campus_review.legacy_versions' => [$version->id => ['tenant_id' => 1,
            'content_hash' => $version->content_hash, 'renderer' => LegacyCampusV5Definition::RENDERER]]]);
    }

    private function refused(callable $run, int $status = 503): void
    {
        try {
            $run();
            $this->fail('Unsafe or unmatched legacy input must be refused.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_exact_public_artifact_is_complete_and_the_two_old_manifest_omissions_are_verified(): void
    {
        $artifact = new LegacyCampusArtifact;
        $this->assertCount(51, $artifact->viewBytes());
        $this->assertCount(14, $artifact->manifest()['pages']);
        $this->assertSame('856f52094af909b05cc28f79e86476c765c12963844ccf934dad61ae92669e74', $artifact->manifest()['source_hash']);
        $this->assertStringStartsWith("\x89PNG", $artifact->bytes('resources/js/enterprise/reviewer/assets/lees-mcrae-official-wordmark.png'));
        $this->assertNotEmpty($artifact->bytes('resources/views/enterprise/partials/usage-overview.blade.php'));
        foreach ($artifact->viewBytes() as $path => $bytes) {
            $this->assertStringNotContainsString('resource_path(', $bytes);
            $this->assertStringNotContainsString('file_get_contents(', $bytes);
            $this->assertStringStartsWith($artifact->viewRoot().'/', $path);
        }
        $this->refused(fn () => $artifact->bytes('../../.env'));
        $this->refused(fn () => $artifact->bytes('app/Proposals/Access.php'));
    }

    public static function tampering(): array
    {
        return ['nested original wordmark' => ['source/resources/js/enterprise/reviewer/assets/lees-mcrae-official-wordmark.png'],
            'nested original partial' => ['source/resources/views/enterprise/partials/usage-overview.blade.php'],
            'derived Blade' => ['views/enterprise/app.blade.php'], 'compiled JS' => ['source/apps/enterprise/public/build/assets/workspace-DyBZPYb_.js'],
            'compiled manifest' => ['source/apps/enterprise/public/build/manifest.json'], 'artifact index' => ['index.json']];
    }

    #[DataProvider('tampering')]
    public function test_every_kind_of_indexed_public_byte_tampering_is_refused(string $relative): void
    {
        $copy = $this->copyArtifact();
        file_put_contents($copy.'/'.$relative, "\nchanged", FILE_APPEND);
        $this->refused(fn () => new LegacyCampusArtifact($copy));
    }

    public function test_missing_extra_alias_link_and_special_file_members_are_refused_before_loading(): void
    {
        $copy = $this->copyArtifact();
        $file = $copy.'/views/enterprise/app.blade.php';
        $original = file_get_contents($file);
        unlink($file);
        $this->refused(fn () => new LegacyCampusArtifact($copy));
        file_put_contents($file, $original);
        file_put_contents($copy.'/unlisted.txt', 'public extra');
        $this->refused(fn () => new LegacyCampusArtifact($copy));
        unlink($copy.'/unlisted.txt');
        mkdir($copy.'/unlisted-directory');
        $this->refused(fn () => new LegacyCampusArtifact($copy));
        rmdir($copy.'/unlisted-directory');
        rename($file, $file.'.retained');
        symlink($file.'.retained', $file);
        $this->refused(fn () => new LegacyCampusArtifact($copy));
        unlink($file);
        rename($file.'.retained', $file);
        link($file, $copy.'/linked');
        $this->refused(fn () => new LegacyCampusArtifact($copy));
        unlink($copy.'/linked');
        if (function_exists('posix_mkfifo')) {
            unlink($file);
            posix_mkfifo($file, 0600);
            $this->refused(fn () => new LegacyCampusArtifact($copy));
        }
    }

    public function test_verified_bytes_are_snapshotted_not_reread_from_a_changed_file(): void
    {
        $copy = $this->copyArtifact();
        $artifact = new LegacyCampusArtifact($copy);
        $before = $artifact->viewBytes();
        file_put_contents($copy.'/views/enterprise/app.blade.php', 'changed after verification');
        $this->assertSame($before, $artifact->viewBytes());
        $this->refused(fn () => new LegacyCampusArtifact($copy));
    }

    public function test_registry_is_empty_by_default_and_does_not_relax_current_source_admission(): void
    {
        $version = $this->version();
        $registry = new LegacyCampusRegistry;
        $manifests = new CampusReviewManifest;
        $this->assertFalse($registry->registered($version));
        $this->assertNull($registry->resolve($version, 'home'));
        $this->refused(fn () => $manifests->assertCurrent($version, 'home'), 409);
        $this->refused(fn () => $manifests->resolveReviewEdition($version, 'home'), 409);
        $this->allow($version);
        $before = $version->getAttributes();
        $this->assertTrue($registry->registered($version));
        $resolved = $manifests->resolveReviewEdition($version, 'projects');
        $this->assertSame('legacy_browser_only', $resolved['kind']);
        $this->assertSame($version->payload['campus_review'], $resolved['manifest']);
        $this->assertTrue($manifests->available($version));
        $this->refused(fn () => $manifests->assertCurrent($version, 'ask'), 409);
        $this->assertSame($before, $version->getAttributes());
    }

    public function test_exact_registry_identity_client_pages_audience_and_current_revocation_expiry_are_required(): void
    {
        foreach (['tenant', 'hash', 'manifest', 'client', 'audience', 'revoked', 'expired', 'null_expiry', 'malformed_expiry', 'page', 'fields'] as $case) {
            $version = $this->version();
            $this->allow($version);
            $page = 'home';
            $status = 404;
            if ($case === 'tenant') {
                $version->tenant_id = 2;
            }
            if ($case === 'hash') {
                $version->content_hash = str_repeat('b', 64);
            }
            if (in_array($case, ['manifest', 'client', 'audience'], true)) {
                $payload = $version->payload;
                if ($case === 'manifest') {
                    $payload['campus_review']['source_hash'] = str_repeat('c', 64);
                    $status = 409;
                } elseif ($case === 'client') {
                    $payload['client']['reference'] = 'other';
                } else {
                    $payload['audience'] = 'invented';
                } $version->payload = $payload;
            }
            if ($case === 'revoked') {
                $version->revoked_at = now();
                $status = 410;
            }
            if ($case === 'expired') {
                $version->expires_at = now()->subSecond();
                $status = 410;
            }
            if ($case === 'null_expiry') {
                $version->expires_at = null;
                $status = 410;
            }
            if ($case === 'malformed_expiry') {
                $attributes = $version->getAttributes();
                $attributes['expires_at'] = 'not-a-date';
                $version->setRawAttributes($attributes);
                $status = 410;
            }
            if ($case === 'page') {
                $page = 'unknown';
            }
            if ($case === 'fields') {
                config(['service_proposals.campus_review.legacy_versions.'.$version->id.'.extra' => true]);
                $status = 503;
            }
            $this->refused(fn () => (new LegacyCampusRegistry)->resolve($version, $page), $status);
        }
    }

    public function test_exact_future_ai_and_workspace_hooks_deny_registered_legacy_before_any_current_gate_or_browser_nonce(): void
    {
        // The combined candidate must exercise its actual application classes,
        // never standalone copies declaring the same App namespace.
        foreach ([\App\Proposals\ReviewerAiAccess::class, \App\Proposals\ReviewerWorkspaceSchema::class,
            \App\Proposals\ReviewerWorkspaceAccess::class] as $class) {
            $this->assertSame(base_path('app/Proposals/'.class_basename($class).'.php'), (new \ReflectionClass($class))->getFileName());
        }
        $version = $this->version();
        $this->allow($version);
        $access = new class($version) extends \App\Proposals\Access
        {
            public function __construct(private Version $version) {}

            public function recipient(\Illuminate\Http\Request $request, string $token): Version
            {
                return $this->version;
            }

            public function unlocked(\Illuminate\Http\Request $request, Version $version): bool
            {
                return true;
            }
        };
        $manifests = new class extends CampusReviewManifest
        {
            public function assertCurrent(Version $version, string $page): array
            {
                throw new \LogicException('The explicit legacy deny must run first.');
            }
        };
        config(['app.url' => 'https://proposal.example.invalid', 'enterprise_runtime.base_path' => '', 'enterprise.runtime' => true,
            'service_proposals.runtime_purpose' => 'proposal_owner', 'service_proposals.reviewer_ai.ui_enabled' => true,
            'service_proposals.reviewer_workspace.enabled' => true]);
        $request = \Illuminate\Http\Request::create('https://proposal.example.invalid/service-proposals/synthetic/campus/ask');
        $request->setLaravelSession(app('session')->driver());
        $before = $request->session()->all();
        $this->refused(fn () => (new \App\Proposals\ReviewerAiAccess($access, $manifests))->scope($request, 'synthetic', startBrowser: true), 404);
        $workspace = new \App\Proposals\ReviewerWorkspaceAccess($access, $manifests, new \App\Proposals\ReviewerWorkspaceSchema);
        $this->refused(fn () => $workspace->publication($version), 404);
        $this->refused(fn () => $workspace->scope($request, 'synthetic', []), 404);
        $this->assertSame($before, $request->session()->all());
        $this->assertNull($request->user());
    }
}
