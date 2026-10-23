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
use PHPUnit\Framework\Attributes\DataProvider;

/** Real independent schema and request/checklist/approval routes; no retail database fixtures. */
final class EnterpriseRequestCompletionTest extends TestCase
{
    private string $runtime;

    private string|false $previousRoot;

    private Tenant $tenant;

    private User $coordinator;

    private string $area;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/enterprise-completion-test-'.Str::uuid();
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
            'app.env' => 'testing', 'app.url' => 'https://completion.example.invalid',
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'enterprise_runtime.isolated' => true, 'enterprise_runtime.base_path' => '',
            'enterprise_client.client_id' => '',
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'session.driver' => 'array', 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4,
            'enterprise.enabled' => true, 'enterprise.writes_enabled' => true,
            'enterprise.operations_enabled' => true, 'team_work.enabled' => true,
        ]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
        $this->tenant = Tenant::query()->create(['name' => 'Synthetic completion workspace', 'slug' => 'synthetic-completion']);
        $this->coordinator = new User;
        $this->coordinator->forceFill(['name' => 'Synthetic coordinator', 'email' => 'completion@example.invalid',
            'password' => 'fixture-only', 'role' => 'member', 'is_active' => true, 'email_verified_at' => now()])->save();
        $this->tenant->users()->attach($this->coordinator->id, ['role' => 'member', 'membership_active' => true]);
        TenantAccessProfile::query()->create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
        TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'team_work',
            'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
        $this->area = (string) Str::uuid();
        TeamArea::query()->create(['id' => $this->area, 'tenant_id' => $this->tenant->id, 'slug' => 'completion-operations',
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
            if (str_starts_with($this->runtime, sys_get_temp_dir().'/enterprise-completion-test-')) {
                (new Filesystem)->deleteDirectory($this->runtime);
            }
        }
    }

    private function command(array $input)
    {
        return $this->postJson(route('enterprise.commands', ['tenant' => $this->tenant->slug]), $input + [
            'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $this->area, 'expected_version' => 0]);
    }

    private function savedProgress(): array
    {
        $project = $this->command(['action' => 'project.create', 'title' => 'Synthetic maintenance'])->assertOk()->json('id');
        $request = $this->command(['action' => 'request.create', 'title' => 'Synthetic inspection', 'description' => 'Disposable fixture'])->assertOk()->json();
        $this->command(['action' => 'request.triage', 'id' => $request['id'], 'expected_version' => 1,
            'note' => 'Earlier triage note'])->assertOk();
        $this->command(['action' => 'request.assign', 'id' => $request['id'], 'expected_version' => 2,
            'project_id' => $project, 'assignee_id' => $this->coordinator->id])->assertOk();

        return $this->command(['action' => 'request.progress', 'id' => $request['id'], 'expected_version' => 3,
            'note' => 'Saved progress before completion'])->assertOk()->assertJsonPath('version', 4)->json();
    }

    private function operation(string $action, array $task, array $extra = []): array
    {
        return $this->postJson(route('enterprise.operations.command', ['tenant' => $this->tenant->slug]), $extra + [
            'action' => $action, 'task_id' => $task['id'], 'request_id' => (string) Str::uuid(),
            'expected_version' => $task['version'], 'expected_task_version' => $task['task_version']])->assertOk()->json();
    }

    private function approve(array $request): void
    {
        $task = $this->operation('task.configure', ['id' => $request['task']['id'], 'version' => 0, 'task_version' => $request['task']['version']], [
            'owner_user_id' => (string) $this->coordinator->id, 'ball_user_id' => (string) $this->coordinator->id, 'ball_team' => false,
            'next_action' => 'Verify the recorded inspection', 'checklist' => [['id' => 'inspect', 'label' => 'Inspection performed', 'weight' => 1]]]);
        $task = $this->operation('task.check', $task, ['item_id' => 'inspect', 'completed' => true]);
        $this->assertSame(3.9, $task['completion_score']);
        $task = $this->operation('task.approve', $task, ['note' => 'Coordinator verified the completed checklist']);
        $this->assertEquals(4.0, $task['completion_score']);
        $this->assertNotNull($task['approval']);
    }

    private function reload(string $request): array
    {
        $response = $this->getJson(route('enterprise.data', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))->assertOk();

        return collect($response->json('requests'))->firstWhere('id', $request);
    }

    public static function completionNotes(): array
    {
        return [
            'empty note' => [['note' => ''], 'Saved progress before completion'],
            'missing note' => [[], 'Saved progress before completion'],
            'null note' => [['note' => null], 'Saved progress before completion'],
            'whitespace note' => [['note' => '   '], 'Saved progress before completion'],
            'explicit replacement' => [['note' => 'Explicit completion note'], 'Explicit completion note'],
            'nonempty zero string' => [['note' => '0'], '0'],
        ];
    }

    #[DataProvider('completionNotes')]
    public function test_approved_completion_preserves_progress_unless_a_nonempty_note_replaces_it(array $note, string $expected): void
    {
        $request = $this->savedProgress();
        $this->assertSame('Saved progress before completion', $this->reload($request['id'])['data']['progress_note']);
        $this->approve($request);
        $nativeEvents = DB::table('team_work_events')->count();
        $input = ['action' => 'request.complete', 'id' => $request['id'], 'expected_version' => 4,
            'request_id' => (string) Str::uuid(), ...$note];
        $this->command($input)->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('version', 5)
            ->assertJsonPath('data.progress_note', $expected);
        $reloaded = $this->reload($request['id']);
        $this->assertSame('completed', $reloaded['status']);
        $this->assertSame($expected, $reloaded['data']['progress_note']);
        $this->assertSame($expected, TeamOperationRecord::query()->findOrFail($request['id'])->data['progress_note']);
        $this->command($input)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('version', 5)
            ->assertJsonPath('data.progress_note', $expected);
        $history = $this->getJson(route('enterprise.history', ['tenant' => $this->tenant->slug, 'id' => $request['id']]))->assertOk()
            ->assertJsonCount(5, 'items')->assertJsonPath('items.0.version', 5)
            ->assertJsonPath('items.0.event.before.data.progress_note', 'Saved progress before completion')
            ->assertJsonPath('items.0.event.after.data.progress_note', $expected)
            ->assertJsonPath('items.1.event.after.data.progress_note', 'Saved progress before completion');
        $history->assertJsonMissingPath('items.0.event.after.task');
        $this->assertSame(5, DB::table('team_operation_revisions')->where('record_id', $request['id'])->count());
        $this->assertSame(5, DB::table('team_operation_attempts')->where('record_id', $request['id'])->count());
        $this->assertSame($nativeEvents, DB::table('team_work_events')->count());
        $this->assertSame(1, DB::table('team_tasks')->count());
        $this->assertSame('member', DB::table('users')->value('role'));
        $this->assertSame('member', DB::table('tenant_user')->value('role'));
        $this->assertSame('coordinator', DB::table('team_area_members')->value('role'));
    }

    public function test_saving_empty_progress_still_intentionally_clears_the_current_note(): void
    {
        $request = $this->savedProgress();
        $this->command(['action' => 'request.progress', 'id' => $request['id'], 'expected_version' => 4, 'note' => ''])
            ->assertOk()->assertJsonPath('version', 5)->assertJsonPath('data.progress_note', '');
        $this->assertSame('', $this->reload($request['id'])['data']['progress_note']);
    }

    public function test_note_preservation_does_not_bypass_approval_version_input_or_revoked_membership_checks(): void
    {
        $request = $this->savedProgress();
        $input = ['action' => 'request.complete', 'id' => $request['id'], 'expected_version' => 4,
            'note' => '', 'request_id' => (string) Str::uuid()];
        $this->command($input)->assertConflict();
        $this->assertSame(4, TeamOperationRecord::query()->findOrFail($request['id'])->version);
        $this->assertSame('Saved progress before completion', $this->reload($request['id'])['data']['progress_note']);
        $this->approve($request);
        $this->command([...$input, 'expected_version' => 3])->assertConflict();
        $this->command($input)->assertOk();
        $this->command([...$input, 'note' => 'Changed replay input'])->assertConflict();
        $this->tenant->users()->updateExistingPivot($this->coordinator->id, ['membership_active' => false]);
        $this->command($input)->assertForbidden();
        $row = TeamOperationRecord::query()->findOrFail($request['id']);
        $this->assertSame(5, $row->version);
        $this->assertSame('Saved progress before completion', $row->data['progress_note']);
        $this->assertSame(5, DB::table('team_operation_revisions')->where('record_id', $request['id'])->count());
    }
}
