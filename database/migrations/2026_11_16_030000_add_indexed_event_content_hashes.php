<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Keep these expressions immutable: the index must change for every
        // source-content mutation, including direct SQL and bulk imports.
        // AUTO_INCREMENT ids and timezone-sensitive TIMESTAMPs are mixed into
        // the small read-time hash instead of the generated expression.
        $tables = [
            'square_orders' => [
                'expression' => 'UNHEX(SHA2(JSON_ARRAY(`square_order_id`,`square_customer_id`,`location_id`,`state`,`total_money_amount`,`total_money_currency`,SHA2(CAST(`raw_payload` AS CHAR CHARACTER SET utf8mb4),256)),256))',
                'indexes' => [
                    // Declared key payload: 8 + 1020 + 4 + 1020 + 32 = 2084 bytes.
                    'epo_window_hash_idx' => ['tenant_id', 'location_id', 'closed_at', 'square_order_id', 'event_content_hash'],
                    'epo_order_hash_idx' => ['tenant_id', 'square_order_id', 'location_id', 'closed_at', 'event_content_hash'],
                ],
            ],
            'square_payments' => [
                'expression' => 'UNHEX(SHA2(JSON_ARRAY(`square_payment_id`,`square_order_id`,`square_customer_id`,`location_id`,`amount_money`,`currency`,`status`,SHA2(CAST(`raw_payload` AS CHAR CHARACTER SET utf8mb4),256)),256))',
                'indexes' => [
                    // Declared key payload: 8 + 1020 + 32 = 1060 bytes.
                    'epp_order_hash_idx' => ['tenant_id', 'square_order_id', 'event_content_hash'],
                ],
            ],
            'marketing_profiles' => [
                'expression' => 'UNHEX(SHA2(JSON_ARRAY(`merged_into_profile_id`,SHA2(CAST(`source_channels` AS CHAR CHARACTER SET utf8mb4),256)),256))',
                'indexes' => [
                    'ep_profile_hash_idx' => ['tenant_id', 'archived_at', 'event_content_hash'],
                ],
            ],
            'marketing_profile_links' => [
                'expression' => 'UNHEX(SHA2(JSON_ARRAY(`marketing_profile_id`,`source_type`,`source_id`,SHA2(CAST(`source_meta` AS CHAR CHARACTER SET utf8mb4),256),`match_method`,`confidence`),256))',
                'indexes' => [
                    'ep_link_hash_idx' => ['tenant_id', 'event_content_hash'],
                ],
            ],
        ];

        foreach ($tables as $table => $definition) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'event_content_hash')) {
                // INVISIBLE keeps raw SELECT * and older active releases from
                // loading internal binary values during the online upgrade.
                DB::statement('ALTER TABLE `'.$table.'` ADD COLUMN `event_content_hash` BINARY(32) GENERATED ALWAYS AS ('.$definition['expression'].') VIRTUAL INVISIBLE, ALGORITHM=INSTANT');
            }
            $column = DB::selectOne("SELECT COLUMN_TYPE AS column_type, EXTRA AS extra, GENERATION_EXPRESSION AS expression FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'event_content_hash'", [$table]);
            $normalize = fn (string $expression): string => str_replace('characterset', 'charset', preg_replace('/[`\s]+/', '', strtolower($expression)));
            if (! $column || strtolower($column->column_type) !== 'binary(32)' || strtoupper($column->extra) !== 'VIRTUAL GENERATED INVISIBLE' || $normalize($column->expression) !== $normalize($definition['expression'])) {
                throw new RuntimeException('Incompatible event content hash on '.$table.'.');
            }

            foreach ($definition['indexes'] as $name => $columns) {
                $existing = DB::select('SELECT COLUMN_NAME AS column_name, SUB_PART AS sub_part, NON_UNIQUE AS non_unique, INDEX_TYPE AS index_type FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', [$table, $name]);
                if ($existing === []) {
                    DB::statement('ALTER TABLE `'.$table.'` ADD INDEX `'.$name.'` ('.implode(',', array_map(fn ($column) => '`'.$column.'`', $columns)).'), ALGORITHM=INPLACE, LOCK=NONE');
                } elseif (array_column($existing, 'column_name') !== $columns || array_filter($existing, fn ($part) => $part->sub_part !== null || (int) $part->non_unique !== 1 || strtoupper($part->index_type) !== 'BTREE')) {
                    throw new RuntimeException('Incompatible event content index '.$name.'.');
                }
            }
        }
    }

    public function down(): void
    {
        // Preserve source data and the rebuildable indexes on application rollback.
    }
};
