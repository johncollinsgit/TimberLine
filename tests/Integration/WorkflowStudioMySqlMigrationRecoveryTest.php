<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('resumes fundraiser encrypted storage repair after a partial MySQL conversion', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    if (! Schema::hasTable('tenants')) {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
        });
    }
    $foundation = require database_path('migrations/2026_08_19_140000_create_modern_forestry_fundraiser_invoice_preparation_tables.php');
    $foundation->up();

    $columns = [
        'modern_forestry_fundraiser_orders' => ['recipient_name', 'recipient_email', 'recipient_phone', 'shipping_address', 'line_items', 'source_payload'],
        'modern_forestry_fundraiser_invoice_packages' => ['invoice_lines'],
    ];
    foreach ($columns as $tableName => $columnNames) {
        expect(Schema::hasTable($tableName))->toBeTrue();
        foreach ($columnNames as $columnName) {
            expect(Schema::hasColumn($tableName, $columnName))->toBeTrue();
        }
    }

    // The first ALTER survived, but Laravel did not record the migration.
    Schema::table('modern_forestry_fundraiser_orders', function (Blueprint $table): void {
        $table->longText('recipient_name')->change();
    });
    $migration = require database_path('migrations/2026_10_02_190000_repair_fundraiser_encrypted_column_storage.php');
    $migration->up();
    $migration->up();

    foreach ($columns as $tableName => $columnNames) {
        foreach ($columnNames as $columnName) {
            expect(Schema::getColumnType($tableName, $columnName))->toBe('longtext');
        }
    }
});

it('resumes Website encrypted column repair after MySQL retains an early column conversion', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    $columns = [
        'website_orders' => ['customer_snapshot', 'shipping_address', 'billing_address', 'service_request'],
        'website_order_events' => ['data'],
        'website_fulfillment_locations' => ['address'],
        'website_shipping_rate_quotes' => ['destination'],
        'website_shipments' => ['destination'],
        'website_shipment_events' => ['payload'],
    ];
    foreach ($columns as $tableName => $columnNames) {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
            });
        }
        foreach ($columnNames as $columnName) {
            if (! Schema::hasColumn($tableName, $columnName)) {
                Schema::table($tableName, function (Blueprint $table) use ($columnName): void {
                    $table->json($columnName)->nullable();
                });
            }
            if (Schema::getColumnType($tableName, $columnName) !== 'json') {
                Schema::table($tableName, function (Blueprint $table) use ($tableName, $columnName): void {
                    $column = $table->json($columnName);
                    if (! in_array($tableName.'.'.$columnName, ['website_fulfillment_locations.address', 'website_shipping_rate_quotes.destination'], true)) {
                        $column->nullable();
                    }
                    $column->change();
                });
            }
        }
    }

    // The first ALTER survived, but Laravel did not record the migration.
    Schema::table('website_orders', function (Blueprint $table): void {
        $table->longText('customer_snapshot')->nullable()->change();
    });

    $migration = require database_path('migrations/2026_10_02_120000_repair_website_encrypted_column_storage.php');
    $migration->up();
    $migration->up();

    foreach ($columns as $tableName => $columnNames) {
        foreach ($columnNames as $columnName) {
            expect(Schema::getColumnType($tableName, $columnName))->toBe('longtext');
        }
    }
});

uses(Tests\TestCase::class);

it('resumes crew status creation after MySQL retains the table', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    Schema::dropIfExists('field_service_crew_statuses');
    foreach (['tenants', 'users', 'field_service_jobs', 'tenant_direct_invoices'] as $tableName) {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
            });
        }
    }
    $migration = require database_path('migrations/2026_09_05_180000_create_field_service_crew_statuses_table.php');
    $migration->up();
    Schema::drop('tenant_ai_usage_events');
    $migration->up();

    expect(Schema::hasTable('field_service_crew_statuses'))->toBeTrue()
        ->and(Schema::hasTable('tenant_ai_usage_events'))->toBeTrue()
        ->and(Schema::hasIndex('field_service_crew_statuses', 'fs_crew_status_tenant_user_unique'))->toBeTrue()
        ->and(Schema::hasIndex('field_service_crew_statuses', 'fs_crew_status_tenant_state_idx'))->toBeTrue()
        ->and(Schema::hasIndex('tenant_ai_usage_events', 'tenant_ai_usage_tenant_client_unique'))->toBeTrue();
});

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

it('resumes fundraiser QuickBooks delivery columns after a partial MySQL migration', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }
    $base = require database_path('migrations/2026_08_19_140000_create_modern_forestry_fundraiser_invoice_preparation_tables.php');
    $base->up();
    if (! Schema::hasColumn('modern_forestry_fundraiser_invoice_packages', 'quickbooks_invoice_id')) {
        Schema::table('modern_forestry_fundraiser_invoice_packages', fn (Blueprint $table) => $table->string('quickbooks_invoice_id', 190)->nullable());
    }
    $migration = require database_path('migrations/2026_09_30_180000_add_quickbooks_delivery_to_fundraiser_invoice_packages.php');
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('modern_forestry_fundraiser_invoice_packages', 'quickbooks_sent_at'))->toBeTrue()
        ->and(Schema::hasColumn('modern_forestry_fundraiser_invoice_packages', 'quickbooks_last_error'))->toBeTrue()
        ->and(Schema::hasIndex('modern_forestry_fundraiser_invoice_packages', 'mffip_tenant_qb_invoice_uq'))->toBeTrue();
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

it('resumes owner approval fields after MySQL retains the first column', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    $foreign = collect(Schema::getForeignKeys('tenant_fleet_tracking_settings'))->first(
        fn (array $foreign): bool => in_array('approved_by_user_id', (array) ($foreign['columns'] ?? []), true)
    );
    if (is_array($foreign) && is_string($foreign['name'] ?? null)) {
        Schema::table('tenant_fleet_tracking_settings', function (Blueprint $table) use ($foreign): void {
            $table->dropForeign($foreign['name']);
        });
    }
    $existing = collect(['approval_basis', 'approval_reference', 'approved_at', 'approved_by_user_id'])
        ->filter(fn (string $column): bool => Schema::hasColumn('tenant_fleet_tracking_settings', $column))
        ->values()->all();
    if ($existing !== []) {
        Schema::table('tenant_fleet_tracking_settings', function (Blueprint $table) use ($existing): void {
            $table->dropColumn($existing);
        });
    }
    Schema::table('tenant_fleet_tracking_settings', function (Blueprint $table): void {
        $table->string('approval_basis', 24)->nullable()->after('policy_sha256');
    });

    $migration = require database_path('migrations/2026_09_05_210000_add_owner_approval_to_fleet_tracking_settings.php');
    $migration->up();

    expect(Schema::hasColumn('tenant_fleet_tracking_settings', 'approval_basis'))->toBeTrue()
        ->and(Schema::hasColumn('tenant_fleet_tracking_settings', 'approval_reference'))->toBeTrue()
        ->and(Schema::hasColumn('tenant_fleet_tracking_settings', 'approved_at'))->toBeTrue()
        ->and(Schema::hasColumn('tenant_fleet_tracking_settings', 'approved_by_user_id'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('tenant_fleet_tracking_settings'))->contains(
            fn (array $foreign): bool => in_array('approved_by_user_id', (array) ($foreign['columns'] ?? []), true)
        ))->toBeTrue();
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

it('resumes material requester attribution after its column is retained', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    if (! Schema::hasTable('users')) {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
    }
    if (! Schema::hasTable('field_service_materials')) {
        Schema::create('field_service_materials', function (Blueprint $table): void {
            $table->id();
        });
    }

    if (Schema::hasColumn('field_service_materials', 'requested_by_user_id')) {
        $requesterForeign = collect(Schema::getForeignKeys('field_service_materials'))->first(
            fn (array $foreign): bool => in_array('requested_by_user_id', (array) ($foreign['columns'] ?? []), true)
        );
        if (is_array($requesterForeign)) {
            Schema::table('field_service_materials', function (Blueprint $table) use ($requesterForeign): void {
                $table->dropForeign((string) $requesterForeign['name']);
            });
        }
        if (Schema::hasIndex('field_service_materials', 'fs_material_requester_idx')) {
            Schema::table('field_service_materials', function (Blueprint $table): void {
                $table->dropIndex('fs_material_requester_idx');
            });
        }
        Schema::table('field_service_materials', function (Blueprint $table): void {
            $table->dropColumn('requested_by_user_id');
        });
    }

    // Simulate MySQL retaining the first ALTER TABLE before the release
    // process records the migration batch.
    Schema::table('field_service_materials', function (Blueprint $table): void {
        $table->unsignedBigInteger('requested_by_user_id')->nullable();
    });

    $migration = require database_path('migrations/2026_09_03_200000_add_requester_to_field_service_materials.php');
    $migration->up();
    $migration->up();

    expect(Schema::hasIndex('field_service_materials', 'fs_material_requester_idx'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('field_service_materials'))->contains(
            fn (array $foreign): bool => in_array('requested_by_user_id', (array) ($foreign['columns'] ?? []), true)
        ))->toBeTrue();
});

it('skips incomplete time session tables and resumes after the reporting index is retained', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    if (! Schema::hasTable('field_service_time_sessions')) {
        Schema::create('field_service_time_sessions', function (Blueprint $table): void {
            $table->id();
        });
    }

    $migration = require database_path('migrations/2026_09_03_201000_add_time_hours_reporting_index.php');
    if (! Schema::hasColumn('field_service_time_sessions', 'tenant_id')
        || ! Schema::hasColumn('field_service_time_sessions', 'clocked_in_at')) {
        $migration->up();
        $migration->up();
        expect(Schema::hasIndex('field_service_time_sessions', 'fs_time_tenant_clocked_idx'))->toBeFalse();
    }

    if (! Schema::hasColumn('field_service_time_sessions', 'tenant_id')) {
        Schema::table('field_service_time_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id');
        });
    }
    if (! Schema::hasColumn('field_service_time_sessions', 'clocked_in_at')) {
        Schema::table('field_service_time_sessions', function (Blueprint $table): void {
            $table->timestamp('clocked_in_at');
        });
    }

    if (Schema::hasIndex('field_service_time_sessions', 'fs_time_tenant_clocked_idx')) {
        Schema::table('field_service_time_sessions', function (Blueprint $table): void {
            $table->dropIndex('fs_time_tenant_clocked_idx');
        });
    }

    // Build the index only after the source columns exist, then simulate MySQL
    // retaining that DDL while the migration row is absent by invoking it again.
    $migration->up();
    expect(Schema::hasIndex('field_service_time_sessions', 'fs_time_tenant_clocked_idx'))->toBeTrue();
    $migration->up();

    expect(Schema::hasIndex('field_service_time_sessions', 'fs_time_tenant_clocked_idx'))->toBeTrue();
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

it('resumes trajectory after MySQL retains finance accounts before trailing financial tables', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    $migration = require database_path('migrations/2026_09_14_120000_create_trajectory_tables.php');
    $migration->up();
    DB::table('trajectory_spaces')->insert(['tenant_id' => 99123, 'owner_user_id' => 99123, 'kind' => 'household', 'name' => 'Recovery fixture', 'enabled' => false]);
    $id = DB::table('trajectory_spaces')->where('tenant_id', 99123)->value('id');
    Schema::drop('trajectory_snapshots');
    Schema::drop('trajectory_notifications');
    $migration->up();
    $migration->up();
    expect(DB::table('trajectory_spaces')->where('id', $id)->value('name'))->toBe('Recovery fixture')
        ->and(Schema::hasTable('trajectory_snapshots'))->toBeTrue()
        ->and(Schema::hasTable('trajectory_notifications'))->toBeTrue()
        ->and(Schema::hasIndex('trajectory_transactions', 'traj_tx_source'))->toBeTrue()
        ->and(Schema::hasIndex('trajectory_allocations', 'traj_allocation_unique'))->toBeTrue();
    DB::table('trajectory_spaces')->where('id', $id)->delete();
});

it('resumes website collections after MySQL retains the collection table', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }
    Schema::dropIfExists('website_collection_products');
    Schema::dropIfExists('website_collections');
    foreach (['tenants', 'tenant_sites', 'website_products'] as $name) {
        if (! Schema::hasTable($name)) {
            Schema::create($name, fn (Blueprint $table) => $table->id());
        }
    }
    $migration = require database_path('migrations/2026_09_21_210000_create_website_collections.php');
    $migration->up();
    Schema::drop('website_collection_products');
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('website_collections', 'wc_site_handle_uq'))->toBeTrue()
        ->and(Schema::hasIndex('website_collection_products', 'wcp_collection_product_uq'))->toBeTrue();
});

it('repairs native Website notification constraints after MySQL retains only the outbox table', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires disposable MySQL.');
    }
    foreach (['tenants', 'tenant_sites', 'website_orders', 'website_shipments'] as $name) {
        if (! Schema::hasTable($name)) {
            Schema::create($name, fn (Blueprint $table) => $table->id());
        }
    }
    $migration = require database_path('migrations/2026_10_15_210000_create_website_order_notifications.php');
    Schema::dropIfExists('website_order_notifications');
    // Exact durable boundary: CREATE TABLE committed, but none of Laravel's
    // following named FK/index ALTER statements or migration bookkeeping ran.
    Schema::create('website_order_notifications', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->unsignedBigInteger('website_order_id');
        $table->unsignedBigInteger('website_shipment_id')->nullable();
        $table->char('transition_key', 64);
        $table->string('kind', 32);
        $table->string('status', 24)->default('pending');
        $table->longText('snapshot');
        $table->unsignedSmallInteger('attempts')->default(0);
        $table->timestamp('next_attempt_at')->nullable();
        $table->char('lease_token', 36)->nullable();
        $table->timestamp('lease_expires_at')->nullable();
        $table->timestamp('accepted_at')->nullable();
        $table->string('provider_message_id', 255)->nullable();
        $table->string('failure_code', 64)->nullable();
        $table->timestamps();
    });
    expect(Schema::getForeignKeys('website_order_notifications'))->toBeEmpty()
        ->and(Schema::hasIndex('website_order_notifications', 'won_transition_uq'))->toBeFalse()
        ->and(Schema::hasIndex('website_order_notifications', 'won_due_idx'))->toBeFalse()
        ->and(Schema::hasIndex('website_order_notifications', 'won_lease_idx'))->toBeFalse()
        ->and(Schema::hasIndex('website_order_notifications', 'won_order_status_idx'))->toBeFalse();
    $key = hash('sha256', random_bytes(20));
    $tenantId = DB::table('tenants')->insertGetId(array_intersect_key(['name' => 'Notification recovery', 'slug' => 'notification-recovery-'.substr($key, 0, 12)], array_flip(Schema::getColumnListing('tenants'))));
    $siteId = DB::table('tenant_sites')->insertGetId(array_intersect_key(['tenant_id' => $tenantId, 'status' => 'draft', 'public_enabled' => false, 'subdomain' => 'notification-'.substr($key, 0, 12)], array_flip(Schema::getColumnListing('tenant_sites'))));
    $orderId = DB::table('website_orders')->insertGetId(array_intersect_key(['tenant_id' => $tenantId, 'tenant_site_id' => $siteId, 'number' => 'RECOVERY-'.substr($key, 0, 12), 'lookup_token' => substr($key, 0, 56)], array_flip(Schema::getColumnListing('website_orders'))));
    DB::table('website_order_notifications')->insert(['tenant_id' => $tenantId, 'website_order_id' => $orderId,
        'transition_key' => $key, 'kind' => 'confirmed', 'snapshot' => 'encrypted-recovery-sentinel', 'status' => 'blocked', 'created_at' => now(), 'updated_at' => now()]);
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('website_order_notifications', 'won_transition_uq'))->toBeTrue()
        ->and(Schema::hasIndex('website_order_notifications', 'won_due_idx'))->toBeTrue()
        ->and(Schema::hasIndex('website_order_notifications', 'won_lease_idx'))->toBeTrue()
        ->and(Schema::hasIndex('website_order_notifications', 'won_order_status_idx'))->toBeTrue()
        ->and(Schema::getColumnType('website_order_notifications', 'snapshot'))->toBe('longtext')
        ->and(DB::table('website_order_notifications')->where('transition_key', $key)->value('snapshot'))->toBe('encrypted-recovery-sentinel')
        ->and(collect(Schema::getForeignKeys('website_order_notifications'))->pluck('name')->all())
        ->toContain('won_tenant_fk', 'won_order_fk', 'won_shipment_fk');
    expect(fn () => DB::table('website_order_notifications')->insert(['tenant_id' => $tenantId, 'website_order_id' => $orderId,
        'transition_key' => $key, 'kind' => 'confirmed', 'snapshot' => 'duplicate-must-be-rejected', 'status' => 'blocked']))
        ->toThrow(\Illuminate\Database\QueryException::class);
    // A different interruption can retain some constraints. Repair the missing
    // final index without recreating the table, its other keys, or its sentinel.
    Schema::table('website_order_notifications', fn (Blueprint $table) => $table->dropIndex('won_order_status_idx'));
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('website_order_notifications', 'won_order_status_idx'))->toBeTrue()
        ->and(DB::table('website_order_notifications')->where('transition_key', $key)->value('snapshot'))->toBe('encrypted-recovery-sentinel');
    DB::table('website_order_notifications')->where('transition_key', $key)->delete();
});

it('repairs native catalog source identities after MySQL retained the bare table before named keys', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires disposable MySQL.');
    }
    foreach (['tenants', 'tenant_sites', 'website_products', 'website_product_variants', 'website_collections'] as $name) {
        if (! Schema::hasTable($name)) {
            Schema::create($name, fn (Blueprint $table) => $table->id());
        }
    }
    Schema::dropIfExists('website_catalog_source_mappings');
    Schema::create('website_catalog_source_mappings', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id');
        $table->unsignedBigInteger('tenant_site_id');
        $table->string('provider', 24);
        $table->string('source_shop', 253);
        $table->string('source_type', 16);
        $table->string('source_gid', 190);
        $table->unsignedBigInteger('website_product_id')->nullable();
        $table->unsignedBigInteger('website_product_variant_id')->nullable();
        $table->unsignedBigInteger('website_collection_id')->nullable();
        $table->char('source_fingerprint', 64);
        $table->char('target_fingerprint', 64);
        $table->longText('provenance');
        $table->timestamps();
    });
    expect(Schema::getForeignKeys('website_catalog_source_mappings'))->toBeEmpty()->and(Schema::hasIndex('website_catalog_source_mappings', 'wcs_source_uq'))->toBeFalse();
    $key = substr(hash('sha256', random_bytes(20)), 0, 12);
    $tenant = DB::table('tenants')->insertGetId(array_intersect_key(['name' => 'Catalog recovery', 'slug' => 'catalog-recovery-'.$key], array_flip(Schema::getColumnListing('tenants'))));
    $site = DB::table('tenant_sites')->insertGetId(array_intersect_key(['tenant_id' => $tenant, 'subdomain' => 'catalog-recovery-'.$key, 'status' => 'draft', 'public_enabled' => false], array_flip(Schema::getColumnListing('tenant_sites'))));
    $row = ['tenant_id' => $tenant, 'tenant_site_id' => $site, 'provider' => 'shopify', 'source_shop' => 'fictional-recovery.myshopify.com', 'source_type' => 'product', 'source_gid' => 'gid://shopify/Product/123', 'source_fingerprint' => str_repeat('a', 64), 'target_fingerprint' => str_repeat('b', 64), 'provenance' => 'encrypted-recovery-sentinel'];
    $id = DB::table('website_catalog_source_mappings')->insertGetId($row);
    $migration = require database_path('migrations/2026_10_15_230000_create_website_catalog_source_mappings.php');
    $migration->up();
    $migration->up();
    expect(collect(Schema::getForeignKeys('website_catalog_source_mappings'))->pluck('name')->all())->toContain('wcs_tenant_fk', 'wcs_site_fk', 'wcs_product_fk', 'wcs_variant_fk', 'wcs_collection_fk');
    foreach (['wcs_source_uq', 'wcs_product_idx', 'wcs_variant_idx', 'wcs_collection_idx'] as $name) {
        expect(Schema::hasIndex('website_catalog_source_mappings', $name))->toBeTrue();
    }
    expect(DB::table('website_catalog_source_mappings')->where('id', $id)->value('provenance'))->toBe('encrypted-recovery-sentinel');
    expect(fn () => DB::table('website_catalog_source_mappings')->insert($row))->toThrow(\Illuminate\Database\QueryException::class);
    expect(fn () => DB::table('website_catalog_source_mappings')->insert(array_merge($row, ['tenant_id' => PHP_INT_MAX, 'source_gid' => 'gid://shopify/Product/124'])))->toThrow(\Illuminate\Database\QueryException::class);
    Schema::table('website_catalog_source_mappings', fn (Blueprint $table) => $table->dropIndex('wcs_collection_idx'));
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('website_catalog_source_mappings', 'wcs_collection_idx'))->toBeTrue()->and(DB::table('website_catalog_source_mappings')->where('id', $id)->value('provenance'))->toBe('encrypted-recovery-sentinel');
    DB::table('website_catalog_source_mappings')->where('id', $id)->delete();
});

it('recovers subscription provider evidence from bare MySQL tables and preserves scoped durable identities', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    foreach (['tenants', 'shopify_stores'] as $name) {
        if (! Schema::hasTable($name)) {
            Schema::create($name, function (Blueprint $t) use ($name): void {
                $t->id();
                if ($name === 'shopify_stores') {
                    $t->unsignedBigInteger('tenant_id');
                    $t->string('store_key')->nullable();
                    $t->string('shop_domain')->nullable();
                }
            });
        }
    }
    $migration = require database_path('migrations/2026_10_15_235000_create_subscription_provider_evidence.php');
    $tables = array_keys($migration->indexes());
    Schema::disableForeignKeyConstraints();
    foreach (array_reverse($tables) as $table) {
        Schema::dropIfExists($table);
    }
    Schema::enableForeignKeyConstraints();
    $migration->up();
    // CREATE of the first table survived, while later tables did not.
    Schema::table('subscription_installations', fn (Blueprint $t) => $t->dropForeign('si_active_credential_fk'));
    foreach (array_reverse(array_slice($tables, 1)) as $table) {
        Schema::drop($table);
    }
    $migration->up();
    $migration->up();
    $fingerprint = function () use ($tables): array {
        $result = [];
        foreach ($tables as $table) {
            $indexes = collect(Schema::getIndexes($table))->map(fn ($x) => \Illuminate\Support\Arr::only($x, ['name', 'columns', 'unique', 'primary']))->sortBy('name')->values()->all();
            $keys = collect(Schema::getForeignKeys($table))->map(fn ($x) => \Illuminate\Support\Arr::only($x, ['name', 'columns', 'foreign_table', 'foreign_columns', 'on_update', 'on_delete']))->sortBy('name')->values()->all();
            $result[$table] = [$indexes, $keys];
        }

        return $result;
    };
    $clean = $fingerprint();
    foreach ($tables as $table) {
        foreach (Schema::getForeignKeys($table) as $fk) {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign($fk['name']));
        }
    }
    foreach ($tables as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            if (! $index['primary']) {
                Schema::table($table, fn (Blueprint $t) => $index['unique'] ? $t->dropUnique($index['name']) : $t->dropIndex($index['name']));
            }
        }
    }
    $suffix = bin2hex(random_bytes(5));
    $tenant = DB::table('tenants')->insertGetId(array_intersect_key(['name' => 'Subscription recovery', 'slug' => 'subscription-recovery-'.$suffix], array_flip(Schema::getColumnListing('tenants'))));
    $store = DB::table('shopify_stores')->insertGetId(array_intersect_key(['tenant_id' => $tenant, 'store_key' => 'recovery-'.$suffix, 'shop_domain' => 'recovery-'.$suffix.'.myshopify.com'], array_flip(Schema::getColumnListing('shopify_stores'))));
    $install = ['public_id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant, 'shopify_store_id' => $store, 'shop_domain' => 'recovery-'.$suffix.'.myshopify.com', 'shop_gid' => 'gid://shopify/Shop/1', 'app_gid' => 'gid://shopify/App/1', 'app_installation_gid' => 'gid://shopify/AppInstallation/1', 'public_client_id' => 'recovery-public', 'evidence' => 'encrypted-installation-sentinel'];
    $id = DB::table('subscription_installations')->insertGetId($install);
    $credential = DB::table('subscription_credential_revisions')->insertGetId(['tenant_id' => $tenant, 'installation_id' => $id, 'revision' => 1, 'credentials' => 'encrypted-token-sentinel', 'app_secret' => 'encrypted-app-secret-sentinel', 'app_secret_reference' => 'test:app', 'app_secret_revision' => '1', 'credential_fingerprint' => str_repeat('a', 64), 'digest_key_id' => str_repeat('a', 16)]);
    $operation = DB::table('subscription_operations')->insertGetId(['tenant_id' => $tenant, 'installation_id' => $id, 'credential_revision_id' => $credential, 'request_uuid' => (string) \Illuminate\Support\Str::uuid(), 'kind' => 'installation_probe', 'request_fingerprint' => str_repeat('b', 64), 'digest_key_id' => str_repeat('a', 16), 'request' => 'encrypted-request-sentinel']);
    $delivery = ['tenant_id' => $tenant, 'installation_id' => $id, 'delivery_id' => 'delivery-sentinel', 'topic' => 'app/uninstalled', 'api_version' => '2026-10', 'body_fingerprint' => str_repeat('c', 64), 'digest_key_id' => str_repeat('a', 16), 'raw_body' => 'encrypted-body-sentinel', 'headers' => 'encrypted-headers-sentinel', 'verification' => 'encrypted-signature-revision-sentinel'];
    $inbox = DB::table('subscription_webhook_inboxes')->insertGetId($delivery);
    DB::table('subscription_provider_resources')->insert(['tenant_id' => $tenant, 'installation_id' => $id, 'sync_operation_id' => $operation, 'resource_type' => 'contract', 'provider_gid' => 'gid://shopify/SubscriptionContract/1', 'provider_revision' => '18446744073709551615', 'status' => 'ACTIVE', 'observed_at' => now(), 'snapshot' => 'encrypted-snapshot-sentinel']);
    $migration->up();
    $migration->up();
    expect($fingerprint())->toBe($clean)
        ->and(DB::table('subscription_installations')->where('id', $id)->value('evidence'))->toBe('encrypted-installation-sentinel')
        ->and(DB::table('subscription_webhook_inboxes')->where('id', $inbox)->value('raw_body'))->toBe('encrypted-body-sentinel')
        ->and(DB::table('subscription_provider_resources')->value('provider_revision'))->toBe('18446744073709551615');
    $second = DB::table('subscription_installations')->insertGetId(array_merge($install, ['public_id' => (string) \Illuminate\Support\Str::uuid(), 'shop_domain' => 'second-'.$suffix.'.myshopify.com', 'shop_gid' => 'gid://shopify/Shop/2', 'app_installation_gid' => 'gid://shopify/AppInstallation/2', 'public_client_id' => 'second-public']));
    expect(fn () => DB::table('subscription_installations')->where('id', $second)->update(['active_credential_revision_id' => $credential]))->toThrow(\Illuminate\Database\QueryException::class);
    DB::table('subscription_installations')->where('id', $id)->update(['active_credential_revision_id' => $credential]);
    Schema::table('subscription_webhook_inboxes', function (Blueprint $t): void {
        $t->dropForeign('swi_install_fk');
        $t->dropIndex('swi_due_idx');
    });
    $migration->up();
    $migration->up();
    expect($fingerprint())->toBe($clean);
    Schema::table('subscription_webhook_inboxes', function (Blueprint $t): void {
        $t->dropForeign('swi_install_fk');
        $t->foreign(['installation_id', 'tenant_id'], 'swi_install_fk')->references(['id', 'tenant_id'])->on('subscription_installations')->restrictOnDelete()->cascadeOnUpdate();
    });
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
    Schema::table('subscription_webhook_inboxes', fn (Blueprint $t) => $t->dropForeign('swi_install_fk'));
    $migration->up();
    Schema::table('subscription_webhook_inboxes', fn (Blueprint $t) => $t->dropUnique('swi_delivery_uq'));
    $duplicate = DB::table('subscription_webhook_inboxes')->insertGetId($delivery);
    expect(fn () => $migration->up())->toThrow(\Illuminate\Database\QueryException::class);
    expect(DB::table('subscription_webhook_inboxes')->count())->toBe(2);
    DB::table('subscription_webhook_inboxes')->where('id', $duplicate)->delete();
    $migration->up();
    Schema::table('subscription_webhook_inboxes', fn (Blueprint $t) => $t->dropForeign('swi_install_fk'));
    $orphan = DB::table('subscription_webhook_inboxes')->insertGetId(array_merge($delivery, ['delivery_id' => 'orphan-sentinel', 'installation_id' => PHP_INT_MAX]));
    expect(fn () => $migration->up())->toThrow(\Illuminate\Database\QueryException::class);
    expect(DB::table('subscription_webhook_inboxes')->where('id', $orphan)->value('raw_body'))->toBe('encrypted-body-sentinel');
    DB::table('subscription_webhook_inboxes')->where('id', $orphan)->delete();
    $migration->up();
    $migration->up();
    expect($fingerprint())->toBe($clean)->and(DB::table('subscription_operations')->where('id', $operation)->value('request'))->toBe('encrypted-request-sentinel');
});

it('deduplicates concurrent subscription operations and webhook deliveries on MySQL after lost dispatch', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    $migration = require database_path('migrations/2026_10_15_235000_create_subscription_provider_evidence.php');
    $migration->up();
    $suffix = bin2hex(random_bytes(6));
    $tenant = DB::table('tenants')->insertGetId(array_intersect_key(['name' => 'Concurrency fixture', 'slug' => 'subscription-race-'.$suffix], array_flip(Schema::getColumnListing('tenants'))));
    $store = DB::table('shopify_stores')->insertGetId(array_intersect_key(['tenant_id' => $tenant, 'store_key' => 'race-'.$suffix, 'shop_domain' => 'race-'.$suffix.'.myshopify.com'], array_flip(Schema::getColumnListing('shopify_stores'))));
    $install = \App\Models\SubscriptionInstallation::query()->create(['tenant_id' => $tenant, 'shopify_store_id' => $store, 'shop_domain' => 'race-'.$suffix.'.myshopify.com', 'shop_gid' => 'gid://shopify/Shop/'.$suffix, 'app_gid' => 'gid://shopify/App/'.$suffix, 'app_installation_gid' => 'gid://shopify/AppInstallation/'.$suffix, 'public_client_id' => 'race-'.$suffix, 'status' => 'verified', 'verified_at' => now()]);
    $credential = \App\Models\SubscriptionCredentialRevision::query()->create(['tenant_id' => $tenant, 'installation_id' => $install->id, 'revision' => 1, 'status' => 'active', 'credentials' => ['access_token' => 'synthetic-private-token'], 'credential_fingerprint' => str_repeat('a', 64), 'digest_key_id' => str_repeat('a', 16)]);
    $install->update(['active_credential_revision_id' => $credential->id]);
    $dir = sys_get_temp_dir().'/subscription-evidence-race-'.$suffix;
    mkdir($dir, 0700);
    $cache = $dir.'/config.php';
    file_put_contents($cache, '<?php return '.var_export(config()->all(), true).';');
    chmod($cache, 0600);
    $processes = [];
    $results = [];
    $uuid = (string) \Illuminate\Support\Str::uuid();
    $code = <<<'PHP'
require 'vendor/autoload.php';
$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$app=require 'bootstrap/app.php'; $app->useEnvironmentPath($d['dir']); $app->loadEnvironmentFrom('absent.env'); $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests(); Illuminate\Support\Facades\Queue::fake();
config()->set('subscriptions.provider_reads_enabled',true); config()->set('subscriptions.provider_read_tenant_ids',[$d['tenant']]);
// This test isolates database concurrency. Real canonical manager/entitlement
// checks are exercised by SubscriptionEvidencePersistenceTest.
$access=new class extends App\Services\Subscriptions\SubscriptionAccessService { public function assertManager(int $id,?App\Models\User $actor):void {} public function entitled(int $id):bool{return true;} };
$actor=(new App\Models\User)->forceFill(['id'=>1]);
touch($d['dir'].'/ready-'.$d['worker']); $deadline=microtime(true)+15; while(!file_exists($d['dir'].'/go')) { if(microtime(true)>$deadline){throw new RuntimeException('Barrier timeout');} usleep(10000); }
$lost=function(int $id) use($d):void { file_put_contents($d['dir'].'/dispatches',json_encode($id).PHP_EOL,FILE_APPEND|LOCK_EX); chmod($d['dir'].'/dispatches',0600); throw new RuntimeException('Synthetic lost after-commit dispatch'); };
$op=(new App\Services\Subscriptions\SubscriptionOperationService($access))->record($d['tenant'],$d['install'],$actor,'owned_contract_sync',$d['uuid'],['bound'=>2],afterCommit:$lost);
$inbox=(new App\Services\Subscriptions\SubscriptionWebhookInboxService($access))->persist($d['tenant'],$d['install'],'race-delivery','same-event','subscription_contracts/update','2026-10','{"id":1}',afterCommit:$lost,signingEvidence:['app_secret_reference'=>'test:app','app_secret_revision'=>'1']);
Illuminate\Support\Facades\Http::assertNothingSent(); Illuminate\Support\Facades\Queue::assertNothingPushed();
echo json_encode(['operation'=>$op->id,'inbox'=>$inbox->id]);
PHP;
    try {
        foreach ([1, 2] as $worker) {
            $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $code], base_path(), ['APP_CONFIG_CACHE' => $cache, 'APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], json_encode(['dir' => $dir, 'worker' => $worker, 'tenant' => $tenant, 'install' => $install->id, 'uuid' => $uuid]), 25);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        while (! file_exists($dir.'/ready-1') || ! file_exists($dir.'/ready-2')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers did not reach barrier.');
            } usleep(10000);
        }
        touch($dir.'/go');
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }
        expect(count(file($dir.'/dispatches', FILE_IGNORE_NEW_LINES)))->toBe(2)
            ->and($results[0])->toBe($results[1])
            ->and(DB::table('subscription_operations')->where('installation_id', $install->id)->where('request_uuid', $uuid)->count())->toBe(1)
            ->and(DB::table('subscription_operations')->where('id', $results[0]['operation'])->value('status'))->toBe('queued')
            ->and(DB::table('subscription_webhook_inboxes')->where('installation_id', $install->id)->count())->toBe(1)
            ->and(DB::table('subscription_webhook_inboxes')->where('id', $results[0]['inbox'])->value('status'))->toBe('pending');
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        } rmdir($dir);
    }
});

it('recovers Recharge evidence from partial MySQL DDL without changing accepted generations or private evidence', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    foreach (['tenants', 'shopify_stores', 'integration_connections'] as $name) {
        if (! Schema::hasTable($name)) {
            Schema::create($name, function (Blueprint $t) use ($name): void {
                $t->id();
                if ($name !== 'tenants') {
                    $t->unsignedBigInteger('tenant_id');
                }
                if ($name === 'shopify_stores') {
                    $t->string('store_key')->nullable();
                    $t->string('shop_domain')->nullable();
                }
                if ($name === 'integration_connections') {
                    $t->string('provider');
                    $t->string('external_account_id')->nullable();
                }
            });
        }
    }
    $migration = require database_path('migrations/2026_10_16_120000_create_subscription_recharge_evidence.php');
    $tables = array_keys($migration->indexes());
    Schema::disableForeignKeyConstraints();
    foreach (array_reverse($tables) as $table) {
        Schema::dropIfExists($table);
    }
    Schema::enableForeignKeyConstraints();
    $migration->up();
    Schema::table('subscription_recharge_connections', fn (Blueprint $t) => $t->dropForeign('src_active_fk'));
    foreach (array_reverse(array_slice($tables, 1)) as $table) {
        Schema::drop($table);
    }
    $migration->up();
    $migration->up();
    $fingerprint = function () use ($tables): array {
        $result = [];
        foreach ($tables as $table) {
            $indexes = collect(Schema::getIndexes($table))->map(fn ($x) => \Illuminate\Support\Arr::only($x, ['name', 'columns', 'unique', 'primary']))->sortBy('name')->values()->all();
            $keys = collect(Schema::getForeignKeys($table))->map(fn ($x) => \Illuminate\Support\Arr::only($x, ['name', 'columns', 'foreign_table', 'foreign_columns', 'on_update', 'on_delete']))->sortBy('name')->values()->all();
            $result[$table] = [$indexes, $keys];
        }

        return $result;
    };
    $clean = $fingerprint();
    foreach ($tables as $table) {
        foreach (Schema::getForeignKeys($table) as $fk) {
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign($fk['name']));
        }
    }
    foreach ($tables as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            if (! $index['primary']) {
                Schema::table($table, fn (Blueprint $t) => $index['unique'] ? $t->dropUnique($index['name']) : $t->dropIndex($index['name']));
            }
        }
    }
    $suffix = bin2hex(random_bytes(5));
    $tenant = DB::table('tenants')->insertGetId(array_intersect_key(['name' => 'Recharge recovery', 'slug' => 'recharge-recovery-'.$suffix], array_flip(Schema::getColumnListing('tenants'))));
    $source = DB::table('integration_connections')->insertGetId(['tenant_id' => $tenant, 'provider' => 'recharge', 'external_account_id' => 'recovery-'.$suffix]);
    $encrypted = fn (string $value) => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['sentinel' => $value]));
    $connectionEvidence = $encrypted('connection-private');
    $syncState = $encrypted('accepted-generation-private');
    $tokenEvidence = $encrypted('immutable-private-token');
    $requestEvidence = $encrypted('private-read-request');
    $snapshotEvidence = $encrypted('customer-private@example.test');
    $mappingEvidence = $encrypted('private-local-mapping');
    $connectionValues = ['public_id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant, 'provider_store_id' => '000123'.$suffix, 'platform' => 'shopify', 'identifier' => 'recovery-'.$suffix.'.myshopify.com', 'source_integration_connection_id' => $source, 'evidence' => $connectionEvidence, 'sync_state' => $syncState];
    $connection = DB::table('subscription_recharge_connections')->insertGetId($connectionValues);
    $credentialValues = ['tenant_id' => $tenant, 'connection_id' => $connection, 'revision' => 1, 'credentials' => $tokenEvidence, 'credential_fingerprint' => str_repeat('a', 64), 'digest_key_id' => str_repeat('b', 16)];
    $credential = DB::table('subscription_recharge_credential_revisions')->insertGetId($credentialValues);
    $operationValues = ['tenant_id' => $tenant, 'connection_id' => $connection, 'credential_revision_id' => $credential, 'request_uuid' => (string) \Illuminate\Support\Str::uuid(), 'kind' => 'shadow_sync', 'request' => $requestEvidence, 'request_fingerprint' => str_repeat('c', 64), 'digest_key_id' => str_repeat('b', 16)];
    $operation = DB::table('subscription_recharge_operations')->insertGetId($operationValues);
    $resourceValues = ['tenant_id' => $tenant, 'connection_id' => $connection, 'sync_operation_id' => $operation, 'generation_id' => (string) \Illuminate\Support\Str::uuid(), 'resource_type' => 'customer', 'provider_id' => '00018446744073709551615001', 'source_updated_at' => '2026-10-16T14:30:00.123456-04:00', 'canonical_digest' => str_repeat('d', 64), 'digest_key_id' => str_repeat('b', 16), 'observed_at' => '2026-10-16 18:30:00.123456', 'completeness' => 'complete', 'snapshot' => $snapshotEvidence, 'mapping' => $mappingEvidence];
    $resource = DB::table('subscription_recharge_resources')->insertGetId($resourceValues);
    $migration->up();
    $migration->up();
    expect($fingerprint())->toBe($clean)
        ->and(DB::table('subscription_recharge_connections')->where('id', $connection)->value('evidence'))->toBe($connectionEvidence)
        ->and(DB::table('subscription_recharge_connections')->where('id', $connection)->value('sync_state'))->toBe($syncState)
        ->and(DB::table('subscription_recharge_credential_revisions')->where('id', $credential)->value('credentials'))->toBe($tokenEvidence)
        ->and(DB::table('subscription_recharge_resources')->where('id', $resource)->value('snapshot'))->toBe($snapshotEvidence)
        ->and(DB::table('subscription_recharge_resources')->where('id', $resource)->value('mapping'))->toBe($mappingEvidence)
        ->and(DB::table('subscription_recharge_resources')->where('id', $resource)->value('provider_id'))->toBe('00018446744073709551615001')
        ->and(DB::table('subscription_recharge_resources')->where('id', $resource)->value('source_updated_at'))->toBe('2026-10-16T14:30:00.123456-04:00')
        ->and(DB::table('subscription_recharge_resources')->where('id', $resource)->value('observed_at'))->toBe('2026-10-16 18:30:00.123456');

    // The active pointer cannot borrow even a same-tenant connection's credential.
    $second = DB::table('subscription_recharge_connections')->insertGetId(array_replace($connectionValues, ['public_id' => (string) \Illuminate\Support\Str::uuid(), 'provider_store_id' => 'second-'.$suffix]));
    expect(fn () => DB::table('subscription_recharge_connections')->where('id', $second)->update(['active_credential_revision_id' => $credential]))->toThrow(\Illuminate\Database\QueryException::class);
    expect(fn () => DB::table('subscription_recharge_credential_revisions')->insert(array_replace($credentialValues, ['tenant_id' => PHP_INT_MAX, 'revision' => 2])))->toThrow(\Illuminate\Database\QueryException::class);
    expect(fn () => DB::table('subscription_recharge_operations')->insert(array_replace($operationValues, ['connection_id' => $second, 'request_uuid' => (string) \Illuminate\Support\Str::uuid()])))->toThrow(\Illuminate\Database\QueryException::class);
    expect(fn () => DB::table('subscription_recharge_resources')->insert(array_replace($resourceValues, ['connection_id' => $second])))->toThrow(\Illuminate\Database\QueryException::class);
    DB::table('subscription_recharge_connections')->where('id', $connection)->update(['active_credential_revision_id' => $credential]);
    $next = DB::table('subscription_recharge_resources')->insertGetId(array_replace($resourceValues, ['generation_id' => (string) \Illuminate\Support\Str::uuid(), 'completeness' => 'staged']));
    expect(DB::table('subscription_recharge_resources')->count())->toBe(2);
    expect(fn () => DB::table('subscription_recharge_resources')->insert($resourceValues))->toThrow(\Illuminate\Database\QueryException::class);

    Schema::table('subscription_recharge_resources', function (Blueprint $t): void {
        $t->dropForeign('srr_operation_fk');
        $t->dropIndex('srr_customer_idx');
    });
    $migration->up();
    $migration->up();
    expect($fingerprint())->toBe($clean);
    Schema::table('subscription_recharge_resources', function (Blueprint $t): void {
        $t->dropForeign('srr_operation_fk');
        $t->foreign(['sync_operation_id', 'connection_id', 'tenant_id'], 'srr_operation_fk')->references(['id', 'connection_id', 'tenant_id'])->on('subscription_recharge_operations')->restrictOnDelete()->cascadeOnUpdate();
    });
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
    Schema::table('subscription_recharge_resources', fn (Blueprint $t) => $t->dropForeign('srr_operation_fk'));
    $migration->up();
    Schema::table('subscription_recharge_resources', function (Blueprint $t): void {
        $t->dropUnique('srr_generation_uq');
        $t->index(['connection_id', 'resource_type', 'provider_id', 'generation_id'], 'srr_generation_uq');
    });
    expect(fn () => $migration->up())->toThrow(RuntimeException::class);
    Schema::table('subscription_recharge_resources', fn (Blueprint $t) => $t->dropIndex('srr_generation_uq'));
    $migration->up();

    // Conflicting retained rows stop repair without deleting or changing evidence.
    Schema::table('subscription_recharge_operations', fn (Blueprint $t) => $t->dropUnique('sro_request_uq'));
    $duplicate = DB::table('subscription_recharge_operations')->insertGetId($operationValues);
    expect(fn () => $migration->up())->toThrow(\Illuminate\Database\QueryException::class);
    expect(DB::table('subscription_recharge_operations')->where('connection_id', $connection)->count())->toBe(2);
    DB::table('subscription_recharge_operations')->where('id', $duplicate)->delete();
    $migration->up();
    Schema::table('subscription_recharge_resources', fn (Blueprint $t) => $t->dropForeign('srr_operation_fk'));
    $orphan = DB::table('subscription_recharge_resources')->insertGetId(array_replace($resourceValues, ['generation_id' => (string) \Illuminate\Support\Str::uuid(), 'sync_operation_id' => PHP_INT_MAX]));
    expect(fn () => $migration->up())->toThrow(\Illuminate\Database\QueryException::class);
    expect(DB::table('subscription_recharge_resources')->where('id', $orphan)->value('snapshot'))->toBe($snapshotEvidence);
    DB::table('subscription_recharge_resources')->where('id', $orphan)->delete();
    $migration->up();
    $migration->up();
    $migration->down();
    expect($fingerprint())->toBe($clean)
        ->and(DB::table('subscription_recharge_operations')->where('id', $operation)->value('request'))->toBe($requestEvidence)
        ->and(DB::table('subscription_recharge_resources')->where('id', $resource)->value('snapshot'))->toBe($snapshotEvidence)
        ->and(DB::table('subscription_recharge_resources')->where('id', $next)->value('completeness'))->toBe('staged');
});

it('deduplicates concurrent Recharge reads and fences recovered leases on MySQL without executing providers', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    $migration = require database_path('migrations/2026_10_16_120000_create_subscription_recharge_evidence.php');
    $migration->up();
    if (! Schema::hasTable('users')) {
        Schema::create('users', fn (Blueprint $t) => $t->id());
    }
    $suffix = bin2hex(random_bytes(6));
    $tenant = DB::table('tenants')->insertGetId(array_intersect_key(['name' => 'Recharge concurrency', 'slug' => 'recharge-race-'.$suffix], array_flip(Schema::getColumnListing('tenants'))));
    $actorId = DB::table('users')->insertGetId(array_intersect_key(['name' => 'Fictional manager', 'email' => 'recharge-'.$suffix.'@example.test', 'password' => 'unused-synthetic-password'], array_flip(Schema::getColumnListing('users'))));
    $connection = \App\Models\SubscriptionRechargeConnection::query()->create(['tenant_id' => $tenant, 'provider_store_id' => 'race-'.$suffix, 'platform' => 'shopify', 'identifier' => 'race-'.$suffix.'.myshopify.com', 'status' => 'verified', 'verified_at' => now()]);
    $credential = \App\Models\SubscriptionRechargeCredentialRevision::query()->create(['tenant_id' => $tenant, 'connection_id' => $connection->id, 'revision' => 1, 'status' => 'active', 'credentials' => ['access_token' => 'synthetic-private-recharge-token'], 'credential_fingerprint' => str_repeat('a', 64), 'digest_key_id' => str_repeat('b', 16)]);
    $connection->update(['active_credential_revision_id' => $credential->id]);
    $dir = sys_get_temp_dir().'/recharge-read-race-'.$suffix;
    mkdir($dir, 0700);
    $cache = $dir.'/config.php';
    file_put_contents($cache, '<?php return '.var_export(config()->all(), true).';');
    chmod($cache, 0600);
    $processes = [];
    $results = [];
    $uuid = (string) \Illuminate\Support\Str::uuid();
    $code = <<<'PHP'
require 'vendor/autoload.php';
$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$app=require 'bootstrap/app.php'; $app->useEnvironmentPath($d['dir']); $app->loadEnvironmentFrom('absent.env'); $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests(); Illuminate\Support\Facades\Queue::fake(); Illuminate\Support\Facades\Mail::fake();
config()->set('subscriptions.recharge_reads_enabled',true); config()->set('subscriptions.recharge_read_tenant_ids',[$d['tenant']]);
// Isolate database concurrency; actual active-manager and entitlement checks are
// exercised by SubscriptionRechargeReadTest request/service regressions.
$access=new class extends App\Services\Subscriptions\SubscriptionAccessService { public function assertManager(int $id,?App\Models\User $actor):void {} public function entitled(int $id):bool{return true;} };
$actor=App\Models\User::query()->findOrFail($d['actor']);
touch($d['dir'].'/ready-'.$d['worker']); $deadline=microtime(true)+15; while(!file_exists($d['dir'].'/go')) { if(microtime(true)>$deadline){throw new RuntimeException('Barrier timeout');} usleep(10000); }
$service=new App\Services\Subscriptions\SubscriptionRechargeOperationService($access);
$op=$service->record($d['tenant'],$d['connection'],$actor,'sync_slice',$d['uuid'],['position'=>['stream'=>0,'cursor'=>null],'limit'=>50]);
$claim=$service->claim($d['tenant'],$op->id,$actor);
Illuminate\Support\Facades\Http::assertNothingSent(); Illuminate\Support\Facades\Queue::assertNothingPushed(); Illuminate\Support\Facades\Mail::assertNothingSent();
echo json_encode(['operation'=>$op->id,'lease'=>$claim?->lease_token]);
PHP;
    try {
        foreach ([1, 2] as $worker) {
            $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $code], base_path(), ['APP_CONFIG_CACHE' => $cache, 'APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null'], json_encode(['dir' => $dir, 'worker' => $worker, 'tenant' => $tenant, 'actor' => $actorId, 'connection' => $connection->id, 'uuid' => $uuid]), 25);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        while (! file_exists($dir.'/ready-1') || ! file_exists($dir.'/ready-2')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Recharge workers did not reach barrier.');
            } usleep(10000);
        }
        touch($dir.'/go');
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }
        $lease = collect($results)->pluck('lease')->filter()->values();
        $id = $results[0]['operation'];
        expect($results[0]['operation'])->toBe($results[1]['operation'])
            ->and($lease->count())->toBe(1)
            ->and(DB::table('subscription_recharge_operations')->where('connection_id', $connection->id)->where('request_uuid', $uuid)->count())->toBe(1)
            ->and(DB::table('subscription_recharge_operations')->where('id', $id)->value('attempts'))->toBe(1);
        config()->set('subscriptions.recharge_reads_enabled', true);
        config()->set('subscriptions.recharge_read_tenant_ids', [$tenant]);
        $access = new class extends \App\Services\Subscriptions\SubscriptionAccessService
        {
            public function assertManager(int $id, ?\App\Models\User $actor): void {}

            public function entitled(int $id): bool
            {
                return true;
            }
        };
        $service = new \App\Services\Subscriptions\SubscriptionRechargeOperationService($access);
        $actor = \App\Models\User::query()->findOrFail($actorId);
        // A lost worker response retains the operation; expiry permits GET-only
        // recovery under a fresh fence without duplicating the request identity.
        DB::table('subscription_recharge_operations')->where('id', $id)->update(['lease_expires_at' => now()->subSecond()]);
        $recovered = $service->claim($tenant, $id, $actor);
        expect($recovered->attempts)->toBe(2)
            ->and($service->complete($tenant, $id, $lease[0], 'completed'))->toBeFalse()
            ->and($service->complete($tenant, $id, $recovered->lease_token, 'completed', ['observed_count' => 0]))->toBeTrue()
            ->and($service->claim($tenant, $id, $actor))->toBeNull();
        expect(fn () => $service->record($tenant, $connection->id, $actor, 'sync_slice', $uuid, ['different' => true]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $replayed = $service->record($tenant, $connection->id, $actor, 'sync_slice', $uuid, ['limit' => 50, 'position' => ['cursor' => null, 'stream' => 0]]);
        expect($replayed->id)->toBe($id)->and($replayed->status)->toBe('completed')
            ->and($replayed->result)->toBe(['observed_count' => 0]);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        } rmdir($dir);
    }
});
