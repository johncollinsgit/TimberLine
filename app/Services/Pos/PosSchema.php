<?php

declare(strict_types=1);

namespace App\Services\Pos;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Explicit schema-only composition for retail and the independent Enterprise DB. */
final class PosSchema
{
    public static function install(): void
    {
        $scope = static function (Blueprint $t): void {
            $t->string('runtime_id', 64);
            $t->unsignedBigInteger('tenant_id');
        };
        $definitions = [
            'merchants' => static function (Blueprint $t) use ($scope): void {
                $t->id();
                $scope($t);
                $t->string('merchant_id', 100);
                $t->string('environment', 10);
            },
            'connections' => static function (Blueprint $t) use ($scope): void {
                $t->char('id', 32)->primary();
                $scope($t);
                $t->string('merchant_id', 100);
                $t->string('provider_location_id', 100);
                $t->string('environment', 10);
                $t->longText('credentials');
                $t->boolean('active')->default(false);
                $t->timestamp('verified_at')->nullable();
                $t->timestamp('acceptance_at')->nullable();
                $t->string('acceptance_build', 100)->nullable();
            },
            'registers' => static function (Blueprint $t) use ($scope): void {
                $t->char('id', 32)->primary();
                $scope($t);
                $t->string('name', 100);
                $t->string('location_id', 64);
                $t->string('location_name', 100);
                $t->string('timezone', 80)->default('America/New_York');
                $t->char('connection_id', 32);
                $t->string('catalog_source', 30);
                $t->longText('configuration');
                $t->boolean('active')->default(false);
                $t->boolean('collection_enabled')->default(false);
                $t->boolean('servicing_enabled')->default(false);
            },
            'assignments' => static function (Blueprint $t) use ($scope): void {
                $t->id();
                $scope($t);
                $t->char('register_id', 32);
                $t->unsignedBigInteger('user_id');
                $t->string('device_id', 64);
                $t->string('role', 20);
                $t->boolean('active')->default(false);
            },
            'items' => static function (Blueprint $t) use ($scope): void {
                $t->char('id', 32)->primary();
                $scope($t);
                $t->string('location_id', 64);
                $t->string('sku', 100);
                $t->string('title', 200);
                $t->string('kind', 30);
                $t->unsignedBigInteger('unit_amount_minor');
                $t->unsignedInteger('tax_bps');
                $t->string('tax_basis', 200);
                $t->timestamp('tax_reviewed_at');
                $t->unsignedBigInteger('reviewed_by');
                $t->char('revision', 64);
                $t->boolean('tracked');
                $t->unsignedInteger('quantity');
                $t->boolean('active')->default(false);
            },
            'sales' => static function (Blueprint $t) use ($scope): void {
                $t->char('id', 32)->primary();
                $scope($t);
                $t->char('register_id', 32);
                $t->string('location_id', 64);
                $t->unsignedBigInteger('actor_id');
                $t->char('request_id', 36);
                $t->char('payment_request_id', 36);
                $t->char('sdk_attempt_id', 36);
                $t->char('active_source_claim', 64)->nullable();
                $t->longText('snapshot');
                $t->char('snapshot_hash', 64);
                $t->unsignedBigInteger('amount_minor');
                $t->char('currency', 3);
                $t->string('commerce_source', 30);
                $t->string('provider_order_id', 100)->nullable();
                $t->string('commerce_order_id', 100)->nullable();
                $t->unsignedBigInteger('commerce_store_id')->nullable();
                $t->string('state', 30)->default('quoted');
                $t->char('attempt_id', 32)->nullable();
                $t->string('stock_state', 30)->default('unprepared');
                $t->longText('source_reference')->nullable();
                $t->longText('payment_hint')->nullable();
                $t->unsignedBigInteger('evidence_version')->default(0);
                $t->timestamp('descriptor_issued_at')->nullable();
                $t->timestamp('created_at');
                $t->timestamp('expires_at');
                $t->timestamp('reconciled_at')->nullable();
            },
            'holds' => static function (Blueprint $t) use ($scope): void {
                $t->id();
                $scope($t);
                $t->char('sale_id', 32);
                $t->char('item_id', 32);
                $t->unsignedInteger('quantity');
                $t->string('state', 20)->default('held');
            },
            'outbox' => static function (Blueprint $t) use ($scope): void {
                $t->char('id', 32)->primary();
                $scope($t);
                $t->char('sale_id', 32);
                $t->string('effect_key', 100);
                $t->longText('payload');
                $t->string('state', 20)->default('pending');
                $t->unsignedInteger('attempts')->default(0);
                $t->timestamp('available_at');
                $t->timestamp('claimed_at')->nullable();
                $t->string('error_code', 100)->nullable();
                $t->timestamp('completed_at')->nullable();
            },
            'events' => static function (Blueprint $t) use ($scope): void {
                $t->id();
                $scope($t);
                $t->char('sale_id', 32);
                $t->string('code', 100);
                $t->longText('details');
                $t->timestamp('created_at');
            },
        ];
        foreach ($definitions as $name => $definition) {
            if (! Schema::hasTable('pos_live_'.$name)) {
                Schema::create('pos_live_'.$name, $definition);
            }
        }
        foreach ([
            ['merchants', 'poslm_merchant_uq', ['runtime_id', 'merchant_id', 'environment'], true],
            ['merchants', 'poslm_tenant_uq', ['runtime_id', 'tenant_id', 'environment'], true],
            ['connections', 'poslc_scope_uq', ['runtime_id', 'tenant_id', 'merchant_id', 'provider_location_id', 'environment'], true],
            ['registers', 'poslr_scope_idx', ['runtime_id', 'tenant_id', 'location_id'], false],
            ['assignments', 'posla_actor_uq', ['register_id', 'user_id', 'device_id'], true],
            ['items', 'posli_sku_uq', ['runtime_id', 'tenant_id', 'location_id', 'sku'], true],
            ['sales', 'posls_request_uq', ['runtime_id', 'tenant_id', 'request_id'], true],
            ['sales', 'posls_source_uq', ['runtime_id', 'tenant_id', 'active_source_claim'], true],
            ['sales', 'posls_payment_uq', ['runtime_id', 'tenant_id', 'payment_request_id'], true],
            ['sales', 'posls_reconcile_idx', ['runtime_id', 'reconciled_at'], false],
            ['sales', 'posls_register_idx', ['runtime_id', 'tenant_id', 'register_id', 'state'], false],
            ['holds', 'poslh_item_uq', ['sale_id', 'item_id'], true],
            ['outbox', 'poslo_effect_uq', ['sale_id', 'effect_key'], true],
            ['outbox', 'poslo_work_idx', ['runtime_id', 'state', 'available_at'], false],
            ['events', 'posle_sale_idx', ['runtime_id', 'tenant_id', 'sale_id'], false],
        ] as [$suffix, $name, $columns, $unique]) {
            $table = 'pos_live_'.$suffix;
            $existing = collect(Schema::getIndexes($table))->firstWhere('name', $name);
            if ($existing && ($existing['columns'] !== $columns || (bool) $existing['unique'] !== $unique)) {
                throw new \RuntimeException('POS index definition changed: '.$name);
            }
            if (! $existing) {
                Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($columns, $name) : $t->index($columns, $name));
            }
        }
    }
}
