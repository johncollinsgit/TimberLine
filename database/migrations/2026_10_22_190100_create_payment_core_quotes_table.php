<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_core_quotes')) {
            Schema::create('payment_core_quotes', function (Blueprint $table): void {
                $table->id();
                $table->char('quote_id', 36);
                $table->char('sale_id', 36);
                $table->unsignedBigInteger('tenant_id');
                $table->string('register_id', 100);
                $table->unsignedBigInteger('actor_id');
                $table->unsignedBigInteger('site_id');
                $table->char('request_id', 36);
                $table->unsignedInteger('version');
                $table->char('binding_fingerprint', 64);
                $table->char('scope_hash', 64);
                $table->char('selection_hash', 64);
                $table->char('payload_hash', 64);
                $table->longText('snapshot');
                $table->timestamp('created_at');
                $table->timestamp('expires_at');
            });
        }
        // Retain immutable quote evidence on parent removal. No cascading FK or provider action.
        // MySQL may retain a created table before later index DDL or migration bookkeeping.
        foreach ([
            'pcq_quote_uq' => [['quote_id'], true],
            'pcq_request_uq' => [['tenant_id', 'request_id'], true],
            'pcq_sale_uq' => [['tenant_id', 'sale_id'], true],
            'pcq_scope_idx' => [['tenant_id', 'register_id', 'created_at'], false],
        ] as $name => [$columns, $unique]) {
            $existing = collect(Schema::getIndexes('payment_core_quotes'))->firstWhere('name', $name);
            if ($existing && ($existing['columns'] !== $columns || (bool) $existing['unique'] !== $unique)) {
                throw new RuntimeException('Payment quote index definition changed: '.$name);
            }
            if (! $existing) {
                Schema::table('payment_core_quotes', fn (Blueprint $table) => $unique ? $table->unique($columns, $name) : $table->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_core_quotes');
    }
};
