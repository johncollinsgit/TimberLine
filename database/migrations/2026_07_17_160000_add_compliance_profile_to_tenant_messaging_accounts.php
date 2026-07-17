<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_messaging_accounts', 'compliance_profile')) {
            Schema::table('tenant_messaging_accounts', function (Blueprint $table): void {
                $table->text('compliance_profile')->nullable()->after('registration');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_messaging_accounts', 'compliance_profile')) {
            Schema::table('tenant_messaging_accounts', function (Blueprint $table): void {
                $table->dropColumn('compliance_profile');
            });
        }
    }
};
