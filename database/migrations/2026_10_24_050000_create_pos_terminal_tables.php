<?php

use App\Services\Pos\PosTerminalSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PosTerminalSchema::install();
    }

    public function down(): void
    {
        // Preserve paired-device ownership and uncertain checkout evidence.
    }
};
