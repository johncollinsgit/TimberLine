<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('team_operation_records', 'voice_expires_at')) {
            Schema::table('team_operation_records', fn (Blueprint $t) => $t->timestamp('voice_expires_at')->nullable());
        }
        foreach (['tor_voice_expiry' => ['voice_expires_at'], 'tor_voice_sessions' => ['tenant_id', 'kind', 'assigned_user_id', 'voice_expires_at'], 'tor_voice_previews' => ['tenant_id', 'kind', 'connection_id']] as $name => $columns) {
            if (! Schema::hasIndex('team_operation_records', $name)) {
                Schema::table('team_operation_records', fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        // Code rollback must preserve private expiry metadata and accounting evidence.
    }
};
