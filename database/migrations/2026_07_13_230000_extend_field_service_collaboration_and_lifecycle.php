<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('field_service_jobs', function (Blueprint $table): void {
            $table->string('operational_status', 40)->nullable()->after('status')->index();
            $table->string('status_source', 24)->default('system')->after('operational_status');
            $table->timestamp('last_financial_activity_at')->nullable()->after('completed_at')->index();
            $table->timestamp('archived_at')->nullable()->after('last_financial_activity_at')->index();
        });

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

        Schema::create('field_service_job_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_service_job_id')->constrained('field_service_jobs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 24)->default('member');
            $table->boolean('following')->default(true);
            $table->timestamps();
            $table->unique(['field_service_job_id', 'user_id'], 'fs_job_participant_unique');
            $table->index(['tenant_id', 'user_id'], 'fs_job_participant_tenant_user_idx');
        });

        Schema::create('field_service_job_note_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_service_job_note_id')->constrained('field_service_job_notes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['field_service_job_note_id', 'user_id'], 'fs_job_note_mention_unique');
        });

        Schema::create('tenant_member_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('phone')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->boolean('push_enabled')->default(true);
            $table->boolean('operational_sms_enabled')->default(false);
            $table->timestamp('operational_sms_opted_in_at')->nullable();
            $table->string('job_comment_notifications', 24)->default('participating');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id'], 'tenant_member_preference_unique');
        });

        Schema::create('field_service_job_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_service_job_id')->constrained('field_service_jobs')->cascadeOnDelete();
            $table->foreignId('field_service_job_note_id')->nullable()->constrained('field_service_job_notes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('status', 30)->default('pending');
            $table->string('provider_message_id')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['field_service_job_note_id', 'user_id', 'channel'], 'fs_job_notification_unique');
            $table->index(['tenant_id', 'user_id', 'read_at'], 'fs_job_notification_inbox_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_service_job_notifications');
        Schema::dropIfExists('tenant_member_preferences');
        Schema::dropIfExists('field_service_job_note_mentions');
        Schema::dropIfExists('field_service_job_participants');
        Schema::table('field_service_jobs', function (Blueprint $table): void {
            $table->dropColumn(['operational_status', 'status_source', 'last_financial_activity_at', 'archived_at']);
        });
    }
};
