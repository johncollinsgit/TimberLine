<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('development_notes')) {
            Schema::create('development_notes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('title', 180)->nullable();
                $table->text('body');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('shopify_admin_user_id', 190)->nullable();
                $table->string('shopify_admin_email', 255)->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'updated_at'], 'development_notes_tenant_updated_idx');
            });
        }

        if (! Schema::hasTable('development_change_logs')) {
            Schema::create('development_change_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('title', 180);
                $table->text('summary');
                $table->string('area', 120)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('shopify_admin_user_id', 190)->nullable();
                $table->string('shopify_admin_email', 255)->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'created_at'], 'development_change_logs_tenant_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('development_change_logs');
        Schema::dropIfExists('development_notes');
    }
};
