<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_external_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_profile_id')
                ->nullable()
                ->constrained('marketing_profiles')
                ->nullOnDelete();
            $table->string('provider')->index();
            $table->string('integration')->index();
            $table->string('store_key')->nullable()->index();
            $table->string('external_customer_id')->index();
            $table->string('external_customer_gid')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('normalized_email')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('normalized_phone')->nullable()->index();
            $table->boolean('accepts_marketing')->nullable();
            $table->unsignedInteger('order_count')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('source_channels')->nullable();
            $table->json('raw_metafields')->nullable();
            $table->integer('points_balance')->nullable();
            $table->string('vip_tier')->nullable();
            $table->text('referral_link')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['provider', 'integration', 'store_key', 'external_customer_id'],
                'cep_provider_integration_store_customer_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_external_profiles');
    }
};
