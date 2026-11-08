<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('team_file_uploads', 'target_type')) {
            Schema::table('team_file_uploads', fn (Blueprint $table) => $table->string('target_type', 16)->default('task'));
        }
        if (! Schema::hasColumn('team_file_uploads', 'event_id')) {
            Schema::table('team_file_uploads', fn (Blueprint $table) => $table->uuid('event_id')->nullable());
        }
        if (! Schema::hasIndex('team_file_uploads', 'team_file_event_idx')) {
            Schema::table('team_file_uploads', fn (Blueprint $table) => $table->index(['tenant_id', 'target_type', 'event_id', 'status'], 'team_file_event_idx'));
        }
    }

    public function down(): void
    {
        // Preserve event target identity and original upload evidence on rollback.
    }
};
