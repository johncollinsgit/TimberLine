<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_birthday_cycle_fences')) {
            Schema::create('pos_birthday_cycle_fences', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('coverage_id');
                $table->unsignedBigInteger('issuance_id');
                $table->string('state', 24);
                $table->uuid('operation_id')->nullable();
                $table->string('online_order_hash', 64)->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'coverage_id'], 'pos_birthday_cycle_fence_unique');
            });
        }
        $index = collect(Schema::getIndexes('pos_birthday_cycle_fences'))->firstWhere('name', 'pos_birthday_cycle_fence_unique');
        if (! Schema::hasColumns('pos_birthday_cycle_fences', ['tenant_id', 'coverage_id', 'issuance_id', 'state', 'operation_id', 'online_order_hash'])
            || ! $index || ! $index['unique'] || $index['columns'] !== ['tenant_id', 'coverage_id']) {
            throw new RuntimeException('Birthday fence index differs from the reviewed annual key.');
        }
        if (! Schema::hasTable('pos_birthday_authorizations')) {
            Schema::create('pos_birthday_authorizations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('runtime_id', 80);
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('profile_id');
                $table->unsignedBigInteger('issuance_id');
                $table->unsignedSmallInteger('cycle_year');
                $table->string('token_hash', 64)->unique();
                $table->string('terms_hash', 64);
                $table->string('sale_id', 32)->nullable();
                $table->timestamp('expires_at');
                $table->timestamps();
            });
        }
        $proofIndex = collect(Schema::getIndexes('pos_birthday_authorizations'))->firstWhere('name', 'pos_birthday_authorizations_token_hash_unique');
        if (! Schema::hasColumns('pos_birthday_authorizations', ['runtime_id', 'tenant_id', 'profile_id', 'issuance_id', 'cycle_year', 'token_hash', 'terms_hash', 'sale_id', 'expires_at'])
            || ! $proofIndex || ! $proofIndex['unique'] || $proofIndex['columns'] !== ['token_hash']) {
            throw new RuntimeException('Birthday authorization index differs from the reviewed token key.');
        }
    }

    public function down(): void
    {
        // Durable operation evidence must survive code rollback.
    }
};
