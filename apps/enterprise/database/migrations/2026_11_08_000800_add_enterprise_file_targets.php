<?php

use App\Foundation\EnterpriseSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (EnterpriseSchema::sharedVersionTwo() as $migration) {
            $migration->up();
        }
    }

    public function down(): void
    {
        // Preserve original target identity and protected upload evidence on code rollback.
    }
};
