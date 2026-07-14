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
                if (! Schema::hasColumn('field_service_jobs', 'priority')) {
                    $table->string('priority', 20)->default('normal')->after('status_source')->index();
                }
                if (! Schema::hasColumn('field_service_jobs', 'scheduled_end_at')) {
                    $table->timestamp('scheduled_end_at')->nullable()->after('scheduled_for');
                }
                if (! Schema::hasColumn('field_service_jobs', 'started_at')) {
                    $table->timestamp('started_at')->nullable()->after('scheduled_end_at');
                }
                if (! Schema::hasColumn('field_service_jobs', 'blocked_reason')) {
                    $table->string('blocked_reason', 500)->nullable()->after('started_at');
                }
                if (! Schema::hasColumn('field_service_jobs', 'canceled_at')) {
                    $table->timestamp('canceled_at')->nullable()->after('completed_at');
                }
            });
        }

        if (Schema::hasTable('field_service_tasks')) {
            Schema::table('field_service_tasks', function (Blueprint $table): void {
                if (! Schema::hasColumn('field_service_tasks', 'description')) {
                    $table->text('description')->nullable()->after('title');
                }
                if (! Schema::hasColumn('field_service_tasks', 'priority')) {
                    $table->string('priority', 20)->default('normal')->after('status')->index();
                }
                if (! Schema::hasColumn('field_service_tasks', 'created_by_user_id')) {
                    $table->foreignId('created_by_user_id')->nullable()->after('assigned_user_id')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('field_service_tasks', 'completed_by_user_id')) {
                    $table->foreignId('completed_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('field_service_tasks', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->after('due_at');
                }
            });
        }

        if (Schema::hasTable('field_service_job_notifications')) {
            Schema::table('field_service_job_notifications', function (Blueprint $table): void {
                if (! Schema::hasColumn('field_service_job_notifications', 'event_type')) {
                    $table->string('event_type', 40)->default('comment')->after('channel')->index();
                }
                if (! Schema::hasColumn('field_service_job_notifications', 'event_key')) {
                    $table->string('event_key', 190)->nullable()->after('event_type');
                }
            });
            if (! $this->indexExists('field_service_job_notifications', 'fs_job_notification_event_unique')) {
                Schema::table('field_service_job_notifications', function (Blueprint $table): void {
                    $table->unique(['tenant_id', 'user_id', 'channel', 'event_key'], 'fs_job_notification_event_unique');
                });
            }
        }

        if (Schema::hasTable('tenant_member_preferences')) {
            Schema::table('tenant_member_preferences', function (Blueprint $table): void {
                if (! Schema::hasColumn('tenant_member_preferences', 'upcoming_job_notifications')) {
                    $table->boolean('upcoming_job_notifications')->default(true)->after('job_comment_notifications');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_member_preferences') && Schema::hasColumn('tenant_member_preferences', 'upcoming_job_notifications')) {
            Schema::table('tenant_member_preferences', fn (Blueprint $table) => $table->dropColumn('upcoming_job_notifications'));
        }

        if (Schema::hasTable('field_service_job_notifications')) {
            if ($this->indexExists('field_service_job_notifications', 'fs_job_notification_event_unique')) {
                Schema::table('field_service_job_notifications', fn (Blueprint $table) => $table->dropUnique('fs_job_notification_event_unique'));
            }
            $columns = collect(['event_type', 'event_key'])
                ->filter(fn (string $column): bool => Schema::hasColumn('field_service_job_notifications', $column))->all();
            if ($columns !== []) {
                Schema::table('field_service_job_notifications', fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }

        if (Schema::hasTable('field_service_tasks')) {
            Schema::table('field_service_tasks', function (Blueprint $table): void {
                foreach (['created_by_user_id', 'completed_by_user_id'] as $column) {
                    if (Schema::hasColumn('field_service_tasks', $column)) {
                        $table->dropConstrainedForeignId($column);
                    }
                }
                $columns = collect(['description', 'priority', 'completed_at'])
                    ->filter(fn (string $column): bool => Schema::hasColumn('field_service_tasks', $column))->all();
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('field_service_jobs')) {
            $columns = collect(['priority', 'scheduled_end_at', 'started_at', 'blocked_reason', 'canceled_at'])
                ->filter(fn (string $column): bool => Schema::hasColumn('field_service_jobs', $column))->all();
            if ($columns !== []) {
                Schema::table('field_service_jobs', fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $definition): bool => ($definition['name'] ?? null) === $index);
    }
};
