<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_billing_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_acceptance_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_authorization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_type', 40);
            $table->string('status', 40)->default('authorized');
            $table->string('provider', 40)->default('stripe');
            $table->string('currency', 3)->default('USD');
            $table->json('line_items');
            $table->unsignedBigInteger('authorized_subtotal_cents')->default(0);
            $table->unsignedBigInteger('provider_tax_cents')->default(0);
            $table->unsignedBigInteger('provider_total_cents')->default(0);
            $table->string('provider_checkout_session_id')->nullable()->unique();
            $table->string('provider_customer_id')->nullable()->index();
            $table->string('provider_payment_intent_id')->nullable()->index();
            $table->string('provider_invoice_id')->nullable()->index();
            $table->string('provider_subscription_id')->nullable()->index();
            $table->string('provider_schedule_id')->nullable()->index();
            $table->string('last_provider_event_id')->nullable();
            $table->string('last_provider_event_type')->nullable();
            $table->timestamp('authorized_at');
            $table->timestamp('checkout_started_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['agreement_version_id', 'order_type']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('tenant_billing_receipts', function (Blueprint $table): void {
            $table->foreignId('tenant_billing_order_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_billing_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tenant_billing_order_id');
        });
        Schema::dropIfExists('tenant_billing_orders');
    }
};
