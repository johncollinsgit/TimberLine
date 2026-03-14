<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_review_histories', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_review_histories', 'reviewer_name')) {
                $table->string('reviewer_name', 160)->nullable()->after('body');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'reviewer_email')) {
                $table->string('reviewer_email', 190)->nullable()->after('reviewer_name');
                $table->index('reviewer_email', 'mrh_reviewer_email_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'product_handle')) {
                $table->string('product_handle', 160)->nullable()->after('product_id');
                $table->index('product_handle', 'mrh_product_handle_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'product_url')) {
                $table->string('product_url', 500)->nullable()->after('product_handle');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'status')) {
                $table->string('status', 32)->default('approved')->after('is_published');
                $table->index('status', 'mrh_status_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'submission_source')) {
                $table->string('submission_source', 80)->nullable()->after('status');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('reviewed_at');
                $table->index('submitted_at', 'mrh_submitted_at_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('submitted_at');
                $table->index('approved_at', 'mrh_approved_at_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
                $table->index('rejected_at', 'mrh_rejected_at_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'moderated_by')) {
                $table->unsignedBigInteger('moderated_by')->nullable()->after('rejected_at');
                $table->index('moderated_by', 'mrh_moderated_by_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'moderation_notes')) {
                $table->text('moderation_notes')->nullable()->after('moderated_by');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'notification_sent_at')) {
                $table->timestamp('notification_sent_at')->nullable()->after('moderation_notes');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'candle_cash_task_event_id')) {
                $table->unsignedBigInteger('candle_cash_task_event_id')->nullable()->after('notification_sent_at');
                $table->index('candle_cash_task_event_id', 'mrh_cc_event_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'candle_cash_task_completion_id')) {
                $table->unsignedBigInteger('candle_cash_task_completion_id')->nullable()->after('candle_cash_task_event_id');
                $table->index('candle_cash_task_completion_id', 'mrh_cc_completion_idx');
            }
        });

        if ($this->supportsNamedForeignKeys() && ! $this->foreignKeyExists('marketing_review_histories', 'mrh_moderated_by_fk')) {
            Schema::table('marketing_review_histories', function (Blueprint $table): void {
                $table->foreign('moderated_by', 'mrh_moderated_by_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if ($this->supportsNamedForeignKeys() && ! $this->foreignKeyExists('marketing_review_histories', 'mrh_cc_event_fk')) {
            Schema::table('marketing_review_histories', function (Blueprint $table): void {
                $table->foreign('candle_cash_task_event_id', 'mrh_cc_event_fk')->references('id')->on('candle_cash_task_events')->nullOnDelete();
            });
        }

        if ($this->supportsNamedForeignKeys() && ! $this->foreignKeyExists('marketing_review_histories', 'mrh_cc_completion_fk')) {
            Schema::table('marketing_review_histories', function (Blueprint $table): void {
                $table->foreign('candle_cash_task_completion_id', 'mrh_cc_completion_fk')->references('id')->on('candle_cash_task_completions')->nullOnDelete();
            });
        }

        DB::table('marketing_review_histories')
            ->whereNull('submission_source')
            ->update([
                'submission_source' => DB::raw("case when provider = 'growave' then 'growave_import' else 'native' end"),
            ]);

        DB::table('marketing_review_histories')
            ->whereNull('status')
            ->orWhere('status', '')
            ->update([
                'status' => DB::raw("case when coalesce(is_published, 1) = 1 then 'approved' else 'rejected' end"),
            ]);

        DB::table('marketing_review_histories')
            ->whereNull('submitted_at')
            ->update([
                'submitted_at' => DB::raw('coalesce(reviewed_at, source_synced_at, created_at)'),
            ]);

        DB::table('marketing_review_histories')
            ->where(function ($query): void {
                $query->whereNull('approved_at')->where('status', 'approved');
            })
            ->update([
                'approved_at' => DB::raw('coalesce(reviewed_at, source_synced_at, created_at)'),
                'is_published' => 1,
            ]);

        DB::table('marketing_review_histories')
            ->where(function ($query): void {
                $query->whereNull('rejected_at')->where('status', 'rejected');
            })
            ->update([
                'rejected_at' => DB::raw('coalesce(reviewed_at, source_synced_at, created_at)'),
                'is_published' => 0,
            ]);

        $profileColumns = collect(Schema::getColumnListing('marketing_profiles'));
        $profileNameColumnsAvailable = $profileColumns->contains('first_name') && $profileColumns->contains('last_name');
        $profileEmailColumnAvailable = $profileColumns->contains('email');

        DB::table('marketing_review_histories')
            ->whereNull('reviewer_name')
            ->whereNotNull('marketing_profile_id')
            ->orderBy('id')
            ->select(['id', 'marketing_profile_id', 'reviewer_email'])
            ->chunkById(200, function ($reviews) use ($profileNameColumnsAvailable, $profileEmailColumnAvailable): void {
                foreach ($reviews as $review) {
                    $profileQuery = DB::table('marketing_profiles')->where('id', $review->marketing_profile_id);
                    $profile = $profileQuery->first(array_values(array_filter([
                        $profileNameColumnsAvailable ? 'first_name' : null,
                        $profileNameColumnsAvailable ? 'last_name' : null,
                        $profileEmailColumnAvailable ? 'email' : null,
                    ])));

                    if (! $profile) {
                        continue;
                    }

                    $reviewerName = null;
                    if ($profileNameColumnsAvailable) {
                        $reviewerName = trim((string) (($profile->first_name ?? '') . ' ' . ($profile->last_name ?? '')));
                        $reviewerName = $reviewerName !== '' ? $reviewerName : null;
                    }

                    DB::table('marketing_review_histories')
                        ->where('id', $review->id)
                        ->update([
                            'reviewer_name' => $reviewerName,
                            'reviewer_email' => $review->reviewer_email ?: ($profileEmailColumnAvailable ? ($profile->email ?? null) : null),
                        ]);
                }
            });

        $integrationConfig = (array) optional(DB::table('marketing_settings')->where('key', 'candle_cash_integration_config')->first())->value;
        if (is_string($integrationConfig)) {
            $decoded = json_decode($integrationConfig, true);
            $integrationConfig = is_array($decoded) ? $decoded : [];
        }

        $integrationConfig = array_merge([
            'google_review_enabled' => true,
            'google_review_url' => 'https://g.page/r/CTucm4R1-wmOEAI/review',
            'product_review_enabled' => true,
            'product_review_platform' => 'backstage_native',
            'product_review_matching_strategy' => 'native_review_submission',
            'product_review_moderation_enabled' => false,
            'product_review_allow_guest' => true,
            'product_review_min_length' => 24,
            'product_review_notification_email' => 'info@theforestrystudio.com',
        ], $integrationConfig);

        if (blank($integrationConfig['google_review_url'] ?? null)) {
            $integrationConfig['google_review_url'] = 'https://g.page/r/CTucm4R1-wmOEAI/review';
        }

        DB::table('marketing_settings')->updateOrInsert(
            ['key' => 'candle_cash_integration_config'],
            [
                'value' => json_encode($integrationConfig, JSON_UNESCAPED_SLASHES),
                'description' => 'Integration and verification settings for automatic-first Candle Cash tasks.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('candle_cash_tasks')
            ->where('handle', 'google-review')
            ->update([
                'reward_amount' => 3.00,
                'enabled' => 1,
                'button_text' => 'Leave a Google review',
                'updated_at' => now(),
            ]);

        DB::table('candle_cash_tasks')
            ->where('handle', 'product-review')
            ->update([
                'enabled' => 1,
                'button_text' => 'Browse products',
                'action_url' => '/collections/all',
                'metadata' => json_encode(['customer_visible' => true], JSON_UNESCAPED_SLASHES),
                'admin_notes' => 'Backstage-native product reviews are now the verified product review integration.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('marketing_review_histories', function (Blueprint $table): void {
            if ($this->supportsNamedForeignKeys()) {
                foreach ([
                    'mrh_moderated_by_fk',
                    'mrh_cc_event_fk',
                    'mrh_cc_completion_fk',
                ] as $foreign) {
                    if ($this->foreignKeyExists('marketing_review_histories', $foreign)) {
                        $table->dropForeign($foreign);
                    }
                }
            }

            foreach ([
                'mrh_reviewer_email_idx',
                'mrh_product_handle_idx',
                'mrh_status_idx',
                'mrh_submitted_at_idx',
                'mrh_approved_at_idx',
                'mrh_rejected_at_idx',
                'mrh_moderated_by_idx',
                'mrh_cc_event_idx',
                'mrh_cc_completion_idx',
            ] as $index) {
                if ($this->indexExists('marketing_review_histories', $index)) {
                    $table->dropIndex($index);
                }
            }

            foreach ([
                'reviewer_name',
                'reviewer_email',
                'product_handle',
                'product_url',
                'status',
                'submission_source',
                'submitted_at',
                'approved_at',
                'rejected_at',
                'moderated_by',
                'moderation_notes',
                'notification_sent_at',
                'candle_cash_task_event_id',
                'candle_cash_task_completion_id',
            ] as $column) {
                if (Schema::hasColumn('marketing_review_histories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        if ($this->isSqlite()) {
            return collect(DB::select('PRAGMA index_list("' . $table . '")'))
                ->contains(fn ($row) => ($row->name ?? null) === $index);
        }

        return collect(DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index]))->isNotEmpty();
    }

    protected function foreignKeyExists(string $table, string $foreign): bool
    {
        if (! $this->supportsNamedForeignKeys()) {
            return false;
        }

        return collect(DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ? AND CONSTRAINT_NAME = ?',
            [$table, 'FOREIGN KEY', $foreign]
        ))->isNotEmpty();
    }

    protected function supportsNamedForeignKeys(): bool
    {
        return ! $this->isSqlite();
    }

    protected function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
};
