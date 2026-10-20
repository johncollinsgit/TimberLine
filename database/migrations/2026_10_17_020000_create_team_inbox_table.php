<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_inbox_items')) {
            Schema::create('team_inbox_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('recipient_user_id');
                $table->unsignedBigInteger('actor_user_id');
                $table->uuid('task_id');
                $table->string('event_type', 40);
                $table->char('event_key', 64);
                $table->dateTime('read_at')->nullable();
                $table->dateTime('archived_at')->nullable();
                $table->dateTime('created_at');
                $table->unique(['tenant_id', 'recipient_user_id', 'event_key'], 'team_inbox_event_unique');
                $table->index(['tenant_id', 'recipient_user_id', 'archived_at'], 'team_inbox_recipient_idx');
            });
        }
        if (! Schema::hasIndex('team_inbox_items', ['id'], 'primary')) {
            Schema::table('team_inbox_items', fn (Blueprint $table) => $table->primary('id'));
        }
        if (! Schema::hasIndex('team_inbox_items', 'team_inbox_event_unique', 'unique')) {
            Schema::table('team_inbox_items', fn (Blueprint $table) => $table->unique(['tenant_id', 'recipient_user_id', 'event_key'], 'team_inbox_event_unique'));
        }
        if (! Schema::hasIndex('team_inbox_items', 'team_inbox_recipient_idx')) {
            Schema::table('team_inbox_items', fn (Blueprint $table) => $table->index(['tenant_id', 'recipient_user_id', 'archived_at'], 'team_inbox_recipient_idx'));
        }
    }

    public function down(): void
    {
        // Retain notification history during an application rollback.
    }
};
