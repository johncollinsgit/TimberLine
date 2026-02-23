<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'requested_via')) {
                $table->string('requested_via')->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('users', 'approval_requested_at')) {
                $table->timestamp('approval_requested_at')->nullable()->after('requested_via');
            }
            if (!Schema::hasColumn('users', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approval_requested_at');
            }
            if (!Schema::hasColumn('users', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            }
        });

        if (Schema::hasColumn('users', 'approved_at')) {
            DB::table('users')
                ->where('is_active', true)
                ->whereNull('approved_at')
                ->update(['approved_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['approved_by', 'approved_at', 'approval_requested_at', 'requested_via'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
