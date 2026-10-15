<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_order_notifications')) {
            Schema::create('website_order_notifications', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('website_order_id');
                $table->unsignedBigInteger('website_shipment_id')->nullable();
                $table->char('transition_key', 64);
                $table->string('kind', 32);
                $table->string('status', 24)->default('pending');
                $table->longText('snapshot');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->char('lease_token', 36)->nullable();
                $table->timestamp('lease_expires_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->string('provider_message_id', 255)->nullable();
                $table->string('failure_code', 64)->nullable();
                $table->timestamps();
                $table->foreign('tenant_id', 'won_tenant_fk')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('website_order_id', 'won_order_fk')->references('id')->on('website_orders')->cascadeOnDelete();
                $table->foreign('website_shipment_id', 'won_shipment_fk')->references('id')->on('website_shipments')->nullOnDelete();
                $table->unique(['tenant_id', 'transition_key'], 'won_transition_uq');
                $table->index(['status', 'next_attempt_at', 'id'], 'won_due_idx');
                $table->index(['status', 'lease_expires_at', 'id'], 'won_lease_idx');
                $table->index(['tenant_id', 'website_order_id', 'status'], 'won_order_status_idx');
            });
        }

        // MySQL may commit CREATE TABLE before Laravel's subsequent FK/index
        // ALTER statements. Resume each missing constraint without rewriting rows.
        foreach (['tenant_id' => ['won_tenant_fk', 'tenants', 'cascade'],
            'website_order_id' => ['won_order_fk', 'website_orders', 'cascade'],
            'website_shipment_id' => ['won_shipment_fk', 'website_shipments', 'set null']] as $column => [$name, $target, $delete]) {
            $exists = collect(Schema::getForeignKeys('website_order_notifications'))->contains(fn (array $foreign): bool => ($foreign['name'] ?? null) === $name
                || (($foreign['name'] ?? null) === null && ($foreign['columns'] ?? []) === [$column] && ($foreign['foreign_table'] ?? null) === $target));
            if (! $exists) {
                Schema::table('website_order_notifications', function (Blueprint $table) use ($column, $name, $target, $delete): void {
                    $table->foreign($column, $name)->references('id')->on($target)->onDelete($delete);
                });
            }
        }
        foreach (['won_transition_uq' => [['tenant_id', 'transition_key'], true],
            'won_due_idx' => [['status', 'next_attempt_at', 'id'], false],
            'won_lease_idx' => [['status', 'lease_expires_at', 'id'], false],
            'won_order_status_idx' => [['tenant_id', 'website_order_id', 'status'], false]] as $name => [$columns, $unique]) {
            if (! Schema::hasIndex('website_order_notifications', $name)) {
                Schema::table('website_order_notifications', function (Blueprint $table) use ($name, $columns, $unique): void {
                    $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('website_order_notifications');
    }
};
