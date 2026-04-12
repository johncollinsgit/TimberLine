<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        try {
            if ($driver === 'mysql') {
                $database = $connection->getDatabaseName();

                $rows = $connection->select(
                    'select 1 from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
                    [$database, $table, $indexName]
                );

                return count($rows) > 0;
            }

            if ($driver === 'sqlite') {
                $rows = $connection->select("pragma index_list('{$table}')");

                foreach ($rows as $row) {
                    $name = $row->name ?? $row['name'] ?? null;
                    if ($name === $indexName) {
                        return true;
                    }
                }

                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    public function up(): void
    {
        if (! Schema::hasTable('customer_access_requests')) {
            return;
        }

        $hasDecisionNote = Schema::hasColumn('customer_access_requests', 'decision_note');
        $hasRejectedBy = Schema::hasColumn('customer_access_requests', 'rejected_by');
        $hasRejectedAt = Schema::hasColumn('customer_access_requests', 'rejected_at');
        $hasRejectionNote = Schema::hasColumn('customer_access_requests', 'rejection_note');
        $hasActivationEmailSentAt = Schema::hasColumn('customer_access_requests', 'activation_email_sent_at');
        $hasActivationEmailLastAttemptedAt = Schema::hasColumn('customer_access_requests', 'activation_email_last_attempted_at');
        $hasActivationEmailLastAttemptStatus = Schema::hasColumn('customer_access_requests', 'activation_email_last_attempt_status');
        $hasActivationEmailLastSentAt = Schema::hasColumn('customer_access_requests', 'activation_email_last_sent_at');
        $hasActivationEmailResendCount = Schema::hasColumn('customer_access_requests', 'activation_email_resend_count');

        Schema::table('customer_access_requests', function (Blueprint $table) use (
            $hasDecisionNote,
            $hasRejectedBy,
            $hasRejectedAt,
            $hasRejectionNote,
            $hasActivationEmailSentAt,
            $hasActivationEmailLastAttemptedAt,
            $hasActivationEmailLastAttemptStatus,
            $hasActivationEmailLastSentAt,
            $hasActivationEmailResendCount,
        ): void {
            if (! $hasDecisionNote) {
                $table->text('decision_note')->nullable();
            }

            if (! $hasRejectedBy) {
                $table->unsignedBigInteger('rejected_by')->nullable();
            }

            if (! $hasRejectedAt) {
                $table->timestamp('rejected_at')->nullable();
            }

            if (! $hasRejectionNote) {
                $table->text('rejection_note')->nullable();
            }

            if (! $hasActivationEmailSentAt) {
                $table->timestamp('activation_email_sent_at')->nullable();
            }

            if (! $hasActivationEmailLastAttemptedAt) {
                $table->timestamp('activation_email_last_attempted_at')->nullable();
            }

            if (! $hasActivationEmailLastAttemptStatus) {
                $table->string('activation_email_last_attempt_status', 40)->nullable();
            }

            if (! $hasActivationEmailLastSentAt) {
                $table->timestamp('activation_email_last_sent_at')->nullable();
            }

            if (! $hasActivationEmailResendCount) {
                $table->unsignedInteger('activation_email_resend_count')->default(0);
            }

            if (
                (! $this->indexExists('customer_access_requests', 'car_rejected_by_idx'))
                && (! $this->indexExists('customer_access_requests', 'customer_access_requests_rejected_by_index'))
            ) {
                $table->index('rejected_by', 'car_rejected_by_idx');
            }

            if (
                (! $this->indexExists('customer_access_requests', 'car_rejected_at_idx'))
                && (! $this->indexExists('customer_access_requests', 'customer_access_requests_rejected_at_index'))
            ) {
                $table->index('rejected_at', 'car_rejected_at_idx');
            }

            if (
                (! $this->indexExists('customer_access_requests', 'car_act_email_sent_idx'))
                && (! $this->indexExists('customer_access_requests', 'customer_access_requests_activation_email_sent_at_index'))
            ) {
                $table->index('activation_email_sent_at', 'car_act_email_sent_idx');
            }

            if (
                (! $this->indexExists('customer_access_requests', 'car_act_email_attempt_idx'))
                && (! $this->indexExists('customer_access_requests', 'customer_access_requests_activation_email_last_attempted_at_index'))
            ) {
                $table->index('activation_email_last_attempted_at', 'car_act_email_attempt_idx');
            }

            if (
                (! $this->indexExists('customer_access_requests', 'car_act_email_last_sent_idx'))
                && (! $this->indexExists('customer_access_requests', 'customer_access_requests_activation_email_last_sent_at_index'))
            ) {
                $table->index('activation_email_last_sent_at', 'car_act_email_last_sent_idx');
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
