<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modern_forestry_fundraiser_orders')) {
            Schema::create('modern_forestry_fundraiser_orders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('source', 32)->default('zapier');
                $table->string('external_order_id', 190);
                $table->string('order_reference', 190)->nullable();
                $table->string('recipient_name', 190);
                $table->string('recipient_email', 255)->nullable();
                $table->string('recipient_phone', 80)->nullable();
                $table->json('shipping_address');
                $table->string('currency', 3)->default('usd');
                $table->unsignedInteger('subtotal_cents');
                $table->unsignedInteger('discount_cents')->default(0);
                $table->unsignedInteger('shipping_cents')->default(0);
                $table->unsignedInteger('tax_cents')->default(0);
                $table->unsignedInteger('total_cents');
                $table->string('status', 32)->default('needs_review');
                $table->string('fingerprint', 64);
                $table->json('line_items');
                $table->json('source_payload')->nullable();
                $table->timestamp('source_created_at')->nullable();
                $table->timestamp('received_at');
                $table->timestamp('reviewed_at')->nullable();
                $table->string('reviewed_by', 190)->nullable();
                $table->text('review_notes')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id', 'mffo_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->unique(['tenant_id', 'source', 'external_order_id'], 'mffo_tenant_source_external_uq');
                $table->index(['tenant_id', 'status', 'received_at'], 'mffo_tenant_status_received_idx');
            });
        }

        if (! Schema::hasTable('modern_forestry_fundraiser_invoice_packages')) {
            Schema::create('modern_forestry_fundraiser_invoice_packages', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('package_reference', 80)->unique('mffip_package_reference_uq');
                $table->string('status', 32)->default('review_required');
                $table->string('delivery_status', 32)->default('not_sent');
                $table->string('tracking_status', 32)->default('not_available');
                $table->string('payer_name', 190);
                $table->string('payer_email', 255);
                $table->string('notification_email', 255);
                $table->string('currency', 3)->default('usd');
                $table->unsignedSmallInteger('payment_terms_days');
                $table->date('invoice_date');
                $table->date('due_date');
                $table->unsignedInteger('subtotal_cents');
                $table->unsignedInteger('discount_cents')->default(0);
                $table->unsignedInteger('shipping_cents')->default(0);
                $table->unsignedInteger('tax_cents')->default(0);
                $table->unsignedInteger('total_cents');
                $table->json('order_ids');
                $table->json('invoice_lines');
                $table->json('review_notes')->nullable();
                $table->string('prepared_by', 190)->nullable();
                $table->timestamp('prepared_at');
                $table->timestamps();

                $table->foreign('tenant_id', 'mffip_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->index(['tenant_id', 'status', 'prepared_at'], 'mffip_tenant_status_prepared_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('modern_forestry_fundraiser_invoice_packages');
        Schema::dropIfExists('modern_forestry_fundraiser_orders');
    }
};
