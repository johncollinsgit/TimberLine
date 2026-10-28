<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('subscription_pilot_enrollments')) {
            Schema::create('subscription_pilot_enrollments', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('tenant_id'); $t->unsignedBigInteger('member_id');
                $t->char('public_id',36); $t->string('status',24)->default('draft');
                $t->longText('terms'); $t->char('terms_hash',64);
                $t->char('access_hash',64)->nullable(); $t->timestamp('access_expires_at')->nullable();
                $t->longText('requests')->nullable();
                $t->string('stripe_customer_id',100)->nullable(); $t->string('setup_session_id',100)->nullable();
                $t->string('setup_intent_id',100)->nullable(); $t->string('payment_method_id',100)->nullable();
                $t->timestamp('authorized_at')->nullable(); $t->timestamp('activated_at')->nullable();
                $t->unsignedBigInteger('activated_by')->nullable(); $t->date('next_billing_date')->nullable(); $t->date('paused_until')->nullable();
                $t->timestamps(6);
            });
        }
        $this->index('subscription_pilot_enrollments','spen_member_uq',['tenant_id','member_id'],true);
        $this->index('subscription_pilot_enrollments','spen_public_uq',['public_id'],true);
        $this->index('subscription_pilot_enrollments','spen_access_uq',['access_hash'],true);
        $this->index('subscription_pilot_enrollments','spen_scope_uq',['id','tenant_id'],true);
        $this->foreign('subscription_pilot_enrollments','spen_member_fk',['member_id','tenant_id'],'subscription_pilot_members',['id','tenant_id']);
        if (!Schema::hasTable('subscription_pilot_cycles')) {
            Schema::create('subscription_pilot_cycles', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('tenant_id'); $t->unsignedBigInteger('enrollment_id');
                $t->char('public_id',36); $t->date('cycle_date'); $t->unsignedBigInteger('amount_cents'); $t->char('currency',3);
                $t->longText('terms'); $t->longText('source_evidence'); $t->longText('reconciliation')->nullable();
                $t->char('stripe_key',36); $t->longText('stripe_payload'); $t->string('payment_intent_id',100)->nullable();
                $t->string('payment_status',32)->default('prepared'); $t->timestamp('payment_started_at')->nullable();
                $t->timestamp('paid_at')->nullable();
                $t->timestamp('cancellation_started_at')->nullable();
                $t->longText('shopify_payload')->nullable(); $t->string('shopify_order_id',100)->nullable();
                $t->string('handoff_status',24)->default('prepared'); $t->timestamp('handoff_started_at')->nullable();
                $t->string('last_error_code',100)->nullable(); $t->timestamps(6);
            });
        }
        $this->index('subscription_pilot_cycles','spcy_cycle_uq',['tenant_id','enrollment_id','cycle_date'],true);
        $this->index('subscription_pilot_cycles','spcy_public_uq',['public_id'],true);
        $this->index('subscription_pilot_cycles','spcy_payment_uq',['tenant_id','payment_intent_id'],true);
        $this->index('subscription_pilot_cycles','spcy_order_uq',['tenant_id','shopify_order_id'],true);
        $this->foreign('subscription_pilot_cycles','spcy_enrollment_fk',['enrollment_id','tenant_id'],'subscription_pilot_enrollments',['id','tenant_id']);
    }

    private function index(string $table,string $name,array $columns,bool $unique): void
    {
        $existing=collect(Schema::getIndexes($table))->firstWhere('name',$name);
        if($existing && ($existing['columns']!==$columns || $existing['unique']!==$unique)) throw new RuntimeException('Pilot execution index requires review: '.$name);
        if(!$existing) Schema::table($table,fn(Blueprint $t)=>$unique?$t->unique($columns,$name):$t->index($columns,$name));
    }

    private function foreign(string $table,string $name,array $columns,string $parent,array $references): void
    {
        $existing=collect(Schema::getForeignKeys($table))->firstWhere('name',$name);
        if($existing && ($existing['columns']!==$columns || $existing['foreign_table']!==$parent || $existing['foreign_columns']!==$references || strtolower($existing['on_delete'])!=='restrict')) throw new RuntimeException('Pilot execution ownership requires review.');
        if(!$existing) Schema::table($table,fn(Blueprint $t)=>$t->foreign($columns,$name)->references($references)->on($parent)->restrictOnDelete());
    }

    public function down(): void
    {
        // Retain authorizations, frozen provider requests and payment/order evidence.
    }
};
