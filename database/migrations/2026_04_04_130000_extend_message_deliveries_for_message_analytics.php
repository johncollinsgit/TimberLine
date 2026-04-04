<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_message_deliveries')) {
            Schema::table('marketing_message_deliveries', function (Blueprint $table): void {
                if (! Schema::hasColumn('marketing_message_deliveries', 'tenant_id')) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('marketing_profile_id');
                    $table->index('tenant_id', 'marketing_message_deliveries_tenant_id_idx');
                }

                if (! Schema::hasColumn('marketing_message_deliveries', 'store_key')) {
                    $table->string('store_key', 80)->nullable()->after('tenant_id');
                    $table->index('store_key', 'marketing_message_deliveries_store_key_idx');
                }

                if (! Schema::hasColumn('marketing_message_deliveries', 'batch_id')) {
                    $table->string('batch_id', 120)->nullable()->after('store_key');
                    $table->index('batch_id', 'marketing_message_deliveries_batch_id_idx');
                }

                if (! Schema::hasColumn('marketing_message_deliveries', 'source_label')) {
                    $table->string('source_label', 140)->nullable()->after('batch_id');
                    $table->index('source_label', 'marketing_message_deliveries_source_label_idx');
                }

                if (! Schema::hasColumn('marketing_message_deliveries', 'message_subject')) {
                    $table->string('message_subject', 255)->nullable()->after('source_label');
                    $table->index('message_subject', 'marketing_message_deliveries_message_subject_idx');
                }
            });

            try {
                Schema::table('marketing_message_deliveries', function (Blueprint $table): void {
                    $table->foreign('tenant_id', 'marketing_message_deliveries_tenant_fk')
                        ->references('id')
                        ->on('tenants')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
                // Safe no-op when FK already exists in this environment.
            }
        }

        if (Schema::hasTable('marketing_email_deliveries')) {
            Schema::table('marketing_email_deliveries', function (Blueprint $table): void {
                if (! Schema::hasColumn('marketing_email_deliveries', 'store_key')) {
                    $table->string('store_key', 80)->nullable()->after('tenant_id');
                    $table->index('store_key', 'marketing_email_deliveries_store_key_idx');
                }

                if (! Schema::hasColumn('marketing_email_deliveries', 'batch_id')) {
                    $table->string('batch_id', 120)->nullable()->after('store_key');
                    $table->index('batch_id', 'marketing_email_deliveries_batch_id_idx');
                }

                if (! Schema::hasColumn('marketing_email_deliveries', 'source_label')) {
                    $table->string('source_label', 140)->nullable()->after('batch_id');
                    $table->index('source_label', 'marketing_email_deliveries_source_label_idx');
                }

                if (! Schema::hasColumn('marketing_email_deliveries', 'message_subject')) {
                    $table->string('message_subject', 255)->nullable()->after('source_label');
                    $table->index('message_subject', 'marketing_email_deliveries_message_subject_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('marketing_message_deliveries')) {
            try {
                Schema::table('marketing_message_deliveries', function (Blueprint $table): void {
                    $table->dropForeign('marketing_message_deliveries_tenant_fk');
                });
            } catch (\Throwable) {
                // Safe no-op when FK is absent.
            }

            Schema::table('marketing_message_deliveries', function (Blueprint $table): void {
                if (Schema::hasColumn('marketing_message_deliveries', 'message_subject')) {
                    $table->dropIndex('marketing_message_deliveries_message_subject_idx');
                    $table->dropColumn('message_subject');
                }

                if (Schema::hasColumn('marketing_message_deliveries', 'source_label')) {
                    $table->dropIndex('marketing_message_deliveries_source_label_idx');
                    $table->dropColumn('source_label');
                }

                if (Schema::hasColumn('marketing_message_deliveries', 'batch_id')) {
                    $table->dropIndex('marketing_message_deliveries_batch_id_idx');
                    $table->dropColumn('batch_id');
                }

                if (Schema::hasColumn('marketing_message_deliveries', 'store_key')) {
                    $table->dropIndex('marketing_message_deliveries_store_key_idx');
                    $table->dropColumn('store_key');
                }

                if (Schema::hasColumn('marketing_message_deliveries', 'tenant_id')) {
                    $table->dropIndex('marketing_message_deliveries_tenant_id_idx');
                    $table->dropColumn('tenant_id');
                }
            });
        }

        if (Schema::hasTable('marketing_email_deliveries')) {
            Schema::table('marketing_email_deliveries', function (Blueprint $table): void {
                if (Schema::hasColumn('marketing_email_deliveries', 'message_subject')) {
                    $table->dropIndex('marketing_email_deliveries_message_subject_idx');
                    $table->dropColumn('message_subject');
                }

                if (Schema::hasColumn('marketing_email_deliveries', 'source_label')) {
                    $table->dropIndex('marketing_email_deliveries_source_label_idx');
                    $table->dropColumn('source_label');
                }

                if (Schema::hasColumn('marketing_email_deliveries', 'batch_id')) {
                    $table->dropIndex('marketing_email_deliveries_batch_id_idx');
                    $table->dropColumn('batch_id');
                }

                if (Schema::hasColumn('marketing_email_deliveries', 'store_key')) {
                    $table->dropIndex('marketing_email_deliveries_store_key_idx');
                    $table->dropColumn('store_key');
                }
            });
        }
    }
};
