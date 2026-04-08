<?php

namespace App\Support\Schema;

use Illuminate\Support\Facades\Schema;

class SchemaCapabilityMap
{
    /**
     * @var array<string,bool>
     */
    protected static array $tableCache = [];

    /**
     * @var array<string,bool>
     */
    protected static array $columnCache = [];

    public function hasTable(string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }

        $cacheKey = $this->tableKey($table);
        if (array_key_exists($cacheKey, self::$tableCache)) {
            return self::$tableCache[$cacheKey];
        }

        return self::$tableCache[$cacheKey] = Schema::hasTable($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        $table = trim($table);
        $column = trim($column);

        if ($table === '' || $column === '') {
            return false;
        }

        $cacheKey = $this->columnKey($table, $column);
        if (array_key_exists($cacheKey, self::$columnCache)) {
            return self::$columnCache[$cacheKey];
        }

        return self::$columnCache[$cacheKey] = Schema::hasColumn($table, $column);
    }

    protected function tableKey(string $table): string
    {
        return $this->connectionName() . ':' . strtolower($table);
    }

    protected function columnKey(string $table, string $column): string
    {
        return $this->connectionName() . ':' . strtolower($table) . ':' . strtolower($column);
    }

    protected function connectionName(): string
    {
        return (string) (Schema::getConnection()->getName() ?? config('database.default', 'default'));
    }
}
