<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('retains reporting rows across interrupted index creation retry and rollback', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Explicit disposable Teams MySQL only.');
    }
    if (! app()->environment('testing') || ! in_array($db->getDatabaseName(), ['everbranch_ci', 'codex_events_test'], true) || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing recovery outside isolated disposable MySQL.');
    }
    $migration = require database_path('migrations/2026_11_15_160000_create_team_event_performance_facts.php');
    $migration->up();
    Schema::table('team_event_performance_facts', function (Blueprint $t): void {
        $t->dropUnique('tepf_source');
        $t->dropIndex('tepf_date');
        $t->dropIndex('tepf_group');
        $t->dropIndex('tepf_customer');
        $t->dropIndex('tepf_event');
    });
    $cipher = Crypt::encryptString('Fictional reporting label');
    $row = ['tenant_id' => 891234, 'area_id' => Str::uuid(), 'generation' => Str::uuid(), 'kind' => 'event',
        'source_key' => str_repeat('a', 64), 'group_key' => str_repeat('b', 64), 'event_id' => Str::uuid(),
        'local_date' => '2025-11-07', 'ends_on' => '2025-11-09', 'net_sales_minor' => 12345, 'known_net_sales_minor' => 12345,
        'sale_count' => 3, 'selling_days' => 3, 'complete' => true, 'search_tokens' => '[]', 'payload' => $cipher];
    $id = DB::table('team_event_performance_facts')->insertGetId($row);
    $migration->up();
    $migration->up();
    $migration->down();
    expect(DB::table('team_event_performance_facts')->where('id', $id)->value('payload'))->toBe($cipher)
        ->and(DB::table('team_event_performance_facts')->where('id', $id)->value('net_sales_minor'))->toBe(12345);
    foreach (['tepf_source', 'tepf_date', 'tepf_group', 'tepf_event', 'tepf_customer'] as $index) {
        expect(Schema::hasIndex('team_event_performance_facts', $index))->toBeTrue();
    }
    expect(fn () => DB::table('team_event_performance_facts')->insert($row))->toThrow(\Illuminate\Database\QueryException::class);
    DB::table('team_event_performance_facts')->where('id', $id)->delete();
});
