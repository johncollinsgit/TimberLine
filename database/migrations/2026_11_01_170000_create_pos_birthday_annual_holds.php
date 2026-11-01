<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_birthday_annual_holds')) {
            Schema::create('pos_birthday_annual_holds', function (Blueprint $table): void {
                $table->id();
                $table->string('runtime_id', 64);
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('coverage_id');
                $table->string('sale_id', 32);
                $table->string('state', 24);
                $table->unsignedBigInteger('amount_minor');
                $table->string('policy_version', 64);
                $table->string('authorization_reference', 64);
                $table->timestamps();
            });
        }
        if (! Schema::hasColumns('pos_birthday_annual_holds', ['runtime_id', 'tenant_id', 'coverage_id', 'sale_id', 'state', 'amount_minor', 'policy_version', 'authorization_reference'])) {
            throw new RuntimeException('Birthday annual hold columns differ from the reviewed schema.');
        }
        foreach (['pos_birthday_annual_hold_cycle_uq' => ['tenant_id', 'coverage_id'], 'pos_birthday_annual_hold_sale_uq' => ['runtime_id', 'tenant_id', 'sale_id']] as $name => $columns) {
            $index = collect(Schema::getIndexes('pos_birthday_annual_holds'))->firstWhere('name', $name);
            if ($index && (! $index['unique'] || $index['columns'] !== $columns)) {
                throw new RuntimeException('Birthday annual hold index differs from the reviewed unique key.');
            }
            if (! $index) {
                Schema::table('pos_birthday_annual_holds', fn (Blueprint $table) => $table->unique($columns, $name));
            }
        }
    }

    public function down(): void
    {
        // Annual hold evidence survives code rollback.
    }
};
