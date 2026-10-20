<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_work_versions')) {
            Schema::create('team_work_versions', function (Blueprint $table): void {
                $table->unsignedBigInteger('tenant_id');
                $table->string('entity_type', 20);
                $table->uuid('entity_id');
                $table->unsignedInteger('version');
                $table->char('sha256', 64);
                $table->json('snapshot');
                $table->dateTime('recorded_at');
                $table->primary(['tenant_id', 'entity_type', 'entity_id', 'version'], 'team_work_version_pk');
            });
        }
        if (! Schema::hasIndex('team_work_versions', ['tenant_id', 'entity_type', 'entity_id', 'version'], 'primary')) {
            Schema::table('team_work_versions', fn (Blueprint $table) => $table->primary(['tenant_id', 'entity_type', 'entity_id', 'version'], 'team_work_version_pk'));
        }
    }

    public function down(): void
    {
        // Preserve original native versions when rolling application code back.
    }
};
