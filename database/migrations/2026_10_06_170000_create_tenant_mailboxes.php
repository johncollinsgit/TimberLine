<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_mail_domains')) {
            Schema::create('tenant_mail_domains', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('domain', 253)->unique();
                $table->string('transport', 32)->default('sendgrid');
                $table->string('status', 32)->default('pending_dns');
                $table->string('provider_domain_id', 120)->nullable();
                $table->json('dns_records')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status'], 'mail_domain_tenant_status_idx');
            });
        }
        if (! Schema::hasTable('tenant_mailboxes')) {
            Schema::create('tenant_mailboxes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tenant_mail_domain_id')->constrained('tenant_mail_domains')->cascadeOnDelete();
                $table->string('address', 320)->unique();
                $table->string('display_name', 120);
                $table->string('status', 32)->default('pending_domain');
                $table->string('provider_account_id', 120)->nullable();
                $table->text('provider_credentials')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status'], 'mailbox_tenant_status_idx');
            });
        }
        if (! Schema::hasTable('tenant_mailbox_users')) {
            Schema::create('tenant_mailbox_users', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tenant_mailbox_id')->constrained('tenant_mailboxes')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('permission', 16)->default('read_write');
                $table->timestamps();
                $table->unique(['tenant_mailbox_id', 'user_id'], 'mailbox_user_unique');
                $table->index(['tenant_id', 'user_id'], 'mailbox_user_tenant_idx');
            });
        }
        if (! Schema::hasTable('tenant_mail_messages')) {
            Schema::create('tenant_mail_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tenant_mailbox_id')->constrained('tenant_mailboxes')->cascadeOnDelete();
                $table->string('folder', 16)->default('inbox');
                $table->string('direction', 16);
                $table->string('from_address', 320);
                $table->string('to_address', 320);
                $table->string('subject', 255)->default('');
                $table->mediumText('text_body')->nullable();
                $table->string('provider_message_id', 255)->nullable();
                $table->string('thread_key', 64);
                $table->string('delivery_status', 32)->default('received');
                $table->timestamp('read_at')->nullable();
                $table->timestamp('starred_at')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();
                $table->index(['tenant_mailbox_id', 'folder', 'occurred_at'], 'mail_msg_folder_time_idx');
                $table->unique(['tenant_mailbox_id', 'direction', 'provider_message_id'], 'mail_msg_provider_dedupe_idx');
                $table->index(['tenant_id', 'thread_key'], 'mail_msg_tenant_thread_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_mail_messages');
        Schema::dropIfExists('tenant_mailbox_users');
        Schema::dropIfExists('tenant_mailboxes');
        Schema::dropIfExists('tenant_mail_domains');
    }
};
