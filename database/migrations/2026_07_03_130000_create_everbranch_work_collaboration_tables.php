<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 80);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->timestamp('muted_until')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'category'], 'work_notification_prefs_unique');
            $table->index(['user_id', 'tenant_id'], 'work_notification_prefs_user_tenant_idx');
        });

        Schema::create('work_push_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('device_token');
            $table->string('device_id')->nullable();
            $table->string('authorization_status', 80)->nullable();
            $table->boolean('push_enabled')->default(true);
            $table->string('app_version')->nullable();
            $table->string('app_build')->nullable();
            $table->string('device_name')->nullable();
            $table->string('device_model')->nullable();
            $table->string('locale', 40)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_registered_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'platform', 'device_token'], 'work_push_devices_unique');
            $table->index(['tenant_id', 'user_id', 'push_enabled'], 'work_push_devices_user_enabled_idx');
        });

        Schema::create('work_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 80)->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('item_type', 80)->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('deep_link')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'read_at'], 'work_notifications_user_read_idx');
            $table->index(['tenant_id', 'item_type', 'item_id'], 'work_notifications_item_idx');
        });

        Schema::create('work_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_notification_id')->constrained('work_notifications')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 40);
            $table->string('status', 80);
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'channel'], 'work_notification_deliveries_user_channel_idx');
        });

        Schema::create('work_item_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('item_type', 80);
            $table->unsignedBigInteger('item_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->json('mentioned_user_ids')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'item_type', 'item_id'], 'work_item_comments_item_idx');
        });

        Schema::create('work_item_watchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('item_type', 80);
            $table->unsignedBigInteger('item_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'item_type', 'item_id', 'user_id'], 'work_item_watchers_unique');
            $table->index(['tenant_id', 'user_id'], 'work_item_watchers_user_idx');
        });

        Schema::create('work_activity_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('item_type', 80);
            $table->unsignedBigInteger('item_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 80)->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'item_type', 'item_id', 'created_at'], 'work_activity_events_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_activity_events');
        Schema::dropIfExists('work_item_watchers');
        Schema::dropIfExists('work_item_comments');
        Schema::dropIfExists('work_notification_deliveries');
        Schema::dropIfExists('work_notifications');
        Schema::dropIfExists('work_push_devices');
        Schema::dropIfExists('work_notification_preferences');
    }
};
