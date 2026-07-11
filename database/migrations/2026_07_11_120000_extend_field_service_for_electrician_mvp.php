<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('field_service_jobs')) {
            Schema::table('field_service_jobs', function (Blueprint $table): void {
                if (! Schema::hasColumn('field_service_jobs', 'lock_box_code')) {
                    $table->string('lock_box_code', 120)->nullable()->after('customer_phone');
                }

                if (! Schema::hasColumn('field_service_jobs', 'external_source')) {
                    $table->string('external_source', 80)->nullable()->after('completed_at');
                }

                if (! Schema::hasColumn('field_service_jobs', 'external_id')) {
                    $table->string('external_id', 160)->nullable()->after('external_source');
                }
            });

            Schema::table('field_service_jobs', function (Blueprint $table): void {
                $table->index(['tenant_id', 'external_source', 'external_id'], 'fs_jobs_tenant_external_idx');
            });
        }

        if (! Schema::hasTable('field_service_job_notes')) {
            Schema::create('field_service_job_notes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('field_service_job_id')->constrained('field_service_jobs')->cascadeOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('body');
                $table->string('status_update', 40)->nullable()->index();
                $table->timestamp('noted_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'field_service_job_id'], 'fs_notes_tenant_job_idx');
                $table->index(['tenant_id', 'noted_at'], 'fs_notes_tenant_noted_idx');
            });
        }

        if (Schema::hasTable('field_service_job_photos')) {
            Schema::table('field_service_job_photos', function (Blueprint $table): void {
                if (! Schema::hasColumn('field_service_job_photos', 'field_service_job_note_id')) {
                    $table->foreignId('field_service_job_note_id')
                        ->nullable()
                        ->after('field_service_job_id')
                        ->constrained('field_service_job_notes')
                        ->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('field_service_materials')) {
            Schema::table('field_service_materials', function (Blueprint $table): void {
                if (! Schema::hasColumn('field_service_materials', 'external_source')) {
                    $table->string('external_source', 80)->nullable()->after('status');
                }

                if (! Schema::hasColumn('field_service_materials', 'external_id')) {
                    $table->string('external_id', 160)->nullable()->after('external_source');
                }
            });

            Schema::table('field_service_materials', function (Blueprint $table): void {
                $table->index(['tenant_id', 'external_source', 'external_id'], 'fs_materials_tenant_external_idx');
            });
        }

        if (! Schema::hasTable('field_service_reminder_settings')) {
            Schema::create('field_service_reminder_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->boolean('enabled')->default(false);
                $table->string('channel', 40)->default('sms');
                $table->string('cadence', 40)->default('daily');
                $table->string('send_time', 20)->nullable();
                $table->string('timezone', 80)->default('America/New_York');
                $table->string('provider_status', 40)->default('not_verified');
                $table->text('customer_copy')->nullable();
                $table->text('internal_notes')->nullable();
                $table->timestamps();

                $table->unique('tenant_id');
                $table->index(['enabled', 'provider_status'], 'fs_reminders_enabled_provider_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('field_service_reminder_settings');

        if (Schema::hasTable('field_service_job_photos') && Schema::hasColumn('field_service_job_photos', 'field_service_job_note_id')) {
            Schema::table('field_service_job_photos', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('field_service_job_note_id');
            });
        }

        Schema::dropIfExists('field_service_job_notes');

        if (Schema::hasTable('field_service_materials')) {
            Schema::table('field_service_materials', function (Blueprint $table): void {
                if (Schema::hasColumn('field_service_materials', 'external_source') || Schema::hasColumn('field_service_materials', 'external_id')) {
                    $table->dropIndex('fs_materials_tenant_external_idx');
                    $table->dropColumn(array_values(array_filter([
                        Schema::hasColumn('field_service_materials', 'external_source') ? 'external_source' : null,
                        Schema::hasColumn('field_service_materials', 'external_id') ? 'external_id' : null,
                    ])));
                }
            });
        }

        if (Schema::hasTable('field_service_jobs')) {
            Schema::table('field_service_jobs', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('field_service_jobs', 'lock_box_code') ? 'lock_box_code' : null,
                    Schema::hasColumn('field_service_jobs', 'external_source') ? 'external_source' : null,
                    Schema::hasColumn('field_service_jobs', 'external_id') ? 'external_id' : null,
                ]));

                if ($columns !== []) {
                    $table->dropIndex('fs_jobs_tenant_external_idx');
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
