<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_social_posts')) {
            Schema::create('marketing_social_posts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index('md_3_tenant_idx');
                $table->unsignedBigInteger('created_by');
                $table->unsignedInteger('revision')->default(1);
                $table->longText('content');
                $table->string('status', 24)->default('draft');
                $table->string('approved_hash', 64)->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('marketing_social_posts', 'md_3_tenant_idx')) {
            Schema::table('marketing_social_posts', fn (Blueprint $table) => $table->index('tenant_id', 'md_3_tenant_idx'));
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_social_posts');
    }
};
