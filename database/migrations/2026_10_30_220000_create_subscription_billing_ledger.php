<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscription_billing_agreements')) {
            Schema::create('subscription_billing_agreements', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('member_id');
                $t->char('public_id', 36);
                $t->string('processor', 24);
                $t->string('status', 24)->default('prepared');
                $t->unsignedInteger('revision')->default(1);
                $t->longText('terms');
                $t->char('terms_hash', 64);
                $t->longText('method_binding');
                $t->longText('authorization_evidence');
                $t->unsignedBigInteger('prepared_by');
                $t->timestamp('method_verified_at')->nullable();
                $t->timestamp('activated_at')->nullable();
                $t->unsignedBigInteger('activated_by')->nullable();
                $t->date('billing_anchor_date');
                $t->date('next_billing_date')->nullable();
                $t->date('resume_on')->nullable();
                $t->timestamps(6);
            });
        }
        $this->index('subscription_billing_agreements', 'sba_member_uq', ['tenant_id', 'member_id']);
        $this->index('subscription_billing_agreements', 'sba_public_uq', ['public_id']);
        $this->index('subscription_billing_agreements', 'sba_scope_uq', ['id', 'tenant_id']);
        $this->foreign('subscription_billing_agreements', 'sba_member_fk', ['member_id', 'tenant_id'], 'subscription_pilot_members', ['id', 'tenant_id']);

        if (! Schema::hasTable('subscription_billing_cycles')) {
            Schema::create('subscription_billing_cycles', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('agreement_id');
                $t->char('public_id', 36);
                $t->string('processor', 24);
                $t->char('merchant_fingerprint', 64);
                $t->date('cycle_date');
                $t->unsignedInteger('agreement_revision');
                $t->unsignedBigInteger('amount_cents');
                $t->char('currency', 3);
                $t->longText('terms');
                $t->longText('method_binding');
                $t->longText('source_evidence');
                $t->char('request_uuid', 36);
                $t->longText('provider_request');
                $t->string('provider_reference', 190)->nullable();
                $t->string('payment_status', 32)->default('prepared');
                $t->timestamp('payment_started_at')->nullable();
                $t->timestamp('paid_at')->nullable();
                $t->longText('reconciliation')->nullable();
                $t->longText('shopify_request')->nullable();
                $t->string('shopify_order_id', 100)->nullable();
                $t->string('handoff_status', 24)->default('prepared');
                $t->timestamp('handoff_started_at')->nullable();
                $t->string('last_error_code', 100)->nullable();
                $t->timestamps(6);
            });
        }
        $this->index('subscription_billing_cycles', 'sbc_cycle_uq', ['tenant_id', 'agreement_id', 'cycle_date']);
        $this->index('subscription_billing_cycles', 'sbc_public_uq', ['public_id']);
        $this->index('subscription_billing_cycles', 'sbc_scope_uq', ['id', 'tenant_id']);
        $this->index('subscription_billing_cycles', 'sbc_request_uq', ['request_uuid']);
        $this->index('subscription_billing_cycles', 'sbc_payment_uq', ['processor', 'merchant_fingerprint', 'provider_reference']);
        $this->index('subscription_billing_cycles', 'sbc_order_uq', ['tenant_id', 'shopify_order_id']);
        $this->foreign('subscription_billing_cycles', 'sbc_agreement_fk', ['agreement_id', 'tenant_id'], 'subscription_billing_agreements', ['id', 'tenant_id']);

        if (! Schema::hasTable('subscription_billing_actions')) {
            Schema::create('subscription_billing_actions', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('agreement_id');
                $t->char('request_uuid', 36);
                $t->string('kind', 32);
                $t->string('actor_type', 24);
                $t->string('actor_reference', 100);
                $t->unsignedInteger('expected_revision');
                $t->longText('request');
                $t->longText('before_state');
                $t->longText('after_state');
                $t->timestamps(6);
            });
        }
        $this->index('subscription_billing_actions', 'sbact_request_uq', ['tenant_id', 'agreement_id', 'request_uuid']);
        $this->foreign('subscription_billing_actions', 'sbact_agreement_fk', ['agreement_id', 'tenant_id'], 'subscription_billing_agreements', ['id', 'tenant_id']);
    }

    private function index(string $table, string $name, array $columns): void
    {
        $existing = collect(Schema::getIndexes($table))->firstWhere('name', $name);
        if ($existing && ($existing['columns'] !== $columns || ! $existing['unique'])) {
            throw new RuntimeException('Subscription billing index differs from the reviewed schema: '.$name);
        }
        if (! $existing) {
            Schema::table($table, fn (Blueprint $t) => $t->unique($columns, $name));
        }
    }

    private function foreign(string $table, string $name, array $columns, string $parent, array $references): void
    {
        $existing = collect(Schema::getForeignKeys($table))->firstWhere('name', $name);
        if ($existing && ($existing['columns'] !== $columns || $existing['foreign_table'] !== $parent || $existing['foreign_columns'] !== $references || strtolower($existing['on_delete']) !== 'restrict')) {
            throw new RuntimeException('Subscription billing ownership differs from the reviewed schema.');
        }
        if (! $existing) {
            Schema::table($table, fn (Blueprint $t) => $t->foreign($columns, $name)->references($references)->on($parent)->restrictOnDelete());
        }
    }

    public function down(): void
    {
        // Preserve mandates, immutable payment requests and customer action history.
    }
};
