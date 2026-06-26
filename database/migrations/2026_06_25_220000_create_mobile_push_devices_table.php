<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_push_devices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('marketing_profile_id')->index();
            $table->string('platform', 32)->default('ios');
            $table->string('device_token', 255);
            $table->string('authorization_status', 40)->nullable();
            $table->boolean('push_enabled')->default(false);
            $table->string('app_version', 40)->nullable();
            $table->string('app_build', 40)->nullable();
            $table->string('device_name', 120)->nullable();
            $table->string('device_model', 120)->nullable();
            $table->string('locale', 40)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_registered_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'device_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_push_devices');
    }
};
