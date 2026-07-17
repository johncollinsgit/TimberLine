<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_direct_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40)->default('draft');
            $table->string('currency', 3)->default('USD');
            $table->string('customer_name');
            $table->string('customer_email');
            $table->json('billing_address');
            $table->unsignedSmallInteger('days_until_due')->default(30);
            $table->string('authorization_reference');
            $table->text('memo')->nullable();
            $table->text('footer')->nullable();
            $table->json('line_items');
            $table->unsignedBigInteger('authorized_subtotal_cents');
            $table->unsignedBigInteger('provider_tax_cents')->default(0);
            $table->unsignedBigInteger('provider_total_cents')->default(0);
            $table->unsignedBigInteger('provider_amount_due_cents')->default(0);
            $table->string('provider_customer_id')->nullable()->index();
            $table->string('provider_invoice_id')->nullable()->unique();
            $table->string('provider_payment_intent_id')->nullable()->index();
            $table->string('provider_invoice_number')->nullable();
            $table->text('hosted_invoice_url')->nullable();
            $table->text('invoice_pdf_url')->nullable();
            $table->string('last_provider_event_id')->nullable();
            $table->string('last_provider_event_type')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_email']);
        });

        Schema::table('tenant_billing_receipts', function (Blueprint $table): void {
            $table->foreignId('tenant_direct_invoice_id')->nullable()->after('tenant_billing_order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_billing_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tenant_direct_invoice_id');
        });
        Schema::dropIfExists('tenant_direct_invoices');
    }
};
