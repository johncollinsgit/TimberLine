<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('team_message_attachments')) {
            return;
        }
        Schema::create('team_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('team_channel_id');
            $table->foreignId('team_message_id')->nullable();
            $table->foreignId('uploaded_by_user_id');
            $table->uuid('client_uuid');
            $table->string('file_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->string('status', 20)->default('uploading');
            $table->string('storage_path', 255)->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'uploaded_by_user_id', 'client_uuid'], 'team_attach_upload_unique');
            $table->index(['tenant_id', 'team_channel_id', 'status'], 'team_attach_channel_idx');
            $table->foreign('tenant_id', 'team_attach_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('team_channel_id', 'team_attach_channel_fk')->references('id')->on('team_channels')->cascadeOnDelete();
            $table->foreign('team_message_id', 'team_attach_message_fk')->references('id')->on('team_messages')->cascadeOnDelete();
            $table->foreign('uploaded_by_user_id', 'team_attach_uploader_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Shared files and their access records survive a code rollback.
    }
};
