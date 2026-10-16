<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_branch_memories')) {
            Schema::create('ai_branch_memories', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->longText('body');
                $table->timestamp('expires_at');
                $table->index(['tenant_id', 'user_id', 'expires_at'], 'aib_memory_scope_idx');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_branch_memories');
    }
};
