<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_registers')) {
            Schema::create('pos_registers', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('tenant_site_id');
                $table->unsignedBigInteger('website_fulfillment_location_id');
                $table->string('name', 120);
                $table->string('reader_id', 120);
                $table->string('merchant_id', 120);
                $table->string('currency', 3)->default('usd');
                $table->boolean('active')->default(false);
                $table->json('cashier_user_ids')->nullable();
                $table->unsignedInteger('tax_rate_bps');
                $table->string('tax_basis', 255);
                $table->timestamp('tax_confirmed_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('pos_payment_attempts')) {
            Schema::create('pos_payment_attempts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('pos_register_id');
                $table->unsignedBigInteger('website_order_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->uuid('request_id');
                $table->string('request_hash', 64);
                $table->longText('snapshot');
                $table->string('status', 32);
                $table->string('provider_id', 120)->nullable();
                $table->timestamp('provider_requested_at')->nullable();
                $table->timestamp('stock_committed_at')->nullable();
                $table->string('last_error', 120)->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('pos_refunds')) {
            Schema::create('pos_refunds', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('pos_payment_attempt_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->uuid('request_id');
                $table->string('request_hash', 64);
                $table->longText('snapshot');
                $table->unsignedInteger('amount_cents');
                $table->longText('reason');
                $table->string('status', 32);
                $table->string('provider_id', 120)->nullable();
                $table->timestamp('provider_requested_at')->nullable();
                $table->string('last_error', 120)->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('pos_simulator_objects')) {
            Schema::create('pos_simulator_objects', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('object_key', 160);
                $table->string('kind', 32);
                $table->string('request_hash', 64);
                $table->longText('payload');
                $table->timestamps();
            });
        }

        // MySQL commits CREATE TABLE independently of later index/FK DDL.
        // Re-entry repairs every named constraint without discarding saved rows.
        foreach ($this->indexes() as $table => $indexes) {
            foreach ($indexes as $name => [$columns, $unique]) {
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $unique
                        ? $blueprint->unique($columns, $name)
                        : $blueprint->index($columns, $name));
                }
            }
        }
        foreach ($this->foreignKeys() as $table => $keys) {
            foreach ($keys as $name => [$column, $target, $delete]) {
                if (! collect(Schema::getForeignKeys($table))->contains(fn ($key) => $key['name'] === $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column, $name)
                        ->references('id')->on($target)->onDelete($delete));
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_simulator_objects');
        Schema::dropIfExists('pos_refunds');
        Schema::dropIfExists('pos_payment_attempts');
        Schema::dropIfExists('pos_registers');
    }

    private function indexes(): array
    {
        return [
            'pos_registers' => [
                'pos_reg_tenant_idx' => [['tenant_id'], false],
                'pos_reg_site_idx' => [['tenant_site_id'], false],
                'pos_reg_location_idx' => [['website_fulfillment_location_id'], false],
                'pos_reg_reader_uq' => [['reader_id'], true],
            ],
            'pos_payment_attempts' => [
                'pos_pay_request_uq' => [['tenant_id', 'request_id'], true],
                'pos_pay_register_idx' => [['pos_register_id'], false],
                'pos_pay_order_idx' => [['website_order_id'], false],
                'pos_pay_user_idx' => [['user_id'], false],
                'pos_pay_provider_uq' => [['provider_id'], true],
                'pos_pay_status_idx' => [['status', 'updated_at'], false],
            ],
            'pos_refunds' => [
                'pos_refund_request_uq' => [['tenant_id', 'request_id'], true],
                'pos_refund_attempt_idx' => [['pos_payment_attempt_id'], false],
                'pos_refund_user_idx' => [['user_id'], false],
                'pos_refund_provider_uq' => [['provider_id'], true],
            ],
            'pos_simulator_objects' => [
                'pos_sim_tenant_idx' => [['tenant_id'], false],
                'pos_sim_object_uq' => [['object_key'], true],
            ],
        ];
    }

    private function foreignKeys(): array
    {
        return [
            'pos_registers' => [
                'pos_reg_tenant_fk' => ['tenant_id', 'tenants', 'cascade'],
                'pos_reg_site_fk' => ['tenant_site_id', 'tenant_sites', 'cascade'],
                'pos_reg_location_fk' => ['website_fulfillment_location_id', 'website_fulfillment_locations', 'restrict'],
            ],
            'pos_payment_attempts' => [
                'pos_pay_tenant_fk' => ['tenant_id', 'tenants', 'cascade'],
                'pos_pay_register_fk' => ['pos_register_id', 'pos_registers', 'restrict'],
                'pos_pay_order_fk' => ['website_order_id', 'website_orders', 'restrict'],
                'pos_pay_user_fk' => ['user_id', 'users', 'set null'],
            ],
            'pos_refunds' => [
                'pos_refund_tenant_fk' => ['tenant_id', 'tenants', 'cascade'],
                'pos_refund_attempt_fk' => ['pos_payment_attempt_id', 'pos_payment_attempts', 'restrict'],
                'pos_refund_user_fk' => ['user_id', 'users', 'set null'],
            ],
            'pos_simulator_objects' => [
                'pos_sim_tenant_fk' => ['tenant_id', 'tenants', 'cascade'],
            ],
        ];
    }
};
