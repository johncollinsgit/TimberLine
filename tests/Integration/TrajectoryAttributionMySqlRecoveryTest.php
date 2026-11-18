<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('retains encrypted attribution history through partial indexes retries and rollback and rejects drift', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('TRAJECTORY_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Explicit disposable Trajectory MySQL only.');
    }
    if (! app()->environment('testing') || ! in_array($db->getDatabaseName(), ['everbranch_ci', 'codex_trajectory_test'], true)
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing attribution recovery outside isolated disposable MySQL.');
    }
    $migration = require database_path('migrations/2026_11_18_120000_create_trajectory_allocation_details.php');
    $migration->up();
    Schema::table('trajectory_allocation_details', function (Blueprint $table): void {
        $table->dropUnique('traj_detail_revision');
        $table->dropIndex('traj_detail_tx_active');
        $table->dropIndex('traj_detail_space_active');
        $table->dropIndex('traj_detail_allocation');
    });
    $cipher = Crypt::encryptString('Fictional owner-confirmed settlement; not a bank credit.');
    $row = ['transaction_id' => 891234, 'allocation_id' => 891234, 'space_id' => 891234, 'revision' => 9, 'line_index' => 0,
        'amount_cents' => -12345, 'category' => 'uniforms', 'program_record_id' => 891234, 'flow' => 'expense',
        'settlement_status' => 'reimbursed', 'settlement_note' => $cipher, 'source_fingerprint' => str_repeat('a', 64),
        'active' => false, 'invalidation_reason' => 'bank_source_changed', 'invalidated_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    $id = DB::table('trajectory_allocation_details')->insertGetId($row);
    $migration->up();
    $migration->up();
    $migration->down();
    expect(DB::table('trajectory_allocation_details')->where('id', $id)->value('settlement_note'))->toBe($cipher)
        ->and(DB::table('trajectory_allocation_details')->where('id', $id)->value('amount_cents'))->toBe(-12345)
        ->and(DB::table('trajectory_allocation_details')->where('id', $id)->value('active'))->toBe(0);
    foreach (['traj_detail_revision', 'traj_detail_tx_active', 'traj_detail_space_active', 'traj_detail_allocation'] as $name) {
        expect(Schema::hasIndex('trajectory_allocation_details', $name))->toBeTrue();
    }
    expect(fn () => DB::table('trajectory_allocation_details')->insert($row))->toThrow(\Illuminate\Database\QueryException::class);
    Schema::table('trajectory_allocation_details', function (Blueprint $table): void {
        $table->dropIndex('traj_detail_tx_active');
        $table->index(['transaction_id', 'revision'], 'traj_detail_tx_active');
    });
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Unexpected attribution index definition');
    expect(DB::table('trajectory_allocation_details')->where('id', $id)->value('settlement_note'))->toBe($cipher);
    Schema::table('trajectory_allocation_details', fn (Blueprint $table) => $table->dropIndex('traj_detail_tx_active'));
    $migration->up();
    DB::table('trajectory_allocation_details')->where('id', $id)->delete();
});
