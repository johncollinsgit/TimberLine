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
    {
        // Payment, recovery and audit evidence survives code rollback.
    }
};
