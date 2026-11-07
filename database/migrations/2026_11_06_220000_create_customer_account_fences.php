<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_account_fences')) {
            Schema::create('customer_account_fences', function (Blueprint $table): void {
                $table->unsignedBigInteger('tenant_id')->primary();
            });
        }
        $primary = collect(Schema::getIndexes('customer_account_fences'))->firstWhere('primary', true);
        if ($primary && $primary['columns'] !== ['tenant_id']) {
            throw new RuntimeException('Customer account fence identity differs from the reviewed schema.');
        }
        if (! $primary) {
            Schema::table('customer_account_fences', fn (Blueprint $table) => $table->primary('tenant_id'));
        }
    }

    public function down(): void {}
};
