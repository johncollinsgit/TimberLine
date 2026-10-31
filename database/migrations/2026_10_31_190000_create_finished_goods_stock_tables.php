<?php

use App\Services\Inventory\StockSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        StockSchema::install();
    }

    public function down(): void
    {
        // Stock/payment/customer evidence is retained on application rollback.
    }
};
