<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('highlevel_authorizations')) {
            Schema::create('highlevel_authorizations', function (Blueprint $table): void {
                $table->id();
                $table->string('app_id', 80);
                $table->string('company_id', 80);
                $table->string('installer_user_id', 80)->nullable();
                $table->longText('access_token')->nullable();
                $table->longText('refresh_token')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->string('status', 30)->default('authorized');
                $table->timestamps();
                $table->unique(['app_id', 'company_id'], 'hl_auth_app_company_uq');
            });
        }
        if (! Schema::hasTable('highlevel_installations')) {
            Schema::create('highlevel_installations', function (Blueprint $table): void {
                $table->id();
                $table->string('app_id', 80);
                $table->string('company_id', 80);
                $table->string('location_id', 80);
                $table->foreignId('tenant_id')->nullable()->constrained('tenants', indexName: 'hl_install_tenant_fk');
                $table->foreignId('actor_user_id')->nullable()->constrained('users', indexName: 'hl_install_actor_fk');
                $table->string('plan_id', 80)->nullable();
                $table->string('status', 30)->default('pending');
                $table->string('payment_status', 30)->default('PENDING');
                $table->string('parent_origin', 255)->nullable();
                $table->longText('access_token')->nullable();
                $table->longText('refresh_token')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('installed_at')->nullable();
                $table->timestamp('uninstalled_at')->nullable();
                $table->timestamp('grace_ends_at')->nullable();
                $table->timestamp('lifecycle_at')->nullable();
                $table->timestamp('payment_at')->nullable();
                $table->timestamps();
                $table->unique(['app_id', 'location_id'], 'hl_install_app_location_uq');
                $table->unique('tenant_id', 'hl_install_tenant_uq');
                $table->index('company_id', 'hl_install_company_idx');
            });
        }
        if (! Schema::hasTable('highlevel_user_bindings')) {
            Schema::create('highlevel_user_bindings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('installation_id')->constrained('highlevel_installations', indexName: 'hl_binding_install_fk');
                $table->string('provider_user_id', 80);
                $table->foreignId('user_id')->constrained('users', indexName: 'hl_binding_user_fk');
                $table->string('role', 30)->default('admin');
                $table->timestamp('verified_at');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->unique(['installation_id', 'provider_user_id'], 'hl_binding_install_user_uq');
            });
        }
        if (! Schema::hasTable('highlevel_sessions')) {
            Schema::create('highlevel_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('installation_id')->constrained('highlevel_installations', indexName: 'hl_session_install_fk');
                $table->foreignId('binding_id')->constrained('highlevel_user_bindings', indexName: 'hl_session_binding_fk');
                $table->char('token_hash', 64)->unique('hl_session_token_uq');
                $table->string('parent_origin', 255);
                $table->timestamp('expires_at')->index('hl_session_expiry_idx');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('highlevel_oauth_states')) {
            Schema::create('highlevel_oauth_states', function (Blueprint $table): void {
                $table->id();
                $table->char('state_hash', 64)->unique('hl_oauth_state_uq');
                $table->string('provider', 30);
                $table->foreignId('session_id')->nullable()->constrained('highlevel_sessions', indexName: 'hl_oauth_session_fk');
                $table->longText('payload')->nullable();
                $table->timestamp('expires_at')->index('hl_oauth_expiry_idx');
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('highlevel_webhook_events')) {
            Schema::create('highlevel_webhook_events', function (Blueprint $table): void {
                $table->id();
                $table->string('provider', 30);
                $table->char('event_key', 64);
                $table->longText('payload')->nullable();
                $table->timestamp('received_at');
                $table->timestamp('processed_at')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->string('error_code', 80)->nullable();
                $table->timestamps();
                $table->unique(['provider', 'event_key'], 'hl_event_provider_key_uq');
                $table->index(['processed_at', 'received_at'], 'hl_event_pending_idx');
            });
        }
        // A global claim serializes selections across client workspaces. Existing
        // standalone duplicate mappings continue to fail closed in ingestion.
        if (! Schema::hasTable('fleet_provider_device_claims')) {
            Schema::create('fleet_provider_device_claims', function (Blueprint $table): void {
                $table->id();
                $table->string('provider', 30);
                $table->string('external_device_id', 100);
                $table->foreignId('integration_connection_id')->nullable()->constrained('integration_connections', indexName: 'fleet_claim_connection_fk');
                $table->timestamps();
                $table->unique(['provider', 'external_device_id'], 'fleet_claim_provider_device_uq');
            });
        }
        if (! Schema::hasColumn('fleet_tracking_devices', 'integration_connection_id')) {
            Schema::table('fleet_tracking_devices', function (Blueprint $table): void {
                $table->unsignedBigInteger('integration_connection_id')->nullable();
            });
        }
        if (! Schema::hasIndex('fleet_tracking_devices', 'fleet_device_connection_idx')) {
            Schema::table('fleet_tracking_devices', function (Blueprint $table): void {
                $table->index('integration_connection_id', 'fleet_device_connection_idx');
            });
        }
    }

    public function down(): void
    {
        // Preserve installation, audit and provider lineage on application rollback.
        // Operational rollback disables the feature; destructive removal is manual.
    }
};
