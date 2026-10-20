<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_stores')) {
            Schema::create('marketplace_stores', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->string('name', 160);
                $t->string('host', 190);
                $t->timestamp('verified_at')->nullable();
                $t->json('settings')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('marketplace_stock_reservations')) {
            Schema::create('marketplace_stock_reservations', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('checkout_id');
                $t->unsignedBigInteger('listing_id');
                $t->string('location_key', 100);
                $t->unsignedInteger('quantity');
                $t->timestamp('released_at')->nullable();
                $t->timestamp('committed_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('marketplace_payment_events')) {
            Schema::create('marketplace_payment_events', function (Blueprint $t): void {
                $t->id();
                $t->string('provider', 30);
                $t->string('event_id', 120);
                $t->string('merchant_key', 120);
                $t->string('payload_hash', 64);
                $t->longText('payload');
                $t->string('status', 30)->default('pending');
                $t->timestamp('processed_at')->nullable();
                $t->string('failure_code', 80)->nullable();
                $t->timestamps();
            });
        }
        foreach (['marketplace_checkouts', 'marketplace_orders', 'marketplace_operations'] as $table) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'connection_id');
            if (! $column['nullable']) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('connection_id')->nullable()->change());
            }
        }
        foreach (['marketplace_checkouts', 'marketplace_orders'] as $table) {
            if (! Schema::hasColumn($table, 'provider')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('provider', 30)->default('shopify'));
            }
        }
        if (! Schema::hasColumn('marketplace_listings', 'shipping_fee_cents')) {
            Schema::table('marketplace_listings', fn (Blueprint $t) => $t->unsignedInteger('shipping_fee_cents')->nullable());
        }
        foreach (['provider_session_ref', 'provider_payment_ref'] as $column) {
            if (! Schema::hasColumn('marketplace_checkouts', $column)) {
                Schema::table('marketplace_checkouts', fn (Blueprint $t) => $t->string($column, 120)->nullable());
            }
        }
        foreach ([['marketplace_stores', ['tenant_id'], 'mm_store_tenant'], ['marketplace_stores', ['host'], 'mm_store_host'], ['marketplace_stock_reservations', ['checkout_id', 'listing_id', 'location_key'], 'mm_stock_reservation'], ['marketplace_payment_events', ['provider', 'merchant_key', 'event_id'], 'mm_payment_event']] as [$table,$columns,$name]) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->unique($columns, $name));
            }
        }
        foreach (['provider_session_ref' => 'mm_native_session', 'provider_payment_ref' => 'mm_native_payment'] as $column => $key) {
            if (! Schema::hasIndex('marketplace_checkouts', $key)) {
                Schema::table('marketplace_checkouts', fn (Blueprint $t) => $t->unique($column, $key));
            }
        }
    }

    public function down(): void
    {
        // Preserve merchant bindings, payment evidence and reservations on rollback.
    }
};
