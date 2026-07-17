<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_agreement_id')->nullable()->constrained('agreements')->nullOnDelete();
            $table->string('agreement_type', 80);
            $table->string('template_key', 120)->nullable();
            $table->string('title');
            $table->string('status', 40)->default('draft');
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->char('public_token_hash', 64)->nullable()->unique();
            $table->text('public_token_encrypted')->nullable();
            $table->string('password_hash')->nullable();
            $table->timestamp('access_expires_at')->nullable();
            $table->timestamp('access_revoked_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['agreement_type', 'status']);
        });

        Schema::create('agreement_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('title');
            $table->longText('rendered_content');
            $table->json('content_payload');
            $table->json('scope_payload');
            $table->json('pricing_payload');
            $table->json('subscription_payload');
            $table->json('termination_payload');
            $table->char('content_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['agreement_id', 'version_number']);
            $table->unique(['agreement_id', 'content_hash']);
        });

        Schema::table('agreements', function (Blueprint $table): void {
            $table->foreign('current_version_id')->references('id')->on('agreement_versions')->nullOnDelete();
        });

        Schema::create('agreement_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signer_legal_name');
            $table->string('signer_title');
            $table->string('signer_email');
            $table->text('electronic_signature_value');
            $table->string('electronic_signature_type', 30)->default('typed');
            $table->boolean('authorized_to_bind');
            $table->boolean('accepted_scope');
            $table->boolean('accepted_pricing');
            $table->boolean('accepted_subscription');
            $table->boolean('accepted_hourly_rate');
            $table->boolean('accepted_termination');
            $table->boolean('electronic_consent');
            $table->timestamp('accepted_at');
            $table->text('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->char('evidence_hash', 64)->unique();
            $table->string('snapshot_path')->nullable();
            $table->char('snapshot_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['agreement_id', 'agreement_version_id']);
            $table->index(['tenant_id', 'accepted_at']);
        });

        Schema::create('agreement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agreement_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 80);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'event_type']);
            $table->index(['agreement_id', 'created_at']);
        });

        Schema::create('agreement_terminations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('status', 40)->default('requested');
            $table->text('reason')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('export_window_ends_at')->nullable();
            $table->timestamp('export_requested_at')->nullable();
            $table->timestamp('export_completed_at')->nullable();
            $table->string('export_status', 40)->default('not_requested');
            $table->string('export_reference')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique('agreement_id');
        });

        Schema::create('subscription_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_acceptance_id')->constrained()->restrictOnDelete();
            $table->string('billing_lane', 50);
            $table->string('provider', 50);
            $table->string('purchase_key', 120);
            $table->string('status', 40)->default('authorized');
            $table->string('pricing_model', 50)->default('agreement_specific');
            $table->string('currency', 3)->default('USD');
            $table->string('billing_interval', 30)->default('month');
            $table->unsignedInteger('onboarding_amount_cents')->default(0);
            $table->unsignedInteger('promotional_amount_cents')->default(0);
            $table->unsignedInteger('promotional_cycles')->default(0);
            $table->unsignedInteger('standard_amount_cents')->default(0);
            $table->string('tax_treatment', 80)->default('provider_calculated_if_applicable');
            $table->text('tax_disclosure')->nullable();
            $table->json('authorized_line_items')->nullable();
            $table->string('provider_subscription_id')->nullable();
            $table->string('provider_plan_handle')->nullable();
            $table->timestamp('authorized_at');
            $table->timestamp('last_reconciled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('agreement_version_id');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('tenant_billing_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_authorization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 50);
            $table->string('provider_receipt_id');
            $table->string('provider_subscription_id')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('status', 40);
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('subtotal_amount_cents')->default(0);
            $table->unsignedBigInteger('tax_amount_cents')->default(0);
            $table->unsignedBigInteger('total_amount_cents')->default(0);
            $table->boolean('provider_calculated_tax')->default(true);
            $table->string('tax_jurisdiction')->nullable();
            $table->timestamp('billing_period_starts_at')->nullable();
            $table->timestamp('billing_period_ends_at')->nullable();
            $table->timestamp('billed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('hosted_invoice_url')->nullable();
            $table->text('receipt_url')->nullable();
            $table->string('source_event_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_receipt_id']);
            $table->index(['tenant_id', 'billed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_receipts');
        Schema::dropIfExists('subscription_authorizations');
        Schema::dropIfExists('agreement_terminations');
        Schema::dropIfExists('agreement_events');
        Schema::dropIfExists('agreement_acceptances');
        Schema::table('agreements', function (Blueprint $table): void {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('agreement_versions');
        Schema::dropIfExists('agreements');
    }
};
