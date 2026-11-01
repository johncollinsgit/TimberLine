<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_candle_cash_reservations')) {
            Schema::create('pos_candle_cash_reservations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('runtime_id', 64);
                $table->unsignedBigInteger('tenant_id');
                $table->char('sale_id', 32);
                $table->unsignedBigInteger('marketing_profile_id');
                $table->unsignedBigInteger('amount_minor');
                $table->string('policy_version', 64);
                $table->string('authorization_reference', 128);
                $table->string('state', 20);
                $table->timestamps();
            });
        }
        foreach ([
            ['pos_reward_sale_unique', ['runtime_id', 'tenant_id', 'sale_id'], true],
            ['pos_reward_customer_index', ['tenant_id', 'marketing_profile_id'], false],
        ] as [$name, $columns, $unique]) {
            $existing = collect(Schema::getIndexes('pos_candle_cash_reservations'))->firstWhere('name', $name);
            if ($existing && ($existing['columns'] !== $columns || (bool) $existing['unique'] !== $unique)) {
                throw new RuntimeException('POS reward index definition changed: '.$name);
            }
            if (! $existing) {
                Schema::table('pos_candle_cash_reservations', fn (Blueprint $table) => $unique
                    ? $table->unique($columns, $name) : $table->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        // Preserve the ledger and balance evidence.
    }
};
