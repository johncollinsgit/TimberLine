<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('form_templates')) {
            Schema::create('form_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 120)->unique();
                $table->string('name', 190);
                $table->text('description')->nullable();
                $table->string('status', 40)->default('draft')->index();
                $table->string('visibility', 40)->default('internal')->index();
                $table->string('handler_key', 120)->nullable()->index();
                $table->json('schema')->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tenant_forms')) {
            Schema::create('tenant_forms', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('form_template_id')->nullable()->constrained('form_templates')->nullOnDelete();
                $table->string('slug', 120);
                $table->string('name', 190);
                $table->text('description')->nullable();
                $table->string('status', 40)->default('draft')->index();
                $table->string('channel', 60)->default('backstage')->index();
                $table->json('schema')->nullable();
                $table->json('destination')->nullable();
                $table->json('settings')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['tenant_id', 'slug'], 'tenant_forms_tenant_slug_unique');
            });
        }

        if (! Schema::hasTable('form_submissions')) {
            Schema::create('form_submissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tenant_form_id')->constrained('tenant_forms')->cascadeOnDelete();
                $table->foreignId('customer_access_request_id')->nullable()->constrained('customer_access_requests')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 40)->default('submitted')->index();
                $table->string('source', 80)->nullable()->index();
                $table->string('source_key', 190)->nullable()->unique();
                $table->timestamp('submitted_at')->nullable()->index();
                $table->string('submitter_name', 190)->nullable();
                $table->string('submitter_email', 190)->nullable()->index();
                $table->string('submitter_phone', 80)->nullable();
                $table->string('submitter_company', 190)->nullable();
                $table->json('payload')->nullable();
                $table->json('normalized_payload')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('tenant_forms');
        Schema::dropIfExists('form_templates');
    }
};
