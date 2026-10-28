<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscription_pilot_members')) {
            Schema::create('subscription_pilot_members', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->char('public_id', 36);
                $t->unsignedBigInteger('marketing_profile_id')->nullable();
                $t->unsignedBigInteger('shopify_store_id');
                $t->string('recharge_customer_id', 100);
                $t->string('shopify_customer_id', 100);
                $t->string('billing_owner', 16)->default('recharge');
                $t->string('status', 24)->default('prepared');
                $t->date('target_date');
                $t->string('timezone', 64)->default('America/New_York');
                $t->char('currency', 3);
                $t->unsignedBigInteger('expected_amount_cents')->nullable();
                $t->longText('details');
                $t->unsignedBigInteger('prepared_by');
                $t->timestamps(6);
            });
        }
        $this->index('subscription_pilot_members', 'spm_tenant_source_uq', ['tenant_id', 'shopify_store_id', 'recharge_customer_id'], true);
        $this->index('subscription_pilot_members', 'spm_public_uq', ['public_id'], true);
        $this->index('subscription_pilot_members', 'spm_scope_uq', ['id', 'tenant_id'], true);
        if (! Schema::hasTable('subscription_pilot_events')) {
            Schema::create('subscription_pilot_events', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('member_id');
                $t->char('event_key', 64);
                $t->string('provider', 16);
                $t->string('kind', 32);
                $t->string('provider_reference', 190);
                $t->string('status', 32);
                $t->string('verification', 24);
                $t->char('currency', 3)->nullable();
                $t->unsignedBigInteger('amount_cents')->nullable();
                $t->date('cycle_date')->nullable();
                $t->timestamp('occurred_at', 6);
                $t->timestamp('observed_at', 6);
                $t->longText('evidence');
                $t->unsignedBigInteger('actor_user_id')->nullable();
                $t->timestamps(6);
            });
        }
        $this->index('subscription_pilot_events', 'spe_event_uq', ['tenant_id', 'member_id', 'event_key'], true);
        $this->index('subscription_pilot_events', 'spe_cycle_idx', ['tenant_id', 'member_id', 'cycle_date'], false);
        $fk = collect(Schema::getForeignKeys('subscription_pilot_events'))->firstWhere('name', 'spe_member_fk');
        if ($fk && ($fk['columns'] !== ['member_id', 'tenant_id'] || $fk['foreign_table'] !== 'subscription_pilot_members'
            || $fk['foreign_columns'] !== ['id', 'tenant_id'] || strtolower($fk['on_delete']) !== 'restrict')) {
            throw new RuntimeException('Subscription pilot parent differs from the reviewed schema.');
        }
        if (! $fk) {
            Schema::table('subscription_pilot_events', fn (Blueprint $t) => $t->foreign(['member_id', 'tenant_id'], 'spe_member_fk')->references(['id', 'tenant_id'])->on('subscription_pilot_members')->restrictOnDelete());
        }
    }

    private function index(string $table, string $name, array $columns, bool $unique): void
    {
        $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);
        if ($index && ($index['columns'] !== $columns || $index['unique'] !== $unique)) {
            throw new RuntimeException('Subscription pilot index differs from the reviewed schema: '.$name);
        }
        if (! $index) {
            Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($columns, $name) : $t->index($columns, $name));
        }
    }

    public function down(): void
    {
        // Financial preparation and reconciliation evidence survives code rollback.
    }
};
