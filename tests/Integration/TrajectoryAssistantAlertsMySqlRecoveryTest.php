<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('retains assistant evidence and resumes MySQL after each completed table boundary', function (int $retained): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('TRAJECTORY_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Explicit disposable Trajectory MySQL only.');
    }
    if (! app()->environment('testing') || ! in_array($db->getDatabaseName(), ['everbranch_ci', 'codex_trajectory_test'], true)
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing assistant recovery outside isolated disposable MySQL.');
    }
    $tables = ['trajectory_assistant_turns', 'trajectory_alert_preferences', 'trajectory_push_subscriptions', 'trajectory_expense_notices'];
    foreach (array_reverse($tables) as $table) {
        Schema::dropIfExists($table);
    }
    $migration = require database_path('migrations/2026_11_18_051000_create_trajectory_assistant_and_alerts.php');
    $migration->up();
    DB::table($tables[0])->insert(['space_id' => 991, 'user_id' => 992, 'request_id' => '11111111-1111-4111-8111-111111111111', 'request_hash' => str_repeat('a', 64), 'question' => 'encrypted-fictional-sentinel', 'context' => 'encrypted-context-sentinel', 'state' => 'answered']);
    foreach (array_reverse(array_slice($tables, $retained)) as $table) {
        Schema::drop($table);
    }
    $migration->up();
    $migration->up();
    expect(DB::table($tables[0])->value('question'))->toBe('encrypted-fictional-sentinel');
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
    $indexes = collect(Schema::getIndexes('trajectory_expense_notices'));
    expect($indexes->firstWhere('name', 'traj_expense_notice_once')['unique'])->toBeTrue();
    $migration->down();
    expect(DB::table($tables[0])->count())->toBe(1);
})->with([1, 2, 3, 4]);
