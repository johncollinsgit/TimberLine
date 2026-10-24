<?php

use App\Services\Pos\PosStripeSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PosStripeSchema::install();
    }

    public function down(): void
    { /* Preserve merchant bindings and uncertain payment evidence. */
    }
};
