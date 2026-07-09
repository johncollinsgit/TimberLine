<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_project_ticket_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_project_ticket_id')->constrained('client_project_tickets')->cascadeOnDelete();
            $table->string('author_name', 120)->nullable();
            $table->text('body');
            $table->boolean('public_visible')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'client_project_ticket_id', 'public_visible'], 'cp_ticket_comments_public_idx');
        });

        Schema::create('client_project_ticket_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_project_ticket_id')->constrained('client_project_tickets')->cascadeOnDelete();
            $table->string('voter_hash', 64);
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['client_project_ticket_id', 'voter_hash'], 'cp_ticket_votes_ticket_voter_unique');
            $table->index(['tenant_id', 'client_project_ticket_id'], 'cp_ticket_votes_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_project_ticket_votes');
        Schema::dropIfExists('client_project_ticket_comments');
    }
};
