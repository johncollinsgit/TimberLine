<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A prior interrupted production release created this table but did not
        // record the migration. Treat an existing table as an already-created
        // base and let the follow-up reconciliation migration verify indexes.
        if (Schema::hasTable('tenant_billing_refunds')) {
            return;
        }

        Schema::create('tenant_billing_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_billing_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_billing_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_direct_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 40);
            $table->string('provider_refund_id')->nullable()->unique();
            $table->string('provider_payment_intent_id')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('USD');
            $table->string('reason', 80)->default('requested_by_customer');
            $table->text('note')->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->timestamp('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_billing_receipt_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_refunds');
    }
};
