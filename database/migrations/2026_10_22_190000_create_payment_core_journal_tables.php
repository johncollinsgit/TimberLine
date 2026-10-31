<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $collation = DB::connection()->getDriverName() === 'mysql' ? 'utf8mb4_bin' : 'binary';
        if (! Schema::hasTable('payment_core_admissions')) {
            Schema::create('payment_core_admissions', function (Blueprint $table): void {
                $table->unsignedBigInteger('tenant_id')->primary();
            });
        }
        if (! Schema::hasTable('payment_core_bindings')) {
            Schema::create('payment_core_bindings', function (Blueprint $table) use ($collation): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('register_id', 100)->collation($collation);
                $table->string('fingerprint', 64)->collation($collation);
                $table->longText('binding');
            });
        }
        if (! Schema::hasTable('payment_core_operations')) {
            Schema::create('payment_core_operations', function (Blueprint $table) use ($collation): void {
                $table->string('id', 32)->collation($collation)->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('binding_id');
                $table->string('register_id', 100)->collation($collation);
                $table->string('sale_id', 100)->collation($collation);
                // MySQL permits multiple NULLs in a unique key, allowing many
                // refunds while only the payment owns a sale identity.
                $table->string('payment_sale_key', 100)->collation($collation)->nullable();
                $table->string('request_id', 100)->collation($collation);
                $table->string('kind', 16)->collation($collation);
                $table->string('original_id', 32)->collation($collation)->nullable();
                $table->string('terms_hash', 64)->collation($collation);
                $table->longText('binding');
                $table->longText('terms');
                $table->unsignedInteger('amount_minor');
                $table->string('currency', 3)->collation($collation);
                $table->string('state', 16)->collation($collation);
                $table->boolean('claimed')->default(false);
                $table->string('provider_id', 100)->collation($collation)->nullable();
                $table->unsignedBigInteger('version')->default(0);
                $table->string('original_provider_id', 100)->collation($collation)->nullable();
                $table->string('effect_scope', 64)->collation($collation);
            });
        }
        if (! Schema::hasTable('payment_core_events')) {
            Schema::create('payment_core_events', function (Blueprint $table) use ($collation): void {
                $table->id();
                $table->string('operation_id', 32)->collation($collation);
                $table->string('event_code', 40)->collation($collation);
                $table->unsignedBigInteger('evidence_version');
            });
        }

        // Partial MySQL DDL must never repair by deleting, merging or rewriting
        // durable payment records. Reject incompatible retained rows first.
        $this->assertRetainedRows();
        $this->assertConstraintShapes();
        foreach ($this->indexes() as $table => $indexes) {
            foreach ($indexes as $name => [$columns, $unique]) {
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $unique
                        ? $blueprint->unique($columns, $name) : $blueprint->index($columns, $name));
                }
            }
        }
        foreach ($this->foreignKeys() as $table => $keys) {
            foreach ($keys as $name => [$column, $target]) {
                if (! collect(Schema::getForeignKeys($table))->contains(fn ($key) => $key['name'] === $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column, $name)
                        ->references('id')->on($target)->restrictOnDelete());
                }
            }
        }
    }

    public function down(): void
    {
        // Unreleased foundation: retain original payment and refund evidence on code rollback.
    }

    private function indexes(): array
    {
        return [
            'payment_core_bindings' => [
                'pc_bind_register_uq' => [['tenant_id', 'register_id'], true],
            ],
            'payment_core_operations' => [
                'pc_op_request_uq' => [['tenant_id', 'request_id'], true],
                'pc_op_sale_uq' => [['tenant_id', 'payment_sale_key'], true],
                'pc_op_effect_uq' => [['effect_scope', 'kind', 'provider_id'], true],
                'pc_op_register_idx' => [['tenant_id', 'register_id', 'state'], false],
                'pc_op_binding_idx' => [['binding_id'], false],
                'pc_op_original_idx' => [['original_id'], false],
            ],
            'payment_core_events' => [
                'pc_event_operation_idx' => [['operation_id'], false],
            ],
        ];
    }

    private function foreignKeys(): array
    {
        return [
            'payment_core_admissions' => ['pc_admit_tenant_fk' => ['tenant_id', 'tenants']],
            'payment_core_bindings' => ['pc_bind_tenant_fk' => ['tenant_id', 'tenants']],
            'payment_core_operations' => [
                'pc_op_tenant_fk' => ['tenant_id', 'tenants'],
                'pc_op_binding_fk' => ['binding_id', 'payment_core_bindings'],
                'pc_op_original_fk' => ['original_id', 'payment_core_operations'],
            ],
            'payment_core_events' => ['pc_event_operation_fk' => ['operation_id', 'payment_core_operations']],
        ];
    }

    private function assertConstraintShapes(): void
    {
        foreach ($this->indexes() as $table => $indexes) {
            $saved = collect(Schema::getIndexes($table))->keyBy('name');
            foreach ($indexes as $name => [$columns, $unique]) {
                $index = $saved->get($name);
                if ($index && ($index['columns'] !== $columns || $index['unique'] !== $unique)) {
                    throw new RuntimeException('Payment journal retained index has an incompatible definition.');
                }
            }
        }
        foreach ($this->foreignKeys() as $table => $keys) {
            $saved = collect(Schema::getForeignKeys($table))->keyBy('name');
            foreach ($keys as $name => [$column, $target]) {
                $key = $saved->get($name);
                if ($key && ($key['columns'] !== [$column] || $key['foreign_table'] !== $target || $key['foreign_columns'] !== ['id']
                    || ! in_array(strtolower($key['on_delete']), ['restrict', 'no action'], true)
                    || ! in_array(strtolower($key['on_update']), ['restrict', 'no action'], true))) {
                    throw new RuntimeException('Payment journal retained foreign key has an incompatible definition.');
                }
            }
        }
    }

    private function assertRetainedRows(): void
    {
        foreach ($this->indexes() as $table => $indexes) {
            foreach ($indexes as [$columns, $unique]) {
                if (! $unique) {
                    continue;
                }
                $query = DB::table($table);
                foreach ($columns as $column) {
                    $query->whereNotNull($column);
                }
                if ($query->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->first()) {
                    throw new RuntimeException('Payment journal retained rows contain duplicate immutable identities.');
                }
            }
        }
        foreach ($this->foreignKeys() as $table => $keys) {
            foreach ($keys as [$column, $target]) {
                if (DB::table($table.' as child')->leftJoin($target.' as parent', 'child.'.$column, '=', 'parent.id')
                    ->whereNotNull('child.'.$column)->whereNull('parent.id')->exists()) {
                    throw new RuntimeException('Payment journal retained rows contain orphaned identities.');
                }
            }
        }
        $operations = DB::table('payment_core_operations');
        if ((clone $operations)->whereNotIn('kind', ['payment', 'refund'])->exists()
            || (clone $operations)->whereNotIn('state', ['reserved', 'processing', 'unknown', 'review', 'succeeded', 'failed', 'cancelled'])->exists()
            || (clone $operations)->where(fn ($query) => $query->where('amount_minor', '<', 1)->orWhere('amount_minor', '>', 100000000))->exists()
            || (clone $operations)->where('kind', 'payment')->where(fn ($query) => $query->whereNull('payment_sale_key')->orWhereColumn('payment_sale_key', '<>', 'sale_id')->orWhereNotNull('original_id'))->exists()
            || (clone $operations)->where('kind', 'refund')->where(fn ($query) => $query->whereNotNull('payment_sale_key')->orWhereNull('original_id'))->exists()
            || DB::table('payment_core_operations as child')->join('payment_core_bindings as binding', 'child.binding_id', '=', 'binding.id')
                ->where(fn ($query) => $query->whereColumn('child.tenant_id', '<>', 'binding.tenant_id')->orWhereColumn('child.register_id', '<>', 'binding.register_id'))->exists()
            || DB::table('payment_core_operations as refund')->join('payment_core_operations as payment', 'refund.original_id', '=', 'payment.id')
                ->where(fn ($query) => $query->whereColumn('refund.tenant_id', '<>', 'payment.tenant_id')->orWhere('payment.kind', '<>', 'payment'))->exists()) {
            throw new RuntimeException('Payment journal retained rows violate payment ownership or money invariants.');
        }
    }
};
