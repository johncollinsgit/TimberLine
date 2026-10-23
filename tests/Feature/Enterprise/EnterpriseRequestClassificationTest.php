<?php

namespace Tests\Feature\Enterprise;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Real isolated Enterprise schema and request/resource routes; no retail fixtures or external I/O. */
final class EnterpriseRequestClassificationTest extends TestCase
{
    private string $runtime;

    private string|false $previousRoot;

    private Tenant $tenant;

    private User $coordinator;

    private string $area;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/enterprise-classification-test-'.Str::uuid();
        foreach (['storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs', 'bootstrap/cache'] as $path) {
            mkdir($this->runtime.'/'.$path, 0700, true);
        }
        file_put_contents($this->runtime.'/.env', '');
        putenv('ENTERPRISE_RUNTIME_ROOT');
        $app = (static fn (string $path) => require $path)(dirname(__DIR__, 3).'/apps/enterprise/bootstrap/app.php');
        $app->useEnvironmentPath($this->runtime);
        $app->useStoragePath($this->runtime.'/storage');
        $app->useBootstrapPath($this->runtime.'/bootstrap');
        $app->instance('enterprise.runtime_root', $this->runtime);
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set([
            'app.env' => 'testing', 'app.url' => 'https://building.example.invalid',
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'enterprise_runtime.isolated' => true, 'enterprise_runtime.base_path' => '',
            'enterprise_client.client_id' => '',
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'session.driver' => 'array', 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4,
            'enterprise.enabled' => true, 'enterprise.writes_enabled' => true,
            'enterprise.operations_enabled' => true, 'enterprise.resources_enabled' => true, 'team_work.enabled' => true,
        ]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
        $this->tenant = Tenant::query()->create(['name' => 'Synthetic classification workspace', 'slug' => 'synthetic-classification']);
        $this->coordinator = new User;
        $this->coordinator->forceFill(['name' => 'Synthetic coordinator', 'email' => 'building@example.invalid',
            'password' => 'fixture-only', 'role' => 'member', 'is_active' => true, 'email_verified_at' => now()])->save();
        $this->tenant->users()->attach($this->coordinator->id, ['role' => 'member', 'membership_active' => true]);
        TenantAccessProfile::query()->create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
        TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'team_work',
            'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
        $this->area = (string) Str::uuid();
        TeamArea::query()->create(['id' => $this->area, 'tenant_id' => $this->tenant->id, 'slug' => 'building-operations',
            'name' => 'Synthetic Operations', 'profile' => 'enterprise_department', 'settings' => []]);
        DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'area_id' => $this->area, 'user_id' => $this->coordinator->id, 'role' => 'coordinator']);
        config(['enterprise.tenant_allowlist' => [(string) $this->tenant->id], 'team_work.tenant_allowlist' => [(string) $this->tenant->id]]);
        $this->actingAs($this->coordinator);
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            parent::tearDown();
        } finally {
            putenv($this->previousRoot === false ? 'ENTERPRISE_RUNTIME_ROOT' : 'ENTERPRISE_RUNTIME_ROOT='.$this->previousRoot);
            if (str_starts_with($this->runtime, sys_get_temp_dir().'/enterprise-classification-test-')) {
                (new Filesystem)->deleteDirectory($this->runtime);
            }
        }
    }

    private function command(array $input)
    {
        return $this->postJson(route('enterprise.commands', ['tenant' => $this->tenant->slug]), $input + [
            'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $this->area, 'expected_version' => 0]);
    }

    private function building(array $extra = []): array
    {
        return $this->postJson(route('enterprise.resources.command', ['tenant' => $this->tenant->slug]), $extra + [
            'action' => 'location.save', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
            'area_id' => $this->area, 'expected_version' => 0, 'title' => 'Synthetic North Hall', 'level' => 'facility'])->assertOk()->json();
    }

    private function projection(): array
    {
        return $this->getJson(route('enterprise.data', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    private function linkedInput(array $building, array $extra = []): array
    {
        return $extra + ['action' => 'request.create', 'title' => 'Synthetic roof inspection', 'location_id' => $building['id'],
            'location_version' => $building['version'], 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()];
    }

    public function test_create_persists_structured_type_and_preassignment_priority_with_reload_audit_and_no_grants(): void
    {
        $building = $this->building();
        $input = $this->linkedInput($building, ['request_type' => 'plumbing', 'priority' => 'high']);
        $row = $this->command($input)->assertOk()->assertJsonPath('request_type', 'plumbing')->assertJsonPath('priority', 'high')
            ->assertJsonPath('priority_source', 'request')->assertJsonPath('requested_priority', 'high')->json();
        $this->assertSame('high', TeamOperationRecord::query()->findOrFail($row['id'])->data['priority']);
        $raw = DB::table('team_operation_records')->where('id', $row['id'])->value('data');
        $this->assertStringNotContainsString('plumbing', $raw);
        $current = collect($this->projection()['requests'])->firstWhere('id', $row['id']);
        $this->assertSame('plumbing', $current['request_type']);
        $this->assertSame('high', $current['priority']);
        $history = $this->getJson(route('enterprise.history', ['tenant' => $this->tenant->slug, 'id' => $row['id']]))->assertOk()->json('items');
        $this->assertSame('plumbing', $history[0]['event']['after']['data']['request_type']);
        $this->assertSame('high', $history[0]['event']['after']['requested_priority']);
        $this->assertArrayNotHasKey('priority', $history[0]['event']['after']);
        $this->assertSame(1, DB::table('tenant_user')->count());
        $this->assertSame(1, DB::table('team_area_members')->count());
        $this->assertSame(0, DB::table('team_project_members')->count());
    }

    public function test_legacy_omission_remains_unrecorded_until_coordinator_classifies_with_versioned_exact_retry(): void
    {
        $input = ['action' => 'request.create', 'title' => 'DEMO • Old intake', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()];
        $row = $this->command($input)->assertOk()->assertJsonPath('request_type', null)->assertJsonPath('priority', null)->assertJsonPath('priority_source', 'not_recorded')->json();
        $this->assertArrayNotHasKey('priority', $row['data']);
        $classify = ['action' => 'request.classify', 'id' => $row['id'], 'expected_version' => 1, 'request_id' => (string) Str::uuid(), 'request_type' => 'lighting', 'priority' => 'low'];
        $this->command($classify)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('priority', 'low');
        $this->command($classify)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('version', 2);
        $this->command(array_replace($classify, ['priority' => 'high']))->assertConflict();
        $this->command(array_replace($classify, ['request_id' => (string) Str::uuid()]))->assertConflict();
        $this->assertSame(2, DB::table('team_operation_revisions')->where('record_id', $row['id'])->count());
        $clear = array_replace($classify, ['expected_version' => 2, 'request_id' => (string) Str::uuid(), 'request_type' => null, 'priority' => null]);
        $this->command($clear)->assertOk()->assertJsonPath('version', 3)->assertJsonPath('request_type', null)->assertJsonPath('priority', null);
    }

    public function test_validation_never_silently_accepts_unknown_values_alias_or_classification_on_another_action(): void
    {
        foreach ([['request_type' => 'invented'], ['request_type' => ['plumbing']], ['priority' => 'critical'], ['priority' => 1], ['type' => 'plumbing']] as $fields) {
            $this->command($fields + ['action' => 'request.create', 'title' => 'DEMO • Invalid fields'])->assertUnprocessable();
        }
        $row = $this->command(['action' => 'request.create', 'title' => 'DEMO • Valid legacy'])->assertOk()->json();
        $this->command(['action' => 'request.classify', 'id' => $row['id'], 'expected_version' => 1])->assertUnprocessable();
        $this->command(['action' => 'request.triage', 'id' => $row['id'], 'expected_version' => 1, 'priority' => 'high'])->assertUnprocessable();
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'enterprise_request')->count());
    }

    public function test_assignment_hands_off_high_priority_and_current_task_priority_changes_do_not_rewrite_intake(): void
    {
        $request = $this->command(['action' => 'request.create', 'title' => 'DEMO • Leaking pipe', 'request_type' => 'plumbing', 'priority' => 'high'])->assertOk()->json();
        $project = $this->command(['action' => 'project.create', 'title' => 'DEMO • Native work'])->assertOk()->json();
        $this->command(['action' => 'request.triage', 'id' => $request['id'], 'expected_version' => 1])->assertOk();
        $classification = ['action' => 'request.classify', 'id' => $request['id'], 'expected_version' => 2, 'request_id' => (string) Str::uuid(), 'request_type' => 'maintenance'];
        $this->command($classification)->assertOk()->assertJsonPath('version', 3)->assertJsonPath('priority', 'high');
        $assign = ['action' => 'request.assign', 'id' => $request['id'], 'expected_version' => 3, 'request_id' => (string) Str::uuid(), 'project_id' => $project['id'], 'assignee_id' => $this->coordinator->id];
        $assigned = $this->command($assign)->assertOk()->assertJsonPath('task.priority', 'high')->assertJsonPath('priority_source', 'task')->json();
        $this->postJson(route('enterprise.operations.command', ['tenant' => $this->tenant->slug]), [
            'action' => 'task.configure', 'task_id' => $assigned['task']['id'], 'request_id' => (string) Str::uuid(), 'expected_version' => 0,
            'expected_task_version' => $assigned['task']['version'], 'owner_user_id' => (string) $this->coordinator->id, 'ball_user_id' => (string) $this->coordinator->id,
            'ball_team' => false, 'next_action' => 'DEMO • Inspect safely', 'priority' => 'urgent', 'blockers' => [],
            'checklist' => [['id' => 'inspect', 'label' => 'DEMO inspection', 'weight' => 1]],
        ])->assertOk();
        $current = collect($this->projection()['requests'])->firstWhere('id', $request['id']);
        $this->assertSame('urgent', $current['priority']);
        $this->assertSame('high', $current['requested_priority']);
        $this->assertSame('maintenance', $current['request_type']);
        $this->assertSame('high', $current['data']['priority']);
        $this->assertSame(4, $current['version']);
        $this->command(array_replace($classification, ['request_id' => (string) Str::uuid(), 'expected_version' => 4, 'priority' => 'low']))->assertConflict();
        $this->command($classification)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('priority', 'urgent');
        $this->command($assign)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('task.id', $assigned['task']['id']);
        $this->assertCount(1, app(\App\Services\Team\TeamWorkRepository::class)->state($this->tenant->id)['tasks']);
        $this->assertSame(4, DB::table('team_operation_revisions')->where('record_id', $request['id'])->count());
    }

    public function test_editor_requester_and_revoked_coordinator_cannot_classify_or_recover_an_old_receipt(): void
    {
        $row = $this->command(['action' => 'request.create', 'title' => 'DEMO • Scoped intake'])->assertOk()->json();
        $classification = ['action' => 'request.classify', 'id' => $row['id'], 'expected_version' => 1, 'request_id' => (string) Str::uuid(), 'priority' => 'high'];
        $this->command($classification)->assertOk();
        $shared = (string) Str::uuid();
        $owner = new User;
        $owner->forceFill(['name' => 'Synthetic owner', 'email' => 'classification-owner@example.invalid', 'password' => 'fixture-only', 'role' => 'member', 'is_active' => true, 'email_verified_at' => now()])->save();
        $this->tenant->users()->attach($owner->id, ['role' => 'owner', 'membership_active' => true]);
        $this->actingAs($owner);
        $this->command(['action' => 'workspace.create', 'id' => $shared, 'title' => 'DEMO • Shared intake', 'visibility' => 'shared'])->assertOk();
        $this->actingAs($this->coordinator);
        $submitted = $this->command(['action' => 'request.create', 'area_id' => $shared, 'title' => 'DEMO • Requester intake', 'request_type' => 'it_support', 'priority' => 'normal'])->assertOk()->json();
        $this->command(['action' => 'request.classify', 'area_id' => $shared, 'id' => $submitted['id'], 'expected_version' => 1, 'priority' => 'high'])->assertNotFound();
        DB::table('team_area_members')->where('user_id', $this->coordinator->id)->update(['role' => 'editor']);
        $this->command(array_replace($classification, ['expected_version' => 2, 'request_id' => (string) Str::uuid()]))->assertNotFound();
        $this->command($classification)->assertNotFound();
        $this->assertSame(2, TeamOperationRecord::query()->findOrFail($row['id'])->version);
        $this->assertSame('high', TeamOperationRecord::query()->findOrFail($row['id'])->data['priority']);
        $this->assertSame(2, DB::table('team_operation_revisions')->where('record_id', $row['id'])->count());
    }

    public function test_foreign_tenant_and_area_references_are_not_classifiable_or_projected(): void
    {
        $request = $this->command(['action' => 'request.create', 'title' => 'DEMO • Current tenant'])->assertOk()->json();
        $foreign = Tenant::query()->create(['name' => 'Synthetic foreign workspace', 'slug' => 'classification-foreign']);
        $foreign->users()->attach($this->coordinator->id, ['role' => 'owner', 'membership_active' => true]);
        TenantAccessProfile::query()->create(['tenant_id' => $foreign->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
        TenantModuleEntitlement::query()->create(['tenant_id' => $foreign->id, 'module_key' => 'team_work', 'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
        config(['enterprise.tenant_allowlist' => [(string) $this->tenant->id, (string) $foreign->id], 'team_work.tenant_allowlist' => [(string) $this->tenant->id, (string) $foreign->id]]);
        $otherArea = (string) Str::uuid();
        $this->postJson(route('enterprise.commands', ['tenant' => $foreign->slug]), ['action' => 'workspace.create', 'id' => $otherArea, 'request_id' => (string) Str::uuid(), 'title' => 'DEMO • Foreign private area', 'visibility' => 'department_only'])->assertOk();
        $other = $this->postJson(route('enterprise.commands', ['tenant' => $foreign->slug]), ['action' => 'request.create', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $otherArea, 'expected_version' => 0, 'title' => 'DEMO • Foreign intake', 'priority' => 'urgent'])->assertOk()->json();
        $this->command(['action' => 'request.classify', 'id' => $other['id'], 'expected_version' => 1, 'priority' => 'low'])->assertNotFound();
        $this->command(['action' => 'request.classify', 'id' => $request['id'], 'area_id' => $otherArea, 'expected_version' => 1, 'priority' => 'low'])->assertNotFound();
        $this->assertSame([$request['id']], array_column($this->projection()['requests'], 'id'));
        $this->assertSame('urgent', TeamOperationRecord::query()->findOrFail($other['id'])->data['priority']);
    }

    public function test_native_caller_validation_does_not_bypass_finite_fields_or_create_rows(): void
    {
        try {
            app(\App\Enterprise\RequestWorkflow::class)->execute($this->tenant, $this->coordinator,
                ['action' => 'request.create', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $this->area, 'expected_version' => 0, 'title' => 'DEMO • Native invalid', 'priority' => 'critical']);
            $this->fail('Invalid native priority was accepted.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'enterprise_request')->count());
    }

    public function test_published_requester_category_is_not_inferred_as_department_type_or_priority(): void
    {
        config(['enterprise.requester_enabled' => true]);
        TeamArea::query()->findOrFail($this->area)->update(['settings' => ['enterprise_request_intake' => true]]);
        $row = $this->postJson(route('enterprise.requester.submit', ['tenant' => $this->tenant->slug]), [
            'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $this->area,
            'title' => 'DEMO • Published intake', 'description' => 'Fictional category boundary', 'category' => 'IT',
        ])->assertOk()->assertJsonPath('category', 'IT')->json();
        $this->assertArrayNotHasKey('request_type', $row);
        $this->assertArrayNotHasKey('priority', $row);
        $current = collect($this->projection()['requests'])->firstWhere('id', $row['id']);
        $this->assertNull($current['request_type']);
        $this->assertNull($current['priority']);
        $this->assertSame('not_recorded', $current['priority_source']);
        $this->command(['action' => 'request.classify', 'id' => $row['id'], 'expected_version' => 1, 'request_type' => 'it_support', 'priority' => 'normal'])
            ->assertOk()->assertJsonPath('request_type', 'it_support')->assertJsonPath('priority', 'normal');
        $this->assertSame('IT', TeamOperationRecord::query()->findOrFail($row['id'])->data['requester_category']);
    }
}
