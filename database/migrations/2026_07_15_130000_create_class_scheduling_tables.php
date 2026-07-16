<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_scheduling_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->boolean('public_signup_enabled')->default(false);
            $table->string('timezone', 80)->default('America/New_York');
            $table->string('public_heading')->default('Upcoming classes');
            $table->text('public_intro')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('logo_url', 2048)->nullable();
            $table->string('hero_image_url', 2048)->nullable();
            $table->string('brand_color', 20)->default('#42654a');
            $table->json('default_reminder_offsets')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('scheduled_classes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('category', 80)->nullable()->index();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('capacity')->default(12);
            $table->decimal('price', 10, 2)->nullable();
            $table->string('status', 40)->default('published')->index();
            $table->boolean('registration_open')->default(true);
            $table->string('image_url', 2048)->nullable();
            $table->json('reminder_offsets')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'starts_at', 'status'], 'scheduled_classes_tenant_start_status_idx');
        });

        Schema::create('class_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('scheduled_class_id')->constrained('scheduled_classes')->cascadeOnDelete();
            $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('normalized_email')->nullable();
            $table->string('phone')->nullable();
            $table->string('normalized_phone')->nullable();
            $table->unsignedInteger('seats')->default(1);
            $table->string('status', 40)->default('confirmed')->index();
            $table->boolean('email_reminders_enabled')->default(true);
            $table->boolean('sms_reminders_enabled')->default(false);
            $table->text('notes')->nullable();
            $table->string('source', 80)->default('public_signup');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(
                ['tenant_id', 'scheduled_class_id', 'normalized_email'],
                'class_enrollments_class_email_unique'
            );
            $table->index(['tenant_id', 'scheduled_class_id', 'status'], 'class_enrollments_tenant_class_status_idx');
            $table->index(['tenant_id', 'marketing_profile_id'], 'class_enrollments_tenant_profile_idx');
        });

        Schema::create('class_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('class_enrollment_id')->constrained('class_enrollments')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20);
            $table->timestamp('scheduled_for')->index();
            $table->string('status', 40)->default('scheduled')->index();
            $table->text('message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'scheduled_for'], 'class_reminders_tenant_status_schedule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_reminders');
        Schema::dropIfExists('class_enrollments');
        Schema::dropIfExists('scheduled_classes');
        Schema::dropIfExists('class_scheduling_settings');
    }
};
