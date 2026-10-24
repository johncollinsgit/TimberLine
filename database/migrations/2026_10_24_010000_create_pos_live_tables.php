<?php

use App\Services\Pos\PosSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PosSchema::install();
    }

    public function down(): void
    {
        // Retain financial evidence and stock history on application rollback.
    }
};
