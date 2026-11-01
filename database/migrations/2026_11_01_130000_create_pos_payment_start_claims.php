<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_payment_start_claims')) {
            Schema::create('pos_payment_start_claims', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('runtime_id', 64);
                $table->unsignedBigInteger('tenant_id');
                $table->char('sale_id', 32);
                $table->string('payment_request_id', 100);
                $table->string('state', 20);
                $table->timestamps();
            });
        }
        foreach ([
            ['pos_start_claim_sale_uq', ['runtime_id', 'tenant_id', 'sale_id'], true],
            ['pos_start_claim_request_uq', ['runtime_id', 'tenant_id', 'payment_request_id'], true],
        ] as [$name, $columns, $unique]) {
            $existing = collect(Schema::getIndexes('pos_payment_start_claims'))->firstWhere('name', $name);
            if ($existing && ($existing['columns'] !== $columns || (bool) $existing['unique'] !== $unique)) {
                throw new RuntimeException('POS payment start claim index definition changed: '.$name);
            }
            if (! $existing) {
                Schema::table('pos_payment_start_claims', fn (Blueprint $table) => $table->unique($columns, $name));
            }
        }
    }

    public function down(): void
    {
        // Preserve financial start/cancellation evidence on rollback.
    }
};
