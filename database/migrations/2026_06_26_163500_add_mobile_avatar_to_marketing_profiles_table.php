<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_profiles', 'mobile_avatar_path')) {
                $table->string('mobile_avatar_path')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('marketing_profiles', 'mobile_avatar_uploaded_at')) {
                $table->timestamp('mobile_avatar_uploaded_at')->nullable()->after('mobile_avatar_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('marketing_profiles', 'mobile_avatar_uploaded_at')) {
                $table->dropColumn('mobile_avatar_uploaded_at');
            }

            if (Schema::hasColumn('marketing_profiles', 'mobile_avatar_path')) {
                $table->dropColumn('mobile_avatar_path');
            }
        });
    }
};
