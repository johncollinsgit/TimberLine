<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Provisioning\ClientProvisioner;
use App\Http\Middleware\EnterpriseCsrfToken;
use App\Models\Tenant;
use App\Models\User;
use App\Proposals\ProposalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Actual isolated owner silo and mounted web kernel; reviewer names confer no employee identity. */
final class EnterpriseProposalPdfRuntimeTest extends TestCase
{
    private const ORIGIN = 'https://proposal.example.invalid';

    private const PREFIX = '/enterprise-proposals';

    private const TOKEN = 'synthetic-proposal-csrf';

    private string $runtime;

    private string|false $previousRoot;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/enterprise-pdf-independent-'.Str::uuid();
        foreach (['storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs',
            'storage/app/private', 'storage/app/team-private', 'bootstrap/cache'] as $path) {
            mkdir($this->runtime.'/'.$path, 0777, true);
        }
        file_put_contents($this->runtime.'/.env', '');
        putenv('ENTERPRISE_RUNTIME_ROOT='.$this->runtime);
        $app = require dirname(__DIR__, 3).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set([
            'app.env' => 'testing', 'app.url' => self::ORIGIN,
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'enterprise_runtime.isolated' => true, 'enterprise_runtime.base_path' => self::PREFIX,
            'enterprise_runtime.trial_mount_only' => false, 'session.path' => self::PREFIX,
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'enterprise_client.client_id' => '71000000-0000-4000-8000-000000000001',
            'enterprise_client.silo_id' => '71000000-0000-4000-8000-000000000002',
            'enterprise_client.storage_id' => '71000000-0000-4000-8000-000000000003',
            'enterprise_client.environment' => 'testing', 'enterprise_client.database' => ':memory:',
            'session.driver' => 'array', 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4,
        ]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        $this->withCredentials();
        $this->withServerVariables(['SCRIPT_FILENAME' => $this->app->publicPath('index.php'),
            'SCRIPT_NAME' => self::PREFIX.'/index.php', 'PHP_SELF' => self::PREFIX.'/index.php']);
        $this->app->bind(EnterpriseCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends EnterpriseCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            putenv($this->previousRoot === false ? 'ENTERPRISE_RUNTIME_ROOT' : 'ENTERPRISE_RUNTIME_ROOT='.$this->previousRoot);
            if (str_starts_with($this->runtime, sys_get_temp_dir().'/enterprise-pdf-independent-')) {
                (new Filesystem)->deleteDirectory($this->runtime);
            }
        }
    }

    private function owner(): array
    {
        $manifest = ['schema_version' => 1, 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'),
            'environment' => 'testing', 'storage_id' => config('enterprise_client.storage_id'),
            'tenant' => ['business_id' => '72000000-0000-4000-8000-000000000001', 'slug' => 'synthetic-proposal-owner', 'name' => 'Synthetic proposal owner'],
            'branding' => ['organization_name' => 'Synthetic proposal owner', 'accent' => '#123456'],
            'departments' => [['id' => '73000000-0000-4000-8000-000000000001', 'key' => 'administration', 'name' => 'Administration', 'visibility' => 'department_only']],
            'locations' => [['id' => '74000000-0000-4000-8000-000000000001', 'key' => 'private-owner-vault',
                'department_key' => 'administration', 'name' => 'OWNER PRIVATE CATALOG SENTINEL', 'source_url' => null,
                'source_label' => 'Synthetic private owner configuration']], 'workflow_defaults' => [], 'role_defaults' => []];
        $provisioner = app(ClientProvisioner::class);
        $result = $provisioner->apply($manifest, $provisioner->plan($manifest)['plan_hash']);
        $this->assertFalse($result['access_provisioned']);
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenant_user')->count());
        $tenant = Tenant::query()->findOrFail($result['tenant_id']);
        // Test-only explicit owner activation; neither migration nor provisioning grants this.
        $owner = new User;
        $owner->forceFill(['name' => 'Synthetic proposal owner', 'email' => 'owner@example.invalid', 'password' => 'fixture-only',
            'is_active' => true, 'email_verified_at' => now(), 'role' => 'member'])->save();
        $tenant->users()->attach($owner->id, ['role' => 'owner', 'membership_active' => true]);
        config(['service_proposals.enabled' => true, 'service_proposals.writes_enabled' => true, 'service_proposals.publish_enabled' => true,
            'service_proposals.runtime_purpose' => 'proposal_owner', 'service_proposals.owner_client_id' => config('enterprise_client.client_id'),
            'service_proposals.owner_silo_id' => config('enterprise_client.silo_id'), 'service_proposals.owner_workspaces' => [$tenant->id => 'enterprise'],
            'service_proposals.recipient_hosts' => ['proposal.example.invalid']]);

        return [$tenant, $owner];
    }

    private function url(string $path): string
    {
        return self::ORIGIN.self::PREFIX.$path;
    }

    private function ownerPath(Tenant $tenant): string
    {
        return $this->url('/owner-workspaces/'.$tenant->slug.'/proposals');
    }

    private function postWithToken(string $url, array $input)
    {
        return $this->withSession(['_token' => self::TOKEN])->post($url, $input, ['X-CSRF-TOKEN' => self::TOKEN]);
    }

    private function publication(Tenant $tenant, User $owner): array
    {
        $service = app(ProposalService::class);
        $draft = $service->leesMcRaeDraft($tenant, $owner->id)->refresh();

        return $service->publish($tenant, $owner->id, $draft->id, $draft->revision,
            'Synthetic reviewer password', 7, Str::uuid()->toString(), 'reviewer');
    }

    public function test_exact_managed_owner_and_recipient_pdf_routes_retain_boundary_and_response_policy(): void
    {
        [$tenant, $owner] = $this->owner();
        $this->assertSame(base_path('app/Proposals/PdfDocument.php'), (new \ReflectionClass(\App\Proposals\PdfDocument::class))->getFileName());
        $this->assertSame(base_path('app/Proposals/PdfController.php'), (new \ReflectionClass(\App\Proposals\PdfController::class))->getFileName());
        $this->assertSame(base_path('apps/enterprise/public'), public_path());
        $this->assertFileExists(base_path('public/images/proposals/evergrove-software-wordmark.jpg'));
        ['version' => $version, 'token' => $token] = $this->publication($tenant, $owner);
        $ownerPdf = $this->ownerPath($tenant).'/'.$version->draft_id.'/pdf';
        $before = [];
        foreach (['assets', 'drafts', 'versions', 'reviews', 'comments'] as $table) {
            $before[$table] = DB::table('service_proposal_'.$table)->orderBy('id')->get()->toJson();
        }
        $this->actingAs($owner)->get($ownerPdf)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $url = $this->url('/service-proposals/'.$token);
        $this->get($url.'/pdf')->assertForbidden();
        $this->postWithToken($url.'/unlock', ['password' => 'Synthetic reviewer password'])->assertRedirect();
        $download = $this->get($url.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $download->getContent());
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->assertStringContainsString("default-src 'none'", $download->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        foreach ($before as $table => $raw) {
            $this->assertSame($raw, DB::table('service_proposal_'.$table)->orderBy('id')->get()->toJson());
        }
        $this->get(str_replace('proposal.example.invalid', 'foreign.example.invalid', $url).'/pdf')->assertNotFound();
        $version->update(['revoked_at' => now()]);
        $this->get($url.'/pdf')->assertGone();
        config(['service_proposals.owner_silo_id' => Str::uuid()->toString()]);
        $this->get($ownerPdf)->assertStatus(503);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_render_failure_returns_no_pdf_and_leaves_no_private_temp_directory(): void
    {
        [$tenant, $owner] = $this->owner();
        $draft = app(ProposalService::class)->leesMcRaeDraft($tenant, $owner->id)->refresh();
        $path = $this->ownerPath($tenant).'/'.$draft->id.'/pdf';
        \Illuminate\Support\Facades\File::partialMock()->shouldReceive('get')->once()
            ->with(base_path('public/images/proposals/evergrove-software-wordmark.jpg'))->andThrow(new \RuntimeException('Independent artwork failure'));
        $this->actingAs($owner)->get($path)->assertStatus(500)->assertDontSee('%PDF-');
        $this->assertSame([], \Illuminate\Support\Facades\File::directories(storage_path('app/private/proposal-pdf')));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
