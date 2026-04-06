<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messaging_conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id');
            $table->foreign('conversation_id', 'mcm_conversation_fk')
                ->references('id')
                ->on('messaging_conversations')
                ->cascadeOnDelete();
            $table->foreignId('tenant_id');
            $table->foreign('tenant_id', 'mcm_tenant_fk')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table->string('store_key', 80)->nullable()->index();
            $table->foreignId('marketing_profile_id')->nullable();
            $table->foreign('marketing_profile_id', 'mcm_profile_fk')
                ->references('id')
                ->on('marketing_profiles')
                ->nullOnDelete();
            $table->foreignId('marketing_message_delivery_id')->nullable();
            $table->foreign('marketing_message_delivery_id', 'mcm_sms_delivery_fk')
                ->references('id')
                ->on('marketing_message_deliveries')
                ->nullOnDelete();
            $table->foreignId('marketing_email_delivery_id')->nullable();
            $table->foreign('marketing_email_delivery_id', 'mcm_email_delivery_fk')
                ->references('id')
                ->on('marketing_email_deliveries')
                ->nullOnDelete();
            $table->string('channel', 20)->index();
            $table->string('direction', 20)->index();
            $table->string('provider', 60)->default('unknown')->index();
            $table->string('provider_message_id')->nullable();
            $table->string('dedupe_hash')->nullable()->unique();
            $table->longText('body')->nullable();
            $table->longText('normalized_body')->nullable();
            $table->string('subject')->nullable();
            $table->string('from_identity')->nullable();
            $table->string('to_identity')->nullable();
            $table->timestamp('received_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->string('delivery_status', 60)->nullable()->index();
            $table->string('message_type', 40)->default('normal')->index();
            $table->timestamp('operator_read_at')->nullable()->index();
            $table->foreignId('created_by')->nullable();
            $table->foreign('created_by', 'mcm_created_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->json('raw_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_message_id'], 'mcm_provider_message_unique');
            $table->index(['conversation_id', 'created_at'], 'mcm_conversation_created_idx');
            $table->index(['tenant_id', 'channel', 'direction', 'created_at'], 'mcm_tenant_channel_direction_created_idx');
        });

        Schema::create('messaging_contact_channel_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreign('tenant_id', 'mccs_tenant_fk')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table->foreignId('marketing_profile_id')->nullable();
            $table->foreign('marketing_profile_id', 'mccs_profile_fk')
                ->references('id')
                ->on('marketing_profiles')
                ->nullOnDelete();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('sms_status', 40)->default('unknown')->index();
            $table->string('email_status', 40)->default('unknown')->index();
            $table->string('sms_status_reason')->nullable();
            $table->string('email_status_reason')->nullable();
            $table->timestamp('sms_status_changed_at')->nullable();
            $table->timestamp('email_status_changed_at')->nullable();
            $table->string('provider_source', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone'], 'mccs_tenant_phone_unique');
            $table->unique(['tenant_id', 'email'], 'mccs_tenant_email_unique');
            $table->index(['tenant_id', 'marketing_profile_id'], 'mccs_tenant_profile_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_contact_channel_states');
        Schema::dropIfExists('messaging_conversation_messages');
    }
};
