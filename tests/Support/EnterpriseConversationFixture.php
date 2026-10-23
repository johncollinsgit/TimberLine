<?php

namespace Tests\Support;

use App\Enterprise\Conversation\Connection;
use App\Enterprise\Provisioning\ClientProvisioner;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class EnterpriseConversationFixture extends TestCase
{
    protected Tenant $tenant;

    protected User $user;

    protected string $area;

    protected string $project;

    protected string $runtime;

    protected string|false $previousRoot;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/enterprise-conversation-test-'.Str::uuid();
        foreach (['storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/framework/testing', 'storage/logs', 'bootstrap/cache'] as $path) {
            mkdir($this->runtime.'/'.$path, 0777, true);
        }
        file_put_contents($this->runtime.'/.env', '');
        putenv('ENTERPRISE_RUNTIME_ROOT='.$this->runtime);
        $app = require dirname(__DIR__, 2).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set([
            'app.env' => 'testing', 'app.url' => 'https://client.example.invalid',
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'enterprise_runtime.isolated' => true, 'enterprise_runtime.base_path' => '/enterprise-campus', 'session.path' => '/enterprise-campus',
            'database.connections.enterprise' => $this->databaseConfiguration(),
            'enterprise_client.client_id' => '10000000-0000-4000-8000-000000000001',
            'enterprise_client.silo_id' => '10000000-0000-4000-8000-000000000002',
            'enterprise_client.storage_id' => '10000000-0000-4000-8000-000000000003',
            'enterprise_client.environment' => 'testing', 'enterprise_client.database' => $this->databaseConfiguration()['database'],
            'cache.default' => 'array', 'session.driver' => 'array', 'hashing.bcrypt.rounds' => 4,
        ]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->prepareDatabase();
        $installed = $this->install();
        $this->tenant = Tenant::query()->findOrFail($installed['tenant_id']);
        $this->user = new User;
        $this->user->forceFill(['name' => 'Synthetic conversation owner', 'email' => 'conversation@example.invalid',
            'password' => 'fixture-only', 'role' => 'member', 'is_active' => true, 'email_verified_at' => now()])->save();
        $this->tenant->users()->attach($this->user->id, ['role' => 'owner', 'membership_active' => true]);
        TenantAccessProfile::query()->create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
        TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'team_work', 'enabled_status' => 'enabled',
            'availability_status' => 'available', 'entitlement_source' => 'test']);
        $this->area = $this->manifest()['departments'][0]['id'];
        $area = TeamArea::query()->findOrFail($this->area);
        $area->settings = array_replace($area->settings ?? [], ['synthetic_fixture' => true]);
        $area->save();
        config(['enterprise.enabled' => true, 'enterprise.writes_enabled' => true, 'enterprise.sample_data' => true,
            'enterprise.tenant_allowlist' => [(string) $this->tenant->id], 'team_work.enabled' => true,
            'team_work.tenant_allowlist' => [(string) $this->tenant->id], 'enterprise.read_only_assistant_enabled' => true,
            'enterprise.briefing_enabled' => true, 'enterprise.planning_enabled' => true, 'enterprise.operations_enabled' => true,
            'enterprise.resources_enabled' => true, 'enterprise.trial.enabled' => true, 'enterprise.trial.tenant_id' => $this->tenant->id,
            'enterprise.trial.area_id' => $this->area, 'enterprise.trial.prior_spend_verified' => true,
            'enterprise.trial.prior_spend_microusd' => 0, 'enterprise.trial.prior_spend_evidence' => 'Synthetic prior-spend evidence only']);
        $this->withServerVariables(['SCRIPT_FILENAME' => $this->app->publicPath('index.php'),
            'SCRIPT_NAME' => '/enterprise-campus/index.php', 'PHP_SELF' => '/enterprise-campus/index.php']);
        $this->actingAs($this->user)->withoutVite();
        $this->project = $this->postJson($this->url('/enterprise/commands'), ['action' => 'project.create', 'area_id' => $this->area,
            'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'title' => 'Synthetic conversation project'])->assertOk()->json('id');
        $this->approve();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if (isset($this->runtime)) {
                putenv($this->previousRoot === false ? 'ENTERPRISE_RUNTIME_ROOT' : 'ENTERPRISE_RUNTIME_ROOT='.$this->previousRoot);
            }
            if (isset($this->runtime) && str_starts_with($this->runtime, sys_get_temp_dir().'/enterprise-conversation-test-')) {
                (new Filesystem)->deleteDirectory($this->runtime);
            }
        }
    }

    protected function manifest(): array
    {
        return ['schema_version' => 1, 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'),
            'environment' => 'testing', 'storage_id' => config('enterprise_client.storage_id'),
            'tenant' => ['business_id' => '20000000-0000-4000-8000-000000000001', 'slug' => 'synthetic-workshop', 'name' => 'Synthetic Workshop B'],
            'branding' => ['organization_name' => 'Synthetic Workshop B', 'accent' => '#123456'],
            'departments' => [['id' => '30000000-0000-4000-8000-000000000001', 'key' => 'repairs', 'name' => 'Repair Department', 'visibility' => 'department_only'],
                ['id' => '30000000-0000-4000-8000-000000000002', 'key' => 'shared', 'name' => 'Shared Work', 'visibility' => 'shared']],
            'locations' => [['id' => '40000000-0000-4000-8000-000000000001', 'key' => 'workshop', 'department_key' => 'repairs',
                'name' => 'Synthetic Workshop', 'source_url' => null, 'source_label' => 'Approved fictional workshop configuration']],
            'workflow_defaults' => [['key' => 'repairs', 'name' => 'Workshop repair template', 'template' => 'maintenance_v1']],
            'role_defaults' => ['manager' => ['label' => 'Repair coordinator', 'view' => 'list', 'density' => 'compact']]];
    }

    protected function install(): array
    {
        $provisioner = app(ClientProvisioner::class);

        return $provisioner->apply($this->manifest(), $provisioner->plan($this->manifest())['plan_hash']);
    }

    protected function url(string $path): string
    {
        return 'https://client.example.invalid/enterprise-campus'.$path.'?tenant='.$this->tenant->slug;
    }

    protected function approve(array $delta = []): void
    {
        $config = $delta + ['enabled' => true, 'api_key' => 'synthetic-key-no-provider-access', 'project_id' => 'proj_synthetic', 'organization_id' => null,
            'approval_id' => 'Synthetic approval only', 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'),
            'mount' => '/enterprise-campus', 'tenant_id' => (string) $this->tenant->id, 'area_id' => $this->area, 'user_ids' => [(string) $this->user->id],
            'prior_spend_microusd' => config('enterprise.trial.prior_spend_microusd'),
            'prior_spend_evidence_hash' => hash('sha256', config('enterprise.trial.prior_spend_evidence')),
            'model' => 'fixture-model-2026-01-01', 'model_context_tokens' => 10000, 'model_context_evidence' => 'Fictional model bounds; not real approval',
            'max_output_tokens' => 100, 'input_ceiling_microusd_per_million' => 2000000, 'output_ceiling_microusd_per_million' => 3000000,
            'per_call_overhead_microusd' => 0, 'rates_evidence' => 'Synthetic inclusive prices only', 'valid_until' => now()->addHour()->toIso8601String()];
        $config['approval_sha256'] = hash('sha256', json_encode(Connection::approvalFields($config), JSON_THROW_ON_ERROR));
        config(['enterprise.conversation' => $config]);
    }

    protected function turn(array $extra = [])
    {
        return $this->postJson($this->url('/enterprise/conversation/turns'), $extra + ['area_id' => $this->area,
            'intent' => 'overview', 'question' => 'What work needs attention?', 'session_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()]);
    }

    protected function fake(?callable $change = null): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => function ($request) use ($change) {
            $body = $request->data();
            $payload = json_decode($body['input'][0]['content'], true, 32, JSON_THROW_ON_ERROR);
            $charge = TeamOperationRecord::query()->where(['kind' => 'ent_trial_charge', 'status' => 'dispatching'])->firstOrFail();
            $this->assertSame('dispatching', $charge->status, 'A durable claim must precede provider I/O.');
            $this->assertSame(20300, $charge->data['reserved_microusd'], 'Reserve the full approved model context, not prompt bytes.');
            $response = ['id' => 'resp_synthetic', 'model' => $body['model'], 'service_tier' => 'default', 'status' => 'completed',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'input_tokens_details' => ['cached_tokens' => 20, 'cache_write_tokens' => 10]],
                'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text',
                    'text' => json_encode(['answer' => 'The checked project is active. Inspect its current source.', 'refs' => array_slice(array_column($payload['sources'], 'ref'), 0, 1)])]]]]];

            return $change ? $change($request, $response, $payload) : Http::response($response);
        }]);
    }

    protected function databaseConfiguration(): array
    {
        return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
    }

    protected function prepareDatabase(): void
    {
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
    }
}
