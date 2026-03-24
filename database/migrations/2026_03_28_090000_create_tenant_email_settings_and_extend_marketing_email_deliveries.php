<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_email_settings')) {
            Schema::create('tenant_email_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->unique();
                $table->string('email_provider', 40)->default('sendgrid');
                $table->boolean('email_enabled')->default(false);
                $table->string('from_name')->nullable();
                $table->string('from_email')->nullable();
                $table->string('reply_to_email')->nullable();
                $table->string('provider_status', 40)->default('not_configured');
                $table->text('provider_config')->nullable();
                $table->boolean('analytics_enabled')->default(true);
                $table->timestamp('last_tested_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index('email_provider', 'tenant_email_settings_provider_index');
                $table->foreign('tenant_id', 'tenant_email_settings_tenant_fk')
                    ->references('id')
                    ->on('tenants')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('marketing_email_deliveries')) {
            Schema::table('marketing_email_deliveries', function (Blueprint $table): void {
                if (! Schema::hasColumn('marketing_email_deliveries', 'tenant_id')) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('marketing_profile_id');
                    $table->index('tenant_id', 'marketing_email_deliveries_tenant_id_index');
                }

                if (! Schema::hasColumn('marketing_email_deliveries', 'provider')) {
                    $table->string('provider', 60)->nullable()->after('tenant_id');
                    $table->index('provider', 'marketing_email_deliveries_provider_index');
                }

                if (! Schema::hasColumn('marketing_email_deliveries', 'provider_message_id')) {
                    $table->string('provider_message_id')->nullable()->after('provider');
                    $table->index('provider_message_id', 'marketing_email_deliveries_provider_message_id_index');
                }

                if (! Schema::hasColumn('marketing_email_deliveries', 'campaign_type')) {
                    $table->string('campaign_type', 120)->nullable()->after('provider_message_id');
                    $table->index('campaign_type', 'marketing_email_deliveries_campaign_type_index');
                }

                if (! Schema::hasColumn('marketing_email_deliveries', 'template_key')) {
                    $table->string('template_key', 120)->nullable()->after('campaign_type');
                    $table->index('template_key', 'marketing_email_deliveries_template_key_index');
                }

                if (! Schema::hasColumn('marketing_email_deliveries', 'metadata')) {
                    $table->json('metadata')->nullable()->after('raw_payload');
                }
            });

            try {
                Schema::table('marketing_email_deliveries', function (Blueprint $table): void {
                    $table->foreign('tenant_id', 'marketing_email_deliveries_tenant_fk')
                        ->references('id')
                        ->on('tenants')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
                // Safe no-op if foreign key already exists.
            }

            if (Schema::hasColumn('marketing_email_deliveries', 'provider')) {
                DB::table('marketing_email_deliveries')
                    ->whereNull('provider')
                    ->whereNotNull('sendgrid_message_id')
                    ->update(['provider' => 'sendgrid']);
            }

            if (Schema::hasColumn('marketing_email_deliveries', 'provider_message_id')) {
                DB::table('marketing_email_deliveries')
                    ->whereNull('provider_message_id')
                    ->whereNotNull('sendgrid_message_id')
                    ->update(['provider_message_id' => DB::raw('sendgrid_message_id')]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('marketing_email_deliveries')) {
            try {
                Schema::table('marketing_email_deliveries', function (Blueprint $table): void {
                    $table->dropForeign('marketing_email_deliveries_tenant_fk');
                });
            } catch (\Throwable) {
                // Safe no-op when FK is absent.
            }

            Schema::table('marketing_email_deliveries', function (Blueprint $table): void {
                if (Schema::hasColumn('marketing_email_deliveries', 'metadata')) {
                    $table->dropColumn('metadata');
                }

                if (Schema::hasColumn('marketing_email_deliveries', 'template_key')) {
                    $table->dropIndex('marketing_email_deliveries_template_key_index');
                    $table->dropColumn('template_key');
                }

                if (Schema::hasColumn('marketing_email_deliveries', 'campaign_type')) {
                    $table->dropIndex('marketing_email_deliveries_campaign_type_index');
                    $table->dropColumn('campaign_type');
                }

                if (Schema::hasColumn('marketing_email_deliveries', 'provider_message_id')) {
                    $table->dropIndex('marketing_email_deliveries_provider_message_id_index');
                    $table->dropColumn('provider_message_id');
                }

                if (Schema::hasColumn('marketing_email_deliveries', 'provider')) {
                    $table->dropIndex('marketing_email_deliveries_provider_index');
                    $table->dropColumn('provider');
                }

                if (Schema::hasColumn('marketing_email_deliveries', 'tenant_id')) {
                    $table->dropIndex('marketing_email_deliveries_tenant_id_index');
                    $table->dropColumn('tenant_id');
                }
            });
        }

        Schema::dropIfExists('tenant_email_settings');
    }
};
