<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_external_profiles')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE customer_external_profiles
                MODIFY provider VARCHAR(80) NOT NULL,
                MODIFY integration VARCHAR(80) NOT NULL,
                MODIFY store_key VARCHAR(80) NULL,
                MODIFY external_customer_id VARCHAR(120) NOT NULL
            ");
        }

        $columns = collect(Schema::getColumnListing('customer_external_profiles'));

        Schema::table('customer_external_profiles', function (Blueprint $table) use ($columns): void {
            if (! $columns->contains('first_name')) {
                $table->string('first_name')->nullable();
            }

            if (! $columns->contains('last_name')) {
                $table->string('last_name')->nullable();
            }

            if (! $columns->contains('full_name')) {
                $table->string('full_name')->nullable();
            }

            if (! $columns->contains('phone')) {
                $table->string('phone')->nullable();
            }

            if (! $columns->contains('normalized_phone')) {
                $table->string('normalized_phone')->nullable();
            }

            if (! $columns->contains('accepts_marketing')) {
                $table->boolean('accepts_marketing')->nullable();
            }

            if (! $columns->contains('order_count')) {
                $table->unsignedInteger('order_count')->nullable();
            }

            if (! $columns->contains('last_order_at')) {
                $table->timestamp('last_order_at')->nullable();
            }

            if (! $columns->contains('last_activity_at')) {
                $table->timestamp('last_activity_at')->nullable();
            }

            if (! $columns->contains('source_channels')) {
                $table->json('source_channels')->nullable();
            }
        });

        $this->ensureIndex('customer_external_profiles', ['provider'], 'customer_external_profiles_provider_index');
        $this->ensureIndex('customer_external_profiles', ['integration'], 'customer_external_profiles_integration_index');
        $this->ensureIndex('customer_external_profiles', ['store_key'], 'customer_external_profiles_store_key_index');
        $this->ensureIndex('customer_external_profiles', ['external_customer_id'], 'customer_external_profiles_external_customer_id_index');
        $this->ensureIndex('customer_external_profiles', ['email'], 'customer_external_profiles_email_index');
        $this->ensureIndex('customer_external_profiles', ['normalized_email'], 'customer_external_profiles_normalized_email_index');
        $this->ensureIndex('customer_external_profiles', ['phone'], 'customer_external_profiles_phone_index');
        $this->ensureIndex('customer_external_profiles', ['normalized_phone'], 'customer_external_profiles_normalized_phone_index');
        $this->ensureIndex('customer_external_profiles', ['synced_at'], 'customer_external_profiles_synced_at_index');
        $this->ensureIndex(
            'customer_external_profiles',
            ['provider', 'integration', 'store_key', 'external_customer_id'],
            'cep_provider_integration_store_customer_unique',
            'unique',
        );
    }

    public function down(): void
    {
        // Forward-only parity fix for environments that created the table from an older schema revision.
    }

    protected function ensureIndex(string $table, array $columns, string $name, string $type = 'index'): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }

        $existingColumns = collect(Schema::getColumnListing($table));
        if (collect($columns)->contains(fn (string $column): bool => ! $existingColumns->contains($column))) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name, $type): void {
            if ($type === 'unique') {
                $blueprint->unique($columns, $name);

                return;
            }

            $blueprint->index($columns, $name);
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn (object $row): bool => ($row->name ?? null) === $index);
        }

        return collect(DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index]))->isNotEmpty();
    }
};
