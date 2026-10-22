<?php

namespace Tests\Feature\Enterprise;

use App\Foundation\EnterpriseSchema;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** A clean independent database, with none of the retail migration fixtures loaded. */
final class EnterpriseSchemaTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set([
            'app.env' => 'testing',
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'enterprise_runtime.isolated' => true,
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'session.driver' => 'array',
            'cache.default' => 'array',
            'cache.stores.array' => ['driver' => 'array', 'serialize' => false],
            'hashing.bcrypt.rounds' => 4,
        ]);
        DB::prohibitDestructiveCommands(false);

        return $app;
    }

    private function install(): void
    {
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
    }

    public function test_default_migrate_installs_only_reviewed_enterprise_schema_without_records_or_grants(): void
    {
        $this->install();
        foreach (['users', 'tenants', 'tenant_user', 'tenant_access_profiles', 'tenant_access_addons', 'tenant_module_states', 'tenant_module_entitlements', 'team_areas', 'team_area_members', 'team_operation_records', 'team_operation_revisions', 'team_operation_attempts', 'team_projects', 'team_tasks', 'team_work_versions', 'team_work_events', 'team_work_receipts', 'team_work_locks', 'team_inbox_items', 'team_file_uploads', 'team_file_chunks'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertSame(0, DB::table($table)->count(), $table.' must start empty');
        }
        foreach (['orders', 'customers', 'shopify_stores', 'tenant_payment_accounts', 'website_orders', 'marketing_profiles', 'field_service_jobs', 'password_reset_tokens', 'sessions'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table.' must not be installed');
        }
        foreach (['tenant_mail_domains', 'tenant_mailboxes', 'tenant_mailbox_users'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame(0, DB::table($table)->count(), $table.' must start empty');
        }
        $this->assertCount(4, DB::table('migrations')->get());
        $this->install();
        $this->assertCount(4, DB::table('migrations')->get());
        $this->assertCount(6, EnterpriseSchema::sharedVersionOne());
    }

    public function test_schema_replay_does_not_backfill_memberships_profiles_or_entitlements(): void
    {
        $this->install();
        $tenant = Tenant::query()->create(['name' => 'Synthetic isolation', 'slug' => 'synthetic-isolation']);
        $user = User::query()->create(['name' => 'Synthetic member', 'email' => 'schema-fixture@example.invalid', 'password' => 'fixture-only']);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame('member', $user->fresh()->role);
        foreach (glob($this->app->databasePath('migrations/*.php')) as $path) {
            (require $path)->up();
        }
        $this->assertSame(0, DB::table('tenant_user')->count());
        $this->assertSame(0, DB::table('tenant_access_profiles')->count());
        $this->assertSame(0, DB::table('tenant_module_entitlements')->count());
        $tenant->users()->attach($user->id, ['role' => 'member']);
        $this->assertFalse((bool) DB::table('tenant_user')->value('membership_active'));
    }

    public function test_actual_enterprise_boot_and_minimal_schema_support_the_persistent_request_lifecycle(): void
    {
        $this->install();
        $tenant = Tenant::query()->create(['name' => 'Synthetic enterprise', 'slug' => 'synthetic-enterprise']);
        $owner = new User;
        $owner->forceFill(['name' => 'Synthetic owner', 'email' => 'enterprise-owner@example.invalid', 'password' => 'fixture-only', 'role' => 'member', 'is_active' => true, 'email_verified_at' => now()])->save();
        $tenant->users()->attach($owner->id, ['role' => 'owner', 'membership_active' => true]);
        TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
        TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'team_work', 'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
        config(['enterprise.enabled' => true, 'enterprise.writes_enabled' => true, 'enterprise.tenant_allowlist' => [(string) $tenant->id], 'team_work.enabled' => true, 'team_work.tenant_allowlist' => [(string) $tenant->id]]);
        $this->assertTrue(app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'team_work'));
        $this->actingAs($owner);
        $command = function (array $input) use ($tenant) {
            return $this->postJson(route('enterprise.commands', ['tenant' => $tenant->slug]), $input + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()])->assertOk();
        };
        $area = $command(['action' => 'workspace.create', 'title' => 'Synthetic facilities', 'visibility' => 'department_only'])->json('id');
        $project = $command(['action' => 'project.create', 'area_id' => $area, 'title' => 'Synthetic maintenance'])->json('id');
        $request = $command(['action' => 'request.create', 'area_id' => $area, 'title' => 'Synthetic inspection', 'description' => 'Disposable fixture', 'expected_version' => 0])->json('id');
        $command(['action' => 'request.triage', 'area_id' => $area, 'id' => $request, 'expected_version' => 1])->assertJsonPath('status', 'triaged');
        $task = $command(['action' => 'request.assign', 'area_id' => $area, 'id' => $request, 'expected_version' => 2, 'project_id' => $project, 'assignee_id' => $owner->id])->json('data.task_id');
        $command(['action' => 'request.progress', 'area_id' => $area, 'id' => $request, 'expected_version' => 3, 'note' => 'Synthetic progress'])->assertJsonPath('status', 'in_progress');
        $command(['action' => 'request.complete', 'area_id' => $area, 'id' => $request, 'expected_version' => 4, 'note' => 'Synthetic completion'])->assertJsonPath('status', 'completed');
        $this->assertNotNull(DB::table('team_tasks')->where('id', $task)->value('completed_at'));
        $this->getJson(route('enterprise.history', ['tenant' => $tenant->slug, 'id' => $request]))->assertOk()->assertJsonCount(5, 'items');
        $this->assertSame(1, DB::table('tenant_user')->count());
        $this->assertSame(0, DB::table('team_area_members')->count());
    }

    public function test_schema_commands_refuse_unconfigured_runtime_before_database_writes(): void
    {
        config(['enterprise_runtime.isolated' => false]);
        $kernel = $this->app->make(Kernel::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Enterprise schema commands require isolated runtime configuration.');
        try {
            $kernel->call('migrate', ['--force' => true]);
        } finally {
            $this->assertFalse(Schema::hasTable('migrations'));
        }
    }

    public function test_separate_login_refuses_mfa_unverified_and_inactive_accounts_without_creating_grants(): void
    {
        $this->install();
        foreach (['mfa', 'unverified', 'inactive'] as $state) {
            $user = new User;
            $user->forceFill([
                'name' => 'Synthetic login '.$state,
                'email' => $state.'@example.invalid',
                'password' => 'fixture-only',
                'role' => 'member',
                'is_active' => $state !== 'inactive',
                'email_verified_at' => $state === 'unverified' ? null : now(),
                'two_factor_secret' => $state === 'mfa' ? 'synthetic-enrolled-factor' : null,
            ])->save();
            $this->postJson('/login', ['email' => $user->email, 'password' => 'fixture-only'])->assertUnprocessable()->assertJsonValidationErrors('email');
            $this->assertGuest();
        }
        $this->assertSame(0, DB::table('tenant_user')->count());
        $this->assertSame(0, DB::table('tenant_module_entitlements')->count());
    }
}
