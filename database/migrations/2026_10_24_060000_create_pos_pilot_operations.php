<?php

use App\Services\Pos\PosPilotSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        PosPilotSchema::install();
    }

    public function down(): void
    { /* Operational and financial evidence is retained during rollback. */
    }
};
