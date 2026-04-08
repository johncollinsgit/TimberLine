<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_profiles')) {
            if ($this->hasColumns('marketing_profiles', ['tenant_id', 'normalized_email', 'id'])
                && ! $this->indexExists('marketing_profiles', 'mp_tenant_normalized_email_id_idx')) {
                $this->createIndex(
                    'marketing_profiles',
                    'mp_tenant_normalized_email_id_idx',
                    ['tenant_id', 'normalized_email', 'id']
                );
            }

            if ($this->hasColumns('marketing_profiles', ['tenant_id', 'normalized_phone', 'id'])
                && ! $this->indexExists('marketing_profiles', 'mp_tenant_normalized_phone_id_idx')) {
                $this->createIndex(
                    'marketing_profiles',
                    'mp_tenant_normalized_phone_id_idx',
                    ['tenant_id', 'normalized_phone', 'id']
                );
            }
        }

        if (! Schema::hasTable('orders')) {
            return;
        }

        $hasStoreKey = Schema::hasColumn('orders', 'shopify_store_key');
        $hasStore = Schema::hasColumn('orders', 'shopify_store');

        if ($hasStoreKey
            && $this->hasColumns('orders', ['tenant_id', 'shopify_customer_id', 'shopify_store_key'])
            && ! $this->indexExists('orders', 'orders_tenant_customer_store_key_idx')) {
            $this->createIndex(
                'orders',
                'orders_tenant_customer_store_key_idx',
                ['tenant_id', 'shopify_customer_id', 'shopify_store_key']
            );
        } elseif (! $hasStoreKey
            && $hasStore
            && $this->hasColumns('orders', ['tenant_id', 'shopify_customer_id', 'shopify_store'])
            && ! $this->indexExists('orders', 'orders_tenant_customer_store_idx')) {
            $this->createIndex(
                'orders',
                'orders_tenant_customer_store_idx',
                ['tenant_id', 'shopify_customer_id', 'shopify_store']
            );
        }

        if ($this->hasColumns('orders', ['tenant_id', 'id'])
            && ! $this->indexExists('orders', 'orders_tenant_id_idx')) {
            $this->createIndex('orders', 'orders_tenant_id_idx', ['tenant_id', 'id']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('marketing_profiles')) {
            $this->dropIndexIfExists('marketing_profiles', 'mp_tenant_normalized_email_id_idx');
            $this->dropIndexIfExists('marketing_profiles', 'mp_tenant_normalized_phone_id_idx');
        }

        if (Schema::hasTable('orders')) {
            $this->dropIndexIfExists('orders', 'orders_tenant_customer_store_key_idx');
            $this->dropIndexIfExists('orders', 'orders_tenant_customer_store_idx');
            $this->dropIndexIfExists('orders', 'orders_tenant_id_idx');
        }
    }

    /**
     * @param  array<int,string>  $columns
     */
    protected function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int,string>  $columns
     */
    protected function createIndex(string $table, string $index, array $columns): void
    {
        $quotedColumns = implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns));
        DB::statement(sprintf(
            'CREATE INDEX %s ON %s (%s)',
            $this->quoteIdentifier($index),
            $this->quoteIdentifier($table),
            $quotedColumns
        ));
    }

    protected function dropIndexIfExists(string $table, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(sprintf('DROP INDEX IF EXISTS %s', $this->quoteIdentifier($index)));

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(sprintf(
                'ALTER TABLE %s DROP INDEX %s',
                $this->quoteIdentifier($table),
                $this->quoteIdentifier($index)
            ));

            return;
        }

        DB::statement(sprintf('DROP INDEX IF EXISTS %s', $this->quoteIdentifier($index)));
    }

    protected function indexExists(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select('PRAGMA index_list("' . $table . '")'))
                ->contains(fn ($row): bool => (string) ($row->name ?? '') === $index);
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return DB::table('information_schema.statistics')
                ->where('table_schema', Schema::getConnection()->getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        }

        return collect(DB::select(
            'SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ? LIMIT 1',
            [$table, $index]
        ))->isNotEmpty();
    }

    protected function quoteIdentifier(string $identifier): string
    {
        $driver = Schema::getConnection()->getDriverName();
        $escaped = str_replace('"', '""', $identifier);

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $escaped = str_replace('`', '``', $identifier);

            return '`' . $escaped . '`';
        }

        return '"' . $escaped . '"';
    }
};
