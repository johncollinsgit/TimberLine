<?php

namespace App\Services\Team\Studio;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/** Optional candidate indexes only; original JSON comparison remains authoritative. */
final class EventCustomerAnchorSchema
{
    private const DEFINITIONS = [
        '$.event_id' => ['column' => 'event_capture_present', 'index' => 'mpl_event_capture_idx'],
        '$.event_attribution.event_id' => ['column' => 'event_attribution_present', 'index' => 'mpl_event_attribution_idx'],
    ];

    public function narrow(Builder $query, string $path): Builder
    {
        $definition = self::DEFINITIONS[$path] ?? null;
        if ($definition === null || ! $this->verified($query->getConnection(), $path, $definition)) {
            return $query;
        }
        $query->forceIndex($definition['index']);

        return $query->getConnection()->getDriverName() === 'mysql'
            ? $query->where($definition['column'], 1)
            : $query->whereRaw('('.$this->sqliteExpression($path).') = 1');
    }

    /** Only exact, schema-verified derivations may be omitted from full field shapes. */
    public function redundantColumns(Connection $db): array
    {
        if ($db->getDriverName() !== 'mysql') {
            return [];
        }
        $columns = [];
        foreach (self::DEFINITIONS as $path => $definition) {
            if ($this->verified($db, $path, $definition)) {
                $columns[] = $definition['column'];
            }
        }

        return $columns;
    }

    private function verified(Connection $db, string $path, array $definition): bool
    {
        if ($db->getDriverName() === 'sqlite') {
            $index = $db->selectOne("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'marketing_profile_links' AND name = ?", [$definition['index']]);
            $expected = 'CREATE INDEX "'.$definition['index'].'" ON "marketing_profile_links" ("tenant_id","source_type",('.$this->sqliteExpression($path).'),"id")';

            return $index && $this->normalize($index->sql ?? '') === $this->normalize($expected);
        }
        if ($db->getDriverName() !== 'mysql') {
            return false;
        }
        $column = $db->selectOne('SELECT COLUMN_TYPE AS column_type, EXTRA AS extra, GENERATION_EXPRESSION AS expression FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['marketing_profile_links', $definition['column']]);
        // MySQL 8.4's information_schema form escapes literal quotes. Accept
        // only its two canonical renderings; path literal bytes stay exact.
        $expected = "(json_extract(`source_meta`,_utf8mb4\\'".$path."\\') is not null)";
        if (! $column || strtolower($column->column_type) !== 'tinyint unsigned' || strtoupper($column->extra) !== 'VIRTUAL GENERATED INVISIBLE'
            || ! in_array($column->expression, [$expected, str_replace("\\'", "'", $expected)], true)) {
            return false;
        }
        $parts = $db->select('SELECT COLUMN_NAME AS column_name, SUB_PART AS sub_part, NON_UNIQUE AS non_unique, INDEX_TYPE AS index_type, IS_VISIBLE AS is_visible, COLLATION AS collation, EXPRESSION AS expression FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', ['marketing_profile_links', $definition['index']]);

        return array_column($parts, 'column_name') === ['tenant_id', 'source_type', $definition['column'], 'id']
            && ! array_filter($parts, fn ($part) => $part->sub_part !== null || (int) $part->non_unique !== 1 || strtoupper($part->index_type) !== 'BTREE' || strtoupper($part->is_visible) !== 'YES' || $part->collation !== 'A' || $part->expression !== null);
    }

    private function sqliteExpression(string $path): string
    {
        return 'CASE WHEN "source_meta" IS NULL THEN 0 WHEN json_valid("source_meta") = 0 THEN 1 WHEN json_type("source_meta",\''.$path.'\') IS NOT NULL THEN 1 ELSE 0 END';
    }

    /** Normalize SQL tokens only; literal path case/bytes remain significant. */
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
}
