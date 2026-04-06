<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('store_key', 80)->nullable()->index();
            $table->string('channel', 20)->index();
            $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
            $table->string('phone', 40)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('subject')->nullable();
            $table->string('status', 40)->default('open')->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('last_inbound_at')->nullable()->index();
            $table->timestamp('last_outbound_at')->nullable()->index();
            $table->unsignedInteger('unread_count')->default(0);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type', 120)->nullable()->index();
            $table->string('source_id')->nullable()->index();
            $table->json('source_context')->nullable();
            $table->text('last_message_preview')->nullable();
            $table->timestamps();

            $table->index(
                ['tenant_id', 'store_key', 'channel', 'status', 'last_message_at'],
                'msg_conv_tenant_store_channel_status_last_idx'
            );
            $table->index(['tenant_id', 'phone', 'channel'], 'msg_conv_tenant_phone_channel_idx');
            $table->index(['tenant_id', 'email', 'channel'], 'msg_conv_tenant_email_channel_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_conversations');
    }
};
