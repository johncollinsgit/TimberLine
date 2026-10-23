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
final class EnterpriseJobManagementTest extends TestCase
{
    private string $runtime;

    private string|false $previousRoot;

    private Tenant $tenant;

    private User $coordinator;

    private string $area;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/enterprise-jobs-test-'.Str::uuid();
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
        $this->tenant = Tenant::query()->create(['name' => 'Synthetic job workspace', 'slug' => 'synthetic-classification']);
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
            if (str_starts_with($this->runtime, sys_get_temp_dir().'/enterprise-jobs-test-')) {
                (new Filesystem)->deleteDirectory($this->runtime);
            }
        }
    }

    private function command(array $input)
    {
        return $this->postJson(route('enterprise.commands', ['tenant' => $this->tenant->slug]), $input + [
            'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $this->area, 'expected_version' => 0]);
    }

    private function secondArea(bool $coordinate = true): string
    {
        $id = (string) Str::uuid();
        TeamArea::query()->create(['id' => $id, 'tenant_id' => $this->tenant->id, 'slug' => 'jobs-'.$id,
            'name' => 'Synthetic second department', 'profile' => 'enterprise_department', 'settings' => []]);
        DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'area_id' => $id, 'user_id' => $this->coordinator->id, 'role' => $coordinate ? 'coordinator' : 'viewer']);

        return $id;
    }

    private function job(array $extra = []): array
    {
        return $this->command($extra + ['action' => 'job.create', 'title' => 'Synthetic inspection project',
            'task_title' => 'Inspect synthetic equipment', 'description' => 'Fictional operational details.',
            'priority' => 'high', 'request_type' => 'maintenance', 'assignee_id' => $this->coordinator->id,
            'due_on' => '2026-10-28'])->assertOk()->json();
    }

    public function test_job_creation_reuses_native_records_with_exact_retry_and_no_duplicate_assignment(): void
    {
        $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()];
        $result = $this->job($input);
        $this->assertFalse($result['replayed']);
        $this->assertSame(1, DB::table('team_projects')->count());
        $this->assertSame(1, DB::table('team_tasks')->count());
        $record = TeamOperationRecord::findOrFail($result['ticket_id']);
        $this->assertSame('assigned', $record->status);
        $this->assertSame($result['task_id'], $record->data['task_id']);
        $again = $this->job($input);
        $this->assertTrue($again['replayed']);
        $this->assertSame($result['task_id'], $again['task_id']);
        $this->assertSame(1, DB::table('team_projects')->count());
        $this->assertSame(1, DB::table('team_tasks')->count());
        $data = $this->getJson(route('enterprise.data', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))->assertOk()->json();
        $this->assertSame('high', $data['projects'][0]['tasks'][0]['priority']);
        $this->assertSame((string) $this->coordinator->id, $data['projects'][0]['tasks'][0]['assignee_id']);
        $this->assertSame($record->version, $data['projects'][0]['tasks'][0]['linked_request']['version']);
    }

    public function test_incoming_routing_is_atomic_versioned_and_checks_both_department_authorities(): void
    {
        $target = $this->secondArea();
        $project = $this->command(['action' => 'project.create', 'area_id' => $target, 'title' => 'Destination project'])->assertOk()->json();
        $ticket = $this->command(['action' => 'request.create', 'title' => 'Incoming fictional ticket', 'request_type' => 'lighting', 'priority' => 'urgent'])->assertOk()->json();
        $body = ['action' => 'request.plan', 'id' => $ticket['id'], 'expected_version' => $ticket['version'], 'target_area_id' => $target,
            'project_id' => $project['id'], 'assignee_id' => $this->coordinator->id, 'acknowledge_routing' => true, 'request_id' => (string) Str::uuid()];
        $result = $this->command($body)->assertOk()->json();
        $record = TeamOperationRecord::findOrFail($ticket['id']);
        $this->assertSame($target, $record->area_id);
        $this->assertSame('assigned', $record->status);
        $this->assertSame($project['id'], $record->data['project_id']);
        $this->assertSame($result['task_id'], $record->data['task_id']);
        $this->assertTrue($this->command($body)->assertOk()->json('replayed'));
        $denied = $this->secondArea(false);
        $ticket = $this->command(['action' => 'request.create', 'title' => 'Keep this request unchanged'])->assertOk()->json();
        $this->command(array_replace($body, ['id' => $ticket['id'], 'expected_version' => $ticket['version'], 'target_area_id' => $denied]))->assertNotFound();
        $this->assertSame($this->area, TeamOperationRecord::findOrFail($ticket['id'])->area_id);
        $this->assertSame('submitted', TeamOperationRecord::findOrFail($ticket['id'])->status);
    }

    public function test_invalid_assignment_rolls_back_entire_job_and_does_not_grant_membership(): void
    {
        $before = DB::table('team_area_members')->count();
        $this->command(['action' => 'job.create', 'title' => 'Invalid job', 'assignee_id' => 999999])->assertUnprocessable();
        $this->assertSame(0, DB::table('team_projects')->count());
        $this->assertSame(0, DB::table('team_tasks')->count());
        $this->assertSame(0, TeamOperationRecord::where('kind', 'enterprise_request')->count());
        $this->assertSame($before, DB::table('team_area_members')->count());
    }

    public function test_task_edits_require_current_linked_ticket_stamp_and_synchronize_native_assignment(): void
    {
        $job = $this->job();
        $record = TeamOperationRecord::findOrFail($job['ticket_id']);
        $body = ['action' => 'task.update', 'id' => $job['task_id'], 'expected_version' => 1,
            'title' => 'Revised fictional task', 'description' => 'Revised description', 'due_on' => '2026-10-29',
            'assignee_id' => $this->coordinator->id, 'priority' => 'urgent', 'related_request_id' => $record->id, 'request_version' => $record->version];
        $this->command(array_replace($body, ['request_version' => 999]))->assertConflict();
        $this->command($body)->assertOk();
        $record->refresh();
        $this->assertSame('Revised fictional task', $record->title);
        $this->assertSame('Revised description', $record->data['description']);
        $this->assertSame('2026-10-29', $record->starts_on->toDateString());
        $this->assertSame('high', $record->data['priority']);
        $this->command($body)->assertConflict();
        $data = $this->getJson(route('enterprise.data', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))->assertOk()->json();
        $this->assertSame('urgent', $data['requests'][0]['priority']);
        $this->assertSame('high', $data['requests'][0]['requested_priority']);
    }

    public function test_native_project_archive_is_reversible_and_stale_edits_are_refused(): void
    {
        $job = $this->job();
        $this->command(['action' => 'project.update', 'id' => $job['project_id'], 'expected_version' => 1, 'title' => 'Revised project'])->assertOk();
        $this->command(['action' => 'project.update', 'id' => $job['project_id'], 'expected_version' => 1, 'title' => 'Stale project'])->assertConflict();
        $this->command(['action' => 'project.archive', 'id' => $job['project_id'], 'expected_version' => 2, 'archived' => true])->assertOk();
        $this->command(['action' => 'project.archive', 'id' => $job['project_id'], 'expected_version' => 3, 'archived' => false])->assertOk();
        $this->assertSame(1, DB::table('team_tasks')->count());
    }

    public function test_current_read_only_members_and_paused_writes_cannot_start_management(): void
    {
        DB::table('team_area_members')->where('user_id', $this->coordinator->id)->update(['role' => 'viewer']);
        $this->command(['action' => 'job.create', 'title' => 'Refused job', 'assignee_id' => $this->coordinator->id])->assertNotFound();
        $this->assertSame(0, DB::table('team_projects')->count());
        DB::table('team_area_members')->where('user_id', $this->coordinator->id)->update(['role' => 'coordinator']);
        config(['enterprise.writes_enabled' => false]);
        $this->command(['action' => 'job.create', 'title' => 'Paused job', 'assignee_id' => $this->coordinator->id])->assertForbidden();
        $this->assertSame(0, DB::table('team_tasks')->count());
    }

    public function test_soft_task_removal_preserves_original_records_and_rechecks_access_on_exact_retry(): void
    {
        $job = $this->job();
        $record = TeamOperationRecord::findOrFail($job['ticket_id']);
        $body = ['action' => 'task.delete', 'id' => $job['task_id'], 'expected_version' => 1,
            'related_request_id' => $record->id, 'request_version' => $record->version, 'request_id' => (string) Str::uuid(), 'acknowledge_removal' => true];
        $this->command($body)->assertOk();
        $this->assertSame(1, DB::table('team_tasks')->count());
        $this->assertSame(1, TeamOperationRecord::where('id', $record->id)->count());
        $this->assertSame('removed', $record->fresh()->status);
        $this->assertTrue($this->command($body)->assertOk()->json('replayed'));
        DB::table('team_area_members')->where('user_id', $this->coordinator->id)->update(['role' => 'viewer']);
        $this->command($body)->assertNotFound();
    }

    public function test_routing_refuses_foreign_and_stale_buildings_and_rolls_back_every_record(): void
    {
        $target = $this->secondArea();
        $project = $this->command(['action' => 'project.create', 'area_id' => $target, 'title' => 'Destination'])->assertOk()->json();
        $building = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'source_key' => (string) Str::uuid(), 'area_id' => $target, 'kind' => 'ent_resource_location', 'title' => 'Synthetic building', 'status' => 'active',
            'version' => 2, 'data' => ['level' => 'facility', 'map_number' => '52', 'source_url' => 'https://example.invalid/map.pdf']]);
        $ticket = $this->command(['action' => 'request.create', 'title' => 'Unassigned'])->assertOk()->json();
        $body = ['action' => 'request.plan', 'id' => $ticket['id'], 'expected_version' => 1, 'target_area_id' => $target,
            'project_id' => $project['id'], 'assignee_id' => $this->coordinator->id, 'acknowledge_routing' => true,
            'location_id' => $building->id, 'location_version' => 1];
        $before = TeamOperationRecord::findOrFail($ticket['id'])->getRawOriginal();
        $this->command($body)->assertConflict();
        $this->assertSame($before, TeamOperationRecord::findOrFail($ticket['id'])->getRawOriginal());
        $this->assertSame(0, DB::table('team_tasks')->count());
        $this->command(array_replace($body, ['target_area_id' => $this->area, 'location_version' => 2]))->assertNotFound();
        $this->assertSame($before, TeamOperationRecord::findOrFail($ticket['id'])->getRawOriginal());
        $this->command(array_replace($body, ['location_version' => 2]))->assertOk();
        $saved = TeamOperationRecord::findOrFail($ticket['id']);
        $this->assertSame($building->id, $saved->data['location_id']);
        $this->assertSame(2, $saved->data['location_version']);
    }

    public function test_completed_and_archived_work_and_changed_receipt_intent_fail_without_replacement(): void
    {
        $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()];
        $job = $this->job($input);
        $this->command($input + ['action' => 'job.create', 'title' => 'Different intent', 'assignee_id' => $this->coordinator->id])->assertConflict();
        $this->assertSame(1, DB::table('team_projects')->count());
        $this->command(['action' => 'project.archive', 'id' => $job['project_id'], 'expected_version' => 1, 'archived' => true])->assertOk();
        $this->command(['action' => 'task.create', 'project_id' => $job['project_id'], 'title' => 'Refused archived task'])->assertUnprocessable();
        $this->assertSame(1, DB::table('team_tasks')->count());
        $this->command(['action' => 'request.plan', 'id' => $job['ticket_id'], 'expected_version' => TeamOperationRecord::findOrFail($job['ticket_id'])->version,
            'target_area_id' => $this->area, 'project_id' => $job['project_id'], 'assignee_id' => $this->coordinator->id, 'acknowledge_routing' => true])->assertConflict();
    }

    public function test_inactive_unverified_and_outside_department_assignees_never_gain_membership(): void
    {
        $other = User::create(['name' => 'Synthetic unavailable worker', 'email' => 'unavailable@example.invalid', 'password' => 'fixture-only', 'is_active' => true]);
        $this->tenant->users()->attach($other->id, ['role' => 'member', 'membership_active' => true]);
        $count = DB::table('team_area_members')->count();
        $this->command(['action' => 'job.create', 'title' => 'Refuse unverified', 'assignee_id' => $other->id])->assertUnprocessable();
        $other->forceFill(['email_verified_at' => now()])->save();
        $this->command(['action' => 'job.create', 'title' => 'Refuse outside department', 'assignee_id' => $other->id])->assertUnprocessable();
        $this->assertSame($count, DB::table('team_area_members')->count());
        $this->assertSame(0, DB::table('team_projects')->count());
    }

    public function test_visible_task_retains_current_authorized_link_stamp_beyond_the_request_list_boundary(): void
    {
        $job = $this->job();
        $this->travel(1)->minutes();
        for ($index = 0; $index < 101; $index++) {
            $this->command(['action' => 'request.create', 'title' => 'Newer incoming '.$index])->assertOk();
        }
        $data = $this->getJson(route('enterprise.data', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))->assertOk()->json();
        $this->assertCount(100, $data['requests']);
        $this->assertNotContains($job['ticket_id'], array_column($data['requests'], 'id'));
        $task = collect($data['projects'][0]['tasks'])->firstWhere('id', $job['task_id']);
        $this->assertTrue($task['can_edit']);
        $this->assertSame('current', $task['edit_link_status']);
        $this->assertSame($job['ticket_id'], $task['linked_request']['id']);
        $this->command(['action' => 'task.update', 'id' => $task['id'], 'expected_version' => $task['version'],
            'related_request_id' => $task['linked_request']['id'], 'request_version' => $task['linked_request']['version'],
            'title' => 'Edit from actual current projection', 'assignee_id' => $this->coordinator->id])->assertOk();
        $this->assertSame('Edit from actual current projection', TeamOperationRecord::findOrFail($job['ticket_id'])->title);
    }

    public function test_multi_project_native_task_retains_read_projection_but_requires_native_review_for_editing(): void
    {
        $first = $this->command(['action' => 'project.create', 'title' => 'First project'])->assertOk()->json('id');
        $second = $this->command(['action' => 'project.create', 'title' => 'Second project'])->assertOk()->json('id');
        $task = app(\App\Services\Team\TeamWorkRepository::class)->execute($this->tenant->id,
            fn () => app(\App\Enterprise\WorkspaceAccess::class)->actor($this->tenant, $this->coordinator, true),
            ['kind' => 'task.create', 'id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'title' => 'Multi-project task', 'memberships' => [$first => [], $second => []]])['result'];
        $data = $this->getJson(route('enterprise.data', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))->assertOk()->json();
        $dto = collect($data['tasks'])->firstWhere('id', $task['id']);
        $this->assertCount(2, $dto['project_ids']);
        $this->assertFalse($dto['can_edit']);
        $this->assertSame('review_required', $dto['edit_link_status']);
        $this->command(['action' => 'task.update', 'id' => $task['id'], 'expected_version' => $task['version'], 'title' => 'Refused management edit'])->assertUnprocessable();
    }

    public function test_hidden_native_membership_cannot_be_edited_through_a_single_visible_project(): void
    {
        $other = new User;
        $other->forceFill(['name' => 'Synthetic private owner', 'email' => 'private-owner@example.invalid', 'password' => 'fixture-only', 'is_active' => true, 'email_verified_at' => now()])->save();
        $this->tenant->users()->attach($other->id, ['role' => 'member', 'membership_active' => true]);
        DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area, 'user_id' => $other->id, 'role' => 'coordinator']);
        $native = function (array $command, ?User $user = null): array {
            $user ??= $this->coordinator;

            return app(\App\Services\Team\TeamWorkRepository::class)->execute($this->tenant->id,
                fn () => app(\App\Enterprise\WorkspaceAccess::class)->actor($this->tenant, $user, true),
                $command + ['idempotency_key' => (string) Str::uuid()])['result'];
        };
        $first = $this->command(['action' => 'project.create', 'title' => 'Visible project'])->assertOk()->json('id');
        $second = $this->command(['action' => 'project.create', 'title' => 'Hidden project'])->assertOk()->json('id');
        $task = $native(['kind' => 'task.create', 'id' => (string) Str::uuid(), 'title' => 'Native shared task', 'assignee_id' => (string) $this->coordinator->id, 'memberships' => [$first => [], $second => []]]);
        $project = $native(['kind' => 'project.member', 'id' => $second, 'expected_version' => 1, 'user_id' => (string) $other->id, 'access' => 'editor']);
        $project = $native(['kind' => 'project.transfer_owner', 'id' => $second, 'expected_version' => $project['version'], 'user_id' => (string) $other->id, 'acknowledge_sharing' => true]);
        $project = $native(['kind' => 'project.update', 'id' => $second, 'expected_version' => $project['version'], 'changes' => ['privacy' => 'private']], $other);
        $native(['kind' => 'project.member', 'id' => $second, 'expected_version' => $project['version'], 'user_id' => (string) $this->coordinator->id, 'access' => null], $other);
        $data = $this->getJson(route('enterprise.data', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))->assertOk()->json();
        $dto = collect($data['tasks'])->firstWhere('id', $task['id']);
        $this->assertSame([$first], $dto['project_ids']);
        $this->assertFalse($dto['can_edit']);
        $this->assertStringNotContainsString($second, json_encode($data, JSON_THROW_ON_ERROR));
        $version = DB::table('team_tasks')->where('id', $task['id'])->value('version');
        $this->command(['action' => 'task.update', 'id' => $task['id'], 'expected_version' => $version, 'title' => 'Refused hidden membership edit'])->assertUnprocessable();
        $this->assertSame($version, DB::table('team_tasks')->where('id', $task['id'])->value('version'));
    }
}
