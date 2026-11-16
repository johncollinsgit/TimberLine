<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_profile_links')) {
            return;
        }
        // Immutable candidate-presence expressions: JSON null is present. Never
        // replace the original comparison with a coerced/generated event ID.
        $definitions = [
            '$.event_id' => ['column' => 'event_capture_present', 'index' => 'mpl_event_capture_idx'],
            '$.event_attribution.event_id' => ['column' => 'event_attribution_present', 'index' => 'mpl_event_attribution_idx'],
        ];
        foreach ($definitions as $path => $definition) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $expression = 'CASE WHEN "source_meta" IS NULL THEN 0 WHEN json_valid("source_meta") = 0 THEN 1 WHEN json_type("source_meta",\''.$path.'\') IS NOT NULL THEN 1 ELSE 0 END';
                $sql = 'CREATE INDEX "'.$definition['index'].'" ON "marketing_profile_links" ("tenant_id","source_type",('.$expression.'),"id")';
                $existing = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = ?", [$definition['index']]);
                if ($existing === null) {
                    DB::statement($sql);
                } elseif ($this->normalize($existing->sql ?? '') !== $this->normalize($sql)) {
                    throw new RuntimeException('Incompatible event customer index '.$definition['index'].'.');
                }

                continue;
            }
            if (DB::connection()->getDriverName() !== 'mysql') {
                continue;
            }
            $expression = "JSON_EXTRACT(`source_meta`,_utf8mb4'".$path."') IS NOT NULL";
            if (! Schema::hasColumn('marketing_profile_links', $definition['column'])) {
                DB::statement('ALTER TABLE `marketing_profile_links` ADD COLUMN `'.$definition['column'].'` TINYINT UNSIGNED GENERATED ALWAYS AS ('.$expression.') VIRTUAL INVISIBLE, ALGORITHM=INSTANT');
            }
            $column = DB::selectOne('SELECT COLUMN_TYPE AS column_type, EXTRA AS extra, GENERATION_EXPRESSION AS expression FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['marketing_profile_links', $definition['column']]);
            $canonical = "(json_extract(`source_meta`,_utf8mb4\\'".$path."\\') is not null)";
            if (! $column || strtolower($column->column_type) !== 'tinyint unsigned' || strtoupper($column->extra) !== 'VIRTUAL GENERATED INVISIBLE'
                || ! in_array($column->expression, [$canonical, str_replace("\\'", "'", $canonical)], true)) {
                throw new RuntimeException('Incompatible event customer presence '.$definition['column'].'.');
            }
            $parts = DB::select('SELECT COLUMN_NAME AS column_name, SUB_PART AS sub_part, NON_UNIQUE AS non_unique, INDEX_TYPE AS index_type, IS_VISIBLE AS is_visible, COLLATION AS collation, EXPRESSION AS expression FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', ['marketing_profile_links', $definition['index']]);
            $columns = ['tenant_id', 'source_type', $definition['column'], 'id'];
            if ($parts === []) {
                // 8 + 1020 + 1 + 8 = 1037 declared key bytes at utf8mb4.
                DB::statement('ALTER TABLE `marketing_profile_links` ADD INDEX `'.$definition['index'].'` ('.implode(',', array_map(fn ($column) => '`'.$column.'`', $columns)).') VISIBLE, ALGORITHM=INPLACE, LOCK=NONE');
            } elseif (array_column($parts, 'column_name') !== $columns || array_filter($parts, fn ($part) => $part->sub_part !== null || (int) $part->non_unique !== 1 || strtoupper($part->index_type) !== 'BTREE' || strtoupper($part->is_visible) !== 'YES' || $part->collation !== 'A' || $part->expression !== null)) {
                throw new RuntimeException('Incompatible event customer index '.$definition['index'].'.');
            }
        }
    }

    public function down(): void
    {
        // Rebuildable indexes and original source evidence survive app rollback.
    }

    private function normalize(string $sql): string
    {
        $parts = preg_split("/('(?:[^'\\\\]|\\\\.|'')*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => &$part) {
            if ($index % 2 === 0) {
                $part = strtolower(preg_replace('/[`"\s]+/', '', $part));
            }
        }
        unset($part);

        return implode('', $parts);
    }
};
