<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_customers')) {
            Schema::create('website_customers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'website_customers_tenant_fk')->cascadeOnDelete();
                $table->string('email', 190)->nullable();
                $table->string('first_name', 120)->nullable();
                $table->string('last_name', 120)->nullable();
                $table->string('phone', 80)->nullable();
                $table->string('status', 24)->default('active')->index();
                $table->json('notes')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'email'], 'website_customers_tenant_email_uq');
                $table->index(['tenant_id', 'created_at'], 'website_customers_tenant_created_idx');
            });
        }

        if (! Schema::hasTable('website_customer_addresses')) {
            Schema::create('website_customer_addresses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'website_addresses_tenant_fk')->cascadeOnDelete();
                $table->foreignId('website_customer_id')->constrained('website_customers', 'id', 'website_addresses_customer_fk')->cascadeOnDelete();
                $table->string('label', 80)->default('Primary');
                $table->string('name', 190)->nullable();
                $table->string('line1', 190)->nullable();
                $table->string('line2', 190)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('state', 80)->nullable();
                $table->string('postal_code', 40)->nullable();
                $table->string('country', 2)->default('US');
                $table->boolean('is_default')->default(false);
                $table->timestamps();
                $table->index(['tenant_id', 'website_customer_id'], 'website_addresses_tenant_customer_idx');
            });
        }

        if (! Schema::hasTable('website_products')) {
            Schema::create('website_products', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'website_products_tenant_fk')->cascadeOnDelete();
                $table->foreignId('tenant_site_id')->constrained('tenant_sites', 'id', 'website_products_site_fk')->cascadeOnDelete();
                $table->string('handle', 160);
                $table->string('title', 190);
                $table->string('product_type', 24)->default('physical')->index();
                $table->text('description')->nullable();
                $table->string('status', 24)->default('draft')->index();
                $table->boolean('track_inventory')->default(false);
                $table->json('media')->nullable();
                $table->json('service_details')->nullable();
                $table->json('seo')->nullable();
                $table->timestamps();
                $table->unique(['tenant_site_id', 'handle'], 'website_products_site_handle_uq');
                $table->index(['tenant_id', 'tenant_site_id', 'status'], 'website_products_tenant_site_status_idx');
            });
        }

        if (! Schema::hasTable('website_product_variants')) {
            Schema::create('website_product_variants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'website_variants_tenant_fk')->cascadeOnDelete();
                $table->foreignId('website_product_id')->constrained('website_products', 'id', 'website_variants_product_fk')->cascadeOnDelete();
                $table->string('title', 190)->default('Default');
                $table->string('sku', 120)->nullable();
                $table->unsignedInteger('price_cents');
                $table->unsignedInteger('compare_at_price_cents')->nullable();
                $table->integer('inventory_quantity')->nullable();
                $table->boolean('is_available')->default(true);
                $table->json('options')->nullable();
                $table->timestamps();
                $table->unique(['website_product_id', 'sku'], 'website_variants_product_sku_uq');
                $table->index(['tenant_id', 'website_product_id'], 'website_variants_tenant_product_idx');
            });
        }

        if (! Schema::hasTable('website_inventory_movements')) {
            Schema::create('website_inventory_movements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'website_movements_tenant_fk')->cascadeOnDelete();
                $table->foreignId('website_product_variant_id')->constrained('website_product_variants', 'id', 'website_movements_variant_fk')->cascadeOnDelete();
                $table->integer('quantity_delta');
                $table->string('reason', 40);
                $table->string('reference_type', 60)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->foreignId('actor_user_id')->nullable()->constrained('users', 'id', 'website_movements_actor_fk')->nullOnDelete();
                $table->timestamps();
                $table->index(['tenant_id', 'website_product_variant_id', 'created_at'], 'website_inventory_variant_created_idx');
            });
        }

        if (! Schema::hasTable('website_carts')) {
            Schema::create('website_carts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'website_carts_tenant_fk')->cascadeOnDelete();
                $table->foreignId('tenant_site_id')->constrained('tenant_sites', 'id', 'website_carts_site_fk')->cascadeOnDelete();
                $table->uuid('token')->unique();
                $table->string('currency', 3)->default('usd');
                $table->string('status', 24)->default('active')->index();
                $table->foreignId('website_customer_id')->nullable()->constrained('website_customers', 'id', 'website_carts_customer_fk')->nullOnDelete();
                $table->timestamps();
                $table->index(['tenant_id', 'tenant_site_id', 'status'], 'website_carts_tenant_site_status_idx');
            });
        }

        if (! Schema::hasTable('website_cart_items')) {
            Schema::create('website_cart_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'website_cart_items_tenant_fk')->cascadeOnDelete();
                $table->foreignId('website_cart_id')->constrained('website_carts', 'id', 'website_cart_items_cart_fk')->cascadeOnDelete();
                $table->foreignId('website_product_variant_id')->constrained('website_product_variants', 'id', 'website_cart_items_variant_fk')->cascadeOnDelete();
                $table->unsignedInteger('quantity');
                $table->timestamps();
                $table->unique(['website_cart_id', 'website_product_variant_id'], 'website_cart_items_cart_variant_uq');
            });
        }

        if (! Schema::hasTable('website_orders')) {
            Schema::create('website_orders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'website_orders_tenant_fk')->cascadeOnDelete();
                $table->foreignId('tenant_site_id')->constrained('tenant_sites', 'id', 'website_orders_site_fk')->cascadeOnDelete();
                $table->foreignId('website_customer_id')->nullable()->constrained('website_customers', 'id', 'website_orders_customer_fk')->nullOnDelete();
                $table->string('number', 40);
                $table->string('lookup_token', 80)->unique();
                $table->string('currency', 3)->default('usd');
                $table->string('payment_status', 32)->default('pending')->index();
                $table->string('fulfillment_status', 32)->default('unfulfilled')->index();
                $table->string('fulfillment_method', 32)->nullable();
                $table->unsignedInteger('subtotal_cents')->default(0);
                $table->unsignedInteger('tax_cents')->default(0);
                $table->unsignedInteger('total_cents')->default(0);
                $table->json('customer_snapshot')->nullable();
                $table->json('service_request')->nullable();
                $table->timestamp('paid_at')->nullable()->index();
                $table->timestamp('fulfilled_at')->nullable()->index();
                $table->timestamps();
                $table->unique(['tenant_id', 'number'], 'website_orders_tenant_number_uq');
                $table->index(['tenant_id', 'created_at'], 'website_orders_tenant_created_idx');
            });
        }

        if (! Schema::hasTable('website_order_lines')) {
            Schema::create('website_order_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'website_lines_tenant_fk')->cascadeOnDelete();
                $table->foreignId('website_order_id')->constrained('website_orders', 'id', 'website_lines_order_fk')->cascadeOnDelete();
                $table->foreignId('website_product_variant_id')->nullable()->constrained('website_product_variants', 'id', 'website_lines_variant_fk')->nullOnDelete();
                $table->string('title', 190);
                $table->string('product_type', 24);
                $table->unsignedInteger('quantity');
                $table->unsignedInteger('unit_price_cents');
                $table->unsignedInteger('line_total_cents');
                $table->json('snapshot');
                $table->timestamps();
                $table->index(['tenant_id', 'website_order_id'], 'website_lines_tenant_order_idx');
            });
        }

        if (! Schema::hasTable('website_inventory_reservations')) {
            Schema::create('website_inventory_reservations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'website_reservations_tenant_fk')->cascadeOnDelete();
                $table->foreignId('website_product_variant_id')->constrained('website_product_variants', 'id', 'website_reservations_variant_fk')->cascadeOnDelete();
                $table->foreignId('website_order_id')->constrained('website_orders', 'id', 'website_reservations_order_fk')->cascadeOnDelete();
                $table->unsignedInteger('quantity');
                $table->string('status', 24)->default('active')->index();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
                $table->unique(['website_order_id', 'website_product_variant_id'], 'website_reservation_order_variant_uq');
                $table->index(['tenant_id', 'website_product_variant_id', 'status'], 'website_reservation_variant_status_idx');
            });
        }

        if (! Schema::hasTable('website_payments')) {
            Schema::create('website_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'website_payments_tenant_fk')->cascadeOnDelete();
                $table->foreignId('website_order_id')->constrained('website_orders', 'id', 'website_payments_order_fk')->cascadeOnDelete();
                $table->string('provider', 32)->default('stripe');
                $table->string('provider_session_id', 190)->nullable();
                $table->string('provider_payment_intent_id', 190)->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->unsignedInteger('amount_cents')->default(0);
                $table->string('currency', 3)->default('usd');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['provider', 'provider_session_id'], 'website_payments_provider_session_uq');
                $table->index(['tenant_id', 'website_order_id'], 'website_payments_tenant_order_idx');
            });
        }

        if (! Schema::hasTable('website_fulfillments')) {
            Schema::create('website_fulfillments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants', 'id', 'website_fulfillments_tenant_fk')->cascadeOnDelete();
                $table->foreignId('website_order_id')->constrained('website_orders', 'id', 'website_fulfillments_order_fk')->cascadeOnDelete();
                $table->string('status', 32)->default('unfulfilled')->index();
                $table->string('method', 32);
                $table->text('note')->nullable();
                $table->foreignId('fulfilled_by_user_id')->nullable()->constrained('users', 'id', 'website_fulfillments_user_fk')->nullOnDelete();
                $table->timestamp('fulfilled_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'website_order_id'], 'website_fulfillments_tenant_order_idx');
            });
        }

        if (! Schema::hasTable('website_stripe_webhook_events')) {
            Schema::create('website_stripe_webhook_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->nullable()->constrained('tenants', 'id', 'website_stripe_events_tenant_fk')->nullOnDelete();
                $table->string('stripe_event_id', 190)->unique();
                $table->string('event_type', 100);
                $table->string('status', 24)->default('received');
                $table->json('payload');
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('website_stripe_webhook_events');
        Schema::dropIfExists('website_fulfillments');
        Schema::dropIfExists('website_payments');
        Schema::dropIfExists('website_inventory_reservations');
        Schema::dropIfExists('website_order_lines');
        Schema::dropIfExists('website_orders');
        Schema::dropIfExists('website_cart_items');
        Schema::dropIfExists('website_carts');
        Schema::dropIfExists('website_inventory_movements');
        Schema::dropIfExists('website_product_variants');
        Schema::dropIfExists('website_products');
        Schema::dropIfExists('website_customer_addresses');
        Schema::dropIfExists('website_customers');
    }
};
