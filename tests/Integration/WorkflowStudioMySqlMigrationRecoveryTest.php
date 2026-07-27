<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('runs cleanly and recovers the partial workflow studio migration on mysql', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    Schema::create('tenants', function (Blueprint $table): void {
        $table->id();
    });

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
    Schema::table('website_inventory_reservations', function (Blueprint $table): void {
        $table->dropForeign('website_reservations_variant_fk');
        $table->dropForeign('website_reservations_order_fk');
    });

    Schema::table('website_inventory_reservations', function (Blueprint $table): void {
        $table->dropUnique('website_reservation_order_variant_uq');
        $table->dropIndex('website_reservation_variant_status_idx');
    });

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
