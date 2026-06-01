<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_workflow_states', function (Blueprint $table) {
            $table->id();
            $table->string('workflow_key')->unique();
            $table->string('status')->default('idle');
            $table->text('cursor')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->json('last_result')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_workflow_links', function (Blueprint $table) {
            $table->id();
            $table->string('workflow_key');
            $table->string('source_system');
            $table->string('source_id');
            $table->string('destination_system');
            $table->string('destination_id')->nullable();
            $table->string('source_fingerprint', 128)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_key', 'source_system', 'source_id'], 'automation_workflow_links_source_unique');
            $table->index(['destination_system', 'destination_id'], 'automation_workflow_links_destination_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_workflow_links');
        Schema::dropIfExists('automation_workflow_states');
    }
};
