<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $after = 'shipping_address1';

            foreach ([
                'shipping_city' => 120,
                'shipping_province' => 120,
                'shipping_province_code' => 16,
                'shipping_zip' => 32,
                'shipping_country_code' => 8,
            ] as $column => $length) {
                if (! Schema::hasColumn('orders', $column)) {
                    $table->string($column, $length)->nullable()->after($after);
                    $after = $column;
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                'shipping_city',
                'shipping_province',
                'shipping_province_code',
                'shipping_zip',
                'shipping_country_code',
            ], fn (string $column): bool => Schema::hasColumn('orders', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
