<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_brand_knowledge_versions')) {
            Schema::create('marketing_brand_knowledge_versions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index('md_1_tenant_idx');
                $table->string('audience', 24);
                $table->string('title');
                $table->longText('content');
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('marketing_brand_knowledge_versions', 'md_1_tenant_idx')) {
            Schema::table('marketing_brand_knowledge_versions', fn (Blueprint $table) => $table->index('tenant_id', 'md_1_tenant_idx'));
        }

    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_brand_knowledge_versions');
    }
};
