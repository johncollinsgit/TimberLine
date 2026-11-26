<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('trajectory_allocation_details', 'budget_account_id')) {
            Schema::table('trajectory_allocation_details', function (Blueprint $table): void {
                $table->unsignedBigInteger('budget_account_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Retain reviewed budget designations when rolling back application code.
    }
};
