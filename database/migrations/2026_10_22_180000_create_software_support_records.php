<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('software_support_records')) {
            Schema::create('software_support_records', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('owner_tenant_id');
                $table->unsignedBigInteger('client_tenant_id');
                $table->unsignedBigInteger('ticket_id');
                $table->unsignedBigInteger('created_by');
                $table->string('department', 64);
                $table->string('kind', 24);
                $table->char('request_key', 64);
                $table->longText('payload');
                $table->timestamps();
            });
        }
        if (! Schema::hasIndex('software_support_records', 'ssr_replay_uq')) {
            Schema::table('software_support_records', fn (Blueprint $table) => $table->unique(['client_tenant_id', 'kind', 'request_key'], 'ssr_replay_uq'));
        }
        if (! Schema::hasIndex('software_support_records', 'ssr_scope_idx')) {
            Schema::table('software_support_records', fn (Blueprint $table) => $table->index(['owner_tenant_id', 'client_tenant_id', 'kind', 'ticket_id'], 'ssr_scope_idx'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('software_support_records');
    }
};
