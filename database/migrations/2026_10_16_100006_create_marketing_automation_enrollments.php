<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_automation_enrollments')) {
            Schema::create('marketing_automation_enrollments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index('md_7_tenant_idx');
                $table->unsignedBigInteger('workflow_id');
                $table->unsignedBigInteger('workflow_version_id');
                $table->unsignedBigInteger('marketing_profile_id');
                $table->string('event_key', 64);
                $table->string('audience', 24);
                $table->string('store_key', 80)->default('');
                $table->string('status', 24)->default('active');
                $table->string('stop_reason', 80)->nullable();
                $table->timestamp('started_at');
                $table->unique(['workflow_id', 'marketing_profile_id', 'event_key'], 'md_enrollment_event_unique');
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('marketing_automation_enrollments', 'md_7_tenant_idx')) {
            Schema::table('marketing_automation_enrollments', fn (Blueprint $table) => $table->index('tenant_id', 'md_7_tenant_idx'));
        }
        if (! Schema::hasIndex('marketing_automation_enrollments', 'md_enrollment_event_unique')) {
            Schema::table('marketing_automation_enrollments', fn (Blueprint $table) => $table->unique(['workflow_id', 'marketing_profile_id', 'event_key'], 'md_enrollment_event_unique'));
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_automation_enrollments');
    }
};
