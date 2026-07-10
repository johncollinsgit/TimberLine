<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table): void {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mobile_authorization_codes')) {
            Schema::create('mobile_authorization_codes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('code_hash', 64)->unique();
                $table->string('code_challenge', 128);
                $table->string('redirect_uri', 500);
                $table->string('client_id', 120)->default('everbranch-mobile');
                $table->string('device_name', 160)->nullable();
                $table->string('state', 255)->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_authorization_codes');
        Schema::dropIfExists('personal_access_tokens');
    }
};
