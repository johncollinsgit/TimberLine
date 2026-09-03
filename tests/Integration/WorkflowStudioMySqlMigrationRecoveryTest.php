<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('resumes QuickBooks equipment source columns after MySQL retained the columns before the unique index', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    if (! Schema::hasTable('customer_equipment')) {
        Schema::create('customer_equipment', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('status', 40)->default('active');
        });
    }

    if (Schema::hasIndex('customer_equipment', 'equipment_tenant_external_unique')) {
        Schema::table('customer_equipment', function (Blueprint $table): void {
            $table->dropUnique('equipment_tenant_external_unique');
        });
    }
    foreach (['external_source', 'external_id'] as $column) {
        if (Schema::hasColumn('customer_equipment', $column)) {
            Schema::table('customer_equipment', function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }

    // Forge completed the two additive columns but stopped before the unique
    // identity index. A retry must add only the missing index and remain safe.
    Schema::table('customer_equipment', function (Blueprint $table): void {
        $table->string('external_source', 80)->nullable()->after('status');
        $table->string('external_id', 255)->nullable()->after('external_source');
    });

    $migration = require database_path('migrations/2026_09_03_150000_add_quickbooks_source_to_customer_equipment.php');
    $migration->up();
    $migration->up();

    expect(Schema::hasColumn('customer_equipment', 'external_source'))->toBeTrue()
        ->and(Schema::hasColumn('customer_equipment', 'external_id'))->toBeTrue()
        ->and(Schema::hasIndex('customer_equipment', 'equipment_tenant_external_unique'))->toBeTrue();
});

it('resumes Modern Forestry fundraiser preparation after the order table is retained', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    Schema::dropIfExists('modern_forestry_fundraiser_invoice_packages');
    Schema::dropIfExists('modern_forestry_fundraiser_orders');

    if (! Schema::hasTable('tenants')) {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
        });
    }

    // MySQL completed the first CREATE TABLE before a candidate stopped. The
    // migration must safely create the trailing package table on retry.
    Schema::create('modern_forestry_fundraiser_orders', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->string('source', 32)->default('zapier');
        $table->string('external_order_id', 190);
        $table->string('order_reference', 190)->nullable();
        $table->string('recipient_name', 190);
        $table->string('recipient_email', 255)->nullable();
        $table->string('recipient_phone', 80)->nullable();
        $table->json('shipping_address');
        $table->string('currency', 3)->default('usd');
        $table->unsignedInteger('subtotal_cents');
        $table->unsignedInteger('discount_cents')->default(0);
        $table->unsignedInteger('shipping_cents')->default(0);
        $table->unsignedInteger('tax_cents')->default(0);
        $table->unsignedInteger('total_cents');
        $table->string('status', 32)->default('needs_review');
        $table->string('fingerprint', 64);
        $table->json('line_items');
        $table->json('source_payload')->nullable();
        $table->timestamp('source_created_at')->nullable();
        $table->timestamp('received_at');
        $table->timestamp('reviewed_at')->nullable();
        $table->string('reviewed_by', 190)->nullable();
        $table->text('review_notes')->nullable();
        $table->timestamps();
        $table->foreign('tenant_id', 'mffo_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
        $table->unique(['tenant_id', 'source', 'external_order_id'], 'mffo_tenant_source_external_uq');
        $table->index(['tenant_id', 'status', 'received_at'], 'mffo_tenant_status_received_idx');
    });

    $migration = require database_path('migrations/2026_08_19_140000_create_modern_forestry_fundraiser_invoice_preparation_tables.php');
    $migration->up();

    expect(Schema::hasTable('modern_forestry_fundraiser_orders'))->toBeTrue()
        ->and(Schema::hasTable('modern_forestry_fundraiser_invoice_packages'))->toBeTrue()
        ->and(Schema::hasIndex('modern_forestry_fundraiser_orders', 'mffo_tenant_source_external_uq'))->toBeTrue()
        ->and(Schema::hasIndex('modern_forestry_fundraiser_invoice_packages', 'mffip_tenant_status_prepared_idx'))->toBeTrue();
});

it('runs cleanly and recovers the partial workflow studio migration on mysql', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    if (! Schema::hasTable('tenants')) {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
        });
    }

    Schema::create('automation_workflows', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->string('status', 30)->default('draft');
        $table->json('draft_definition');
        $table->timestamp('last_run_at')->nullable();
        $table->timestamps();
    });

    Schema::create('automation_workflow_versions', function (Blueprint $table): void {
        $table->id();
    });

    Schema::create('automation_workflow_runs', function (Blueprint $table): void {
        $table->id();
    });

    Schema::create('automation_workflow_run_steps', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('automation_workflow_run_id');
        $table->string('step_key', 100);
        $table->string('status', 30);
        $table->json('summary')->nullable();
        $table->unsignedInteger('duration_ms')->nullable();
    });

    Schema::create('automation_workflow_links', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('automation_workflow_id')->nullable();
        $table->string('source_system');
        $table->string('source_id');

        $table->unique(
            ['automation_workflow_id', 'source_system', 'source_id'],
            'automation_links_workflow_source_unique'
        );
        $table->foreign('automation_workflow_id', 'automation_workflow_links_workflow_fk')
            ->references('id')->on('automation_workflows')->nullOnDelete();
    });

    $migration = require database_path(
        'migrations/2026_07_24_120000_add_workflow_studio_v2_foundation.php'
    );

    $migration->up();

    expect(Schema::hasIndex('automation_workflow_links', 'awl_workflow_step_source_uq'))
        ->toBeTrue()
        ->and(Schema::hasIndex('automation_workflow_links', 'automation_links_workflow_source_unique'))
        ->toBeFalse()
        ->and(Schema::hasTable('automation_workflow_domain_events'))
        ->toBeTrue();

    // Reconstruct the exact schema state left by the failed Forge candidate:
    // all preceding additive DDL exists, the legacy FK-supporting index remains,
    // and the replacement index plus trailing domain-event table are absent.
    DB::statement(
        'ALTER TABLE automation_workflow_links
        ADD UNIQUE INDEX automation_links_workflow_source_unique
        (automation_workflow_id, source_system, source_id)'
    );
    DB::statement(
        'ALTER TABLE automation_workflow_links
        DROP INDEX awl_workflow_step_source_uq'
    );
    Schema::drop('automation_workflow_domain_events');

    expect(Schema::hasIndex('automation_workflow_links', 'automation_links_workflow_source_unique'))
        ->toBeTrue()
        ->and(Schema::hasIndex('automation_workflow_links', 'awl_workflow_step_source_uq'))
        ->toBeFalse()
        ->and(Schema::hasTable('automation_workflow_domain_events'))
        ->toBeFalse();

    $migration->up();

    expect(Schema::hasIndex('automation_workflow_links', 'awl_workflow_step_source_uq'))
        ->toBeTrue()
        ->and(Schema::hasIndex('automation_workflow_links', 'automation_links_workflow_source_unique'))
        ->toBeFalse()
        ->and(Schema::hasTable('automation_workflow_domain_events'))
        ->toBeTrue();
});

it('recovers Website Commerce after MySQL retains a partial reservation table', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    foreach (['website_stripe_webhook_events', 'website_fulfillments', 'website_payments', 'website_inventory_reservations', 'website_order_lines', 'website_orders', 'website_cart_items', 'website_carts', 'website_inventory_movements', 'website_product_variants', 'website_products', 'website_customer_addresses', 'website_customers'] as $table) {
        Schema::dropIfExists($table);
    }

    if (! Schema::hasTable('tenants')) {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
        });
    }

    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
    }

    if (! Schema::hasTable('tenant_sites')) {
        Schema::create('tenant_sites', function (Blueprint $table): void {
            $table->id();
        });
    }

    $commerce = require database_path('migrations/2026_07_27_180000_create_website_commerce_tables.php');
    $repair = require database_path('migrations/2026_07_27_181000_repair_partial_website_commerce_schema.php');

    $commerce->up();

    // Recreate the exact shape left by the failed production candidate: the
    // table exists, but its two trailing FKs and supporting indexes do not.
    // Use individual MySQL DDL statements here. Laravel can coalesce schema
    // changes into an order MySQL rejects when an index still supports an FK.
    // The composite variant index also supports the tenant FK, so remove and
    // restore that constraint around the simulation. The real failed table
    // retains only this tenant FK and its minimal supporting index.
    DB::statement('ALTER TABLE website_inventory_reservations DROP FOREIGN KEY website_reservations_tenant_fk');
    DB::statement('ALTER TABLE website_inventory_reservations DROP FOREIGN KEY website_reservations_variant_fk');
    DB::statement('ALTER TABLE website_inventory_reservations DROP FOREIGN KEY website_reservations_order_fk');
    DB::statement('ALTER TABLE website_inventory_reservations DROP INDEX website_reservation_order_variant_uq');
    DB::statement('ALTER TABLE website_inventory_reservations DROP INDEX website_reservation_variant_status_idx');
    DB::statement('ALTER TABLE website_inventory_reservations ADD CONSTRAINT website_reservations_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE');

    $commerce->up();
    $repair->up();

    expect(Schema::hasTable('website_stripe_webhook_events'))->toBeTrue()
        ->and(Schema::hasIndex('website_inventory_reservations', 'website_reservation_order_variant_uq'))->toBeTrue()
        ->and(Schema::hasIndex('website_inventory_reservations', 'website_reservation_variant_status_idx'))->toBeTrue()
        ->and(DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'website_inventory_reservations')
            ->where('CONSTRAINT_NAME', 'website_reservations_variant_fk')
            ->exists())->toBeTrue()
        ->and(DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'website_inventory_reservations')
            ->where('CONSTRAINT_NAME', 'website_reservations_order_fk')
            ->exists())->toBeTrue();
});

it('resumes Customer Loop after a release created its first table before migration recording', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    Schema::dropIfExists('customer_loop_actions');
    Schema::dropIfExists('customer_loop_activities');

    if (! Schema::hasTable('tenants')) {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
        });
    }

    if (! Schema::hasTable('marketing_profiles')) {
        Schema::create('marketing_profiles', function (Blueprint $table): void {
            $table->id();
        });
    }

    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
    }

    // This is the exact durable state Forge left behind: the first DDL
    // completed, but the migration itself was never recorded as complete.
    Schema::create('customer_loop_activities', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->unsignedBigInteger('marketing_profile_id')->nullable();
        $table->timestamp('occurred_at');
    });

    $migration = require database_path('migrations/2026_08_07_180000_create_customer_loop_tables.php');
    $migration->up();

    expect(Schema::hasTable('customer_loop_activities'))->toBeTrue()
        ->and(Schema::hasTable('customer_loop_actions'))->toBeTrue();
});

it('repairs the trailing Customer Loop index after MySQL stopped during action-table creation', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    Schema::dropIfExists('customer_loop_actions');
    Schema::dropIfExists('customer_loop_activities');

    Schema::create('customer_loop_activities', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->unsignedBigInteger('marketing_profile_id')->nullable();
        $table->timestamp('occurred_at');
    });
    Schema::create('customer_loop_actions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->unsignedBigInteger('marketing_profile_id')->nullable();
        $table->string('status', 32);
        $table->timestamp('due_at')->nullable();
        // This was the last successful automatic index before MySQL rejected
        // the following generated index name as too long.
        $table->index(
            ['tenant_id', 'status', 'due_at'],
            'customer_loop_actions_tenant_id_status_due_at_index'
        );
    });

    $migration = require database_path('migrations/2026_08_07_180000_create_customer_loop_tables.php');
    $migration->up();

    expect(Schema::hasIndex('customer_loop_actions', 'customer_loop_actions_tenant_id_status_due_at_index'))->toBeTrue()
        ->and(Schema::hasIndex('customer_loop_actions', 'cl_action_tenant_profile_status_idx'))->toBeTrue();
});

it('resumes field workforce and fleet tracking after retaining its first settings table', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    foreach (['fleet_location_points', 'fleet_tracking_policy_acknowledgements', 'fleet_tracking_devices', 'tenant_fleet_tracking_settings', 'field_service_time_change_requests', 'field_service_work_shifts', 'tenant_workforce_settings'] as $table) {
        Schema::dropIfExists($table);
    }
    foreach (['tenants', 'users', 'field_service_jobs', 'field_service_time_sessions', 'field_service_time_entries', 'field_service_vehicles'] as $table) {
        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
            });
        }
    }

    // Simulate the durable state after the first DDL committed but before the
    // migration record. The real migration must create every trailing table.
    Schema::create('tenant_workforce_settings', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->boolean('enforce_scheduled_clocking')->default(false);
        $table->unsignedSmallInteger('clock_early_minutes')->default(15);
        $table->unsignedSmallInteger('clock_late_minutes')->default(15);
        $table->unsignedBigInteger('updated_by_user_id')->nullable();
        $table->timestamps();
        $table->unique('tenant_id', 'tws_tenant_unique');
        $table->foreign('tenant_id', 'tws_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
        $table->foreign('updated_by_user_id', 'tws_updated_by_fk')->references('id')->on('users')->nullOnDelete();
    });

    $migration = require database_path('migrations/2026_08_13_160000_create_field_workforce_and_fleet_tracking_tables.php');
    $migration->up();

    expect(Schema::hasTable('tenant_workforce_settings'))->toBeTrue()
        ->and(Schema::hasTable('field_service_work_shifts'))->toBeTrue()
        ->and(Schema::hasTable('field_service_time_change_requests'))->toBeTrue()
        ->and(Schema::hasTable('tenant_fleet_tracking_settings'))->toBeTrue()
        ->and(Schema::hasTable('fleet_tracking_devices'))->toBeTrue()
        ->and(Schema::hasTable('fleet_tracking_policy_acknowledgements'))->toBeTrue()
        ->and(Schema::hasTable('fleet_location_points'))->toBeTrue();
});

it('keeps a retained wholesale email messenger draft table safe on retry', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    Schema::dropIfExists('wholesale_email_messenger_drafts');

    // Simulate Forge completing the table DDL before the migration record was
    // committed. The migration must remain a safe no-op on its next attempt.
    Schema::create('wholesale_email_messenger_drafts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->string('store_key', 80);
        $table->string('name', 160);
        $table->string('subject', 200);
        $table->json('sections');
        $table->json('personalization')->nullable();
        $table->unsignedInteger('revision')->default(1);
        $table->unsignedBigInteger('created_by')->nullable();
        $table->unsignedBigInteger('updated_by')->nullable();
        $table->timestamps();
    });

    $migration = require database_path('migrations/2026_08_11_120000_create_wholesale_email_messenger_drafts_table.php');
    $migration->up();

    expect(Schema::hasTable('wholesale_email_messenger_drafts'))->toBeTrue();
});

it('resumes mobile bag completion state after the first column is retained', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    if (! Schema::hasTable('modern_forestry_mobile_bag_snapshots')) {
        Schema::create('modern_forestry_mobile_bag_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('next_reminder_at')->nullable();
        });
    }

    foreach (['cart_started_at', 'completed_at'] as $column) {
        if (Schema::hasColumn('modern_forestry_mobile_bag_snapshots', $column)) {
            Schema::table('modern_forestry_mobile_bag_snapshots', function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }

    // Recreate the durable state left when Forge applies the first DDL but has
    // not yet recorded the migration or added the second completion column.
    Schema::table('modern_forestry_mobile_bag_snapshots', function (Blueprint $table): void {
        $table->timestamp('cart_started_at')->nullable()->after('last_synced_at');
    });

    $migration = require database_path(
        'migrations/2026_08_13_150000_add_completion_state_to_modern_forestry_mobile_bag_snapshots.php'
    );
    $migration->up();

    expect(Schema::hasColumn('modern_forestry_mobile_bag_snapshots', 'cart_started_at'))->toBeTrue()
        ->and(Schema::hasColumn('modern_forestry_mobile_bag_snapshots', 'completed_at'))->toBeTrue();
});

it('resumes sales-tax reporting destination fields after MySQL retains the first column', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    if (! Schema::hasTable('orders')) {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('shipping_address1')->nullable();
        });
    }

    foreach (['shipping_city', 'shipping_province', 'shipping_province_code', 'shipping_zip', 'shipping_country_code'] as $column) {
        if (Schema::hasColumn('orders', $column)) {
            Schema::table('orders', function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }

    // Simulate the durable partial state: MySQL committed the first column,
    // then the release stopped before the remaining address columns.
    Schema::table('orders', function (Blueprint $table): void {
        $table->string('shipping_city', 120)->nullable()->after('shipping_address1');
    });

    $migration = require database_path('migrations/2026_08_20_120000_add_reporting_destination_fields_to_orders_table.php');
    $migration->up();

    foreach (['shipping_city', 'shipping_province', 'shipping_province_code', 'shipping_zip', 'shipping_country_code'] as $column) {
        expect(Schema::hasColumn('orders', $column))->toBeTrue();
    }
});

it('resumes the complete Commerce foundation from durable partial MySQL state', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    foreach (['website_shipment_events', 'website_shipments', 'website_order_events', 'website_fulfillment_lines', 'website_shipping_rate_quotes', 'website_shipping_packages', 'website_fulfillment_locations', 'commerce_import_events', 'commerce_external_records', 'commerce_import_runs', 'commerce_sources'] as $table) {
        Schema::dropIfExists($table);
    }

    foreach (['tenants', 'users', 'tenant_sites'] as $tableName) {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
            });
        }
    }

    if (! Schema::hasTable('website_carts')) {
        Schema::create('website_carts', function (Blueprint $table): void {
            $table->id();
        });
    }
    if (! Schema::hasTable('website_fulfillments')) {
        Schema::create('website_fulfillments', function (Blueprint $table): void {
            $table->id();
        });
    }
    if (! Schema::hasTable('website_order_lines')) {
        Schema::create('website_order_lines', function (Blueprint $table): void {
            $table->id();
        });
    }
    if (! Schema::hasTable('website_product_variants')) {
        Schema::create('website_product_variants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('inventory_quantity')->default(0);
        });
    }
    if (! Schema::hasTable('website_orders')) {
        Schema::create('website_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('currency', 3)->default('usd');
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->json('customer_snapshot')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
        });
    }

    // The production database reached this durable first-table state during
    // an earlier failed Commerce candidate, without a migration-batch record.
    Schema::create('commerce_sources', function (Blueprint $table): void {
        $table->id();
    });

    $migration = require database_path(
        'migrations/2026_08_07_190000_add_commerce_operations_and_shipping_foundation.php'
    );
    $migration->up();

    expect(Schema::hasTable('commerce_import_runs'))->toBeTrue()
        ->and(Schema::hasTable('website_shipment_events'))->toBeTrue()
        ->and(Schema::hasColumn('website_product_variants', 'shipping_height_inches'))->toBeTrue()
        ->and(Schema::hasColumn('website_orders', 'closed_at'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('website_shipping_rate_quotes'))
            ->contains(fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['website_fulfillment_location_id']))
        ->toBeTrue();

    // Reconstruct the later failure point directly. MySQL had created the
    // table and its earlier foreign keys, then rejected Laravel's 68-character
    // location FK name before the trailing indexes were added.
    Schema::drop('website_shipping_rate_quotes');
    Schema::create('website_shipping_rate_quotes', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('tenant_id');
        $table->foreignId('tenant_site_id');
        $table->foreignId('website_cart_id');
        $table->foreignId('website_fulfillment_location_id');
        $table->timestamp('expires_at');
        $table->foreign('tenant_id', 'website_rate_quotes_tenant_fk')
            ->references('id')->on('tenants')->cascadeOnDelete();
        $table->foreign('tenant_site_id', 'website_rate_quotes_site_fk')
            ->references('id')->on('tenant_sites')->cascadeOnDelete();
        $table->foreign('website_cart_id', 'website_rate_quotes_cart_fk')
            ->references('id')->on('website_carts')->cascadeOnDelete();
    });

    $migration->up();

    expect(collect(Schema::getForeignKeys('website_shipping_rate_quotes'))
        ->contains(fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['website_fulfillment_location_id']))
        ->toBeTrue()
        ->and(Schema::hasIndex('website_shipping_rate_quotes', 'website_rate_quotes_expires_idx'))->toBeTrue()
        ->and(Schema::hasIndex('website_shipping_rate_quotes', 'website_rate_quotes_tenant_cart_expiry_idx'))->toBeTrue();
});

it('resumes marketing profile archival after the additive column is retained', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    if (! Schema::hasTable('marketing_profiles')) {
        Schema::create('marketing_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable();
            $table->timestamp('merged_at')->nullable();
        });
    } elseif (! Schema::hasColumn('marketing_profiles', 'merged_at')) {
        Schema::table('marketing_profiles', function (Blueprint $table): void {
            $table->timestamp('merged_at')->nullable();
        });
    }

    if (Schema::hasColumn('marketing_profiles', 'archived_at')) {
        Schema::table('marketing_profiles', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }

    $migration = require database_path('migrations/2026_08_24_120000_add_archival_to_marketing_profiles.php');
    $migration->up();

    // Re-run after the first DDL step was retained but before Laravel records
    // its migration batch. The guard must make the retry safe.
    $migration->up();

    expect(Schema::hasColumn('marketing_profiles', 'archived_at'))->toBeTrue();
});

it('resumes the job-update SMS setting after its column is retained', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    if (! Schema::hasTable('field_service_reminder_settings')) {
        Schema::create('field_service_reminder_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();
        });
    }

    if (Schema::hasColumn('field_service_reminder_settings', 'job_update_sms')) {
        Schema::table('field_service_reminder_settings', function (Blueprint $table): void {
            $table->dropColumn('job_update_sms');
        });
    }

    $migration = require database_path('migrations/2026_08_25_150000_add_job_update_sms_setting_to_field_service_reminder_settings.php');
    $migration->up();

    // A deploy may stop after MySQL has committed the DDL but before Laravel
    // records the migration. A retry must safely retain the existing column.
    $migration->up();

    expect(Schema::hasColumn('field_service_reminder_settings', 'job_update_sms'))->toBeTrue();
});

it('resumes marketing groups after MySQL rejects the legacy import-row foreign-key name', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    foreach (['marketing_group_import_rows', 'marketing_group_import_runs', 'marketing_campaign_groups', 'marketing_group_members', 'marketing_groups'] as $table) {
        Schema::dropIfExists($table);
    }

    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
    }

    if (! Schema::hasTable('marketing_profiles')) {
        Schema::create('marketing_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('normalized_phone')->nullable();
        });
    } elseif (! Schema::hasColumn('marketing_profiles', 'normalized_phone')) {
        Schema::table('marketing_profiles', function (Blueprint $table): void {
            $table->string('normalized_phone')->nullable();
        });
    }

    if (! Schema::hasTable('marketing_campaigns')) {
        Schema::create('marketing_campaigns', function (Blueprint $table): void {
            $table->id();
        });
    }

    $migrationPath = 'migrations/2026_03_12_090000_add_marketing_groups_and_addresses.php';
    $migration = require database_path($migrationPath);
    $migration->up();

    // The first four CREATE TABLE statements are durable if MySQL rejects the
    // final table. Recreate that exact retry boundary.
    Schema::drop('marketing_group_import_rows');
    $migration->up();

    expect(Schema::hasTable('marketing_group_import_rows'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('marketing_group_import_rows'))
            ->contains(fn (array $foreignKey): bool => ($foreignKey['name'] ?? null) === 'mgir_import_run_fk'))
        ->toBeTrue();
});

it('resumes message groups after MySQL rejects the legacy member foreign-key name', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    Schema::dropIfExists('marketing_message_group_members');
    Schema::dropIfExists('marketing_message_groups');

    $migrationPath = 'migrations/2026_03_19_090000_create_marketing_message_group_tables.php';
    $migration = require database_path($migrationPath);
    $migration->up();

    // The first table remains after the second CREATE TABLE statement fails.
    Schema::drop('marketing_message_group_members');
    $migration->up();

    expect(Schema::hasTable('marketing_message_groups'))->toBeTrue()
        ->and(Schema::hasTable('marketing_message_group_members'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('marketing_message_group_members'))
            ->contains(fn (array $foreignKey): bool => ($foreignKey['name'] ?? null) === 'mmgm_message_group_fk'))
        ->toBeTrue();
});

it('creates external customer profiles with a composite key below the MySQL byte limit', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    Schema::dropIfExists('customer_external_profiles');

    $migrationPath = 'migrations/2026_03_16_090000_create_customer_external_profiles_table.php';
    $migration = require database_path($migrationPath);
    $migration->up();
    $migration->up();

    $lengths = DB::table('information_schema.COLUMNS')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', 'customer_external_profiles')
        ->whereIn('COLUMN_NAME', ['provider', 'integration', 'store_key', 'external_customer_id'])
        ->pluck('CHARACTER_MAXIMUM_LENGTH', 'COLUMN_NAME');

    expect(Schema::hasIndex('customer_external_profiles', 'cep_provider_integration_store_customer_unique'))->toBeTrue()
        ->and((int) $lengths['provider'])->toBe(80)
        ->and((int) $lengths['integration'])->toBe(80)
        ->and((int) $lengths['store_key'])->toBe(80)
        ->and((int) $lengths['external_customer_id'])->toBe(120);
});

it('resumes tenant reward overrides after MySQL rejects the legacy reward foreign-key name', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    foreach (['tenant_candle_cash_reward_overrides', 'tenant_candle_cash_task_overrides', 'tenant_marketing_settings'] as $table) {
        Schema::dropIfExists($table);
    }

    foreach (['tenants', 'candle_cash_tasks', 'candle_cash_rewards'] as $tableName) {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
            });
        }
    }

    $migrationPath = 'migrations/2026_03_30_100000_create_tenant_rewards_editor_isolation_tables.php';
    $migration = require database_path($migrationPath);
    $migration->up();

    // The first two settings tables remain if creation of the third fails.
    Schema::drop('tenant_candle_cash_reward_overrides');
    $migration->up();

    expect(Schema::hasTable('tenant_candle_cash_reward_overrides'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('tenant_candle_cash_reward_overrides'))
            ->contains(fn (array $foreignKey): bool => ($foreignKey['name'] ?? null) === 'tenant_cc_reward_override_reward_fk'))
        ->toBeTrue();
});

it('creates billing refunds with an identifier below the MySQL limit', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    Schema::dropIfExists('tenant_billing_refunds');

    foreach (['tenants', 'tenant_billing_receipts', 'tenant_billing_orders', 'tenant_direct_invoices', 'users'] as $tableName) {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
            });
        }
    }

    $migrationPath = 'migrations/2026_07_20_230000_create_tenant_billing_refunds_table.php';
    $migration = require database_path($migrationPath);
    $migration->up();
    $migration->up();

    expect(Schema::hasIndex('tenant_billing_refunds', 'billing_refunds_receipt_created_idx'))->toBeTrue();
});
