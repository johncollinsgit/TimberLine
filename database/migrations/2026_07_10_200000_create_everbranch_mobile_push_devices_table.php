<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('everbranch_mobile_push_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->text('device_token');
            $table->char('device_token_hash', 64)->unique();
            $table->string('app_version', 40)->nullable();
            $table->string('device_name', 160)->nullable();
            $table->boolean('notifications_enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'notifications_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('everbranch_mobile_push_devices');
    }
};
