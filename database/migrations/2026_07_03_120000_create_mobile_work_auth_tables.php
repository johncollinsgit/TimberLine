<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_login_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('email')->index();
            $table->string('tenant_hint')->nullable();
            $table->string('token_hash', 128)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->string('requested_ip', 80)->nullable();
            $table->text('requested_user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('mobile_user_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('selected_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('token_hash', 128)->unique();
            $table->string('device_id')->nullable()->index();
            $table->string('device_name')->nullable();
            $table->string('app_version')->nullable();
            $table->timestamp('last_used_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'selected_tenant_id'], 'mobile_sessions_user_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_user_sessions');
        Schema::dropIfExists('mobile_login_challenges');
    }
};
