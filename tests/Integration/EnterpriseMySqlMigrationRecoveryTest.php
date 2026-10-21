<?php

namespace Tests\Integration;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Explicit disposable service only; never accepts the retail/production database. */
final class EnterpriseMySqlMigrationRecoveryTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_durable_mysql_partial_schema_recovers_without_grants_or_lost_work(): void
    {
        if (getenv('ENTERPRISE_DISPOSABLE_MYSQL') !== '1') {
            $this->markTestSkipped('Requires an explicitly disposable Enterprise MySQL service.');
        }
        if (! app()->environment('testing') || config('database.connections.enterprise.driver') !== 'mysql'
            || config('database.connections.enterprise.database') !== 'everbranch_enterprise_ci'
            || ! in_array(config('database.connections.enterprise.host'), ['127.0.0.1', 'localhost'], true)) {
            throw new \RuntimeException('Refusing recovery outside the isolated Enterprise MySQL fixture.');
        }
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
        DB::table('tenants')->insert(['id' => 9901, 'name' => 'Synthetic durable tenant', 'slug' => 'synthetic-durable']);
        DB::table('users')->insert(['id' => 9901, 'name' => 'Synthetic durable member', 'email' => 'mysql-recovery@example.invalid', 'password' => 'synthetic-fixture-only']);
        DB::table('team_work_locks')->insert(['tenant_id' => 9901]);

        // MySQL retains these tables/data while later DDL or bookkeeping is absent.
        Schema::drop('team_operation_attempts');
        Schema::drop('team_operation_revisions');
        Schema::drop('team_file_chunks');
        Schema::drop('tenant_module_states');
        Schema::table('users', fn (Blueprint $table) => $table->dropUnique('ee_users_email'));
        Schema::table('tenant_user', fn (Blueprint $table) => $table->dropForeign('ee_tu_user_fk'));
        Schema::table('tenant_user', fn (Blueprint $table) => $table->dropForeign('ee_tu_tenant_fk'));
        Schema::table('tenant_user', fn (Blueprint $table) => $table->dropUnique('ee_tu_membership'));
        Schema::table('team_projects', fn (Blueprint $table) => $table->dropIndex('team_project_tenant_archive_idx'));
        Schema::table('team_operation_records', fn (Blueprint $table) => $table->dropUnique('tor_source'));
        $identity = require database_path('migrations/2026_10_21_000100_create_enterprise_identity.php');
        $access = require database_path('migrations/2026_10_21_000200_create_enterprise_access_profiles.php');
        $shared = require database_path('migrations/2026_10_21_000300_create_enterprise_shared_work_schema.php');
        foreach ([$identity, $access, $shared] as $migration) {
            $migration->up();
            $migration->up();
            $migration->down(); // Code rollback must retain all evidence.
        }
        $this->assertSame('Synthetic durable tenant', DB::table('tenants')->where('id', 9901)->value('name'));
        $this->assertSame('mysql-recovery@example.invalid', DB::table('users')->where('id', 9901)->value('email'));
        $this->assertTrue(DB::table('team_work_locks')->where('tenant_id', 9901)->exists());
        foreach (['tenant_user', 'tenant_access_profiles', 'tenant_module_entitlements'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertTrue(Schema::hasIndex('users', 'ee_users_email', 'unique'));
        $this->assertTrue(Schema::hasIndex('tenant_user', 'ee_tu_membership', 'unique'));
        $this->assertTrue(Schema::hasIndex('team_projects', 'team_project_tenant_archive_idx'));
        $this->assertTrue(Schema::hasIndex('team_operation_records', 'tor_source', 'unique'));
        foreach (['tenant_module_states', 'team_file_chunks', 'team_operation_attempts', 'team_operation_revisions'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $foreign = collect(Schema::getForeignKeys('tenant_user'))->firstWhere('name', 'ee_tu_user_fk');
        $this->assertSame(['user_id'], $foreign['columns']);
        $this->assertSame('users', $foreign['foreign_table']);
    }
}
