<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('preserves typed event and legacy task uploads across durable partial DDL retry and rollback', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Explicit disposable Teams MySQL only.');
    }
    if (! app()->environment('testing') || $db->getDatabaseName() !== 'everbranch_ci' || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing recovery outside isolated disposable MySQL.');
    }
    $base = require database_path('migrations/2026_10_17_040000_create_team_file_uploads.php');
    $base->up();
    $migration = require database_path('migrations/2026_11_08_190000_add_team_file_event_targets.php');
    $migration->up();
    $taskFile = (string) Str::uuid();
    $eventFile = (string) Str::uuid();
    $eventId = (string) Str::uuid();
    $fixture = fn ($id) => ['id' => $id, 'tenant_id' => 876123, 'task_id' => (string) Str::uuid(), 'uploader_user_id' => 876124, 'client_key' => (string) Str::uuid(), 'filename' => 'Fictional.txt', 'mime_type' => 'text/plain', 'expected_size' => 18, 'sha256' => hash('sha256', 'Fictional evidence'), 'status' => 'quarantined', 'created_at' => now(), 'updated_at' => now()];
    // Simulate death after target_type DDL committed, before event_id and index.
    Schema::table('team_file_uploads', fn (Blueprint $table) => $table->dropIndex('team_file_event_idx'));
    Schema::table('team_file_uploads', fn (Blueprint $table) => $table->dropColumn('event_id'));
    DB::table('team_file_uploads')->insert($fixture($taskFile));
    $migration->up();
    $migration->up();
    expect(DB::table('team_file_uploads')->where('id', $taskFile)->first())->target_type->toBe('task')->event_id->toBeNull();
    DB::table('team_file_uploads')->insert(array_replace($fixture($eventFile), ['target_type' => 'event', 'task_id' => 'event-updates', 'event_id' => $eventId]));
    Schema::table('team_file_uploads', fn (Blueprint $table) => $table->dropIndex('team_file_event_idx'));
    $migration->up();
    $migration->down();
    $migration->up();
    expect(DB::table('team_file_uploads')->where('id', $eventFile)->first())->target_type->toBe('event')->event_id->toBe($eventId)->task_id->toBe('event-updates');
    expect(Schema::hasIndex('team_file_uploads', 'team_file_event_idx'))->toBeTrue();
    DB::table('team_file_uploads')->whereIn('id', [$taskFile, $eventFile])->delete();
});
