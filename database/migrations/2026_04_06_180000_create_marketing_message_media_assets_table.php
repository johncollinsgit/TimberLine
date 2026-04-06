<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_message_media_assets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('store_key', 80)->nullable()->index();
            $table->string('channel', 32)->default('email')->index();
            $table->string('disk', 64)->default('public');
            $table->string('path', 500);
            $table->string('public_url', 1000);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text', 255)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'store_key', 'channel', 'created_at'], 'mmma_scope_created_idx');
            $table->index(['tenant_id', 'store_key', 'channel', 'mime_type'], 'mmma_scope_mime_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_message_media_assets');
    }
};
