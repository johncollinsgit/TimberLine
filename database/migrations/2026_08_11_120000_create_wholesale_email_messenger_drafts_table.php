<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wholesale_email_messenger_drafts')) {
            return;
        }

        Schema::create('wholesale_email_messenger_drafts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('store_key', 80);
            $table->string('name', 160);
            $table->string('subject', 200);
            $table->json('sections');
            $table->json('personalization')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'store_key', 'name'], 'wem_draft_tenant_store_name_uq');
            $table->foreign('tenant_id', 'wem_draft_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by', 'wem_draft_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'wem_draft_updated_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_email_messenger_drafts');
    }
};
