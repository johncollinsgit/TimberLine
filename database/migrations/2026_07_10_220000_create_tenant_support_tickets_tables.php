<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 180);
            $table->string('category', 40)->default('help');
            $table->string('priority', 20)->default('normal');
            $table->string('status', 40)->default('open');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'last_activity_at'], 'tenant_support_status_activity_idx');
        });

        Schema::create('tenant_support_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_context', 20);
            $table->text('body');
            $table->timestamps();
            $table->index(['tenant_support_ticket_id', 'id'], 'tenant_support_messages_ticket_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_support_ticket_messages');
        Schema::dropIfExists('tenant_support_tickets');
    }
};
