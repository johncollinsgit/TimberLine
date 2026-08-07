<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'marketing_profile_id', 'occurred_at']);
        });

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
            $table->index(['tenant_id', 'status', 'due_at']);
            $table->index(['tenant_id', 'marketing_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_loop_actions');
        Schema::dropIfExists('customer_loop_activities');
    }
};
