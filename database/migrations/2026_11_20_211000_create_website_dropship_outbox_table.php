<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('website_dropship_outbox')) {
            $claim = collect(Schema::getIndexes('website_dropship_outbox'))->firstWhere('name', 'wdo_order_uq');
            if (($claim['unique'] ?? false) !== true || $claim['columns'] !== ['website_order_id']) {
                throw new RuntimeException('Unexpected supplier outbox claim index.');
            }

            return;
        }

        Schema::create('website_dropship_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('tenant_site_id');
            $table->unsignedBigInteger('website_order_id')->unique('wdo_order_uq');
            $table->unsignedBigInteger('website_payment_id');
            $table->string('supplier_key', 120);
            $table->string('shop_id', 80);
            $table->string('shop_domain', 100);
            $table->string('status', 24);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('dispatch_token')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index('wdo_due_idx');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // An operator may need this evidence to recover an interrupted handoff.
    }
};
