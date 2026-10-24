<?php

use App\Foundation\EnterpriseSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (EnterpriseSchema::sharedVersionOne() as $migration) {
            $migration->up();
        }
    }

    public function down(): void
    {
        // Never delegate destructive shared down() methods during code rollback.
    }
};
