<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_member_preferences') || Schema::hasColumn('tenant_member_preferences', 'team_message_notifications')) {
            return;
        }
        Schema::table('tenant_member_preferences', function (Blueprint $table): void {
            $table->boolean('team_message_notifications')->default(true);
        });
    }

    public function down(): void
    {
        // Preserve tenant alert choices across a code rollback.
    }
};
