<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_module_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('related_module_key')->nullable()->index();
            $table->string('title');
            $table->text('problem_summary');
            $table->text('current_workaround')->nullable();
            $table->text('desired_outcome')->nullable();
            $table->text('tools_involved')->nullable();
            $table->string('users_impacted')->nullable();
            $table->string('frequency')->nullable();
            $table->string('urgency')->nullable();
            $table->string('budget_range')->nullable();
            $table->boolean('reusable_module_interest')->default(false);
            $table->string('mobile_relevance')->nullable();
            $table->string('status')->default('new')->index();
            $table->text('landlord_notes')->nullable();
            $table->string('next_action')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['mobile_relevance']);
            $table->index(['reusable_module_interest']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_module_requests');
    }
};
