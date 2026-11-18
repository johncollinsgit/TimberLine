<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite source keys already use binary equality. MySQL's default
        // case-insensitive collation can resolve a different provider ID.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        $collation = DB::selectOne('SELECT PAD_ATTRIBUTE AS padding FROM information_schema.COLLATIONS WHERE COLLATION_NAME = ?', ['utf8mb4_0900_bin']);
        if (! $collation || $collation->padding !== 'NO PAD') {
            throw new RuntimeException('Exact Trajectory source identity requires MySQL utf8mb4_0900_bin without padding.');
        }
        $definitions = [
            'trajectory_accounts' => ['source_key' => [160, false]],
            'trajectory_transactions' => ['source_key' => [190, false], 'pending_source_key' => [190, true]],
        ];
        $changes = [];
        // Admit every existing shape before the first implicitly committed DDL.
        foreach ($definitions as $table => $columns) {
            foreach ($columns as $column => [$length, $nullable]) {
                $actual = DB::selectOne('SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS max_length, IS_NULLABLE AS nullable, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation_name, COLUMN_DEFAULT AS default_value, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
                if (! $actual || $actual->data_type !== 'varchar' || (int) $actual->max_length !== $length
                    || $actual->nullable !== ($nullable ? 'YES' : 'NO') || $actual->charset !== 'utf8mb4'
                    || $actual->default_value !== null || $actual->extra !== ''
                    || ! in_array($actual->collation_name, ['utf8mb4_unicode_ci', 'utf8mb4_0900_bin'], true)) {
                    throw new RuntimeException('Unexpected Trajectory source identity column '.$table.'.'.$column.'.');
                }
                if ($actual->collation_name !== 'utf8mb4_0900_bin') {
                    $changes[$table][] = 'MODIFY `'.$column.'` VARCHAR('.$length.') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin '.($nullable ? 'NULL DEFAULT NULL' : 'NOT NULL');
                }
            }
        }
        foreach (['trajectory_accounts' => 'traj_account_source', 'trajectory_transactions' => 'traj_tx_source'] as $table => $index) {
            $parts = DB::select('SELECT COLUMN_NAME AS column_name, SUB_PART AS prefix_length, NON_UNIQUE AS non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', [$table, $index]);
            if (count($parts) !== 1 || $parts[0]->column_name !== 'source_key' || $parts[0]->prefix_length !== null || (int) $parts[0]->non_unique !== 0) {
                throw new RuntimeException('Unexpected Trajectory source identity index '.$index.'.');
            }
        }
        foreach ($changes as $table => $clauses) {
            // Existing keys, lengths, indexes and financial rows remain intact.
            // A completed table is skipped on retry after interrupted MySQL DDL.
            DB::statement('ALTER TABLE `'.$table.'` '.implode(', ', $clauses));
        }
    }

    public function down(): void
    {
        // Keep exact identity on code rollback: case-distinct provider records
        // may now coexist. Reverting to case folding could collide or misroute.
    }
};
