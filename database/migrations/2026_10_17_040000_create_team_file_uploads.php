<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_file_uploads')) {
            Schema::create('team_file_uploads', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->uuid('task_id');
                $table->unsignedBigInteger('uploader_user_id');
                $table->uuid('client_key');
                $table->string('filename', 240);
                $table->string('mime_type', 80);
                $table->unsignedInteger('expected_size');
                $table->char('sha256', 64);
                $table->string('status', 30);
                $table->string('storage_key', 200)->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->unique(['tenant_id', 'uploader_user_id', 'client_key'], 'team_file_client_unique');
                $table->index(['tenant_id', 'task_id', 'status'], 'team_file_task_idx');
            });
        }
        if (! Schema::hasTable('team_file_chunks')) {
            Schema::create('team_file_chunks', function (Blueprint $table): void {
                $table->unsignedBigInteger('tenant_id');
                $table->uuid('upload_id');
                $table->unsignedInteger('chunk_index');
                $table->unsignedInteger('size');
                $table->char('sha256', 64);
                $table->primary(['tenant_id', 'upload_id', 'chunk_index'], 'team_file_chunk_pk');
            });
        }
        if (! Schema::hasIndex('team_file_uploads', ['id'], 'primary')) {
            Schema::table('team_file_uploads', fn (Blueprint $table) => $table->primary('id'));
        }
        if (! Schema::hasIndex('team_file_uploads', 'team_file_client_unique', 'unique')) {
            Schema::table('team_file_uploads', fn (Blueprint $table) => $table->unique(['tenant_id', 'uploader_user_id', 'client_key'], 'team_file_client_unique'));
        }
        if (! Schema::hasIndex('team_file_uploads', 'team_file_task_idx')) {
            Schema::table('team_file_uploads', fn (Blueprint $table) => $table->index(['tenant_id', 'task_id', 'status'], 'team_file_task_idx'));
        }
        if (! Schema::hasIndex('team_file_chunks', ['tenant_id', 'upload_id', 'chunk_index'], 'primary')) {
            Schema::table('team_file_chunks', fn (Blueprint $table) => $table->primary(['tenant_id', 'upload_id', 'chunk_index'], 'team_file_chunk_pk'));
        }
    }

    public function down(): void
    {
        // Keep original file provenance and upload receipts during code rollback.
    }
};
