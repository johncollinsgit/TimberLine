<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive finished-goods storage. Materials and original commerce records are retained. */
final class StockSchema
{
    public const TABLES = ['mutexes', 'items', 'pools', 'balances', 'operations', 'movements', 'reservations', 'bindings', 'provider_items', 'publications', 'online_orders', 'receipt_associations', 'production_links', 'production_sources', 'catalog_runs', 'online_inbox', 'event_links', 'bundles'];

    public static function install(): void
    {
        $definitions = [
            'bundles' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->char('item_id', 32);
                $t->unsignedInteger('required_quantity');
                $t->longText('eligible_items');
                $t->char('revision', 64);
                $t->boolean('active')->default(true);
                $t->unsignedBigInteger('reviewed_by');
                $t->timestamps();
            },
            'event_links' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('event_id');
                $t->string('provider', 20);
                $t->string('external_id', 150);
                $t->longText('metadata');
                $t->unsignedBigInteger('reviewed_by');
                $t->timestamps();
            },
            'online_inbox' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('store_id');
                $t->string('order_id', 100);
                $t->char('event_hash', 64);
                $t->longText('payload');
                $t->string('state', 20)->default('pending');
                $t->unsignedInteger('attempts')->default(0);
                $t->string('error_code', 100)->nullable();
                $t->timestamp('next_attempt_at')->nullable();
                $t->timestamps();
            },
            'catalog_runs' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('store_id');
                $t->char('generation', 32);
                $t->timestamp('started_at');
                $t->timestamp('completed_at')->nullable();
            },
            'production_sources' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('order_id');
                $t->unsignedBigInteger('reviewed_by');
                $t->timestamps();
            },
            'production_links' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('order_line_id');
                $t->char('item_id', 32);
                $t->char('source_signature', 64);
                $t->unsignedBigInteger('received_quantity')->default(0);
                $t->unsignedBigInteger('reviewed_by');
                $t->timestamps();
            },
            'mutexes' => function (Blueprint $t): void {
                $t->unsignedBigInteger('tenant_id')->primary();
            },
            'items' => function (Blueprint $t): void {
                $t->char('id', 32)->primary();
                $t->unsignedBigInteger('tenant_id');
                $t->string('sku', 190);
                $t->string('title', 250);
                $t->string('product_key', 190);
                $t->string('variant_label', 190)->default('');
                $t->text('image_url')->nullable();
                $t->unsignedBigInteger('unit_amount_minor')->default(0);
                $t->char('currency', 3)->default('USD');
                $t->boolean('tracked')->default(true);
                $t->boolean('active')->default(true);
                $t->char('revision', 64);
                $t->longText('metadata')->nullable();
                $t->timestamps();
            },
            'pools' => function (Blueprint $t): void {
                $t->char('id', 32)->primary();
                $t->unsignedBigInteger('tenant_id');
                $t->string('name', 150);
                $t->string('kind', 20);
                $t->string('event_key', 150)->nullable();
                $t->boolean('active')->default(true);
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->timestamps();
            },
            'balances' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->char('pool_id', 32);
                $t->char('item_id', 32);
                $t->bigInteger('on_hand')->default(0);
                $t->unsignedBigInteger('reserved')->default(0);
                $t->unsignedBigInteger('version')->default(0);
                $t->timestamp('counted_at')->nullable();
                $t->timestamps();
            },
            'operations' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->char('request_hash', 64);
                $t->char('input_hash', 64);
                $t->string('kind', 30);
                $t->unsignedBigInteger('actor_id');
                $t->longText('result');
                $t->timestamp('created_at');
            },
            'movements' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->char('pool_id', 32);
                $t->char('item_id', 32);
                $t->char('operation_hash', 64);
                $t->string('reason', 40);
                $t->bigInteger('delta');
                $t->bigInteger('before_quantity');
                $t->bigInteger('after_quantity');
                $t->unsignedBigInteger('actor_id');
                $t->string('source_ref', 190)->nullable();
                $t->timestamp('created_at');
            },
            'reservations' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('hold_key', 160);
                $t->char('pool_id', 32);
                $t->char('item_id', 32);
                $t->unsignedInteger('quantity');
                $t->unsignedBigInteger('balance_version')->default(0);
                $t->timestamp('collection_locked_at')->nullable();
                $t->string('state', 20)->default('held');
                $t->timestamp('expires_at');
                $t->string('payment_ref', 190)->nullable();
                $t->timestamps();
            },
            'bindings' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('channel', 20);
                $t->string('channel_key', 150);
                $t->char('pool_id', 32);
                $t->boolean('active')->default(true);
                $t->boolean('publish_enabled')->default(false);
                $t->unsignedBigInteger('reviewed_by');
                $t->longText('settings');
                $t->timestamps();
            },
            'provider_items' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('store_id');
                $t->char('item_id', 32);
                $t->string('variant_id', 100);
                $t->string('inventory_item_id', 100)->nullable();
                $t->boolean('active')->default(true);
                $t->char('catalog_generation', 32)->nullable();
                $t->unsignedBigInteger('unit_amount_minor')->default(0);
                $t->char('revision', 64)->nullable();
                $t->string('inventory_policy', 20)->nullable();
                $t->string('location_id', 100);
                $t->bigInteger('acknowledged_quantity')->nullable();
                $t->unsignedBigInteger('acknowledged_version')->default(0);
                $t->timestamps();
                $t->bigInteger('pending_online_delta')->default(0);
            },
            'publications' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('binding_id');
                $t->char('pool_id', 32);
                $t->char('item_id', 32);
                $t->unsignedBigInteger('version');
                $t->bigInteger('quantity');
                $t->bigInteger('expected_quantity')->nullable();
                $t->char('idempotency_key', 36);
                $t->string('state', 20)->default('pending');
                $t->unsignedInteger('attempts')->default(0);
                $t->string('provider_reference', 190)->nullable();
                $t->timestamp('claimed_at')->nullable();
                $t->string('error_code', 100)->nullable();
                $t->timestamps();
            },
            'online_orders' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('store_id');
                $t->string('order_id', 100);
                $t->char('pool_id', 32);
                $t->string('state', 20);
                $t->timestamp('provider_updated_at');
                $t->longText('lines');
                $t->longText('restocks');
                $t->timestamps();
            },
            'receipt_associations' => function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('runtime_id', 64);
                $t->char('sale_id', 32);
                $t->unsignedBigInteger('customer_profile_id')->nullable();
                $t->unsignedBigInteger('actor_id');
                $t->char('request_hash', 64);
                $t->char('input_hash', 64);
                $t->longText('contact');
                $t->longText('reward_context')->nullable();
                $t->timestamps();
            },
        ];
        foreach ($definitions as $name => $definition) {
            if (! Schema::hasTable('commerce_stock_'.$name)) {
                Schema::create('commerce_stock_'.$name, $definition);
            }
        }
        foreach ([
            ['bundles', 'csu_item_uq', ['tenant_id', 'item_id'], true],
            ['event_links', 'cse_source_uq', ['tenant_id', 'provider', 'external_id'], true],
            ['event_links', 'cse_event_idx', ['tenant_id', 'event_id'], false],
            ['online_inbox', 'csi_event_uq', ['tenant_id', 'store_id', 'event_hash'], true],
            ['online_inbox', 'csi_queue_idx', ['tenant_id', 'state', 'next_attempt_at'], false],
            ['catalog_runs', 'csg_store_uq', ['tenant_id', 'store_id'], true],
            ['production_sources', 'csd_order_uq', ['order_id'], true],
            ['production_links', 'csl_source_uq', ['tenant_id', 'order_line_id'], true],
            ['items', 'csi_tenant_idx', ['tenant_id', 'active'], false],
            ['pools', 'csp_event_uq', ['tenant_id', 'event_key'], true],
            ['balances', 'csb_scope_uq', ['tenant_id', 'pool_id', 'item_id'], true],
            ['operations', 'cso_request_uq', ['tenant_id', 'request_hash'], true],
            ['movements', 'csm_scope_idx', ['tenant_id', 'pool_id', 'item_id', 'id'], false],
            ['reservations', 'csr_hold_item_uq', ['tenant_id', 'hold_key', 'item_id'], true],
            ['reservations', 'csr_expiry_idx', ['tenant_id', 'state', 'expires_at'], false],
            ['bindings', 'csc_channel_uq', ['tenant_id', 'channel', 'channel_key'], true],
            ['provider_items', 'csx_variant_uq', ['tenant_id', 'store_id', 'variant_id'], true],
            ['publications', 'css_version_uq', ['binding_id', 'item_id', 'version'], true],
            ['publications', 'css_queue_idx', ['state', 'id'], false],
            ['online_orders', 'csw_order_uq', ['tenant_id', 'store_id', 'order_id'], true],
            ['receipt_associations', 'csa_sale_uq', ['tenant_id', 'runtime_id', 'sale_id'], true],
        ] as [$table, $name, $columns, $unique]) {
            $table = 'commerce_stock_'.$table;
            $old = collect(Schema::getIndexes($table))->firstWhere('name', $name);
            if ($old && ($old['columns'] !== $columns || (bool) $old['unique'] !== $unique)) {
                throw new \RuntimeException('Stock index definition changed: '.$name);
            }
            if (! $old) {
                Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($columns, $name) : $t->index($columns, $name));
            }
        }
    }
}
