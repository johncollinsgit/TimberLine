<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commerce_sources')) {
            Schema::create('commerce_sources', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('integration_connection_id')->nullable()->constrained('integration_connections')->nullOnDelete();
                $table->string('provider', 32);
                $table->string('external_account_id', 190)->nullable();
                $table->string('external_account_label', 190)->nullable();
                $table->string('mode', 32)->default('connected_operations');
                $table->string('status', 32)->default('draft')->index();
                $table->json('capabilities')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('last_imported_at')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'provider', 'external_account_id'], 'commerce_sources_tenant_provider_account_uq');
            });
        }

        if (! Schema::hasTable('commerce_import_runs')) {
            Schema::create('commerce_import_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('commerce_source_id')->constrained()->cascadeOnDelete();
                $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('mode', 24)->default('dry_run');
                $table->string('status', 32)->default('queued')->index();
                $table->json('requested_resources')->nullable();
                $table->json('counts')->nullable();
                $table->json('report')->nullable();
                $table->text('cursor')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('paused_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'commerce_source_id', 'created_at'], 'commerce_import_runs_tenant_source_created_idx');
            });
        }

        if (! Schema::hasTable('commerce_external_records')) {
            Schema::create('commerce_external_records', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('commerce_source_id')->constrained()->cascadeOnDelete();
                $table->string('resource_type', 48);
                $table->string('external_id', 190);
                $table->string('external_parent_id', 190)->nullable();
                $table->string('fingerprint', 64);
                $table->timestamp('source_updated_at')->nullable();
                $table->json('snapshot')->nullable();
                $table->timestamp('imported_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
                $table->unique(['commerce_source_id', 'resource_type', 'external_id'], 'commerce_external_records_source_resource_external_uq');
                $table->index(['tenant_id', 'resource_type', 'imported_at'], 'commerce_external_records_tenant_resource_imported_idx');
            });
        }

        if (! Schema::hasTable('commerce_import_events')) {
            Schema::create('commerce_import_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('commerce_import_run_id')->constrained()->cascadeOnDelete();
                $table->string('event_type', 64);
                $table->string('status', 32)->default('recorded');
                $table->text('message');
                $table->json('context')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'commerce_import_run_id', 'created_at'], 'commerce_import_events_tenant_run_created_idx');
            });
        }

        if (! Schema::hasTable('website_fulfillment_locations')) {
            Schema::create('website_fulfillment_locations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tenant_site_id')->constrained('tenant_sites')->cascadeOnDelete();
                $table->string('name', 190);
                $table->json('address');
                $table->boolean('is_default')->default(false);
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index(['tenant_id', 'tenant_site_id', 'active'], 'website_locations_tenant_site_active_idx');
            });
        }

        if (! Schema::hasTable('website_shipping_packages')) {
            Schema::create('website_shipping_packages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tenant_site_id')->constrained('tenant_sites')->cascadeOnDelete();
                $table->string('name', 190);
                $table->unsignedInteger('length_inches');
                $table->unsignedInteger('width_inches');
                $table->unsignedInteger('height_inches');
                $table->unsignedInteger('weight_ounces');
                $table->boolean('is_default')->default(false);
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index(['tenant_id', 'tenant_site_id', 'active'], 'website_packages_tenant_site_active_idx');
            });
        }

        if (! Schema::hasTable('website_shipping_rate_quotes')) {
            Schema::create('website_shipping_rate_quotes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tenant_site_id')->constrained('tenant_sites')->cascadeOnDelete();
                $table->foreignId('website_cart_id')->constrained('website_carts')->cascadeOnDelete();
                $table->foreignId('website_fulfillment_location_id');
                $table->string('provider', 32)->default('easypost');
                $table->string('provider_shipment_id', 190);
                $table->string('provider_rate_id', 190);
                $table->string('carrier', 64);
                $table->string('service', 120);
                $table->unsignedInteger('amount_cents');
                $table->string('currency', 3)->default('usd');
                $table->integer('delivery_days')->nullable();
                $table->json('destination');
                $table->json('parcel');
                $table->timestamp('expires_at')->index();
                $table->timestamps();
                $table->foreign('website_fulfillment_location_id', 'website_rate_quotes_location_fk')
                    ->references('id')->on('website_fulfillment_locations')->cascadeOnDelete();
                $table->index(['tenant_id', 'website_cart_id', 'expires_at'], 'website_rate_quotes_tenant_cart_expiry_idx');
            });
        }

        // MySQL limits identifiers to 64 characters. Laravel's generated
        // foreign-key name for this column is 68 characters, which can leave
        // the table durable but the constraint absent when a release stops.
        if (! $this->hasForeignKey('website_shipping_rate_quotes', ['website_fulfillment_location_id'])) {
            Schema::table('website_shipping_rate_quotes', function (Blueprint $table): void {
                $table->foreign('website_fulfillment_location_id', 'website_rate_quotes_location_fk')
                    ->references('id')->on('website_fulfillment_locations')->cascadeOnDelete();
            });
        }
        $this->ensureIndex(
            'website_shipping_rate_quotes',
            ['expires_at'],
            'website_rate_quotes_expires_idx',
            'website_shipping_rate_quotes_expires_at_index'
        );
        $this->ensureIndex(
            'website_shipping_rate_quotes',
            ['tenant_id', 'website_cart_id', 'expires_at'],
            'website_rate_quotes_tenant_cart_expiry_idx'
        );

        if (! Schema::hasTable('website_fulfillment_lines')) {
            Schema::create('website_fulfillment_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('website_fulfillment_id')->constrained('website_fulfillments')->cascadeOnDelete();
                $table->foreignId('website_order_line_id')->constrained('website_order_lines')->cascadeOnDelete();
                $table->unsignedInteger('quantity');
                $table->timestamps();
                $table->unique(['website_fulfillment_id', 'website_order_line_id'], 'website_fulfillment_lines_fulfillment_line_uq');
            });
        }

        if (! Schema::hasTable('website_order_events')) {
            Schema::create('website_order_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('website_order_id')->constrained('website_orders')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event_type', 64);
                $table->string('visibility', 24)->default('staff');
                $table->text('message');
                $table->json('data')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'website_order_id', 'created_at'], 'website_order_events_tenant_order_created_idx');
            });
        }

        if (! Schema::hasTable('website_shipments')) {
            Schema::create('website_shipments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('website_order_id')->constrained('website_orders')->cascadeOnDelete();
                $table->foreignId('website_fulfillment_id')->constrained('website_fulfillments')->cascadeOnDelete();
                $table->foreignId('website_fulfillment_location_id')->nullable()->constrained('website_fulfillment_locations')->nullOnDelete();
                $table->string('provider', 32)->default('easypost');
                $table->string('provider_shipment_id', 190)->nullable()->unique();
                $table->string('provider_rate_id', 190)->nullable();
                $table->string('carrier', 64)->nullable();
                $table->string('service', 120)->nullable();
                $table->string('tracking_number', 190)->nullable()->index();
                $table->string('tracking_url', 2048)->nullable();
                $table->string('label_url', 2048)->nullable();
                $table->unsignedInteger('label_cost_cents')->nullable();
                $table->string('currency', 3)->default('usd');
                $table->string('status', 32)->default('pending')->index();
                $table->json('destination')->nullable();
                $table->json('parcel')->nullable();
                $table->timestamp('purchased_at')->nullable();
                $table->timestamp('voided_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'website_order_id', 'status'], 'website_shipments_tenant_order_status_idx');
            });
        }

        if (! Schema::hasTable('website_shipment_events')) {
            Schema::create('website_shipment_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('website_shipment_id')->constrained('website_shipments')->cascadeOnDelete();
                $table->string('provider_event_id', 190)->nullable();
                $table->string('event_type', 100);
                $table->string('status', 64)->nullable();
                $table->text('message')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->timestamps();
                $table->unique(['website_shipment_id', 'provider_event_id'], 'website_shipment_events_shipment_provider_event_uq');
            });
        }

        if (! Schema::hasColumn('website_product_variants', 'shipping_weight_ounces')) {
            Schema::table('website_product_variants', function (Blueprint $table): void {
                $table->unsignedInteger('shipping_weight_ounces')->nullable()->after('inventory_quantity');
            });
        }
        if (! Schema::hasColumn('website_product_variants', 'shipping_length_inches')) {
            Schema::table('website_product_variants', function (Blueprint $table): void {
                $table->unsignedInteger('shipping_length_inches')->nullable()->after('shipping_weight_ounces');
            });
        }
        if (! Schema::hasColumn('website_product_variants', 'shipping_width_inches')) {
            Schema::table('website_product_variants', function (Blueprint $table): void {
                $table->unsignedInteger('shipping_width_inches')->nullable()->after('shipping_length_inches');
            });
        }
        if (! Schema::hasColumn('website_product_variants', 'shipping_height_inches')) {
            Schema::table('website_product_variants', function (Blueprint $table): void {
                $table->unsignedInteger('shipping_height_inches')->nullable()->after('shipping_width_inches');
            });
        }

        if (! Schema::hasColumn('website_orders', 'order_status')) {
            Schema::table('website_orders', function (Blueprint $table): void {
                $table->string('order_status', 32)->default('open')->index()->after('currency');
            });
        }
        if (! Schema::hasColumn('website_orders', 'source')) {
            Schema::table('website_orders', function (Blueprint $table): void {
                $table->string('source', 32)->default('native')->index()->after('order_status');
            });
        }
        if (! Schema::hasColumn('website_orders', 'risk_status')) {
            Schema::table('website_orders', function (Blueprint $table): void {
                $table->string('risk_status', 32)->default('normal')->index()->after('source');
            });
        }
        if (! Schema::hasColumn('website_orders', 'review_status')) {
            Schema::table('website_orders', function (Blueprint $table): void {
                $table->string('review_status', 32)->default('none')->index()->after('risk_status');
            });
        }
        if (! Schema::hasColumn('website_orders', 'exception_status')) {
            Schema::table('website_orders', function (Blueprint $table): void {
                $table->string('exception_status', 32)->default('none')->index()->after('review_status');
            });
        }
        if (! Schema::hasColumn('website_orders', 'discount_cents')) {
            Schema::table('website_orders', function (Blueprint $table): void {
                $table->unsignedInteger('discount_cents')->default(0)->after('subtotal_cents');
            });
        }
        if (! Schema::hasColumn('website_orders', 'shipping_cents')) {
            Schema::table('website_orders', function (Blueprint $table): void {
                $table->unsignedInteger('shipping_cents')->default(0)->after('tax_cents');
            });
        }
        if (! Schema::hasColumn('website_orders', 'refunded_cents')) {
            Schema::table('website_orders', function (Blueprint $table): void {
                $table->unsignedInteger('refunded_cents')->default(0)->after('total_cents');
            });
        }
        if (! Schema::hasColumn('website_orders', 'shipping_address')) {
            Schema::table('website_orders', function (Blueprint $table): void {
                $table->json('shipping_address')->nullable()->after('customer_snapshot');
            });
        }
        if (! Schema::hasColumn('website_orders', 'billing_address')) {
            Schema::table('website_orders', function (Blueprint $table): void {
                $table->json('billing_address')->nullable()->after('shipping_address');
            });
        }
        if (! Schema::hasColumn('website_orders', 'shipping_rate_snapshot')) {
            Schema::table('website_orders', function (Blueprint $table): void {
                $table->json('shipping_rate_snapshot')->nullable()->after('billing_address');
            });
        }
        if (! Schema::hasColumn('website_orders', 'cancelled_at')) {
            Schema::table('website_orders', function (Blueprint $table): void {
                $table->timestamp('cancelled_at')->nullable()->index()->after('fulfilled_at');
            });
        }
        if (! Schema::hasColumn('website_orders', 'closed_at')) {
            Schema::table('website_orders', function (Blueprint $table): void {
                $table->timestamp('closed_at')->nullable()->index()->after('cancelled_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('website_orders', function (Blueprint $table): void {
            $table->dropColumn(['order_status', 'source', 'risk_status', 'review_status', 'exception_status', 'discount_cents', 'shipping_cents', 'refunded_cents', 'shipping_address', 'billing_address', 'shipping_rate_snapshot', 'cancelled_at', 'closed_at']);
        });
        Schema::table('website_product_variants', function (Blueprint $table): void {
            $table->dropColumn(['shipping_weight_ounces', 'shipping_length_inches', 'shipping_width_inches', 'shipping_height_inches']);
        });
        Schema::dropIfExists('website_shipment_events');
        Schema::dropIfExists('website_shipments');
        Schema::dropIfExists('website_fulfillment_lines');
        Schema::dropIfExists('website_order_events');
        Schema::dropIfExists('website_shipping_rate_quotes');
        Schema::dropIfExists('website_shipping_packages');
        Schema::dropIfExists('website_fulfillment_locations');
        Schema::dropIfExists('commerce_import_events');
        Schema::dropIfExists('commerce_external_records');
        Schema::dropIfExists('commerce_import_runs');
        Schema::dropIfExists('commerce_sources');
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasForeignKey(string $table, array $columns): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureIndex(string $tableName, array $columns, string $indexName, ?string $legacyIndexName = null): void
    {
        if (Schema::hasIndex($tableName, $indexName)
            || ($legacyIndexName !== null && Schema::hasIndex($tableName, $legacyIndexName))) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }
};
