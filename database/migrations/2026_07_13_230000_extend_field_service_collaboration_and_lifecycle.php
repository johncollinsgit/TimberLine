<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('field_service_jobs', 'operational_status')) {
            Schema::table('field_service_jobs', fn (Blueprint $table) => $table->string('operational_status', 40)->nullable()->after('status')->index());
        }
        if (! Schema::hasColumn('field_service_jobs', 'status_source')) {
            Schema::table('field_service_jobs', fn (Blueprint $table) => $table->string('status_source', 24)->default('system')->after('operational_status'));
        }
        if (! Schema::hasColumn('field_service_jobs', 'last_financial_activity_at')) {
            Schema::table('field_service_jobs', fn (Blueprint $table) => $table->timestamp('last_financial_activity_at')->nullable()->after('completed_at')->index());
        }
        if (! Schema::hasColumn('field_service_jobs', 'archived_at')) {
            Schema::table('field_service_jobs', fn (Blueprint $table) => $table->timestamp('archived_at')->nullable()->after('last_financial_activity_at')->index());
        }

        DB::table('field_service_jobs')->select(['id', 'status'])->orderBy('id')->chunkById(500, function ($jobs): void {
            foreach ($jobs as $job) {
                $status = match (strtolower(trim((string) $job->status))) {
                    'done', 'complete', 'completed' => 'complete',
                    'quoted', 'quote', 'estimate', 'estimated' => 'quote',
                    'cancelled', 'canceled', 'closed' => 'history',
                    'blocked' => 'blocked',
                    default => 'active',
                };
                DB::table('field_service_jobs')->where('id', $job->id)->update(['operational_status' => $status]);
            }
        });

        if (! Schema::hasTable('field_service_job_participants')) {
            Schema::create('field_service_job_participants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id');
                $table->foreignId('field_service_job_id');
                $table->foreignId('user_id');
                $table->string('role', 24)->default('member');
                $table->boolean('following')->default(true);
                $table->timestamps();
                $table->foreign('tenant_id', 'fs_job_part_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('field_service_job_id', 'fs_job_part_job_fk')->references('id')->on('field_service_jobs')->cascadeOnDelete();
                $table->foreign('user_id', 'fs_job_part_user_fk')->references('id')->on('users')->cascadeOnDelete();
                $table->unique(['field_service_job_id', 'user_id'], 'fs_job_participant_unique');
                $table->index(['tenant_id', 'user_id'], 'fs_job_participant_tenant_user_idx');
            });
        }

        if (! Schema::hasTable('field_service_job_note_mentions')) {
            Schema::create('field_service_job_note_mentions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id');
                $table->foreignId('field_service_job_note_id');
                $table->foreignId('user_id');
                $table->timestamps();
                $table->foreign('tenant_id', 'fs_note_mention_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('field_service_job_note_id', 'fs_note_mention_note_fk')->references('id')->on('field_service_job_notes')->cascadeOnDelete();
                $table->foreign('user_id', 'fs_note_mention_user_fk')->references('id')->on('users')->cascadeOnDelete();
                $table->unique(['field_service_job_note_id', 'user_id'], 'fs_job_note_mention_unique');
            });
        }

        if (! Schema::hasTable('tenant_member_preferences')) {
            Schema::create('tenant_member_preferences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id');
                $table->foreignId('user_id');
                $table->text('phone')->nullable();
                $table->timestamp('phone_verified_at')->nullable();
                $table->boolean('push_enabled')->default(true);
                $table->boolean('operational_sms_enabled')->default(false);
                $table->timestamp('operational_sms_opted_in_at')->nullable();
                $table->string('job_comment_notifications', 24)->default('participating');
                $table->timestamps();
                $table->foreign('tenant_id', 'tenant_member_pref_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('user_id', 'tenant_member_pref_user_fk')->references('id')->on('users')->cascadeOnDelete();
                $table->unique(['tenant_id', 'user_id'], 'tenant_member_preference_unique');
            });
        }

        if (! Schema::hasTable('field_service_job_notifications')) {
            Schema::create('field_service_job_notifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id');
                $table->foreignId('field_service_job_id');
                $table->foreignId('field_service_job_note_id')->nullable();
                $table->foreignId('user_id');
                $table->string('channel', 20);
                $table->string('status', 30)->default('pending');
                $table->string('provider_message_id')->nullable();
                $table->string('failure_code', 80)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->foreign('tenant_id', 'fs_job_notification_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('field_service_job_id', 'fs_job_notification_job_fk')->references('id')->on('field_service_jobs')->cascadeOnDelete();
                $table->foreign('field_service_job_note_id', 'fs_job_notification_note_fk')->references('id')->on('field_service_job_notes')->cascadeOnDelete();
                $table->foreign('user_id', 'fs_job_notification_user_fk')->references('id')->on('users')->cascadeOnDelete();
                $table->unique(['field_service_job_note_id', 'user_id', 'channel'], 'fs_job_notification_unique');
                $table->index(['tenant_id', 'user_id', 'read_at'], 'fs_job_notification_inbox_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('field_service_job_notifications');
        Schema::dropIfExists('tenant_member_preferences');
        Schema::dropIfExists('field_service_job_note_mentions');
        Schema::dropIfExists('field_service_job_participants');
        $columns = collect(['operational_status', 'status_source', 'last_financial_activity_at', 'archived_at'])
            ->filter(fn (string $column): bool => Schema::hasColumn('field_service_jobs', $column))->values()->all();
        if ($columns !== []) {
            Schema::table('field_service_jobs', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
