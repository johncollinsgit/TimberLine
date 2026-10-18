<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_branch_conversations')) {
            Schema::create('ai_branch_conversations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('context_hash', 64);
                $table->longText('messages')->nullable();
                $table->uuid('pending_uuid')->nullable();
                $table->timestamp('expires_at');
                $table->index(['tenant_id', 'user_id', 'expires_at'], 'aib_conversation_scope_idx');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_branch_conversations');
    }
};
