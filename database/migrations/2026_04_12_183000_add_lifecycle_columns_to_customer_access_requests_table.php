<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_access_requests')) {
            return;
        }

        Schema::table('customer_access_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_access_requests', 'decision_note')) {
                $table->text('decision_note')->nullable();
            }

            if (! Schema::hasColumn('customer_access_requests', 'rejected_by')) {
                $table->unsignedBigInteger('rejected_by')->nullable()->index();
            }

            if (! Schema::hasColumn('customer_access_requests', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->index();
            }

            if (! Schema::hasColumn('customer_access_requests', 'rejection_note')) {
                $table->text('rejection_note')->nullable();
            }

            if (! Schema::hasColumn('customer_access_requests', 'activation_email_sent_at')) {
                $table->timestamp('activation_email_sent_at')->nullable()->index();
            }

            if (! Schema::hasColumn('customer_access_requests', 'activation_email_last_attempted_at')) {
                $table->timestamp('activation_email_last_attempted_at')->nullable()->index();
            }

            if (! Schema::hasColumn('customer_access_requests', 'activation_email_last_attempt_status')) {
                $table->string('activation_email_last_attempt_status', 40)->nullable();
            }

            if (! Schema::hasColumn('customer_access_requests', 'activation_email_last_sent_at')) {
                $table->timestamp('activation_email_last_sent_at')->nullable()->index();
            }

            if (! Schema::hasColumn('customer_access_requests', 'activation_email_resend_count')) {
                $table->unsignedInteger('activation_email_resend_count')->default(0);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_access_requests')) {
            return;
        }

        Schema::table('customer_access_requests', function (Blueprint $table): void {
            foreach ([
                'decision_note',
                'rejected_by',
                'rejected_at',
                'rejection_note',
                'activation_email_sent_at',
                'activation_email_last_attempted_at',
                'activation_email_last_attempt_status',
                'activation_email_last_sent_at',
                'activation_email_resend_count',
            ] as $column) {
                if (Schema::hasColumn('customer_access_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

