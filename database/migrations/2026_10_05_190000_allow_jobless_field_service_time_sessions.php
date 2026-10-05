<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('field_service_time_sessions')) {
            return;
        }

        $column = collect(Schema::getColumns('field_service_time_sessions'))
            ->firstWhere('name', 'field_service_job_id');
        if ($column === null || $column['nullable']) {
            return;
        }

        Schema::table('field_service_time_sessions', function (Blueprint $table): void {
            $table->foreignId('field_service_job_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Existing general-work sessions must keep their nullable job reference.
    }
};
