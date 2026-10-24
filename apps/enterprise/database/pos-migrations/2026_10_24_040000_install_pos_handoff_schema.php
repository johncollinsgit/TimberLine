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
        // Retain staff and original-payment correlation through rollback.
    }
};
