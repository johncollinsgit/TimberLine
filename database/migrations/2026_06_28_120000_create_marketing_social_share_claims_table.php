<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_profile_scent_quiz_results', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_profile_scent_quiz_results', 'public_share_token')) {
                $table->string('public_share_token', 80)->nullable()->unique()->after('personality_body');
            }
        });

        Schema::create('marketing_social_share_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('marketing_profile_id');
            $table->string('platform', 32);
            $table->string('target_type', 64);
            $table->string('target_id', 160);
            $table->string('share_url', 2048);
            $table->string('status', 40)->default('started')->index();
            $table->string('proof_url', 2048)->nullable();
            $table->text('proof_text')->nullable();
            $table->unsignedBigInteger('candle_cash_transaction_id')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('claimed_at')->nullable()->index();
            $table->timestamp('awarded_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['marketing_profile_id', 'platform', 'target_type', 'target_id'],
                'mssc_profile_platform_target_unique'
            );
            $table->index(['tenant_id', 'platform', 'status'], 'mssc_tenant_platform_status_idx');
            $table->index(['tenant_id', 'target_type', 'target_id'], 'mssc_tenant_target_idx');

            $table->foreign('tenant_id', 'mssc_tenant_fk')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table->foreign('marketing_profile_id', 'mssc_profile_fk')
                ->references('id')
                ->on('marketing_profiles')
                ->cascadeOnDelete();
            $table->foreign('candle_cash_transaction_id', 'mssc_transaction_fk')
                ->references('id')
                ->on('candle_cash_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_social_share_claims');

        Schema::table('marketing_profile_scent_quiz_results', function (Blueprint $table): void {
            if (Schema::hasColumn('marketing_profile_scent_quiz_results', 'public_share_token')) {
                $table->dropUnique('marketing_profile_scent_quiz_results_public_share_token_unique');
                $table->dropColumn('public_share_token');
            }
        });
    }
};
