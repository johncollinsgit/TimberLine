<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('resumes connected Team operation tables without replacing encrypted business records', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicit isolated disposable MySQL.');
    }
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'everbranch_ci' || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'])) {
        throw new RuntimeException('Refusing recovery outside isolated MySQL.');
    }
    $migration = require database_path('migrations/2026_10_20_010000_create_team_operations_tables.php');
    $migration->up();
    $id = (string) \Illuminate\Support\Str::uuid();
    $payload = \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['notes' => 'Fictional durable evidence']));
    DB::table('team_operation_records')->insert(['id' => $id, 'tenant_id' => 991, 'area_id' => (string) \Illuminate\Support\Str::uuid(), 'kind' => 'event', 'title' => 'Fictional recovery', 'source_key' => hash('sha256', $id), 'data' => $payload]);
    Schema::drop('team_operation_attempts');
    Schema::drop('team_operation_revisions');
    Schema::table('team_operation_records', fn (\Illuminate\Database\Schema\Blueprint $t) => $t->dropUnique('tor_source'));
    $migration->up();
    $migration->up();
    expect(DB::table('team_operation_records')->where('id', $id)->value('data'))->toBe($payload)->and(Schema::hasIndex('team_operation_records', 'tor_source'))->toBeTrue()->and(Schema::hasIndex('team_operation_attempts', 'toa_request'))->toBeTrue()->and(Schema::hasIndex('team_operation_revisions', 'tov_version'))->toBeTrue();
});

it('resumes per-user Team connections without replacing retained credentials or selections', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicit isolated disposable MySQL opt-in.');
    }
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'everbranch_ci'
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing recovery test outside isolated local/CI MySQL.');
    }
    $migration = require database_path('migrations/2026_10_19_010000_create_team_user_connections.php');
    $migration->up();
    $id = (string) \Illuminate\Support\Str::uuid();
    $credentials = \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['access_token' => 'fictional-recovery-token']));
    DB::table('team_user_connections')->insert(['id' => $id, 'tenant_id' => 991, 'user_id' => 992, 'provider' => 'asana', 'credentials' => $credentials, 'project_ids' => json_encode(['101' => '900'])]);
    Schema::table('team_user_connections', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->dropUnique('team_user_provider_unique'));
    $migration->up();
    $migration->up();
    expect(DB::table('team_user_connections')->where('id', $id)->value('credentials'))->toBe($credentials)
        ->and(Schema::hasIndex('team_user_connections', 'team_user_provider_unique', 'unique'))->toBeTrue();
});

it('resumes Team schema after MySQL retains early tables and rows', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires a disposable MySQL database; never configure an existing database.');
    }
    if (getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicit disposable MySQL opt-in.');
    }
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'everbranch_ci'
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing recovery test outside the isolated local/CI MySQL fixture.');
    }
    $migration = require database_path('migrations/2026_10_17_010000_create_team_work_tables.php');
    $inbox = require database_path('migrations/2026_10_17_020000_create_team_inbox_table.php');
    $versions = require database_path('migrations/2026_10_17_030000_create_team_work_versions_table.php');
    $files = require database_path('migrations/2026_10_17_040000_create_team_file_uploads.php');
    $migration->up();
    $inbox->up();
    $versions->up();
    $files->up();
    DB::table('team_work_locks')->insertOrIgnore(['tenant_id' => 991]);
    foreach (['team_work_receipts', 'team_work_events', 'team_task_projects'] as $table) {
        Schema::drop($table);
    }
    Schema::table('team_tasks', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->dropIndex('team_task_assignment_idx'));
    DB::statement('ALTER TABLE team_project_members DROP PRIMARY KEY');
    Schema::table('team_inbox_items', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->dropUnique('team_inbox_event_unique'));
    DB::statement('ALTER TABLE team_work_versions DROP PRIMARY KEY');
    Schema::drop('team_file_chunks');
    Schema::table('team_file_uploads', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->dropUnique('team_file_client_unique'));
    $migration->up();
    $migration->up();
    $inbox->up();
    $inbox->up();
    $versions->up();
    $versions->up();
    $files->up();
    $files->up();
    expect(DB::table('team_work_locks')->where('tenant_id', 991)->exists())->toBeTrue()
        ->and(Schema::hasIndex('team_task_projects', 'team_project_order_idx'))->toBeTrue()
        ->and(Schema::hasTable('team_work_receipts'))->toBeTrue()
        ->and(Schema::hasIndex('team_tasks', 'team_task_assignment_idx'))->toBeTrue()
        ->and(Schema::hasIndex('team_project_members', ['tenant_id', 'project_id', 'user_id'], 'primary'))->toBeTrue()
        ->and(Schema::hasIndex('team_inbox_items', 'team_inbox_event_unique', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('team_work_versions', ['tenant_id', 'entity_type', 'entity_id', 'version'], 'primary'))->toBeTrue()
        ->and(Schema::hasIndex('team_file_uploads', 'team_file_client_unique', 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('team_file_chunks', ['tenant_id', 'upload_id', 'chunk_index'], 'primary'))->toBeTrue();
});
