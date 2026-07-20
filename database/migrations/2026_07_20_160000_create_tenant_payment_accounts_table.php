<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_payment_accounts')) {
            return;
        }

        Schema::create('tenant_payment_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 40)->default('stripe_connect');
            $table->string('provider_account_id')->nullable()->unique();
            $table->string('status', 40)->default('not_started')->index();
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
            $table->unsignedInteger('platform_fee_bps')->default(0);
            $table->timestamp('onboarding_started_at')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_accounts');
    }
};
