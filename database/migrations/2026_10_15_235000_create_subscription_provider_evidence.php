<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscription_installations')) {
            Schema::create('subscription_installations', function (Blueprint $t): void {
                $t->id();
                $t->char('public_id', 36);
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('shopify_store_id');
                $t->string('shop_domain', 253);
                $t->string('shop_gid', 190);
                $t->string('app_gid', 190);
                $t->string('app_installation_gid', 190);
                $t->string('public_client_id', 128);
                $t->string('api_version', 16)->default('2026-10');
                $t->string('status', 32)->default('pending_verification');
                $t->unsignedBigInteger('active_credential_revision_id')->nullable();
                $t->json('scopes')->nullable();
                $t->string('shop_timezone', 100)->nullable();
                $t->timestamp('verified_at', 6)->nullable();
                $t->timestamp('last_synced_at', 6)->nullable();
                $t->string('read_pause_reason', 64)->nullable();
                $t->longText('evidence')->nullable();
                $t->longText('sync_state')->nullable();
                $t->timestamps(6);
            });
        }
        if (! Schema::hasTable('subscription_credential_revisions')) {
            Schema::create('subscription_credential_revisions', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('installation_id');
                $t->unsignedInteger('revision');
                $t->string('status', 32)->default('pending_verification');
                $t->longText('credentials');
                $t->char('credential_fingerprint', 64);
                $t->char('digest_key_id', 16);
                $t->string('grant_type', 40)->nullable();
                $t->string('app_secret_reference', 190)->nullable();
                $t->string('app_secret_revision', 64)->nullable();
                $t->longText('app_secret')->nullable();
                $t->timestamp('app_secret_expires_at', 6)->nullable();
                foreach (['expires_at', 'refresh_expires_at', 'verified_at', 'rotated_at', 'revoked_at'] as $column) {
                    $t->timestamp($column, 6)->nullable();
                }
                $t->timestamps(6);
            });
        }
        if (! Schema::hasTable('subscription_operations')) {
            Schema::create('subscription_operations', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('installation_id');
                $t->unsignedBigInteger('credential_revision_id');
                $t->unsignedBigInteger('actor_user_id')->nullable();
                $t->char('request_uuid', 36);
                $t->string('kind', 40);
                $t->char('request_fingerprint', 64);
                $t->char('digest_key_id', 16);
                $t->string('status', 24)->default('queued');
                $t->longText('request');
                $t->longText('result')->nullable();
                $t->unsignedInteger('attempts')->default(0);
                $t->char('lease_token', 36)->nullable();
                $t->timestamp('lease_expires_at', 6)->nullable();
                $t->timestamp('next_attempt_at', 6)->nullable();
                $t->timestamp('completed_at', 6)->nullable();
                $t->string('failure_code', 64)->nullable();
                $t->timestamps(6);
            });
        }
        if (! Schema::hasTable('subscription_webhook_inboxes')) {
            Schema::create('subscription_webhook_inboxes', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('installation_id');
                $t->string('delivery_id', 100);
                $t->string('event_id', 100)->nullable();
                $t->string('topic', 100);
                $t->string('api_version', 16);
                $t->char('body_fingerprint', 64);
                $t->char('digest_key_id', 16);
                $t->longText('raw_body');
                $t->longText('headers');
                $t->longText('verification');
                $t->string('status', 24)->default('pending');
                $t->string('quarantine_reason', 64)->nullable();
                $t->longText('conflict_evidence')->nullable();
                $t->unsignedInteger('conflict_count')->default(0);
                $t->unsignedInteger('attempts')->default(0);
                $t->char('lease_token', 36)->nullable();
                $t->timestamp('lease_expires_at', 6)->nullable();
                $t->timestamp('next_attempt_at', 6)->nullable();
                $t->timestamp('completed_at', 6)->nullable();
                $t->timestamps(6);
            });
        }
        if (! Schema::hasTable('subscription_provider_resources')) {
            Schema::create('subscription_provider_resources', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('installation_id');
                $t->unsignedBigInteger('sync_operation_id');
                $t->string('resource_type', 32);
                $t->string('provider_gid', 190);
                $t->string('parent_contract_gid', 190)->nullable();
                $t->string('customer_gid', 190)->nullable();
                // SQLite NUMERIC affinity loses uint64 precision; production MySQL uses exact DECIMAL.
                if (Schema::getConnection()->getDriverName() === 'sqlite') {
                    $t->string('provider_revision', 20)->nullable();
                } else {
                    $t->decimal('provider_revision', 20, 0)->nullable();
                } $t->timestamp('provider_updated_at', 6)->nullable();
                $t->string('status', 40);
                $t->string('completeness', 24)->default('complete');
                $t->timestamp('observed_at', 6);
                $t->string('stale_reason', 64)->nullable();
                $t->longText('snapshot');
                $t->timestamps(6);
            });
        }
        // MySQL commits CREATE and each following ALTER independently. Repair every
        // named key; never drop rows or silently adopt a different key definition.
        foreach ($this->indexes() as $table => $definitions) {
            foreach ($definitions as $name => [$columns, $unique]) {
                $existing = collect(Schema::getIndexes($table))->firstWhere('name', $name);
                if ($existing && ($existing['columns'] !== $columns || $existing['unique'] !== $unique || $existing['primary'])) {
                    throw new RuntimeException('Unexpected subscription evidence index definition.');
                }
                if (! $existing) {
                    Schema::table($table, fn (Blueprint $t) => $unique ? $t->unique($columns, $name) : $t->index($columns, $name));
                }
            }
        }
        foreach ($this->foreignKeys() as $table => $definitions) {
            foreach ($definitions as $name => [$columns, $target, $references]) {
                $exists = collect(Schema::getForeignKeys($table))->contains(function (array $key) use ($name, $columns, $target, $references): bool {
                    $same = $key['columns'] === $columns && $key['foreign_table'] === $target && $key['foreign_columns'] === $references && strtolower($key['on_delete']) === 'restrict' && in_array(strtolower($key['on_update']), ['restrict', 'no action'], true);
                    if (($key['name'] ?? null) === $name && ! $same) {
                        throw new RuntimeException('Unexpected subscription evidence foreign key definition.');
                    }

                    return $same && (($key['name'] ?? null) === $name || Schema::getConnection()->getDriverName() !== 'mysql');
                });
                if (! $exists) {
                    Schema::table($table, fn (Blueprint $t) => $t->foreign($columns, $name)->references($references)->on($target)->restrictOnDelete());
                }
            }
        }
    }

    public function indexes(): array
    {
        return [
            'subscription_installations' => ['si_public_uq' => [['public_id'], true], 'si_tenant_id_uq' => [['id', 'tenant_id'], true], 'si_provider_install_uq' => [['app_installation_gid'], true], 'si_shop_app_uq' => [['shop_gid', 'app_gid'], true], 'si_domain_client_uq' => [['shop_domain', 'public_client_id'], true], 'si_tenant_status_idx' => [['tenant_id', 'status', 'id'], false]],
            'subscription_credential_revisions' => ['scr_scope_uq' => [['id', 'installation_id', 'tenant_id'], true], 'scr_revision_uq' => [['installation_id', 'revision'], true]],
            'subscription_operations' => ['so_scope_uq' => [['id', 'installation_id', 'tenant_id'], true], 'so_request_uq' => [['installation_id', 'request_uuid'], true], 'so_due_idx' => [['status', 'next_attempt_at', 'id'], false], 'so_lease_idx' => [['status', 'lease_expires_at', 'id'], false], 'so_tenant_install_idx' => [['tenant_id', 'installation_id', 'id'], false]],
            'subscription_webhook_inboxes' => ['swi_delivery_uq' => [['installation_id', 'delivery_id'], true], 'swi_event_idx' => [['installation_id', 'event_id'], false], 'swi_due_idx' => [['status', 'next_attempt_at', 'id'], false], 'swi_lease_idx' => [['status', 'lease_expires_at', 'id'], false], 'swi_tenant_install_idx' => [['tenant_id', 'installation_id', 'id'], false]],
            'subscription_provider_resources' => ['spr_identity_uq' => [['installation_id', 'resource_type', 'provider_gid'], true], 'spr_scope_type_idx' => [['tenant_id', 'installation_id', 'resource_type', 'id'], false]],
        ];
    }

    public function foreignKeys(): array
    {
        $scope = fn (string $prefix) => [$prefix.'_install_fk' => [['installation_id', 'tenant_id'], 'subscription_installations', ['id', 'tenant_id']]];

        return [
            'subscription_installations' => ['si_tenant_fk' => [['tenant_id'], 'tenants', ['id']], 'si_store_fk' => [['shopify_store_id'], 'shopify_stores', ['id']], 'si_active_credential_fk' => [['active_credential_revision_id', 'id', 'tenant_id'], 'subscription_credential_revisions', ['id', 'installation_id', 'tenant_id']]],
            'subscription_credential_revisions' => $scope('scr'),
            'subscription_operations' => $scope('so') + ['so_credential_fk' => [['credential_revision_id', 'installation_id', 'tenant_id'], 'subscription_credential_revisions', ['id', 'installation_id', 'tenant_id']]],
            'subscription_webhook_inboxes' => $scope('swi'),
            'subscription_provider_resources' => $scope('spr') + ['spr_operation_fk' => [['sync_operation_id', 'installation_id', 'tenant_id'], 'subscription_operations', ['id', 'installation_id', 'tenant_id']]],
        ];
    }

    public function down(): void
    {
        // Identity, credentials and accepted evidence survive code rollback.
    }
};
