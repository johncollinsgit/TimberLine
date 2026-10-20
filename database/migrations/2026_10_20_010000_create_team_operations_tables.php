<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_areas')) {
            Schema::create('team_areas', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id');
                $t->string('slug', 60);
                $t->string('name', 100);
                $t->string('profile', 24);
                $t->unsignedInteger('version')->default(1);
                $t->text('settings')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasIndex('team_areas', 'ta_tenant_slug')) {
            Schema::table('team_areas', fn (Blueprint $t) => $t->unique(['tenant_id', 'slug'], 'ta_tenant_slug'));
        }
        if (! Schema::hasTable('team_area_members')) {
            Schema::create('team_area_members', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id');
                $t->uuid('area_id');
                $t->unsignedBigInteger('user_id');
                $t->string('role', 20);
                $t->timestamps();
            });
        }
        if (! Schema::hasIndex('team_area_members', 'tam_member')) {
            Schema::table('team_area_members', fn (Blueprint $t) => $t->unique(['tenant_id', 'area_id', 'user_id'], 'tam_member'));
        }
        if (! Schema::hasTable('team_provider_connections')) {
            Schema::create('team_provider_connections', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('user_id');
                $t->uuid('area_id');
                $t->string('provider', 24);
                $t->string('account_key', 64);
                $t->string('account_name')->nullable();
                $t->string('status', 24)->default('connected');
                $t->unsignedInteger('generation')->default(1);
                $t->text('credentials')->nullable();
                $t->text('settings')->nullable();
                $t->text('sync_state')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('last_success_at')->nullable();
                $t->timestamp('retry_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasIndex('team_provider_connections', 'tpc_identity')) {
            Schema::table('team_provider_connections', fn (Blueprint $t) => $t->unique(['tenant_id', 'user_id', 'area_id', 'provider', 'account_key'], 'tpc_identity'));
        }
        if (! Schema::hasTable('team_operation_records')) {
            Schema::create('team_operation_records', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id');
                $t->uuid('area_id');
                $t->string('kind', 24);
                $t->string('title', 500);
                $t->string('status', 32)->default('draft');
                $t->unsignedInteger('version')->default(1);
                $t->unsignedBigInteger('assigned_user_id')->nullable();
                $t->uuid('connection_id')->nullable();
                $t->string('source_key', 64);
                $t->string('visibility', 20)->default('area');
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->longText('data')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasIndex('team_operation_records', 'tor_source')) {
            Schema::table('team_operation_records', fn (Blueprint $t) => $t->unique(['tenant_id', 'area_id', 'kind', 'source_key'], 'tor_source'));
        }
        if (! Schema::hasIndex('team_operation_records', 'tor_feed')) {
            Schema::table('team_operation_records', fn (Blueprint $t) => $t->index(['tenant_id', 'area_id', 'kind', 'starts_on'], 'tor_feed'));
        }
        if (! Schema::hasTable('team_source_snapshots')) {
            Schema::create('team_source_snapshots', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id');
                $t->uuid('connection_id');
                $t->string('resource_key', 64);
                $t->string('checksum', 64);
                $t->longText('payload');
                $t->timestamp('observed_at');
            });
        }
        if (! Schema::hasIndex('team_source_snapshots', 'tss_version')) {
            Schema::table('team_source_snapshots', fn (Blueprint $t) => $t->unique(['tenant_id', 'connection_id', 'resource_key', 'checksum'], 'tss_version'));
        }
        if (! Schema::hasTable('team_operation_attempts')) {
            Schema::create('team_operation_attempts', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id');
                $t->uuid('area_id');
                $t->unsignedBigInteger('user_id');
                $t->uuid('record_id')->nullable();
                $t->uuid('connection_id')->nullable();
                $t->string('kind', 32);
                $t->string('status', 24)->default('prepared');
                $t->uuid('request_id');
                $t->string('input_hash', 64);
                $t->longText('payload')->nullable();
                $t->longText('result')->nullable();
                $t->timestamp('scheduled_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasIndex('team_operation_attempts', 'toa_request')) {
            Schema::table('team_operation_attempts', fn (Blueprint $t) => $t->unique(['tenant_id', 'user_id', 'request_id'], 'toa_request'));
        }
        if (! Schema::hasTable('team_operation_revisions')) {
            Schema::create('team_operation_revisions', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('tenant_id');
                $t->uuid('record_id');
                $t->unsignedInteger('version');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->longText('payload');
                $t->timestamp('created_at');
            });
        }
        if (! Schema::hasIndex('team_operation_revisions', 'tov_version')) {
            Schema::table('team_operation_revisions', fn (Blueprint $t) => $t->unique(['tenant_id', 'record_id', 'version'], 'tov_version'));
        }
    }

    public function down(): void
    {
        foreach (['team_operation_revisions', 'team_operation_attempts', 'team_source_snapshots', 'team_operation_records', 'team_provider_connections', 'team_area_members', 'team_areas'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
