<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Provisioning\ClientProvisioner;
use App\Enterprise\Provisioning\ClientSilo;
use App\Http\Middleware\EnterpriseCsrfToken;
use App\Models\Tenant;
use App\Models\User;
use App\Proposals\Comment;
use App\Proposals\ProposalService;
use App\Proposals\Version;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** Actual isolated owner silo and mounted web kernel; reviewer names confer no employee identity. */
final class EnterpriseProposalRuntimeTest extends TestCase
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

    public function test_clean_runtime_composes_disabled_routes_and_empty_proposal_schema_without_grants(): void
    {
        $this->assertFalse(config('service_proposals.enabled'));
        $this->assertFalse(config('service_proposals.writes_enabled'));
        $this->assertFalse(config('service_proposals.publish_enabled'));
        $this->assertSame([], config('service_proposals.owner_workspaces'));
        $this->assertSame([], config('service_proposals.recipient_hosts'));
        $this->assertTrue(Route::has('service-proposals.owner.index'));
        $this->assertTrue(Route::has('service-proposals.recipient.comment'));
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'service-proposals.')) {
                $this->assertContains(\App\Enterprise\ProposalRuntimeBoundary::class, $route->gatherMiddleware());
                $route->prepareForSerialization();
                $this->assertNotEmpty(serialize($route));
            }
        }
        foreach (['assets', 'drafts', 'versions', 'reviews', 'comments'] as $kind) {
            $this->assertSame(0, DB::table('service_proposal_'.$kind)->count());
        }
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertSame(0, DB::table('tenant_user')->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_owner_runtime_purpose_and_silo_binding_fail_closed_for_an_ordinary_client(): void
    {
        [$tenant, $owner] = $this->owner();
        $this->actingAs($owner)->get($this->ownerPath($tenant))->assertOk();
        config(['service_proposals.runtime_purpose' => '']);
        $this->get($this->ownerPath($tenant))->assertStatus(503);
        config(['service_proposals.runtime_purpose' => 'proposal_owner', 'service_proposals.owner_client_id' => Str::uuid()->toString()]);
        $this->get($this->ownerPath($tenant))->assertStatus(503);
        config(['service_proposals.owner_client_id' => config('enterprise_client.client_id'), 'service_proposals.owner_silo_id' => Str::uuid()->toString()]);
        $this->get($this->ownerPath($tenant))->assertStatus(503);
        config(['service_proposals.owner_silo_id' => config('enterprise_client.silo_id'), 'service_proposals.owner_workspaces' => [$tenant->id => 'regular']]);
        $this->get($this->ownerPath($tenant))->assertStatus(503);
        config(['service_proposals.owner_workspaces' => [$tenant->id => 'enterprise', 9999 => 'enterprise']]);
        $this->get($this->ownerPath($tenant))->assertStatus(503);
        config(['service_proposals.enabled' => false]);
        $this->get($this->ownerPath($tenant))->assertNotFound();
        $this->assertSame(1, DB::table('tenants')->count());
        $this->assertSame(1, DB::table('tenant_user')->count());
        Http::assertNothingSent();
    }

    public function test_owner_uses_current_local_membership_and_csrf_without_team_entitlement_or_retail_fallback(): void
    {
        [$tenant, $owner] = $this->owner();
        $path = $this->ownerPath($tenant);
        $this->actingAs($owner)->get($path)->assertOk()->assertSee('Owner workspace');
        $this->post($path.'/lees-mcrae-draft')->assertStatus(419);
        $this->postWithToken($path.'/lees-mcrae-draft', [])->assertRedirect();
        $this->assertSame(1, DB::table('service_proposal_drafts')->count());
        $this->assertSame(0, DB::table('tenant_access_profiles')->count());
        $this->assertSame(0, DB::table('tenant_module_entitlements')->count());
        $tenant->users()->updateExistingPivot($owner->id, ['role' => 'member']);
        $this->get($path)->assertForbidden();
        $tenant->users()->updateExistingPivot($owner->id, ['role' => 'owner', 'membership_active' => false]);
        $this->get($path)->assertForbidden();
        $tenant->users()->updateExistingPivot($owner->id, ['membership_active' => true]);
        DB::table('users')->where('id', $owner->id)->update(['is_active' => false]);
        $this->actingAs($owner->fresh())->get($path)->assertForbidden();
        $this->get($this->url('/retail/plan'))->assertNotFound();
        $this->assertSame(1, DB::table('tenant_user')->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_anonymous_reviewers_keep_password_grants_names_and_comments_scoped_to_the_exact_version(): void
    {
        [$tenant, $owner] = $this->owner();
        ['version' => $version, 'token' => $token] = $this->publication($tenant, $owner);
        $path = $this->url('/service-proposals/'.$token);
        $this->get($path)->assertOk()->assertSee('A private proposal.')->assertDontSee('Lees-McRae');
        $this->postWithToken($path.'/comments', ['page' => 'overview', 'body' => 'Must not persist', 'comment_key' => Str::uuid()->toString()])->assertForbidden();
        $this->post($path.'/unlock', ['password' => 'Synthetic reviewer password'])->assertStatus(419);
        $this->postWithToken($path.'/unlock', ['password' => 'Synthetic reviewer password'])->assertRedirect($path);
        $this->get($path)->assertOk()->assertSee('Ross')->assertSee('Sarah')->assertSee('Paul')->assertSee('self-selected');
        $this->postWithToken($path.'/reviewer-name', ['reviewer_name' => 'Invented reviewer'])->assertSessionHasErrors('reviewer_name');
        $this->postWithToken($path.'/reviewer-name', ['reviewer_name' => 'Ross'])->assertRedirect($path);
        $input = ['page' => 'approach', 'body' => '<script>review sentinel</script>', 'comment_key' => Str::uuid()->toString()];
        $this->postWithToken($path.'/comments', $input)->assertRedirect();
        $this->postWithToken($path.'/comments', $input)->assertRedirect();
        $this->assertSame(1, Comment::query()->count());
        $comment = Comment::query()->sole();
        $this->assertSame('Ross', $comment->payload['name']);
        $this->assertSame('approach', $comment->payload['page']);
        $this->assertSame($version->id, $comment->version_id);
        $this->assertSame('self_selected_display_name', $comment->payload['identity_status']);
        $this->get($path)->assertOk()->assertSee('&lt;script&gt;review sentinel&lt;/script&gt;', false)->assertDontSee('<script>review sentinel</script>', false);
        ['token' => $otherToken] = $this->publication($tenant, $owner);
        $this->get($this->url('/service-proposals/'.$otherToken))->assertDontSee('review sentinel');
        $this->postWithToken($path.'/selection', [])->assertForbidden();
        $this->postWithToken($path.'/request-agreement', [])->assertForbidden();
        $this->assertSame(0, DB::table('service_proposal_reviews')->count());
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('tenant_user')->count());
        $this->assertNull(auth()->id());
        $version->update(['revoked_at' => now()]);
        $this->get($path)->assertGone();
        $this->postWithToken($path.'/comments', $input)->assertGone();
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_recipient_access_and_generated_links_preserve_the_approved_host_prefix_and_version_expiry(): void
    {
        [$tenant, $owner] = $this->owner();
        ['version' => $version, 'token' => $token] = $this->publication($tenant, $owner);
        $path = $this->url('/service-proposals/'.$token);
        $this->postWithToken($path.'/unlock', ['password' => 'Synthetic reviewer password'])->assertRedirect($path);
        $this->postWithToken($path.'/reviewer-name', ['reviewer_name' => 'Ross'])->assertRedirect($path);
        $response = $this->get($path)->assertOk()->assertSee('action="'.$path.'/comments"', false)->assertCookieMissing('XSRF-TOKEN');
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->get('https://other.example.invalid'.self::PREFIX.'/service-proposals/'.$token)->assertNotFound();
        $this->get(self::ORIGIN.'/service-proposals/'.$token)->assertNotFound();
        $this->travel(8)->days();
        $this->get($path)->assertGone();
        $this->assertSame(0, Comment::query()->count());
        $this->assertSame(1, Version::query()->count());
        Http::assertNothingSent();
    }

    public function test_private_schema_replay_and_code_rollback_preserve_encrypted_publications_and_comments(): void
    {
        [$tenant, $owner] = $this->owner();
        ['version' => $version] = $this->publication($tenant, $owner);
        app(ProposalService::class)->comment($version, 'Sarah', 'scope', 'Synthetic retained comment', Str::uuid()->toString());
        $ciphertext = DB::table('service_proposal_versions')->where('id', $version->id)->value('payload');
        $this->assertStringNotContainsString('Lees-McRae', $ciphertext);
        $migration = require database_path('migrations/2026_10_22_000700_create_enterprise_service_proposals.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame($ciphertext, DB::table('service_proposal_versions')->where('id', $version->id)->value('payload'));
        $this->assertSame(1, Comment::query()->count());
        $this->assertSame('Sarah', Comment::query()->sole()->payload['name']);
        $this->assertSame($tenant->id, (int) app(ClientSilo::class)->assertReady()->tenant_id);
        $this->assertSame(1, DB::table('tenants')->count());
        Http::assertNothingSent();
    }

    public function test_cached_routes_with_fresh_limiter_registration_keep_real_public_and_password_throttles(): void
    {
        [$tenant, $owner] = $this->owner();
        ['token' => $token] = $this->publication($tenant, $owner);
        $router = $this->app['router'];
        foreach ($router->getRoutes() as $route) {
            $route->prepareForSerialization();
        }
        $router->setCompiledRoutes($router->getRoutes()->compile());
        $limiter = new \Illuminate\Cache\RateLimiter($this->app['cache']->store('array'));
        \Illuminate\Support\Facades\RateLimiter::swap($limiter);
        $this->assertNull($limiter->limiter('service-proposals-public'));
        $this->assertNull($limiter->limiter('service-proposals-unlock'));
        // A cached boot executes providers, not the route file that originally named these limits.
        $this->app->call([new \App\Providers\EnterpriseServiceProvider($this->app), 'boot']);
        $this->assertNotNull($limiter->limiter('service-proposals-public'));
        $this->assertNotNull($limiter->limiter('service-proposals-unlock'));
        $path = $this->url('/service-proposals/'.$token);
        $this->get($path)->assertOk();
        $this->assertNotNull($limiter->limiter('service-proposals-public'));
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postWithToken($path.'/unlock', ['password' => 'Wrong synthetic password'])->assertSessionHasErrors('access');
        }
        $this->postWithToken($path.'/unlock', ['password' => 'Synthetic reviewer password'])->assertStatus(429);
        $this->assertSame(0, Comment::query()->count());
        $this->assertSame(1, DB::table('users')->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_password_campus_bridge_uses_only_public_catalog_for_anonymous_and_signed_in_owner_without_team_access(): void
    {
        [$tenant, $owner] = $this->owner();
        config(['enterprise.reviewer_demo_enabled' => true, 'service_proposals.campus_review' => ['enabled' => true, 'build_id' => 'synthetic-owner-runtime-build']]);
        $this->app->instance(\App\Proposals\CampusReviewManifest::class, new class extends \App\Proposals\CampusReviewManifest
        {
            protected function sourceHash(): string
            {
                return str_repeat('f', 64);
            }
        });
        ['token' => $token] = $this->publication($tenant, $owner);
        $path = $this->url('/service-proposals/'.$token);
        $this->postWithToken($path.'/unlock', ['password' => 'Synthetic reviewer password'])->assertRedirect($path);
        $this->assertNull(auth()->id());
        foreach ([false, true] as $signedIn) {
            if ($signedIn) {
                $this->actingAs($owner);
            }
            $response = $this->get($path.'/campus/buildings')->assertOk()->assertSee('lees-mcrae-official-map-v1')
                ->assertSee('"count":68', false)->assertDontSee('OWNER PRIVATE CATALOG SENTINEL')
                ->assertDontSee('client-'.config('enterprise_client.silo_id'))->assertDontSee($owner->email);
            $this->assertStringContainsString("connect-src 'self'", $response->headers->get('Content-Security-Policy'));
            $this->assertStringContainsString("script-src 'nonce-", $response->headers->get('Content-Security-Policy'));
        }
        $this->assertSame(1, DB::table('tenants')->count());
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('tenant_user')->count());
        $this->assertSame(0, DB::table('tenant_access_profiles')->count());
        $this->assertSame(0, DB::table('tenant_module_entitlements')->count());
        $this->assertSame(0, Comment::query()->count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
