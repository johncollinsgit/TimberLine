<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\WorkspaceAccess;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Team\TeamWorkRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** Actual independent runtime and durable native ACL records; no role simulation or grant endpoints. */
final class EnterpriseRolePreviewTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set([
            'app.env' => 'testing', 'app.url' => 'https://preview.example.invalid',
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'enterprise_runtime.isolated' => true, 'enterprise_runtime.base_path' => '',
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'session.driver' => 'array', 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4,
            'enterprise.enabled' => true, 'enterprise.writes_enabled' => true, 'enterprise.operations_enabled' => true,
            'team_work.enabled' => true,
        ]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
    }

    private function workspace(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Synthetic role preview', 'slug' => 'preview-'.Str::lower(Str::random(10))]);
        TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
        TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'team_work', 'enabled_status' => 'enabled',
            'availability_status' => 'available', 'entitlement_source' => 'test']);
        config(['enterprise.tenant_allowlist' => [...config('enterprise.tenant_allowlist', []), (string) $tenant->id],
            'team_work.tenant_allowlist' => [...config('team_work.tenant_allowlist', []), (string) $tenant->id]]);

        return [$tenant, $this->member($tenant, 'owner')];
    }

    private function member(Tenant $tenant, string $role): User
    {
        $user = new User;
        $user->forceFill(['name' => 'Synthetic '.$role.' '.Str::lower(Str::random(5)), 'email' => Str::uuid().'@example.invalid',
            'password' => 'fixture-only', 'role' => 'member', 'is_active' => true, 'email_verified_at' => now()])->save();
        $tenant->users()->attach($user->id, ['role' => $role, 'membership_active' => true]);

        return $user;
    }

    private function command(Tenant $tenant, User $user, array $input): array
    {
        return $this->actingAs($user)->postJson(route('enterprise.commands', ['tenant' => $tenant->slug]),
            $input + ['id' => Str::uuid()->toString(), 'request_id' => Str::uuid()->toString(), 'expected_version' => 0])->assertOk()->json();
    }

    private function area(Tenant $tenant, User $owner, string $visibility = 'department_only'): string
    {
        return $this->command($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Synthetic department', 'visibility' => $visibility])['id'];
    }

    private function areaMember(Tenant $tenant, User $member, string $area, string $role): void
    {
        DB::table('team_area_members')->insert(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'area_id' => $area,
            'user_id' => $member->id, 'role' => $role]);
    }

    private function graph(Tenant $tenant, User $user, array $command): array
    {
        return app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $user),
            $command + ['id' => Str::uuid()->toString(), 'idempotency_key' => Str::uuid()->toString()])['result'];
    }

    private function preview(Tenant $tenant, User $observer, string $area, ?User $member = null)
    {
        return $this->actingAs($observer)->getJson(route('enterprise.role_preview', ['tenant' => $tenant->slug,
            'area_id' => $area, ...($member ? ['member_id' => $member->id] : [])]));
    }

    public function test_current_area_roster_includes_verified_view_only_members_without_contacts_or_identity_changes(): void
    {
        [$tenant, $owner] = $this->workspace();
        $area = $this->area($tenant, $owner);
        $viewer = $this->member($tenant, 'viewer');
        $editor = $this->member($tenant, 'member');
        $this->areaMember($tenant, $viewer, $area, 'viewer');
        $this->areaMember($tenant, $editor, $area, 'editor');
        $outsider = $this->member($tenant, 'member');
        $inactive = $this->member($tenant, 'member');
        $inactive->forceFill(['is_active' => false])->save();
        $unverified = $this->member($tenant, 'member');
        $unverified->forceFill(['email_verified_at' => null])->save();
        $unknown = $this->member($tenant, 'invented');
        foreach ([$inactive, $unverified, $unknown] as $member) {
            $this->areaMember($tenant, $member, $area, 'viewer');
        }
        $response = $this->preview($tenant, $owner, $area)->assertOk()->assertHeader('Cache-Control')
            ->assertJsonPath('read_only', true)->assertJsonPath('impersonation', false)->assertJsonPath('preview', null)
            ->assertJsonPath('observer.id', (string) $owner->id)->assertJsonPath('scope.tenant_id', (string) $tenant->id);
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertEqualsCanonicalizing(array_map('strval', [$owner->id, $viewer->id, $editor->id]), array_column($response->json('roster'), 'id'));
        $this->assertSame('viewer', collect($response->json('roster'))->firstWhere('id', (string) $viewer->id)['tenant_role']);
        foreach ([$owner, $viewer, $editor, $outsider, $inactive, $unverified] as $member) {
            $this->assertStringNotContainsString($member->email, $response->getContent());
        }
        $this->assertSame($owner->id, auth()->id());
        $this->assertSame((string) $owner->id, $response->headers->get('X-Team-Viewer'));
        $this->assertSame(0, DB::table('team_projects')->count());
        Http::assertNothingSent();
    }

    public function test_selected_members_change_real_visible_data_and_private_objects_require_both_subject_and_observer_access(): void
    {
        [$tenant, $owner] = $this->workspace();
        $area = $this->area($tenant, $owner);
        $coordinator = $this->member($tenant, 'member');
        $viewer = $this->member($tenant, 'viewer');
        $this->areaMember($tenant, $coordinator, $area, 'coordinator');
        $this->areaMember($tenant, $viewer, $area, 'viewer');
        $common = $this->command($tenant, $owner, ['action' => 'project.create', 'area_id' => $area, 'title' => 'Shared operational project']);
        $ownerPrivate = $this->graph($tenant, $owner, ['kind' => 'project.create', 'name' => 'OWNER PRIVATE SENTINEL', 'privacy' => 'private', 'enterprise_area_id' => $area]);
        $subjectPrivate = $this->graph($tenant, $coordinator, ['kind' => 'project.create', 'name' => 'SUBJECT PRIVATE SENTINEL', 'privacy' => 'private', 'enterprise_area_id' => $area]);
        $public = $this->command($tenant, $owner, ['action' => 'request.create', 'area_id' => $area, 'title' => 'Unassigned visible request',
            'description' => 'NEVER RETURN DESCRIPTION']);
        foreach ([[$owner, $ownerPrivate, 'OWNER LINKED SENTINEL'], [$coordinator, $subjectPrivate, 'SUBJECT LINKED SENTINEL']] as [$actor, $project, $title]) {
            $request = $this->command($tenant, $actor, ['action' => 'request.create', 'area_id' => $area, 'title' => $title]);
            $this->command($tenant, $actor, ['action' => 'request.triage', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => 1]);
            $this->command($tenant, $actor, ['action' => 'request.assign', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => 2,
                'project_id' => $project['id'], 'assignee_id' => $actor->id]);
        }
        $before = $this->databaseSnapshot();
        config(['enterprise.writes_enabled' => false]);
        $visible = $this->preview($tenant, $owner, $area, $viewer)->assertOk()->assertJsonPath('preview.member.tenant_role', 'viewer');
        $this->assertSame([$common['id']], array_column($visible->json('preview.projects'), 'id'));
        $this->assertSame([$public['id']], array_column($visible->json('preview.requests'), 'id'));
        $self = $this->preview($tenant, $owner, $area, $owner)->assertOk();
        $this->assertEqualsCanonicalizing([$common['id'], $ownerPrivate['id']], array_column($self->json('preview.projects'), 'id'));
        $this->assertSame(2, $self->json('preview.counts.requests'));
        $limited = $this->preview($tenant, $owner, $area, $coordinator)->assertOk();
        $this->assertSame([$common['id']], array_column($limited->json('preview.projects'), 'id'), 'Organization ownership does not reveal another member private graph.');
        $this->assertSame([$public['id']], array_column($limited->json('preview.requests'), 'id'));
        $this->assertStringNotContainsString('SUBJECT PRIVATE SENTINEL', $limited->getContent());
        $this->assertStringNotContainsString('SUBJECT LINKED SENTINEL', $limited->getContent());
        $this->assertStringNotContainsString('NEVER RETURN DESCRIPTION', $limited->getContent());
        $this->assertSame($before, $this->databaseSnapshot());
        $this->assertSame($owner->id, auth()->id());
        Http::assertNothingSent();
    }

    public function test_only_current_tenant_owner_or_admin_can_observe_and_forged_global_roles_do_not_grant_preview(): void
    {
        [$tenant, $owner] = $this->workspace();
        $area = $this->area($tenant, $owner, 'shared');
        foreach (['member', 'manager', 'viewer', 'guest'] as $role) {
            $user = $this->member($tenant, $role);
            $user->forceFill(['role' => 'admin'])->save();
            $this->preview($tenant, $user, $area, $owner)->assertForbidden();
        }
        $admin = $this->member($tenant, 'admin');
        $this->preview($tenant, $admin, $area, $owner)->assertOk()->assertJsonPath('observer.tenant_role', 'admin');
        $tenant->users()->updateExistingPivot($admin->id, ['role' => 'member']);
        $this->preview($tenant, $admin, $area, $owner)->assertForbidden();
        $this->actingAs($owner)->postJson(route('enterprise.role_preview', ['tenant' => $tenant->slug]), ['area_id' => $area])->assertStatus(405);
        $this->assertTrue(Route::has('enterprise.role_preview'));
        $owner->forceFill(['is_active' => false])->save();
        $this->preview($tenant, $owner, $area)->assertForbidden();
    }

    public function test_target_membership_verification_department_and_tenant_revocations_are_rechecked_on_each_request(): void
    {
        [$tenant, $owner] = $this->workspace();
        $area = $this->area($tenant, $owner);
        $member = $this->member($tenant, 'member');
        $this->areaMember($tenant, $member, $area, 'viewer');
        $this->preview($tenant, $owner, $area, $member)->assertOk();
        DB::table('team_area_members')->where('user_id', $member->id)->delete();
        $this->preview($tenant, $owner, $area, $member)->assertNotFound();
        $this->areaMember($tenant, $member, $area, 'viewer');
        $tenant->users()->updateExistingPivot($member->id, ['membership_active' => false]);
        $this->preview($tenant, $owner, $area, $member)->assertNotFound();
        $tenant->users()->updateExistingPivot($member->id, ['membership_active' => true]);
        foreach (['is_active' => false, 'email_verified_at' => null] as $field => $value) {
            $original = $member->$field;
            $member->forceFill([$field => $value])->save();
            $this->preview($tenant, $owner, $area, $member)->assertNotFound();
            $member->forceFill([$field => $original])->save();
        }
        [$foreign, $foreignOwner] = $this->workspace();
        $foreignArea = $this->area($foreign, $foreignOwner, 'shared');
        $this->preview($tenant, $owner, $area, $foreignOwner)->assertNotFound();
        $this->preview($tenant, $owner, $foreignArea, $member)->assertNotFound();
        $this->preview($foreign, $owner, $foreignArea, $foreignOwner)->assertForbidden();
        config(['enterprise.operations_enabled' => false]);
        $this->preview($tenant, $owner, $area)->assertNotFound();
    }

    public function test_deleted_or_revoked_private_task_links_and_other_record_kinds_are_never_projected(): void
    {
        [$tenant, $owner] = $this->workspace();
        $area = $this->area($tenant, $owner);
        $project = $this->command($tenant, $owner, ['action' => 'project.create', 'area_id' => $area, 'title' => 'Current work']);
        $request = $this->command($tenant, $owner, ['action' => 'request.create', 'area_id' => $area, 'title' => 'Linked work']);
        $this->command($tenant, $owner, ['action' => 'request.triage', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => 1]);
        $task = $this->command($tenant, $owner, ['action' => 'request.assign', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => 2,
            'project_id' => $project['id'], 'assignee_id' => $owner->id])['data']['task_id'];
        TeamOperationRecord::query()->create(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'area_id' => $area,
            'kind' => 'ent_finance_packet', 'source_key' => hash('sha256', 'finance-sentinel'), 'title' => 'FINANCE NEVER PROJECTED',
            'status' => 'draft', 'version' => 1, 'data' => ['account' => 'CONFIDENTIAL FINANCE CONTENT']]);
        $this->preview($tenant, $owner, $area, $owner)->assertOk()->assertJsonPath('preview.counts.requests', 1);
        DB::table('team_tasks')->where('id', $task)->update(['deleted' => true]);
        $result = $this->preview($tenant, $owner, $area, $owner)->assertOk()->assertJsonPath('preview.counts.requests', 0);
        $this->assertStringNotContainsString('FINANCE NEVER PROJECTED', $result->getContent());
        $this->assertStringNotContainsString('CONFIDENTIAL FINANCE CONTENT', $result->getContent());
        Http::assertNothingSent();
    }

    public function test_bounded_counts_are_returned_intersections_and_projection_never_reads_content_tables_or_writes(): void
    {
        [$tenant, $owner] = $this->workspace();
        $area = $this->area($tenant, $owner, 'shared');
        $viewer = $this->member($tenant, 'viewer');
        $rows = [];
        for ($index = 0; $index < 101; $index++) {
            $rows[] = ['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id, 'owner_user_id' => $owner->id,
                'name' => 'Visible project '.$index, 'privacy' => 'workspace', 'archived' => false, 'version' => 1,
                'payload' => json_encode(['enterprise_area_id' => $area, 'description' => 'NEVER FETCH CONTENT', 'comments' => ['NEVER FETCH COMMENTS']])];
        }
        DB::table('team_projects')->insert($rows);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $response = $this->preview($tenant, $owner, $area, $viewer)->assertOk()->assertJsonCount(100, 'preview.projects')
            ->assertJsonPath('preview.counts.projects', 100)->assertJsonPath('preview.coverage.projects_partial', true)
            ->assertJsonPath('preview.coverage.counts', 'returned_authorized_intersection_only');
        $this->assertStringNotContainsString('NEVER FETCH CONTENT', $response->getContent());
        $this->assertStringNotContainsString('NEVER FETCH COMMENTS', $response->getContent());
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:insert|update|delete|replace)\b/i', $sql);
            $this->assertDoesNotMatchRegularExpression('/\b(?:team_work_events|team_work_receipts|team_file_uploads|team_file_chunks|tenant_mailboxes|tenant_mail_domains)\b/i', $sql);
        }
        $this->assertSame($owner->id, auth()->id());
        Http::assertNothingSent();
    }

    private function databaseSnapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'tenant_user', 'tenant_module_entitlements', 'team_area_members', 'team_projects', 'team_tasks', 'team_project_members',
            'team_operation_records', 'team_operation_attempts', 'team_operation_revisions', 'team_work_receipts', 'team_inbox_items'] as $table) {
            $snapshot[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}
