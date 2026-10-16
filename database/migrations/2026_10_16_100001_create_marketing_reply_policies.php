<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_reply_policies')) {
            Schema::create('marketing_reply_policies', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index('md_2_tenant_idx');
                $table->string('channel', 24);
                $table->string('audience', 24);
                $table->string('store_key', 80)->default('');
                $table->unsignedBigInteger('knowledge_version_id');
                $table->boolean('enabled')->default(false);
                $table->unsignedBigInteger('approved_by');
                $table->timestamp('approved_at');
                $table->unsignedInteger('revision')->default(1);
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('marketing_reply_policies', 'md_2_tenant_idx')) {
            Schema::table('marketing_reply_policies', fn (Blueprint $table) => $table->index('tenant_id', 'md_2_tenant_idx'));
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_reply_policies');
    }
};
