<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Provisioning\ClientProvisioner;
use App\Http\Middleware\EnterpriseCsrfToken;
use App\Models\Tenant;
use App\Models\User;
use App\Proposals\CampusReviewManifest;
use App\Proposals\Comment;
use App\Proposals\LegacyCampusArtifact;
use App\Proposals\LegacyCampusRenderer;
use App\Proposals\LegacyCampusV5Definition;
use App\Proposals\ProposalService;
use App\Proposals\Version;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Actual isolated owner silo and mounted web kernel; reviewer names confer no employee identity. */
final class LegacyCampusRuntimeTest extends TestCase
{
    private const ORIGIN = 'https://proposal.example.invalid';

    private const PREFIX = '/enterprise-proposals';

    private const TOKEN = 'synthetic-proposal-csrf';

    private string $runtime;

    private string|false $previousRoot;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/enterprise-proposal-test-'.Str::uuid();
        foreach (['storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs',
            'storage/app/private', 'storage/app/team-private', 'bootstrap/cache'] as $path) {
            mkdir($this->runtime.'/'.$path, 0777, true);
        }
        file_put_contents($this->runtime.'/.env', '');
        putenv('ENTERPRISE_RUNTIME_ROOT='.$this->runtime);
        $app = (static fn (string $path) => require $path)(dirname(__DIR__, 3).'/apps/enterprise/bootstrap/app.php');
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
            if (str_starts_with($this->runtime, sys_get_temp_dir().'/enterprise-proposal-test-')) {
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

    private function legacy(): array
    {
        [$tenant, $owner] = $this->owner();
        config(['enterprise.reviewer_demo_enabled' => true, 'service_proposals.campus_review.enabled' => true,
            'service_proposals.campus_review.build_id' => 'synthetic-new-release', 'service_proposals.campus_review.legacy_versions' => []]);
        // Publish using the old source binding; no immutable row is rewritten or copied into a new edition.
        app()->instance(CampusReviewManifest::class, new class extends CampusReviewManifest
        {
            public function current(): array
            {
                return (new LegacyCampusArtifact)->manifest();
            }
        });
        ['version' => $version, 'token' => $token] = $this->publication($tenant, $owner);
        app()->instance(CampusReviewManifest::class, new CampusReviewManifest);
        config(['service_proposals.campus_review.legacy_versions' => [$version->id => ['tenant_id' => $tenant->id,
            'content_hash' => $version->content_hash, 'renderer' => LegacyCampusV5Definition::RENDERER]]]);
        $path = $this->url('/service-proposals/'.$token);
        $binding = ['version_hash' => $version->content_hash, 'build_id' => $version->payload['campus_review']['build_id'],
            'source_hash' => $version->payload['campus_review']['source_hash']];

        return compact('tenant', 'owner', 'version', 'token', 'path', 'binding');
    }

    public function test_mounted_legacy_retains_exact_version_password_name_comments_idempotency_and_original_binding(): void
    {
        extract($this->legacy());
        $raw = DB::table('service_proposal_versions')->where('id', $version->id)->first();
        $this->get($path.'/campus/projects')->assertRedirect($path);
        $this->postWithToken($path.'/unlock', ['password' => 'Synthetic reviewer password'])->assertRedirect();
        $this->get($path.'/campus/projects')->assertOk()->assertSee('Coming soon')->assertDontSee('OWNER PRIVATE CATALOG SENTINEL');
        $this->post($path.'/campus/projects/comments', [])->assertStatus(419);
        $this->postWithToken($path.'/campus/projects/reviewer-name', [...$binding, 'reviewer_name' => 'Ross'])->assertRedirect();
        $input = [...$binding, 'body' => '<shared old feedback & intact>', 'comment_key' => (string) Str::uuid(), 'expected_reviewer_name' => 'Ross'];
        $this->postWithToken($path.'/campus/projects/comments', $input)->assertRedirect();
        $cipher = DB::table('service_proposal_comments')->value('payload');
        $this->postWithToken($path.'/campus/projects/comments', $input)->assertRedirect();
        $this->assertSame($cipher, DB::table('service_proposal_comments')->value('payload'));
        $this->assertSame(1, Comment::count());
        $this->postWithToken($path.'/activity', ['event' => 'campus_page', 'page' => 'projects', 'event_id' => (string) Str::uuid(),
            'version_hash' => $version->content_hash])->assertOk()->assertJsonPath('recorded', true);
        $this->postWithToken($path.'/activity', ['event' => 'tour_scene', 'scene' => 3, 'event_id' => (string) Str::uuid(),
            'version_hash' => $version->content_hash])->assertOk()->assertJsonPath('recorded', true);
        $context = $this->getJson($path.'/campus/projects/review')->assertOk()->assertJsonPath('review.reviewer_name', 'Ross')
            ->assertJsonPath('review.version_id', $version->id)->assertJsonPath('review.version_hash', $version->content_hash)
            ->assertJsonPath('review.source_hash', $binding['source_hash'])->assertJsonPath('review.comments.0.body', $input['body']);
        $this->assertSame($raw->payload, DB::table('service_proposal_versions')->where('id', $version->id)->value('payload'));
        $this->assertSame($raw->content_hash, $version->fresh()->content_hash);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('tenant_user')->count());
        $this->assertNull(auth()->id());
        $this->postWithToken($path.'/campus/projects/comments', [...$input, 'source_hash' => str_repeat('c', 64)])->assertConflict();
        $this->assertSame(1, Comment::count());
        config(['service_proposals.campus_review.legacy_versions' => []]);
        $this->get($path.'/campus/projects')->assertConflict();
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_mounted_registered_legacy_still_requires_current_host_password_grant_and_revocation(): void
    {
        extract($this->legacy());
        $this->get(str_replace('proposal.example.invalid', 'foreign.invalid', $path).'/campus/projects')->assertNotFound();
        $this->postWithToken($path.'/unlock', ['password' => 'Synthetic reviewer password'])->assertRedirect();
        $this->get($path.'/campus/projects')->assertOk();
        $this->withSession(['service_proposal_access.'.$version->id => ['hash' => str_repeat('b', 64), 'until' => now()->addMinute()->timestamp]])
            ->get($path.'/campus/projects')->assertRedirect($path);
        $this->postWithToken($path.'/unlock', ['password' => 'Synthetic reviewer password'])->assertRedirect();
        $version->update(['revoked_at' => now()]);
        $this->get($path.'/campus/projects')->assertGone();
        $this->assertSame(0, Comment::count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_mounted_pre_registry_null_and_malformed_expiry_close_without_any_rendering_or_public_content(): void
    {
        extract($this->legacy());
        $this->postWithToken($path.'/unlock', ['password' => 'Synthetic reviewer password'])->assertRedirect();
        $this->get($path.'/campus/home')->assertOk();
        $nullHydration = false;
        // The native schema correctly forbids SQL NULL; this exercises a malformed input Version after real query hydration.
        \Illuminate\Support\Facades\Event::listen('eloquent.retrieved: '.Version::class, function (Version $row) use (&$nullHydration): void {
            if ($nullHydration) {
                $attributes = $row->getAttributes();
                $attributes['expires_at'] = null;
                $row->setRawAttributes($attributes);
            }
        });
        foreach ([null, 'not-a-date', now()->subMinute()->toDateTimeString()] as $expiry) {
            $nullHydration = $expiry === null;
            if (! $nullHydration) {
                DB::table('service_proposal_versions')->where('id', $version->id)->update(['expires_at' => $expiry]);
            }
            $this->get($path.'/campus/home')->assertGone()->assertDontSee('Lees-McRae campus demonstration');
            $this->get($path)->assertGone()->assertDontSee(e($version->payload['title']), false);
        }
        $nullHydration = false;
        DB::table('service_proposal_versions')->where('id', $version->id)->update(['expires_at' => $version->expires_at]);
        $this->get($path.'/campus/home')->assertOk();
        Http::assertNothingSent();
    }

    public function test_mounted_render_buffer_rechecks_current_access_after_rendering(): void
    {
        extract($this->legacy());
        $this->postWithToken($path.'/unlock', ['password' => 'Synthetic reviewer password'])->assertRedirect();
        // Container beforeResolving occurs after controller context but before actual renderer construction.
        app()->beforeResolving(LegacyCampusRenderer::class, function () use ($version): void {
            DB::table('service_proposal_versions')->where('id', $version->id)->update(['revoked_at' => now()]);
        });
        $this->get($path.'/campus/home')->assertGone()->assertDontSee('Lees-McRae campus demonstration');
        $this->assertSame(0, Comment::count());
        Http::assertNothingSent();
    }
}
