<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_branch_actions')) {
            Schema::create('ai_branch_actions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->uuid('client_uuid');
                $table->string('kind', 40);
                $table->string('status', 20)->default('pending');
                $table->longText('payload');
                $table->string('digest', 64);
                $table->timestamp('expires_at');
                $table->timestamp('decided_at')->nullable();
                $table->unsignedBigInteger('result_id')->nullable();
                $table->unique(['tenant_id', 'client_uuid'], 'aib_action_request_uq');
                $table->index(['tenant_id', 'user_id', 'status'], 'aib_action_scope_idx');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_branch_actions');
    }
};
