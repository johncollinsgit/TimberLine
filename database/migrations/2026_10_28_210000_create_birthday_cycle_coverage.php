<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('birthday_cycle_coverage')) {
            Schema::create('birthday_cycle_coverage', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('canonical_profile_id');
                $table->unsignedSmallInteger('cycle_year');
                $table->unsignedBigInteger('issuance_id')->nullable();
                $table->boolean('legacy_covered')->default(false);
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('birthday_cycle_coverage', 'bcc_customer_year_uq')) {
            Schema::table('birthday_cycle_coverage', fn (Blueprint $table) => $table->unique(['tenant_id', 'canonical_profile_id', 'cycle_year'], 'bcc_customer_year_uq'));
        }
        $index = collect(Schema::getIndexes('birthday_cycle_coverage'))->firstWhere('name', 'bcc_customer_year_uq');
        if (! $index || ! $index['unique'] || $index['columns'] !== ['tenant_id', 'canonical_profile_id', 'cycle_year']) {
            throw new RuntimeException('Birthday annual coverage index differs from the reviewed unique key.');
        }
    }

    public function down(): void
    {
        // Coverage is durable duplicate-prevention evidence. Code rollback retains it.
    }
};
