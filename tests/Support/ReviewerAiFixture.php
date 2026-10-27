<?php

namespace Tests\Support;

use App\Enterprise\Provisioning\ClientProvisioner;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Proposals\ReviewerAiStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Actual isolated owner runtime with explicit FICTIONAL test setup, never employee grants or application auto-initialization. */
abstract class ReviewerAiFixture extends TestCase
{
    protected string $runtime;

    protected string|false $previousRoot;

    protected Tenant $tenant;

    protected TeamArea $anchor;

    protected TeamOperationRecord $ledger;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/proposal-reviewer-ai-test-'.Str::uuid();
        foreach (['storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs',
            'storage/app/private', 'storage/app/team-private', 'bootstrap/cache'] as $path) {
            mkdir($this->runtime.'/'.$path, 0700, true);
        }
        file_put_contents($this->runtime.'/.env', '');
        putenv('ENTERPRISE_RUNTIME_ROOT='.$this->runtime);
        $app = require dirname(__DIR__, 2).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();
        $app->detectEnvironment(fn () => 'testing');
        $database = $this->databaseConfiguration();
        $app['config']->set(['app.env' => 'testing', 'app.url' => 'https://proposal.example.invalid',
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'enterprise_runtime.isolated' => true, 'enterprise_runtime.base_path' => '/enterprise-owner',
            'enterprise_runtime.trial_mount_only' => false, 'session.path' => '/enterprise-owner',
            'database.connections.enterprise' => $database,
            'enterprise_client.client_id' => '71000000-0000-4000-8000-000000000001',
            'enterprise_client.silo_id' => '71000000-0000-4000-8000-000000000002',
            'enterprise_client.storage_id' => '71000000-0000-4000-8000-000000000003',
            'enterprise_client.environment' => 'testing', 'enterprise_client.database' => $database['database'],
            'session.driver' => 'array', 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->prepareDatabase();
        $manifest = ['schema_version' => 1, 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'),
            'environment' => 'testing', 'storage_id' => config('enterprise_client.storage_id'),
            'tenant' => ['business_id' => '72000000-0000-4000-8000-000000000001', 'slug' => 'synthetic-ai-owner', 'name' => 'Synthetic proposal AI owner'],
            'branding' => ['organization_name' => 'Synthetic proposal AI owner', 'accent' => '#123456'],
            'departments' => [['id' => '73000000-0000-4000-8000-000000000001', 'key' => 'administration', 'name' => 'Administration', 'visibility' => 'department_only']],
            'locations' => [['id' => '74000000-0000-4000-8000-000000000001', 'key' => 'private-vault', 'department_key' => 'administration',
                'name' => 'PRIVATE OWNER SENTINEL', 'source_url' => null, 'source_label' => 'Synthetic fixture']], 'workflow_defaults' => [], 'role_defaults' => []];
        $provisioner = app(ClientProvisioner::class);
        $installed = $provisioner->apply($manifest, $provisioner->plan($manifest)['plan_hash']);
        $this->tenant = Tenant::query()->findOrFail($installed['tenant_id']);
        $evidence = str_repeat('a', 64); // FICTIONAL evidence only; no runtime approval or real campaign reset.
        $this->anchor = TeamArea::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'slug' => ReviewerAiStore::AREA_SLUG, 'name' => 'Private proposal AI cost anchor', 'profile' => 'enterprise_shared', 'version' => 1,
            'settings' => ['synthetic_fixture' => true, 'proposal_review_ai_anchor' => true, 'inventory_evidence_hash' => $evidence, 'baseline_evidence_hash' => $evidence]]);
        // Explicit fixture/operator setup. The application Store/Budget must NEVER initialize this row.
        $this->ledger = TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'area_id' => $this->anchor->id, 'kind' => 'ent_trial_budget', 'title' => 'Private proposal AI lifetime trial', 'status' => 'active',
            'version' => 1, 'visibility' => 'area', 'source_key' => hash('sha256', ReviewerAiStore::LEDGER_SOURCE),
            'data' => ['baseline' => ['prior_microusd' => 0, 'evidence_hash' => $evidence], 'cap_microusd' => 5000000]]);
        config(['enterprise.enabled' => false, 'enterprise.writes_enabled' => false, 'enterprise.trial.enabled' => false,
            'enterprise.conversation.enabled' => false, 'service_proposals.enabled' => true, 'service_proposals.writes_enabled' => false,
            'service_proposals.runtime_purpose' => 'proposal_owner', 'service_proposals.owner_client_id' => config('enterprise_client.client_id'),
            'service_proposals.owner_silo_id' => config('enterprise_client.silo_id'), 'service_proposals.owner_workspaces' => [$this->tenant->id => 'enterprise'],
            'service_proposals.reviewer_ai' => ['ui_enabled' => true, 'enabled' => true, 'tenant_id' => $this->tenant->id, 'area_id' => $this->anchor->id,
                'ledger_id' => $this->ledger->id, 'prior_spend_verified' => true, 'prior_spend_microusd' => 0,
                'prior_spend_evidence_hash' => $evidence, 'inventory_evidence_hash' => $evidence]]);
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenant_user')->count());
        $this->assertSame(0, DB::table('team_area_members')->count());
    }

    protected function databaseConfiguration(): array
    {
        return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
    }

    protected function prepareDatabase(): void
    {
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            parent::tearDown();
        } finally {
            putenv($this->previousRoot === false ? 'ENTERPRISE_RUNTIME_ROOT' : 'ENTERPRISE_RUNTIME_ROOT='.$this->previousRoot);
            if (isset($this->runtime) && str_starts_with($this->runtime, sys_get_temp_dir().'/proposal-reviewer-ai-test-')) {
                (new Filesystem)->deleteDirectory($this->runtime);
            }
        }
    }
}
