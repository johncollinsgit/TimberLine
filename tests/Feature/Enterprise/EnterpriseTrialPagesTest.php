<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Trial\ReviewerTrial;
use App\Http\Middleware\EnterpriseCsrfToken;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Exercises the actual dedicated runtime and web routes without provider calls. */
final class EnterpriseTrialPagesTest extends TestCase
{
    private const ORIGIN = 'https://trial.example.invalid';

    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set([
            'app.env' => 'testing', 'app.url' => self::ORIGIN,
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'enterprise_runtime.isolated' => true,
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'session.driver' => 'array', 'cache.default' => 'array',
            'cache.stores.array' => ['driver' => 'array', 'serialize' => false],
        ]);
        DB::prohibitDestructiveCommands(false);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
        $tenant = Tenant::query()->create(['name' => 'Synthetic trial page fixture', 'slug' => 'trial-pages']);
        $area = TeamArea::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Synthetic trial fixture',
            'slug' => 'synthetic', 'profile' => 'enterprise_department', 'settings' => ['synthetic_fixture' => true], 'version' => 1]);
        config(['enterprise.trial' => [
            'enabled' => true, 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'canonical_origin' => self::ORIGIN,
            'prior_spend_verified' => true, 'prior_spend_microusd' => 0, 'prior_spend_evidence' => 'Synthetic zero-cost test evidence',
            'rates_valid_until' => now()->addHour()->toIso8601String(),
            'reviewers' => ['synthetic' => ['code_hash' => hash('sha256', 'synthetic-pages-invitation'), 'expires_at' => now()->addHour()->toIso8601String(),
                'emails' => ['approved-recipient@example.invalid'], 'sms' => ['+12025550123']]],
            'channels' => ['sms' => ['enabled' => false], 'email' => ['enabled' => false], 'ai' => ['enabled' => false]],
        ]]);
    }

    private function link(): array
    {
        $session = new Store('synthetic-trial-pages', new ArraySessionHandler(120));
        $session->start();
        $request = Request::create(self::ORIGIN.'/enterprise/trial', 'POST');
        $request->setLaravelSession($session);
        app(ReviewerTrial::class)->start($request, 'synthetic-pages-invitation');
        $preview = app(ReviewerTrial::class)->preview($request, ['request_id' => (string) Str::uuid(), 'channel' => 'sms',
            'destination' => '+12025550123', 'fields' => ['title', 'owner', 'next_action']]);
        // Deliberately seed an active capability; page tests never submit a paid test.
        $row = TeamOperationRecord::query()->findOrFail($preview['link_id']);
        $row->status = 'active';
        $row->save();

        return [basename($preview['content']['url']), $row];
    }

    public function test_pages_fail_closed_without_trial_flag_runtime_origin_or_marked_storage(): void
    {
        config(['enterprise.trial.enabled' => false]);
        $this->get(self::ORIGIN.'/enterprise/trial')->assertNotFound();
        config(['enterprise.trial.enabled' => true, 'enterprise.runtime' => false]);
        $this->get(self::ORIGIN.'/enterprise/trial')->assertNotFound();
        config(['enterprise.runtime' => true]);
        $this->get('https://other.example.invalid/enterprise/trial')->assertNotFound();
        $this->get('http://trial.example.invalid/enterprise/trial')->assertNotFound();
        $this->get(self::ORIGIN.'/enterprise/trial?email=private%40example.invalid')->assertNotFound();
        TeamArea::query()->firstOrFail()->update(['settings' => []]);
        $this->get(self::ORIGIN.'/enterprise/trial')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_guest_reviewer_shell_has_csp_csrf_and_no_employee_or_provider_configuration(): void
    {
        $response = $this->get(self::ORIGIN.'/enterprise/trial')->assertOk()
            ->assertSee('Reviewer invitation code')->assertSee('Prepare exact preview')->assertSee('Confirm this exact test')
            ->assertSee('Provider acceptance, delivery, link opening and acknowledgment are separate events.')
            ->assertDontSee('approved-recipient@example.invalid')->assertDontSee('synthetic-pages-invitation')->assertDontSee('tenant_id');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString("connect-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('script nonce=', $response->getContent());
        $this->assertStringContainsString('name="csrf-token"', $response->getContent());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenant_user')->count());
        Http::assertNothingSent();
    }

    public function test_capability_page_works_without_reviewer_cookies_and_records_open_only_on_data_request(): void
    {
        [$token, $link] = $this->link();
        $url = self::ORIGIN.'/enterprise/trial/task/'.$token;
        $this->get($url)->assertOk()->assertSee('Acknowledge this sample task')->assertSee('up to 5 MB')
            ->assertDontSee('approved-recipient@example.invalid')->assertDontSee('+12025550123')->assertDontSee('synthetic-pages-invitation');
        $this->assertSame(0, $link->fresh()->data['open_count']);
        $this->getJson($url.'/data')->assertOk()->assertJsonPath('sample', true)->assertJsonPath('task.title', 'Sample facilities request');
        $this->assertSame(1, $link->fresh()->data['open_count']);
        Http::assertNothingSent();
    }

    public function test_expired_revoked_and_other_tokens_never_render_task_data(): void
    {
        [$token, $link] = $this->link();
        $link->update(['status' => 'revoked']);
        $this->get(self::ORIGIN.'/enterprise/trial/task/'.$token)->assertNotFound()->assertDontSee('Acknowledge this sample task');
        $data = $link->data;
        $data['expires_at'] = now()->subMinute()->toIso8601String();
        $link->update(['status' => 'active', 'data' => $data]);
        $this->get(self::ORIGIN.'/enterprise/trial/task/'.$token)->assertNotFound();
        $this->get(self::ORIGIN.'/enterprise/trial/task/'.str_repeat('z', 43))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_controlled_web_mutations_require_csrf_even_with_a_valid_invitation(): void
    {
        $this->app->bind(EnterpriseCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends EnterpriseCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson(self::ORIGIN.'/enterprise/trial/start', ['reviewer_code' => 'synthetic-pages-invitation'])->assertStatus(419);
        $this->withSession(['_token' => 'synthetic-csrf'])->postJson(self::ORIGIN.'/enterprise/trial/start', ['reviewer_code' => 'not-an-approved-invitation'], ['X-CSRF-TOKEN' => 'synthetic-csrf'])->assertForbidden();
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_session')->count());
        Http::assertNothingSent();
    }

    public function test_browser_session_api_requires_its_own_approval_and_retail_demo_routes_are_absent(): void
    {
        $this->getJson(self::ORIGIN.'/enterprise/trial/session')->assertForbidden();
        foreach (['/platform/demos/lees-mcrae', '/retail/plan', '/billing/checkout', '/register'] as $uri) {
            $this->get(self::ORIGIN.$uri)->assertNotFound();
        }
        $this->get(self::ORIGIN.'/enterprise/trial/task/'.str_repeat('a', 43).'?email=private%40example.invalid')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_same_origin_trial_cookie_is_scoped_and_never_overwrites_retail_xsrf_cookie(): void
    {
        config(['enterprise_runtime.trial_mount_only' => true, 'session.path' => '/enterprise/trial']);
        $response = $this->withUnencryptedCookie('XSRF-TOKEN', 'synthetic-retail-cookie')->get(self::ORIGIN.'/enterprise/trial')
            ->assertOk()->assertCookieMissing('XSRF-TOKEN');
        $cookies = array_values(array_filter($response->headers->getCookies(), fn ($cookie) => $cookie->getName() === 'everbranch_enterprise_session'));
        $this->assertCount(1, $cookies);
        $this->assertSame('/enterprise/trial', $cookies[0]->getPath());
        $this->assertTrue($cookies[0]->isSecure());
        $this->assertTrue($cookies[0]->isHttpOnly());
        $this->assertNull($cookies[0]->getDomain());
        $this->getJson(self::ORIGIN.'/enterprise/trial/session')->assertForbidden()->assertCookieMissing('XSRF-TOKEN');
        Http::assertNothingSent();
    }

    public function test_trial_mount_rejects_other_paths_and_unsafe_cookie_scope_before_starting_a_session(): void
    {
        config(['enterprise_runtime.trial_mount_only' => true, 'session.path' => '/enterprise/trial']);
        foreach (['/', '/login', '/teams', '/enterprise/data', '/enterprise/trial-other', '/enterprise/trials'] as $path) {
            $response = $this->get(self::ORIGIN.$path)->assertNotFound();
            $this->assertSame([], $response->headers->getCookies());
        }
        config(['session.path' => '/']);
        $response = $this->get(self::ORIGIN.'/enterprise/trial')->assertStatus(503);
        $this->assertSame([], $response->headers->getCookies());
        config(['session.path' => '/enterprise/trial', 'session.cookie' => 'laravel_session']);
        $this->get(self::ORIGIN.'/enterprise/trial')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_scoped_trial_retains_real_csrf_validation_and_other_enterprise_pages_keep_their_cookie(): void
    {
        $this->app->bind(EnterpriseCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends EnterpriseCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        config(['enterprise_runtime.trial_mount_only' => true, 'session.path' => '/enterprise/trial']);
        $this->postJson(self::ORIGIN.'/enterprise/trial/start', ['reviewer_code' => 'synthetic-pages-invitation'])->assertStatus(419);
        $this->withSession(['_token' => 'synthetic-scoped-csrf'])->postJson(self::ORIGIN.'/enterprise/trial/start',
            ['reviewer_code' => 'synthetic-pages-invitation'], ['X-CSRF-TOKEN' => 'synthetic-scoped-csrf'])
            ->assertOk()->assertJsonPath('sample', true)->assertCookieMissing('XSRF-TOKEN');
        config(['enterprise_runtime.trial_mount_only' => false, 'session.path' => '/']);
        $this->get(self::ORIGIN.'/login')->assertOk()->assertCookie('XSRF-TOKEN');
        Http::assertNothingSent();
    }
}
