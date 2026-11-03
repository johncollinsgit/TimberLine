<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_shop_pay_attempts')) {
            Schema::create('website_shop_pay_attempts', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique('wsp_attempt_uuid_uq');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('website_cart_id');
                $table->unsignedBigInteger('website_order_id')->nullable();
                $table->unsignedBigInteger('website_payment_id')->nullable();
                $table->string('source_identifier', 190)->unique('wsp_attempt_source_uq');
                $table->uuid('idempotency_key')->unique('wsp_attempt_idempotency_uq');
                $table->text('session_token')->nullable();
                $table->text('receipt_token')->nullable();
                $table->string('shopify_order_id', 190)->nullable();
                $table->string('shopify_order_name', 190)->nullable();
                $table->string('shopify_transaction_id', 190)->nullable();
                $table->string('status', 32)->default('created');
                $table->unsignedInteger('amount_cents');
                $table->string('currency', 3)->default('usd');
                $table->string('cart_fingerprint', 64);
                $table->text('checkout_state')->nullable();
                $table->string('failure_code', 80)->nullable();
                $table->string('failure_message', 255)->nullable();
                $table->unsignedTinyInteger('reconciliation_attempts')->default(0);
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('reconciled_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status', 'created_at'], 'wsp_attempt_tenant_status_idx');
                $table->index(['tenant_id', 'shopify_order_id'], 'wsp_attempt_order_ref_idx');
            });
        }
        // A committed table can survive interrupted index DDL. Never remove rows.
        $expected = [
            ['wsp_attempt_uuid_uq', ['uuid'], true],
            ['wsp_attempt_source_uq', ['source_identifier'], true],
            ['wsp_attempt_idempotency_uq', ['idempotency_key'], true],
            ['wsp_attempt_tenant_status_idx', ['tenant_id', 'status', 'created_at'], false],
            ['wsp_attempt_order_ref_idx', ['tenant_id', 'shopify_order_id'], false],
        ];
        foreach ($expected as [$name, $columns, $unique]) {
            $index = collect(Schema::getIndexes('website_shop_pay_attempts'))->firstWhere('name', $name);
            if ($index) {
                if ($index['columns'] !== $columns || $index['unique'] !== $unique) {
                    throw new RuntimeException('Unexpected Wallet index definition: '.$name);
                }
            } else {
                Schema::table('website_shop_pay_attempts', function (Blueprint $table) use ($name, $columns, $unique): void {
                    $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        // Preserve original financial attempts during rollback.
    }
};
