<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\GmailConnections;
use App\Enterprise\Trial\TrialBudget;
use App\Http\Middleware\EnterpriseCsrfToken;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
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
        $this->withCredentials(); // These are same-origin browser APIs, including cookies on JSON requests.
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
        $this->get(self::ORIGIN.'/login', ['X-Forwarded-Prefix' => self::PREFIX])->assertNotFound();
        $this->get('http://workspace.example.invalid'.self::PREFIX.'/login')->assertNotFound();
        $this->get('https://workspace.example.invalid:8443'.self::PREFIX.'/login')->assertNotFound();
        $this->withServerVariables(['SCRIPT_FILENAME' => $this->app->publicPath('index.php'), 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php']);
        $response = $this->get($this->url('/login'))->assertNotFound();
        $this->assertSame([], $response->headers->getCookies());
        $this->withServerVariables(['SCRIPT_FILENAME' => $this->app->publicPath('index.php'), 'SCRIPT_NAME' => self::PREFIX.'/index.php', 'PHP_SELF' => self::PREFIX.'/index.php']);
        config(['session.path' => '/']);
        $response = $this->get($this->url('/login'))->assertStatus(503);
        $this->assertSame([], $response->headers->getCookies());
        Http::assertNothingSent();
    }

    public function test_mount_supports_independent_provider_flags_but_refuses_trial_only_combination_and_invalid_prefix(): void
    {
        foreach (['enterprise.trial.enabled', 'enterprise.gmail.connection_enabled', 'enterprise.gmail.send_enabled'] as $key) {
            config([$key => true]);
            $this->get($this->url('/login'))->assertOk();
            config([$key => false]);
        }
        config(['enterprise_runtime.trial_mount_only' => true]);
        $response = $this->get($this->url('/login'))->assertStatus(503);
        $this->assertSame([], $response->headers->getCookies());
        config(['enterprise_runtime.trial_mount_only' => false]);
        config(['enterprise_runtime.base_path' => '/enterprise-campus/../other']);
        $this->get($this->url('/login'))->assertStatus(503);
        config(['enterprise_runtime.base_path' => self::PREFIX]);
        foreach ([self::ORIGIN.self::PREFIX, self::ORIGIN.'?path=private', 'http://workspace.example.invalid'] as $origin) {
            config(['app.url' => $origin]);
            $response = $this->get($this->url('/login'))->assertStatus(503);
            $this->assertSame([], $response->headers->getCookies());
        }
        Http::assertNothingSent();
    }

    private function gmail(): array
    {
        [$tenant, $user] = $this->owner();
        $this->actingAs($user);
        $area = $this->postJson($this->url('/enterprise/commands?tenant='.$tenant->slug), [
            'action' => 'workspace.create', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
            'expected_version' => 0, 'title' => 'Synthetic prefix Gmail area', 'visibility' => 'department_only',
        ])->assertOk()->json('id');
        config(['enterprise.gmail' => ['connection_enabled' => true, 'send_enabled' => false,
            'tenant_allowlist' => [(string) $tenant->id], 'client_id' => 'synthetic-client.apps.googleusercontent.com',
            'client_secret' => 'synthetic-no-real-secret', 'redirect_uri' => $this->url('/enterprise/gmail/callback')]]);

        return [$tenant, $user, $area];
    }

    private function gmailStart(Tenant $tenant, string $area): array
    {
        $url = $this->postJson($this->url('/enterprise/gmail/connect?tenant='.$tenant->slug), ['area_id' => $area])->assertOk()->json('authorization_url');
        $this->withCookie(config('session.cookie'), $this->app['session']->driver()->getId());
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame($this->url('/enterprise/gmail/callback'), $query['redirect_uri']);
        $this->assertSame(implode(' ', GmailConnections::SCOPES), $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);

        return $query;
    }

    public function test_prefixed_gmail_uses_exact_callback_and_returns_to_own_prefixed_ask_once(): void
    {
        [$tenant, $user, $area] = $this->gmail();
        $query = $this->gmailStart($tenant, $area);
        Http::fake([
            GmailConnections::TOKEN_URL => function ($request) {
                $this->assertSame($this->url('/enterprise/gmail/callback'), $request['redirect_uri']);
                $this->assertSame('authorization_code', $request['grant_type']);

                return Http::response(['access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
                    'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => implode(' ', GmailConnections::SCOPES)]);
            },
            GmailConnections::USERINFO_URL => Http::response(['sub' => 'synthetic-subject', 'email' => $user->email, 'email_verified' => true]),
        ]);
        $callback = $this->url('/enterprise/gmail/callback').'?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']);
        $response = $this->get($callback)->assertStatus(303)->assertHeader('Referrer-Policy', 'no-referrer');
        $destination = $response->headers->get('Location');
        $this->assertStringStartsWith($this->url('?'), $destination);
        parse_str(parse_url($destination, PHP_URL_QUERY), $back);
        $this->assertSame(['tenant' => $tenant->slug, 'area_id' => $area, 'section' => 'ask', 'gmail' => 'connected'], $back);
        $this->assertSame('completed', TeamOperationRecord::query()->where('kind', GmailConnections::STATE)->sole()->status);
        $this->get($callback)->assertConflict();
        Http::assertSentCount(2);
        config(['enterprise_runtime.base_path' => '/different-campus',
            'enterprise.gmail.redirect_uri' => self::ORIGIN.'/different-campus/enterprise/gmail/callback']);
        $this->assertSame([], app(GmailConnections::class)->mailboxes($tenant, $user, $area));
        Http::assertSentCount(2); // A moved mount never refreshes or preserves the prior consent binding.
        $this->assertSame(1, DB::table('tenant_user')->count());
    }

    public function test_prefixed_gmail_rejects_host_root_session_actor_and_mount_replay_without_exchange(): void
    {
        [$tenant, $user, $area] = $this->gmail();
        $query = $this->gmailStart($tenant, $area);
        $callback = '/enterprise/gmail/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']);
        $this->get(self::ORIGIN.$callback)->assertNotFound();
        $this->get('https://other.example.invalid'.self::PREFIX.$callback)->assertNotFound();
        $this->withCookie(config('session.cookie'), Str::random(40))->get($this->url($callback))->assertForbidden();
        $query = $this->gmailStart($tenant, $area);
        $callback = '/enterprise/gmail/callback?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']);
        $user->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
        $this->get($this->url($callback))->assertForbidden();
        $user->tenants()->updateExistingPivot($tenant->id, ['membership_active' => true]);
        // An explicit mount change cannot carry a prior browser's authorization forward.
        config(['enterprise_runtime.base_path' => '/different-campus', 'session.path' => '/different-campus',
            'enterprise.gmail.redirect_uri' => self::ORIGIN.'/different-campus/enterprise/gmail/callback']);
        $this->withServerVariables(['SCRIPT_FILENAME' => $this->app->publicPath('index.php'), 'SCRIPT_NAME' => '/different-campus/index.php', 'PHP_SELF' => '/different-campus/index.php']);
        $this->get(self::ORIGIN.'/different-campus'.$callback)->assertConflict();
        Http::assertNothingSent();
    }

    public function test_prefixed_gmail_rejects_guest_without_intended_url_and_connect_keeps_real_csrf(): void
    {
        $this->get($this->url('/enterprise/gmail/callback?state=synthetic-private-state&code=synthetic-private-code'))
            ->assertForbidden()->assertHeaderMissing('Location')->assertSessionMissing('url.intended');
        [$tenant, , $area] = $this->gmail();
        $this->app->bind(EnterpriseCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends EnterpriseCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $url = $this->url('/enterprise/gmail/connect?tenant='.$tenant->slug);
        $this->postJson($url, ['area_id' => $area])->assertStatus(419);
        $this->withSession(['_token' => 'synthetic-gmail-csrf'])->postJson($url, ['area_id' => $area], ['X-CSRF-TOKEN' => 'synthetic-gmail-csrf'])
            ->assertOk()->assertCookieMissing('XSRF-TOKEN');
        Http::assertNothingSent();
    }

    public function test_prefixed_gmail_rejects_other_users_tenants_departments_and_expired_state_before_io(): void
    {
        [$tenant, $user, $area] = $this->gmail();
        $query = $this->gmailStart($tenant, $area);
        $callback = $this->url('/enterprise/gmail/callback').'?'.http_build_query(['state' => $query['state'], 'code' => 'synthetic-code']);
        $other = new User;
        $other->forceFill(['name' => 'Other synthetic actor', 'email' => 'other@example.invalid', 'password' => 'synthetic-only',
            'is_active' => true, 'email_verified_at' => now(), 'role' => 'member'])->save();
        $tenant->users()->attach($other->id, ['role' => 'owner', 'membership_active' => true]);
        $this->actingAs($other)->get($callback)->assertForbidden();
        $this->actingAs($user)->get($callback.'&tenant=other-tenant')->assertForbidden();
        $this->get($callback.'&area_id='.Str::uuid())->assertForbidden();
        $state = TeamOperationRecord::query()->where('kind', GmailConnections::STATE)->sole();
        $data = $state->data;
        $data['expires_at'] = now()->subMinute()->toIso8601String();
        $state->update(['data' => $data]);
        $this->get($callback)->assertConflict();
        $this->assertSame('pending', $state->fresh()->status);
        Http::assertNothingSent();
    }

    private function trial(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Synthetic prefixed trial storage', 'slug' => 'synthetic-prefix-trial']);
        $area = TeamArea::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Synthetic trial only',
            'slug' => 'synthetic', 'profile' => 'enterprise_department', 'settings' => ['synthetic_fixture' => true], 'version' => 1]);
        config(['enterprise.trial' => ['enabled' => true, 'canonical_origin' => self::ORIGIN, 'tenant_id' => $tenant->id, 'area_id' => $area->id,
            'prior_spend_verified' => true, 'prior_spend_microusd' => 12000, 'prior_spend_evidence' => 'Synthetic related prior trial baseline',
            'rates_valid_until' => now()->addDay()->toIso8601String(),
            'reviewers' => ['synthetic' => ['code_hash' => hash('sha256', 'synthetic-prefixed-invitation'), 'expires_at' => now()->addHour()->toIso8601String(),
                'emails' => ['receiver@example.invalid'], 'sms' => ['+12025550123']]],
            'channels' => ['sms' => ['enabled' => true, 'account_sid' => 'AC'.str_repeat('a', 32), 'auth_token' => 'synthetic-token',
                'from_number' => '+15005550006', 'sender_verified' => true, 'sender_evidence' => 'Synthetic sender evidence', 'max_cost_microusd_per_segment' => 10000],
                'email' => ['enabled' => false], 'ai' => ['enabled' => false]]]]);
    }

    public function test_prefixed_trial_boot_links_confirmation_and_cross_device_task_stay_within_mount(): void
    {
        $this->trial();
        $base = $this->url('/enterprise/trial');
        $this->get($base)->assertOk()->assertSee(json_encode($base), false)->assertCookieMissing('XSRF-TOKEN');
        $this->withSession(['_token' => 'synthetic-trial-prefix'])->postJson($base.'/start', ['reviewer_code' => 'synthetic-prefixed-invitation'])->assertOk();
        $this->withCookie(config('session.cookie'), $this->app['session']->driver()->getId());
        $input = ['request_id' => (string) Str::uuid(), 'channel' => 'sms', 'destination' => '+12025550123', 'fields' => ['title', 'next_action']];
        $previewResponse = $this->postJson($base.'/previews', $input);
        $this->assertSame(200, $previewResponse->status(), $previewResponse->getContent());
        $preview = $previewResponse->json();
        $this->assertStringStartsWith($base.'/task/', $preview['content']['url']);
        $this->assertStringNotContainsString('receiver', $preview['content']['url']);
        $this->postJson($base.'/previews', $input)->assertOk()->assertJsonPath('id', $preview['id'])->assertJsonPath('replayed', true);
        $email = $this->postJson($base.'/previews', ['request_id' => (string) Str::uuid(), 'channel' => 'email',
            'destination' => 'receiver@example.invalid', 'fields' => ['title']])->assertOk()->json();
        $this->assertFalse($email['quote']['ready']); // Preview is not provider activation.
        $this->assertStringContainsString($base.'/wordmark.png', $email['content']['html']);
        $this->assertStringStartsWith($base.'/task/', $email['content']['url']);
        $this->get($base.'/wordmark.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->postJson($base.'/previews/'.$preview['id'].'/confirm', ['confirmed' => true, 'digest' => str_repeat('0', 64)])->assertNotFound();
        Http::assertNothingSent();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM'.str_repeat('b', 32), 'status' => 'queued'], 201)]);
        $confirm = ['confirmed' => true, 'digest' => $preview['digest']];
        $this->postJson($base.'/previews/'.$preview['id'].'/confirm', $confirm)->assertOk()->assertJsonPath('events.acceptance', 'accepted')->assertJsonPath('events.delivery', 'unverified');
        $this->postJson($base.'/previews/'.$preview['id'].'/confirm', $confirm)->assertOk()->assertJsonPath('replayed', true);
        Http::assertSentCount(1);
        $status = app(TrialBudget::class)->status();
        $this->assertSame(TrialBudget::CAP, $status['cap_microusd']);
        $this->assertGreaterThanOrEqual(12000, $status['used_or_reserved_microusd']);
        $this->flushSession(); // Capability works on a second device without employee/reviewer authority.
        $this->withCookie(config('session.cookie'), Str::random(40));
        $task = $preview['content']['url'];
        $this->get($task)->assertOk()->assertSee(json_encode($task), false);
        $data = $this->getJson($task.'/data')->assertOk()->json();
        $update = ['request_id' => (string) Str::uuid(), 'expected_version' => $data['task']['version'], 'action' => 'acknowledge'];
        $this->postJson($task.'/update', $update)->assertOk();
        $this->postJson($task.'/update', $update)->assertOk();
        Storage::fake('team_private');
        config(['team_work.scanner.enabled' => false]);
        $file = UploadedFile::fake()->createWithContent('sample.pdf', "%PDF-1.7\nSynthetic prefix fixture\n%%EOF");
        $upload = ['request_id' => (string) Str::uuid(), 'file' => $file];
        $attachment = $this->postJson($task.'/uploads', $upload)->assertOk()->assertJsonPath('status', 'quarantined')->json();
        $this->postJson($task.'/uploads', $upload)->assertOk()->assertJsonPath('id', $attachment['id'])->assertJsonPath('replayed', true);
        $this->get($task.'/files/'.$attachment['id'])->assertNotFound();
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_upload')->count());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenant_user')->count());
        $this->get(self::ORIGIN.'/enterprise/trial')->assertNotFound();
        $this->get('https://elsewhere.example.invalid'.self::PREFIX.'/enterprise/trial')->assertNotFound();
        $this->get($base.'?reviewer_code=private')->assertNotFound();
        config(['enterprise_runtime.base_path' => '/different-campus', 'session.path' => '/different-campus']);
        $this->withServerVariables(['SCRIPT_FILENAME' => $this->app->publicPath('index.php'), 'SCRIPT_NAME' => '/different-campus/index.php', 'PHP_SELF' => '/different-campus/index.php']);
        $this->get(str_replace(self::PREFIX, '/different-campus', $task))->assertForbidden();
        $newBase = self::ORIGIN.'/different-campus/enterprise/trial';
        $fresh = $this->postJson($newBase.'/start', ['reviewer_code' => 'synthetic-prefixed-invitation'])->assertOk()->json();
        $this->assertSame($status['used_or_reserved_microusd'], $fresh['budget']['used_or_reserved_microusd']);
        Http::assertSentCount(1);
    }

    public function test_prefixed_trial_csrf_and_unknown_outcome_keep_one_lifetime_reservation(): void
    {
        $this->trial();
        $this->app->bind(EnterpriseCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends EnterpriseCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $base = $this->url('/enterprise/trial');
        $this->postJson($base.'/start', ['reviewer_code' => 'synthetic-prefixed-invitation'])->assertStatus(419);
        $headers = ['X-CSRF-TOKEN' => 'synthetic-trial-csrf'];
        $this->withSession(['_token' => $headers['X-CSRF-TOKEN']])->postJson($base.'/start', ['reviewer_code' => 'synthetic-prefixed-invitation'], $headers)->assertOk();
        $this->withCookie(config('session.cookie'), $this->app['session']->driver()->getId());
        $preview = $this->postJson($base.'/previews', ['request_id' => (string) Str::uuid(), 'channel' => 'sms',
            'destination' => '+12025550123', 'fields' => ['title']], $headers)->assertOk()->json();
        $calls = 0;
        Http::fake(['api.twilio.com/*' => function () use (&$calls) {
            $calls++;
            throw new \RuntimeException('Synthetic interrupted provider outcome');
        }]);
        $confirm = ['confirmed' => true, 'digest' => $preview['digest']];
        $this->postJson($base.'/previews/'.$preview['id'].'/confirm', $confirm, $headers)->assertOk()->assertJsonPath('status', 'uncertain');
        $this->postJson($base.'/previews/'.$preview['id'].'/confirm', $confirm, $headers)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, $calls);
        $this->assertSame(12000 + $preview['quote']['maximum_microusd'], app(TrialBudget::class)->status()['used_or_reserved_microusd']);
        $this->assertSame('uncertain', TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->sole()->status);
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
