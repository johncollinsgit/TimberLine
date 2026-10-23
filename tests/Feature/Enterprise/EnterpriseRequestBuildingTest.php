<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Resources;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Real isolated Enterprise schema and request/resource routes; no retail fixtures or external I/O. */
final class EnterpriseRequestBuildingTest extends TestCase
{
    private string $runtime;

    private string|false $previousRoot;

    private Tenant $tenant;

    private User $coordinator;

    private string $area;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/enterprise-building-test-'.Str::uuid();
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
        $this->tenant = Tenant::query()->create(['name' => 'Synthetic building workspace', 'slug' => 'synthetic-building']);
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
            if (str_starts_with($this->runtime, sys_get_temp_dir().'/enterprise-building-test-')) {
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

    public function test_saved_building_link_survives_reload_and_audit_without_text_inference_or_new_grants(): void
    {
        $building = $this->building();
        $projection = $this->projection();
        $this->assertSame([['id' => $building['id'], 'title' => 'Synthetic North Hall', 'version' => 1]], $projection['buildings']['items']);
        $this->assertFalse($projection['buildings']['has_more']);
        $request = $this->command($this->linkedInput($building, ['location' => 'Client-supplied misleading label']))->assertOk()
            ->assertJsonPath('data.location', 'Synthetic North Hall')->assertJsonPath('data.location_id', $building['id'])
            ->assertJsonPath('data.location_version', 1)->assertJsonPath('data.latitude', null)->assertJsonPath('data.longitude', null)->json();
        $legacy = $this->command(['action' => 'request.create', 'title' => 'Legacy text only', 'location' => 'Synthetic North Hall',
            'latitude' => '36.16', 'longitude' => '-81.87'])->assertOk()->assertJsonMissingPath('data.location_id')->json();
        $reloaded = collect($this->projection()['requests'])->keyBy('id');
        $this->assertSame($building['id'], $reloaded[$request['id']]['data']['location_id']);
        $this->assertArrayNotHasKey('location_id', $reloaded[$legacy['id']]['data']);
        $this->assertSame('36.16', $reloaded[$legacy['id']]['data']['latitude']);
        $history = $this->getJson(route('enterprise.history', ['tenant' => $this->tenant->slug, 'id' => $request['id']]))->assertOk();
        $history->assertJsonPath('items.0.event.after.data.location_id', $building['id']);
        $ciphertext = DB::table('team_operation_records')->where('id', $request['id'])->value('data');
        $this->assertStringNotContainsString($building['id'], $ciphertext);
        $this->assertStringContainsString($building['id'], Crypt::decryptString($ciphertext));
        $this->assertSame('member', DB::table('users')->value('role'));
        $this->assertSame('member', DB::table('tenant_user')->value('role'));
        $this->assertSame('coordinator', DB::table('team_area_members')->value('role'));
        $this->assertSame(1, DB::table('team_area_members')->count());
    }

    public function test_current_request_projection_retains_real_links_and_canonical_priority_for_linked_only_counts(): void
    {
        $building = $this->building();
        $first = $this->command($this->linkedInput($building))->assertOk()->json();
        $second = $this->command($this->linkedInput($building, ['title' => 'Synthetic second linked job']))->assertOk()->json();
        $legacy = $this->command(['action' => 'request.create', 'title' => 'Synthetic matching text', 'location' => $building['title']])->assertOk()->json();
        $project = $this->command(['action' => 'project.create', 'title' => 'Synthetic maintenance'])->assertOk()->json('id');
        $this->command(['action' => 'request.triage', 'id' => $first['id'], 'expected_version' => 1])->assertOk();
        $assigned = $this->command(['action' => 'request.assign', 'id' => $first['id'], 'expected_version' => 2,
            'project_id' => $project, 'assignee_id' => $this->coordinator->id])->assertOk()->json();
        $this->postJson(route('enterprise.operations.command', ['tenant' => $this->tenant->slug]), [
            'action' => 'task.configure', 'task_id' => $assigned['task']['id'], 'request_id' => (string) Str::uuid(),
            'expected_version' => 0, 'expected_task_version' => $assigned['task']['version'],
            'owner_user_id' => (string) $this->coordinator->id, 'ball_user_id' => (string) $this->coordinator->id,
            'ball_team' => false, 'next_action' => 'Synthetic roof inspection', 'priority' => 'urgent',
            'checklist' => [['id' => 'inspect', 'label' => 'Synthetic inspection', 'weight' => 1]]])->assertOk();
        $projection = $this->projection();
        $linked = collect($projection['requests'])->filter(fn ($row) => ($row['data']['location_id'] ?? null) === $building['id']);
        $this->assertEqualsCanonicalizing([$first['id'], $second['id']], $linked->pluck('id')->all());
        $this->assertSame(2, $linked->whereNotIn('status', ['completed', 'closed', 'cancelled', 'rejected'])->count());
        $this->assertSame(1, $linked->filter(fn ($row) => ($row['task']['priority'] ?? null) === 'urgent')->count());
        $this->assertArrayNotHasKey('location_id', collect($projection['requests'])->firstWhere('id', $legacy['id'])['data']);
        // Private graph ACL still controls a linked request after canonical assignment.
        DB::table('team_area_members')->where('user_id', $this->coordinator->id)->delete();
        $this->getJson(route('enterprise.data', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))->assertNotFound();
    }

    public function test_projection_and_create_exclude_foreign_archived_wrong_kind_and_stale_targets(): void
    {
        $building = $this->building();
        $otherArea = (string) Str::uuid();
        TeamArea::query()->create(['id' => $otherArea, 'tenant_id' => $this->tenant->id, 'slug' => 'private-other',
            'name' => 'Synthetic restricted area', 'profile' => 'enterprise_department', 'settings' => []]);
        $foreignTenant = Tenant::query()->create(['name' => 'Synthetic other silo', 'slug' => 'synthetic-other']);
        $foreignArea = (string) Str::uuid();
        TeamArea::query()->create(['id' => $foreignArea, 'tenant_id' => $foreignTenant->id, 'slug' => 'foreign',
            'name' => 'Synthetic foreign area', 'profile' => 'enterprise_department', 'settings' => []]);
        $rows = [];
        foreach ([
            ['tenant_id' => $this->tenant->id, 'area_id' => $otherArea],
            ['tenant_id' => $foreignTenant->id, 'area_id' => $foreignArea],
            ['status' => 'archived'], ['status' => 'inactive'], ['status' => 'withdrawn'],
            ['kind' => Resources::KINDS['asset']], ['data' => ['level' => 'room']],
        ] as $delta) {
            $row = TeamOperationRecord::query()->findOrFail($building['id'])->replicate();
            $row->forceFill($delta + ['id' => (string) Str::uuid(), 'source_key' => Str::random(64)])->save();
            $rows[] = $row;
        }
        $this->assertSame([$building['id']], array_column($this->projection()['buildings']['items'], 'id'));
        foreach ($rows as $index => $row) {
            $status = in_array($index, [0, 1, 5], true) ? 404 : 409;
            $this->command($this->linkedInput(['id' => $row->id, 'version' => $row->version]))->assertStatus($status);
        }
        $this->command($this->linkedInput(['id' => (string) Str::uuid(), 'version' => 1]))->assertNotFound();
        $this->building(['id' => $building['id'], 'expected_version' => 1, 'title' => 'Synthetic renamed hall']);
        $this->command($this->linkedInput($building))->assertConflict();
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'enterprise_request')->count());
    }

    public function test_pair_validation_prohibits_coordinates_catalog_ids_and_noncreate_retargeting(): void
    {
        $building = $this->building();
        foreach ([
            ['location_version' => null], ['location_id' => null], ['location_version' => 0],
            ['location_id' => '52'], ['latitude' => 36.16, 'longitude' => -81.87],
        ] as $delta) {
            $this->command($this->linkedInput($building, $delta))->assertUnprocessable();
        }
        $request = $this->command($this->linkedInput($building))->assertOk()->json();
        $this->command(['action' => 'request.triage', 'id' => $request['id'], 'expected_version' => 1,
            'location_id' => $building['id'], 'location_version' => 1])->assertUnprocessable();
        $this->command(['action' => 'project.create', 'title' => 'Synthetic project',
            'location_id' => $building['id'], 'location_version' => 1])->assertUnprocessable();
        $this->assertSame(1, TeamOperationRecord::query()->findOrFail($request['id'])->version);
    }

    public function test_choice_projection_is_bounded_and_reports_omitted_buildings(): void
    {
        $building = $this->building();
        for ($index = 0; $index < 200; $index++) {
            $row = TeamOperationRecord::query()->findOrFail($building['id'])->replicate();
            $row->forceFill(['id' => (string) Str::uuid(), 'source_key' => Str::random(64), 'title' => 'Synthetic building '.$index])->save();
        }
        $projection = $this->projection()['buildings'];
        $this->assertCount(200, $projection['items']);
        $this->assertTrue($projection['has_more']);
        $this->assertSame(['id', 'title', 'version'], array_keys($projection['items'][0]));
    }

    public function test_disabled_resources_preserves_legacy_intake_and_current_permissions_fail_closed(): void
    {
        $building = $this->building();
        config(['enterprise.resources_enabled' => false]);
        $this->assertSame([], $this->projection()['buildings']['items']);
        $this->command($this->linkedInput($building))->assertNotFound();
        $this->command(['action' => 'request.create', 'title' => 'Legacy label', 'location' => 'North Hall'])->assertOk();
        config(['enterprise.resources_enabled' => true]);
        DB::table('team_area_members')->where('user_id', $this->coordinator->id)->update(['role' => 'viewer']);
        // Existing area viewers may read choices, but cannot submit to this department.
        $this->assertCount(1, $this->projection()['buildings']['items']);
        $this->command($this->linkedInput($building))->assertForbidden();
        DB::table('team_area_members')->where('user_id', $this->coordinator->id)->delete();
        $this->getJson(route('enterprise.data', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))->assertNotFound();
        $this->command($this->linkedInput($building))->assertNotFound();
        $this->tenant->users()->updateExistingPivot($this->coordinator->id, ['membership_active' => false]);
        $this->command($this->linkedInput($building))->assertForbidden();
    }

    public function test_accepted_exact_retry_recovers_one_receipt_but_new_stale_writes_and_changed_retries_fail(): void
    {
        $building = $this->building();
        $input = $this->linkedInput($building);
        $request = $this->command($input)->assertOk()->json();
        $this->building(['id' => $building['id'], 'expected_version' => 1, 'title' => 'Synthetic renamed hall']);
        $before = [DB::table('team_operation_attempts')->count(), DB::table('team_operation_revisions')->count()];
        $this->command($input)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('id', $request['id'])
            ->assertJsonPath('data.location', 'Synthetic North Hall')->assertJsonPath('data.location_version', 1);
        $this->command([...$input, 'location_version' => 2])->assertConflict();
        $this->command($this->linkedInput($building))->assertConflict();
        $this->assertSame($before, [DB::table('team_operation_attempts')->count(), DB::table('team_operation_revisions')->count()]);
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'enterprise_request')->count());
        $this->tenant->users()->updateExistingPivot($this->coordinator->id, ['membership_active' => false]);
        $this->command($input)->assertForbidden();
    }
}
