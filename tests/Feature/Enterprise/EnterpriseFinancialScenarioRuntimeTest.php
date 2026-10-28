<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Provisioning\ClientProvisioner;
use App\Http\Middleware\EnterpriseCsrfToken;
use App\Models\Tenant;
use App\Models\User;
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
final class EnterpriseFinancialScenarioRuntimeTest extends TestCase
{
    private const ORIGIN = 'https://proposal.example.invalid';

    private const PREFIX = '/enterprise-proposals';

    private const TOKEN = 'synthetic-proposal-csrf';

    private string $runtime;

    private string|false $previousRoot;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/enterprise-financial-test-'.Str::uuid();
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
            if (str_starts_with($this->runtime, sys_get_temp_dir().'/enterprise-financial-test-')) {
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

    private function protectedRows(): array
    {
        $result = [];
        foreach (['assets', 'drafts', 'versions', 'reviews', 'comments'] as $kind) {
            $result[$kind] = DB::table('service_proposal_'.$kind)->orderBy('id')->get()->toJson();
        }

        return $result;
    }

    public function test_mounted_cart_has_real_csrf_session_persistence_and_no_business_provider_or_legal_writes(): void
    {
        [$tenant, $owner] = $this->owner();
        ['version' => $version, 'token' => $token] = $this->publication($tenant, $owner);
        $url = $this->url('/service-proposals/'.$token);
        $this->postWithToken($url.'/unlock', ['password' => 'Synthetic reviewer password'])->assertRedirect($url);
        $page = $this->get($url.'?section=scope')->assertOk()->assertSee('Monthly usage example')->assertSee('Reviewer access');
        $hash = $page->viewData('financial')['binding'];
        $data = ['action' => 'prepare', 'planning_hash' => $hash, 'name' => 'Fictional mounted cart', 'service_id' => $version->payload['items'][1]['id'],
            'department_count' => 3, 'profile' => 'moderate', 'management' => 'department'];
        $before = $this->protectedRows();
        config(['service_proposals.writes_enabled' => false]);
        $this->post($url.'/financial-planning', $data)->assertStatus(419);
        $this->postWithToken($url.'/financial-planning', $data)->assertRedirect($url.'?section=scope#usage-estimator');
        $state = $this->get($url)->assertOk()->viewData('financial');
        $input = $state['draft']['input'];
        $input['departments'][1]['questions'] = 250;
        $input['departments'][1]['method'] = 'actual';
        $save = ['action' => 'save', 'planning_hash' => $hash, 'scenario_id' => $state['draft']['id'], 'scenario' => $input];
        $this->postWithToken($url.'/financial-planning', $save)->assertRedirect($url.'?section=scope#usage-estimator');
        $this->postWithToken($url.'/financial-planning', $save)->assertRedirect();
        $cart = $this->get($url)->assertOk()->viewData('financial');
        $this->assertCount(1, $cart['scenarios']);
        $result = array_values($cart['scenarios'])[0];
        $this->assertSame(250, $result['input']['departments'][1]['questions']);
        $this->assertSame('actual', $result['input']['departments'][1]['method']);
        $this->assertSame(250000, $result['service']['totals_cents']['month']);
        $this->assertNull($result['all_in_total']);
        $this->postWithToken($url.'/request-agreement', [])->assertForbidden();
        $this->assertSame($before, $this->protectedRows());
        $this->assertStringContainsString("default-src 'none'", $page->headers->get('Content-Security-Policy'));
        preg_match_all('~<script data-proposal-navigation>(.*?)</script>~s', $page->getContent(), $scripts);
        $this->assertCount(1, $scripts[1]);
        $this->assertSame(file_get_contents(resource_path('js/service-proposals/mobile-navigation.js')), $scripts[1][0]);
        preg_match('~(?:^|;)\\s*script-src\\s+([^;]+)~', $page->headers->get('Content-Security-Policy'), $policy);
        $this->assertSame("'sha256-".base64_encode(hash('sha256', $scripts[1][0], true))."'", $policy[1]);
        $this->assertStringContainsString("script-src-attr 'none'", $page->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_each_mounted_post_rechecks_expiry_unlock_host_and_runtime_authority(): void
    {
        [$tenant, $owner] = $this->owner();
        ['version' => $version, 'token' => $token] = $this->publication($tenant, $owner);
        $url = $this->url('/service-proposals/'.$token);
        $this->postWithToken($url.'/financial-planning', [])->assertForbidden();
        $this->postWithToken($url.'/unlock', ['password' => 'Synthetic reviewer password']);
        $hash = $this->get($url)->viewData('financial')['binding'];
        $data = ['action' => 'clear', 'planning_hash' => $hash];
        $this->postWithToken(str_replace('proposal.example.invalid', 'foreign.example.invalid', $url).'/financial-planning', $data)->assertNotFound();
        $this->travel(31)->minutes();
        $this->postWithToken($url.'/financial-planning', $data)->assertForbidden();
        $this->postWithToken($url.'/unlock', ['password' => 'Synthetic reviewer password']);
        $this->travel(8)->days();
        $this->postWithToken($url.'/financial-planning', $data)->assertGone();
        $this->travelBack();
        config(['service_proposals.owner_silo_id' => (string) Str::uuid()]);
        $this->postWithToken($url.'/financial-planning', $data)->assertStatus(503);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_owner_preview_planning_needs_current_owner_and_cannot_cross_a_draft_or_silo(): void
    {
        [$tenant, $owner] = $this->owner();
        $draft = app(ProposalService::class)->leesMcRaeDraft($tenant, $owner->id)->refresh();
        $path = $this->ownerPath($tenant).'/'.$draft->id;
        $this->actingAs($owner);
        $page = $this->get($path.'/preview')->assertOk();
        $this->assertSame($draft->id, $page->viewData('draft')->id);
        $data = ['action' => 'clear', 'planning_hash' => $page->viewData('financial')['binding']];
        $this->post($path.'/financial-planning', $data)->assertStatus(419);
        $this->postWithToken($path.'/financial-planning', $data)->assertRedirect($path.'/preview?section=scope#usage-estimator');
        $this->postWithToken($this->ownerPath($tenant).'/'.Str::uuid().'/financial-planning', $data)->assertNotFound();
        $tenant->users()->updateExistingPivot($owner->id, ['role' => 'member']);
        $this->postWithToken($path.'/financial-planning', $data)->assertForbidden();
        $tenant->users()->updateExistingPivot($owner->id, ['role' => 'owner', 'membership_active' => false]);
        $this->postWithToken($path.'/financial-planning', $data)->assertForbidden();
        $this->assertSame(0, Version::count());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
