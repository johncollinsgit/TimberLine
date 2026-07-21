<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_material_catalog_items', function (Blueprint $table): void {
            $table->decimal('quantity_on_hand', 12, 2)->default(0)->after('description');
            $table->decimal('reorder_level', 12, 2)->default(0)->after('quantity_on_hand');
            $table->decimal('unit_cost', 12, 2)->nullable()->after('reorder_level');
        });

        Schema::create('field_service_vehicle_inventory', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('field_service_vehicle_id');
            $table->foreignId('field_material_catalog_item_id');
            $table->decimal('quantity', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['field_service_vehicle_id', 'field_material_catalog_item_id'], 'fs_vehicle_inventory_unique');
            $table->index(['tenant_id', 'field_material_catalog_item_id'], 'fs_vehicle_inventory_item_idx');
            $table->foreign('tenant_id', 'fs_vehicle_inventory_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('field_service_vehicle_id', 'fs_vehicle_inventory_vehicle_fk')->references('id')->on('field_service_vehicles')->cascadeOnDelete();
            $table->foreign('field_material_catalog_item_id', 'fs_vehicle_inventory_catalog_fk')->references('id')->on('field_material_catalog_items')->cascadeOnDelete();
        });

        Schema::create('field_service_job_vehicle_crews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('field_service_job_id');
            $table->foreignId('field_service_vehicle_id');
            $table->foreignId('user_id');
            $table->timestamps();
            $table->unique(['field_service_job_id', 'field_service_vehicle_id', 'user_id'], 'fs_job_vehicle_crew_unique');
            $table->index(['tenant_id', 'user_id', 'field_service_job_id'], 'fs_job_vehicle_crew_user_idx');
            $table->foreign('tenant_id', 'fs_job_vehicle_crew_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('field_service_job_id', 'fs_job_vehicle_crew_job_fk')->references('id')->on('field_service_jobs')->cascadeOnDelete();
            $table->foreign('field_service_vehicle_id', 'fs_job_vehicle_crew_vehicle_fk')->references('id')->on('field_service_vehicles')->cascadeOnDelete();
            $table->foreign('user_id', 'fs_job_vehicle_crew_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('field_inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('field_material_catalog_item_id');
            $table->foreignId('field_service_vehicle_id')->nullable();
            $table->foreignId('field_service_job_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->string('movement_type', 40);
            $table->decimal('quantity', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at'], 'field_inventory_movement_tenant_idx');
            $table->index(['field_material_catalog_item_id', 'created_at'], 'field_inventory_movement_item_idx');
            $table->foreign('tenant_id', 'field_inventory_movement_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('field_material_catalog_item_id', 'field_inventory_movement_catalog_fk')->references('id')->on('field_material_catalog_items')->cascadeOnDelete();
            $table->foreign('field_service_vehicle_id', 'field_inventory_movement_vehicle_fk')->references('id')->on('field_service_vehicles')->nullOnDelete();
            $table->foreign('field_service_job_id', 'field_inventory_movement_job_fk')->references('id')->on('field_service_jobs')->nullOnDelete();
            $table->foreign('created_by_user_id', 'field_inventory_movement_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_inventory_movements');
        Schema::dropIfExists('field_service_job_vehicle_crews');
        Schema::dropIfExists('field_service_vehicle_inventory');

        Schema::table('field_material_catalog_items', function (Blueprint $table): void {
            $table->dropColumn(['quantity_on_hand', 'reorder_level', 'unit_cost']);
        });
    }
};
