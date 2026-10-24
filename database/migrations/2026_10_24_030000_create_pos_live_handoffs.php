<?php

use App\Services\Pos\PosHandoffSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PosHandoffSchema::install();
    }

    public function down(): void
    {
        // Keep staff-to-device correlation and original payment recovery evidence.
    }
};
