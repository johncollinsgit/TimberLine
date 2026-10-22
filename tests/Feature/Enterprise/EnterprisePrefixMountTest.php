<?php

namespace Tests\Feature\Enterprise;

use App\Http\Middleware\EnterpriseCsrfToken;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** Actual dedicated HTTP kernel with server-provided SCRIPT_NAME/base URL, not an APP_URL-only mock. */
final class EnterprisePrefixMountTest extends TestCase
{
    private const ORIGIN = 'https://workspace.example.invalid';

    private const PREFIX = '/enterprise-campus';

    public function createApplication()
    {
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
            'session.driver' => 'array', 'cache.default' => 'array', 'cache.stores.array' => ['driver' => 'array', 'serialize' => false],
            'hashing.bcrypt.rounds' => 4, 'enterprise.sample_data' => true,
            'enterprise.trial.enabled' => false, 'enterprise.gmail.connection_enabled' => false, 'enterprise.gmail.send_enabled' => false,
        ]);
        DB::prohibitDestructiveCommands(false);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withServerVariables([
            'SCRIPT_FILENAME' => $this->app->publicPath('index.php'),
            'SCRIPT_NAME' => self::PREFIX.'/index.php', 'PHP_SELF' => self::PREFIX.'/index.php',
        ]);
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
    }

    private function url(string $path = ''): string
    {
        return self::ORIGIN.self::PREFIX.$path;
    }

    private function owner(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Synthetic prefix fixture', 'slug' => 'synthetic-prefix']);
        $user = new User;
        $user->forceFill(['name' => 'Synthetic prefix owner', 'email' => 'prefix-owner@example.invalid', 'password' => 'synthetic-only',
            'is_active' => true, 'email_verified_at' => now(), 'role' => 'member'])->save();
        $tenant->users()->attach($user->id, ['role' => 'owner', 'membership_active' => true]);
        TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
        TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'team_work', 'enabled_status' => 'enabled',
            'availability_status' => 'available', 'entitlement_source' => 'test']);
        config(['enterprise.enabled' => true, 'enterprise.writes_enabled' => true, 'enterprise.tenant_allowlist' => [(string) $tenant->id],
            'team_work.enabled' => true, 'team_work.tenant_allowlist' => [(string) $tenant->id],
            'enterprise.briefing_enabled' => true, 'enterprise.roll_call_enabled' => true, 'enterprise.operations_enabled' => true,
            'enterprise.workflow_enabled' => true]);

        return [$tenant, $user];
    }

    public function test_actual_server_base_url_prefixes_login_and_keeps_retail_cookies_untouched(): void
    {
        $response = $this->withUnencryptedCookie('XSRF-TOKEN', 'synthetic-retail-cookie')->get($this->url('/login'))
            ->assertOk()->assertSee('action="'.$this->url('/login').'"', false)
            ->assertSee('Persistent demonstration workspace')->assertCookieMissing('XSRF-TOKEN');
        $cookies = array_values(array_filter($response->headers->getCookies(), fn ($cookie) => $cookie->getName() === 'everbranch_enterprise_session'));
        $this->assertCount(1, $cookies);
        $this->assertSame(self::PREFIX, $cookies[0]->getPath());
        $this->assertTrue($cookies[0]->isSecure());
        $this->assertTrue($cookies[0]->isHttpOnly());
        $this->assertNull($cookies[0]->getDomain());
        $this->get($this->url('/?tenant=synthetic-prefix'))->assertRedirect($this->url('/login'));
        Http::assertNothingSent();
    }

    public function test_wrong_base_url_outside_prefix_and_unsafe_cookie_scope_fail_before_sessions(): void
    {
        foreach (['/login', '/enterprise-campus-other/login'] as $path) {
            $response = $this->get(self::ORIGIN.$path)->assertNotFound();
            $this->assertSame([], $response->headers->getCookies());
        }
        $this->withServerVariables(['SCRIPT_FILENAME' => $this->app->publicPath('index.php'), 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php']);
        $response = $this->get($this->url('/login'))->assertNotFound();
        $this->assertSame([], $response->headers->getCookies());
        $this->withServerVariables(['SCRIPT_FILENAME' => $this->app->publicPath('index.php'), 'SCRIPT_NAME' => self::PREFIX.'/index.php', 'PHP_SELF' => self::PREFIX.'/index.php']);
        config(['session.path' => '/']);
        $response = $this->get($this->url('/login'))->assertStatus(503);
        $this->assertSame([], $response->headers->getCookies());
        Http::assertNothingSent();
    }

    public function test_unsupported_mount_provider_flags_and_trial_only_combination_fail_closed(): void
    {
        foreach (['enterprise.trial.enabled', 'enterprise.gmail.connection_enabled', 'enterprise.gmail.send_enabled', 'enterprise_runtime.trial_mount_only'] as $key) {
            config([$key => true]);
            $response = $this->get($this->url('/login'))->assertStatus(503);
            $this->assertSame([], $response->headers->getCookies());
            config([$key => false]);
        }
        config(['enterprise_runtime.base_path' => '/enterprise-campus/../other']);
        $this->get($this->url('/login'))->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_full_mount_keeps_real_csrf_and_prefixes_authenticated_guest_redirect(): void
    {
        $this->app->bind(EnterpriseCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends EnterpriseCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson($this->url('/login'), ['email' => 'fixture@example.invalid', 'password' => 'fixture-only'])->assertStatus(419);
        $this->withSession(['_token' => 'synthetic-prefix-csrf'])->postJson($this->url('/login'), [], ['X-CSRF-TOKEN' => 'synthetic-prefix-csrf'])
            ->assertUnprocessable()->assertCookieMissing('XSRF-TOKEN');
        [, $user] = $this->owner();
        Route::middleware(['web', 'guest'])->get('/guest-prefix-fixture', fn () => 'This must redirect.');
        $this->actingAs($user)->get($this->url('/guest-prefix-fixture'))->assertRedirect($this->url());
        Http::assertNothingSent();
    }

    public function test_authenticated_shell_workflow_and_authorized_source_links_use_the_full_prefix(): void
    {
        [$tenant, $user] = $this->owner();
        $this->actingAs($user);
        $command = fn ($input) => $this->postJson($this->url('/enterprise/commands?tenant='.$tenant->slug),
            $input + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0])->assertOk()->json();
        $area = $command(['action' => 'workspace.create', 'title' => 'Synthetic prefix Operations', 'visibility' => 'department_only'])['id'];
        $project = $command(['action' => 'project.create', 'area_id' => $area, 'title' => 'Synthetic prefix project'])['id'];
        $request = $command(['action' => 'request.create', 'area_id' => $area, 'title' => 'Synthetic prefix request']);
        $request = $command(['action' => 'request.triage', 'id' => $request['id'], 'area_id' => $area, 'expected_version' => 1]);
        $command(['action' => 'request.assign', 'id' => $request['id'], 'area_id' => $area, 'expected_version' => 2, 'project_id' => $project, 'assignee_id' => $user->id]);
        $query = '?tenant='.$tenant->slug.'&area_id='.$area;
        $this->get($this->url('/'.$query))->assertOk()->assertSee('Persistent demonstration workspace');
        $briefing = $this->getJson($this->url('/enterprise/briefing'.$query))->assertOk()->json();
        $this->assertNotEmpty($briefing['projects']);
        $this->assertStringStartsWith($this->url(), $briefing['projects'][0]['url']);
        $this->assertStringStartsWith($this->url(), $briefing['requests'][0]['url']);
        $roll = $this->getJson($this->url('/enterprise/roll-call'.$query.'&scope=workspace'))->assertOk()->json();
        $this->assertNotEmpty($roll['items']);
        $this->assertStringStartsWith($this->url(), $roll['items'][0]['source_url']);
        $this->withoutVite();
        $workflow = $this->get($this->url('/projects/workspace'.$query.'&project_id='.$project))->assertOk()->assertSee('Persistent demonstration workspace');
        preg_match('/data-bootstrap="([^"]+)"/', $workflow->getContent(), $match);
        $boot = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame($this->url('/enterprise/operations'), $boot['routes']['operations']);
        $this->assertSame($this->url('/enterprise/workflows'), $boot['routes']['workflows']);
        config(['enterprise.sample_data' => false]);
        $this->get($this->url('/'.$query))->assertOk()->assertDontSee('Persistent demonstration workspace');
        $this->assertSame(1, DB::table('tenant_user')->count());
        Http::assertNothingSent();
    }
}
