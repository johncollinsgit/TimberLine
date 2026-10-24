<?php

use App\Foundation\EnterprisePosSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        EnterprisePosSchema::install();
    }

    public function down(): void
    { /* Preserve operator attribution and original payment evidence. */
    }
};
