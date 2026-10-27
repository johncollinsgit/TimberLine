<?php

namespace Tests\Feature\Proposals;

use App\Proposals\LegacyCampusArtifact;
use App\Proposals\LegacyCampusRenderer;
use App\Proposals\LegacyCampusViews;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Private native bootstrap, synthetic models, no SQL/HTTP and no repository artifact edits. */
final class LegacyCampusRendererTest extends TestCase
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

    private function context(string $page): array
    {
        $manifest = (new LegacyCampusArtifact)->manifest();
        $urls = ['proposal' => 'http://localhost/enterprise/proposals/synthetic'];
        foreach ($manifest['pages'] as $entry) {
            foreach (['pages' => '', 'data_pages' => '/review', 'name_pages' => '/name', 'comment_pages' => '/comment'] as $group => $suffix) {
                $urls[$group][$entry['id']] = 'http://localhost/enterprise/proposals/synthetic/campus/'.$entry['id'].$suffix;
            }
        }
        foreach (['current' => 'pages', 'data' => 'data_pages', 'name' => 'name_pages', 'comment' => 'comment_pages'] as $key => $group) {
            $urls[$key] = $urls[$group][$page];
        }

        return ['activity_url' => 'http://localhost/enterprise/proposals/synthetic/activity', 'version_id' => '11111111-1111-4111-8111-111111111111',
            'version_hash' => str_repeat('a', 64), 'build_id' => $manifest['build_id'], 'source_hash' => $manifest['source_hash'],
            'client_reference' => $manifest['client_reference'], 'page' => $page, 'pages' => $manifest['pages'], 'reviewer_name' => 'Andrew',
            'reviewer_names' => ['Andrew', 'Blaine'], 'comments' => [['name' => 'Blaine', 'page' => $page,
                'body' => '<private synthetic feedback & intact>', 'created_at' => '2026-10-24T00:00:00Z']],
            'comment_key' => '22222222-2222-4222-8222-222222222222', 'csrf_token' => 'synthetic-csrf', 'writes_enabled' => true, 'urls' => $urls,
            'coverage' => ['scanned_comments' => 1, 'scan_limit' => 2000, 'visible_limit' => 200, 'partial' => false]];
    }

    private function request(): Request
    {
        config(['enterprise.reviewer_demo_enabled' => true, 'enterprise.reviewer_trial_destination_approved' => false]);
        $request = Request::create('http://localhost/enterprise/proposals/synthetic/campus/home');
        $request->setLaravelSession(app('session')->driver());
        app()->instance('request', $request);

        return $request;
    }

    private function withoutNonce(string $value): string
    {
        return preg_replace('/nonce(?:="|-)[A-Za-z0-9+\/=]+/', 'nonce=REDACTED', $value);
    }

    public static function pages(): array
    {
        return array_map(fn ($page) => [$page], ['home', 'requests', 'projects', 'roll_call', 'buildings', 'calendar', 'people',
            'assets', 'workflows', 'finance', 'usage', 'ask', 'contractors', 'requester']);
    }

    #[DataProvider('pages')]
    public function test_all_original_pages_and_workflow_have_exact_rendered_and_csp_parity_except_fresh_nonce(string $page): void
    {
        $request = $this->request();
        $context = $this->context($page);
        $baselinePath = base_path('tests/Fixtures/legacy-campus-v5-render-baseline.json');
        $this->assertSame('16b0ab52c703508b02dcea2a388967fcc4992f94fe218485dba277bb5d287aac', hash_file('sha256', $baselinePath));
        $baseline = json_decode(file_get_contents($baselinePath), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('7610979cecc2eae3a8b83c210d08f57f30fef7a5', $baseline['source_commit']);
        $this->assertSame(\App\Proposals\LegacyCampusV5Definition::INDEX_SHA256, $baseline['artifact_index_sha256']);
        $this->assertCount(14, $baseline['pages']);
        $expected = $baseline['pages'][$page];
        $this->assertSame($expected['context_sha256'], hash('sha256', json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $legacy = (new LegacyCampusRenderer)->respond($request, $page === 'workflows', $context);
        $this->assertSame($expected['html_sha256'], hash('sha256', $this->withoutNonce($legacy->getContent())), $page.' HTML');
        foreach (['Content-Security-Policy', 'Cache-Control', 'Content-Type', 'Referrer-Policy', 'X-Robots-Tag', 'X-Content-Type-Options'] as $header) {
            $this->assertSame($expected['headers'][$header], $this->withoutNonce($legacy->headers->get($header)), $page.' '.$header);
        }
        $this->assertStringContainsString('no-store', $legacy->headers->get('Cache-Control'));
        $this->assertStringContainsString('connect-src \'self\'', $legacy->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('unsafe-inline', explode('; style-src', $legacy->headers->get('Content-Security-Policy'))[0]);
        $this->assertStringContainsString('&lt;private synthetic feedback &amp; intact&gt;', $legacy->getContent());
    }

    public function test_render_uses_only_cached_verified_views_and_assets_after_disk_changes_and_no_current_fallback(): void
    {
        $this->request();
        $directory = $this->runtime.'/artifact';
        (new Filesystem)->copyDirectory(base_path('resources/legacy-campus/v5'), $directory);
        $artifact = new LegacyCampusArtifact(realpath($directory));
        $renderer = new LegacyCampusRenderer($artifact);
        $before = $renderer->respond($this->request(), false, $this->context('home'));
        // Remove the entire checked disk closure; a standard Blade finder/compiler would now fail.
        (new Filesystem)->deleteDirectory($directory);
        $after = $renderer->respond($this->request(), false, $this->context('home'));
        $this->assertSame($this->withoutNonce($before->getContent()), $this->withoutNonce($after->getContent()));
        try {
            (new LegacyCampusViews($artifact))->render('enterprise.current-unlisted', []);
            $this->fail('Current views must never provide a fallback.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('An unlisted legacy view was refused.', $error->getMessage());
        }
    }

    public function test_current_kill_switch_host_query_and_exact_original_context_are_still_required(): void
    {
        foreach (['demo_off', 'review_off', 'foreign_host', 'query', 'source', 'page', 'wrong_workflow'] as $case) {
            $request = $this->request();
            config(['service_proposals.campus_review.enabled' => true]);
            $context = $this->context('home');
            $status = 404;
            if ($case === 'demo_off') {
                config(['enterprise.reviewer_demo_enabled' => false]);
            }
            if ($case === 'review_off') {
                config(['service_proposals.campus_review.enabled' => false]);
            }
            if ($case === 'foreign_host') {
                $request->headers->set('host', 'foreign.invalid');
            }
            if ($case === 'query') {
                $request->query->set('pretend', 'true');
            }
            if ($case === 'source') {
                $context['source_hash'] = str_repeat('b', 64);
                $status = 409;
            }
            if ($case === 'page') {
                $context['page'] = 'invented';
                $status = 409;
            }
            if ($case === 'wrong_workflow') {
                $status = 409;
            }
            try {
                (new LegacyCampusRenderer)->respond($request, $case === 'wrong_workflow', $context);
                $this->fail($case.' must be refused.');
            } catch (HttpException $error) {
                $this->assertSame($status, $error->getStatusCode(), $case);
            }
        }
    }
}
