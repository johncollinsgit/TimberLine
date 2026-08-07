<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A zero-downtime release can be interrupted after the first table is
        // created and before Laravel records this migration. Resume safely in
        // that state instead of blocking the next protected release.
        if (! Schema::hasTable('customer_loop_activities')) {
            Schema::create('customer_loop_activities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('marketing_profile_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('source_type', 80);
                $table->string('source_id', 120)->nullable();
                $table->string('event_key', 160)->unique();
                $table->string('title', 190);
                $table->text('summary')->nullable();
                $table->json('safe_context')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();
            });
        }

        $this->ensureIndex(
            'customer_loop_activities',
            ['tenant_id', 'occurred_at'],
            'cl_activity_tenant_occurred_idx',
            'customer_loop_activities_tenant_id_occurred_at_index'
        );
        $this->ensureIndex(
            'customer_loop_activities',
            ['tenant_id', 'marketing_profile_id', 'occurred_at'],
            'cl_activity_tenant_profile_occurred_idx',
            'customer_loop_activities_tenant_id_marketing_profile_id_occurred_at_index'
        );

        if (! Schema::hasTable('customer_loop_actions')) {
            Schema::create('customer_loop_actions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_loop_activity_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('marketing_profile_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action_type', 50);
                $table->string('status', 32)->default('suggested');
                $table->string('title', 190);
                $table->text('reason');
                $table->text('draft_body')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->timestamp('prepared_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('snoozed_until')->nullable();
                $table->json('safe_context')->nullable();
                $table->timestamps();
            });
        }

        $this->ensureIndex(
            'customer_loop_actions',
            ['tenant_id', 'status', 'due_at'],
            'cl_action_tenant_status_due_idx',
            'customer_loop_actions_tenant_id_status_due_at_index'
        );
        $this->ensureIndex(
            'customer_loop_actions',
            ['tenant_id', 'marketing_profile_id', 'status'],
            'cl_action_tenant_profile_status_idx',
            'customer_loop_actions_tenant_id_marketing_profile_id_status_index'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_loop_actions');
        Schema::dropIfExists('customer_loop_activities');
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function ensureIndex(string $tableName, array $columns, string $indexName, string $legacyIndexName): void
    {
        if (Schema::hasIndex($tableName, $indexName) || Schema::hasIndex($tableName, $legacyIndexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }
};
