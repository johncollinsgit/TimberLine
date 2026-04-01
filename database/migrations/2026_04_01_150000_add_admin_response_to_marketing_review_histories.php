<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_review_histories', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_review_histories', 'admin_response')) {
                $table->text('admin_response')->nullable()->after('moderation_notes');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'admin_response_created_at')) {
                $table->timestamp('admin_response_created_at')->nullable()->after('admin_response');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'admin_response_updated_at')) {
                $table->timestamp('admin_response_updated_at')->nullable()->after('admin_response_created_at');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'admin_response_by')) {
                $table->unsignedBigInteger('admin_response_by')->nullable()->after('admin_response_updated_at');
                $table->index('admin_response_by', 'mrh_admin_response_by_idx');
            }
            if (! Schema::hasColumn('marketing_review_histories', 'admin_response_notified_at')) {
                $table->timestamp('admin_response_notified_at')->nullable()->after('admin_response_by');
            }
        });

        if ($this->supportsNamedForeignKeys() && ! $this->foreignKeyExists('marketing_review_histories', 'mrh_admin_response_by_fk')) {
            Schema::table('marketing_review_histories', function (Blueprint $table): void {
                $table->foreign('admin_response_by', 'mrh_admin_response_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('marketing_review_histories', function (Blueprint $table): void {
            if ($this->supportsNamedForeignKeys() && $this->foreignKeyExists('marketing_review_histories', 'mrh_admin_response_by_fk')) {
                $table->dropForeign('mrh_admin_response_by_fk');
            }

            foreach ([
                'mrh_admin_response_by_idx',
            ] as $index) {
                if ($this->indexExists('marketing_review_histories', $index)) {
                    $table->dropIndex($index);
                }
            }

            foreach ([
                'admin_response',
                'admin_response_created_at',
                'admin_response_updated_at',
                'admin_response_by',
                'admin_response_notified_at',
            ] as $column) {
                if (Schema::hasColumn('marketing_review_histories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    protected function supportsNamedForeignKeys(): bool
    {
        return method_exists($this, 'foreignKeyExists');
    }
};
