<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'onboarding_guide_answers')) {
                $table->json('onboarding_guide_answers')->nullable()->after('ui_preferences');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'onboarding_guide_answers')) {
                $table->dropColumn('onboarding_guide_answers');
            }
        });
    }
};
