<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landlord_operator_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('action_type', 120)->index();
            $table->string('status', 40)->default('success')->index();
            $table->string('target_type', 120)->nullable()->index();
            $table->string('target_id', 190)->nullable()->index();
            $table->json('context')->nullable();
            $table->json('confirmation')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('result')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id', 'landlord_operator_actions_tenant_id_foreign')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();
            $table->foreign('actor_user_id', 'landlord_operator_actions_actor_user_id_foreign')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_operator_actions');
    }
};

