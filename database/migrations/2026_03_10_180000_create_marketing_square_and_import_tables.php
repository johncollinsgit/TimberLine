<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('square_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('square_customer_id')->unique();
            $table->string('given_name')->nullable();
            $table->string('family_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('reference_id')->nullable()->index();
            $table->json('group_ids')->nullable();
            $table->json('segment_ids')->nullable();
            $table->json('preferences')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('square_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('square_order_id')->unique();
            $table->string('square_customer_id')->nullable()->index();
            $table->string('location_id')->nullable()->index();
            $table->string('state')->nullable()->index();
            $table->bigInteger('total_money_amount')->nullable();
            $table->string('total_money_currency', 8)->nullable();
            $table->timestamp('closed_at')->nullable()->index();
            $table->string('source_name')->nullable()->index();
            $table->json('raw_tax_names')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('square_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('square_payment_id')->unique();
            $table->string('square_order_id')->nullable()->index();
            $table->string('square_customer_id')->nullable()->index();
            $table->string('location_id')->nullable()->index();
            $table->bigInteger('amount_money')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('status')->nullable()->index();
            $table->string('source_type')->nullable();
            $table->timestamp('created_at_source')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('marketing_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->string('status')->default('running')->index();
            $table->string('source_label')->nullable();
            $table->string('file_name')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->json('summary')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('marketing_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_import_run_id')
                ->constrained('marketing_import_runs')
                ->cascadeOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('external_key')->nullable()->index();
            $table->string('status')->default('imported')->index();
            $table->json('messages')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['marketing_import_run_id', 'status'], 'mir_run_status_idx');
        });

        Schema::create('marketing_external_campaign_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_profile_id')
                ->constrained('marketing_profiles')
                ->cascadeOnDelete();
            $table->string('source_type')->index();
            $table->string('external_contact_id')->nullable()->index();
            $table->unsignedInteger('sends_count')->default(0);
            $table->unsignedInteger('opens_count')->default(0);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->timestamp('unsubscribed_at')->nullable()->index();
            $table->timestamp('last_engaged_at')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['marketing_profile_id', 'source_type', 'external_contact_id'],
                'mecs_profile_source_external_unique'
            );
        });

        Schema::create('marketing_order_event_attributions', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type')->index();
            $table->string('source_id')->index();
            $table->foreignId('event_instance_id')->constrained('event_instances')->cascadeOnDelete();
            $table->string('attribution_method')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_type', 'source_id', 'event_instance_id'],
                'moea_source_event_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_order_event_attributions');
        Schema::dropIfExists('marketing_external_campaign_stats');
        Schema::dropIfExists('marketing_import_rows');
        Schema::dropIfExists('marketing_import_runs');
        Schema::dropIfExists('square_payments');
        Schema::dropIfExists('square_orders');
        Schema::dropIfExists('square_customers');
    }
};
