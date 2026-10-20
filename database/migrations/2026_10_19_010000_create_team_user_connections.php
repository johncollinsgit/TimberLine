<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_user_connections')) {
            Schema::create('team_user_connections', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('provider', 24);
                $table->text('credentials')->nullable();
                $table->json('project_ids')->nullable();
                $table->json('preferences')->nullable();
                $table->string('external_user_id', 120)->nullable();
                $table->string('account_name')->nullable();
                $table->string('status', 24)->default('disconnected');
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('team_user_connections', 'team_user_provider_unique')) {
            Schema::table('team_user_connections', fn (Blueprint $table) => $table->unique(['tenant_id', 'user_id', 'provider'], 'team_user_provider_unique'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user_connections');
    }
};
