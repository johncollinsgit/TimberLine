<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'storefront_checkout_token')) {
                $table->string('storefront_checkout_token', 190)->nullable()->after('attribution_meta');
                $table->index('storefront_checkout_token', 'orders_storefront_checkout_token_idx');
            }

            if (! Schema::hasColumn('orders', 'storefront_cart_token')) {
                $table->string('storefront_cart_token', 190)->nullable()->after('storefront_checkout_token');
                $table->index('storefront_cart_token', 'orders_storefront_cart_token_idx');
            }

            if (! Schema::hasColumn('orders', 'storefront_session_key')) {
                $table->string('storefront_session_key', 190)->nullable()->after('storefront_cart_token');
                $table->index('storefront_session_key', 'orders_storefront_session_key_idx');
            }

            if (! Schema::hasColumn('orders', 'storefront_client_id')) {
                $table->string('storefront_client_id', 190)->nullable()->after('storefront_session_key');
                $table->index('storefront_client_id', 'orders_storefront_client_id_idx');
            }

            if (! Schema::hasColumn('orders', 'storefront_message_delivery_id')) {
                $table->unsignedBigInteger('storefront_message_delivery_id')->nullable()->after('storefront_client_id');
                $table->index('storefront_message_delivery_id', 'orders_storefront_message_delivery_id_idx');
            }

            if (! Schema::hasColumn('orders', 'storefront_linked_event_id')) {
                $table->unsignedBigInteger('storefront_linked_event_id')->nullable()->after('storefront_message_delivery_id');
                $table->index('storefront_linked_event_id', 'orders_storefront_linked_event_id_idx');
            }

            if (! Schema::hasColumn('orders', 'storefront_link_confidence')) {
                $table->decimal('storefront_link_confidence', 5, 2)->nullable()->after('storefront_linked_event_id');
            }

            if (! Schema::hasColumn('orders', 'storefront_link_method')) {
                $table->string('storefront_link_method', 80)->nullable()->after('storefront_link_confidence');
            }

            if (! Schema::hasColumn('orders', 'storefront_linked_at')) {
                $table->timestamp('storefront_linked_at')->nullable()->after('storefront_link_method');
            }
        });

        if (Schema::hasTable('marketing_storefront_events')
            && Schema::hasColumn('orders', 'storefront_linked_event_id')
        ) {
            try {
                Schema::table('orders', function (Blueprint $table): void {
                    $table->foreign('storefront_linked_event_id', 'orders_storefront_linked_event_fk')
                        ->references('id')
                        ->on('marketing_storefront_events')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
                // Safe no-op for environments where the FK already exists.
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (Schema::hasColumn('orders', 'storefront_linked_event_id')) {
            try {
                Schema::table('orders', function (Blueprint $table): void {
                    $table->dropForeign('orders_storefront_linked_event_fk');
                });
            } catch (\Throwable) {
                // Safe no-op when FK is absent.
            }
        }

        Schema::table('orders', function (Blueprint $table): void {
            foreach ([
                'orders_storefront_checkout_token_idx',
                'orders_storefront_cart_token_idx',
                'orders_storefront_session_key_idx',
                'orders_storefront_client_id_idx',
                'orders_storefront_message_delivery_id_idx',
                'orders_storefront_linked_event_id_idx',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                    // Safe no-op when index is absent.
                }
            }

            foreach ([
                'storefront_checkout_token',
                'storefront_cart_token',
                'storefront_session_key',
                'storefront_client_id',
                'storefront_message_delivery_id',
                'storefront_linked_event_id',
                'storefront_link_confidence',
                'storefront_link_method',
                'storefront_linked_at',
            ] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

