<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fleet_operation_records')) {
            Schema::create('fleet_operation_records', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('device_id')->nullable();
                $table->string('kind', 32);
                $table->char('source_key', 64);
                $table->string('status', 32)->default('open');
                $table->timestamp('event_at')->nullable();
                $table->longText('payload');
                $table->timestamps();
                $table->foreign('tenant_id', 'fleet_ops_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('device_id', 'fleet_ops_device_fk')->references('id')->on('fleet_tracking_devices')->nullOnDelete();
                $table->unique(['tenant_id', 'kind', 'source_key'], 'fleet_ops_source_unique');
                $table->index(['tenant_id', 'kind', 'event_at'], 'fleet_ops_time_index');
                $table->index(['device_id', 'kind'], 'fleet_ops_device_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_operation_records');
    }
};
