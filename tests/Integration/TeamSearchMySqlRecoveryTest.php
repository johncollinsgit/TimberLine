<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('recovers interrupted search DDL without replacing encrypted documents or rebuild checkpoints', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly isolated disposable MySQL.');
    }
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'everbranch_ci'
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing recovery outside isolated MySQL.');
    }
    $migration = require database_path('migrations/2026_10_29_150000_create_team_search_index.php');
    $migration->up();
    $ciphertext = Crypt::encryptString('Fictional retained search document');
    $snapshotId = (string) \Illuminate\Support\Str::uuid();
    DB::table('team_source_snapshots')->insert(['id' => $snapshotId, 'tenant_id' => 991, 'connection_id' => (string) \Illuminate\Support\Str::uuid(), 'resource_key' => str_repeat('c', 64), 'checksum' => str_repeat('d', 64), 'payload' => $ciphertext, 'observed_at' => now(), 'search_generation' => 7]);
    Schema::table('team_source_snapshots', fn ($t) => $t->dropColumn('search_observed_at'));
    $id = DB::table('team_search_documents')->insertGetId(['tenant_id' => 991, 'kind' => 'task', 'entity_id' => 'fictional-search-recovery', 'schema_version' => 1, 'fingerprint' => str_repeat('a', 64), 'payload' => $ciphertext, 'indexed_at' => now()]);
    DB::table('team_search_runs')->updateOrInsert(['tenant_id' => 991], ['schema_version' => 1, 'source' => 'task', 'after_id' => 'fictional-checkpoint']);
    Schema::drop('team_search_terms');
    Schema::table('team_search_documents', fn ($t) => $t->dropUnique('tsd_identity'));
    $migration->up();
    DB::table('team_search_terms')->insert(['tenant_id' => 991, 'document_id' => $id, 'token' => str_repeat('b', 64)]);
    Schema::table('team_search_terms', function ($t) {
        $t->dropUnique('tst_lookup');
        $t->dropIndex('tst_document');
    });
    $migration->up();
    $migration->up();
    expect(DB::table('team_search_documents')->where('id', $id)->value('payload'))->toBe($ciphertext)
        ->and(DB::table('team_search_runs')->where('tenant_id', 991)->value('after_id'))->toBe('fictional-checkpoint')
        ->and(DB::table('team_search_terms')->where('document_id', $id)->value('token'))->toBe(str_repeat('b', 64))
        ->and(Schema::hasIndex('team_search_documents', 'tsd_identity'))->toBeTrue()
        ->and(Schema::hasIndex('team_search_terms', 'tst_lookup'))->toBeTrue()
        ->and(Schema::hasIndex('team_search_terms', 'tst_document'))->toBeTrue()
        ->and(DB::table('team_source_snapshots')->where('id', $snapshotId)->value('payload'))->toBe($ciphertext)
        ->and(DB::table('team_source_snapshots')->where('id', $snapshotId)->value('search_generation'))->toBe(7)
        ->and(Schema::hasColumn('team_source_snapshots', 'search_observed_at'))->toBeTrue();
});
