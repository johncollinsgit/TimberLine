<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subscription_recharge_connections')) {
            Schema::create('subscription_recharge_connections', function (Blueprint $t): void {
                $t->id();
                $t->char('public_id', 36);
                $t->unsignedBigInteger('tenant_id');
                $t->string('provider_store_id', 100);
                $t->string('platform', 32);
                $t->string('identifier', 190);
                $t->unsignedBigInteger('source_integration_connection_id')->nullable();
                $t->unsignedBigInteger('shopify_store_id')->nullable();
                $t->string('shop_domain', 190)->nullable();
                $t->string('api_version', 16)->default('2021-11');
                $t->string('status', 32)->default('pending_verification');
                $t->unsignedBigInteger('active_credential_revision_id')->nullable();
                $t->json('scopes')->nullable();
                $t->timestamp('verified_at', 6)->nullable();
                $t->timestamp('last_complete_sync_at', 6)->nullable();
                $t->string('read_pause_reason', 64)->nullable();
                $t->longText('evidence')->nullable();
                $t->longText('sync_state')->nullable();
                $t->timestamps(6);
            });
        }
        if (! Schema::hasTable('subscription_recharge_credential_revisions')) {
            Schema::create('subscription_recharge_credential_revisions', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('connection_id');
                $t->unsignedInteger('revision');
                $t->string('status', 32)->default('pending_verification');
                $t->longText('credentials');
                $t->char('credential_fingerprint', 64);
                $t->char('digest_key_id', 16);
                $t->json('scopes')->nullable();
                $t->string('token_id', 190)->nullable();
                $t->timestamp('verified_at', 6)->nullable();
                $t->timestamp('rotated_at', 6)->nullable();
                $t->timestamp('revoked_at', 6)->nullable();
                $t->timestamp('expires_at', 6)->nullable();
                $t->timestamps(6);
            });
        }
        if (! Schema::hasTable('subscription_recharge_operations')) {
            Schema::create('subscription_recharge_operations', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('connection_id');
                $t->unsignedBigInteger('credential_revision_id');
                $t->unsignedBigInteger('actor_user_id')->nullable();
                $t->char('request_uuid', 36);
                $t->string('kind', 40);
                $t->string('status', 24)->default('queued');
                $t->longText('request');
                $t->longText('result')->nullable();
                $t->char('request_fingerprint', 64);
                $t->char('digest_key_id', 16);
                $t->unsignedInteger('attempts')->default(0);
                $t->char('lease_token', 36)->nullable();
                $t->timestamp('lease_expires_at', 6)->nullable();
                $t->timestamp('next_attempt_at', 6)->nullable();
                $t->timestamp('completed_at', 6)->nullable();
                $t->timestamp('failure_at', 6)->nullable();
                $t->string('failure_code', 64)->nullable();
                $t->timestamps(6);
            });
        }
        if (! Schema::hasTable('subscription_recharge_resources')) {
            Schema::create('subscription_recharge_resources', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('connection_id');
                $t->unsignedBigInteger('sync_operation_id');
                $t->char('generation_id', 36);
                $t->string('resource_type', 40);
                $t->string('provider_id', 190);
                $t->string('parent_provider_id', 190)->nullable();
                $t->string('customer_provider_id', 190)->nullable();
                $t->string('status', 40)->default('unknown');
                // Retain the provider's exact timestamp; this is not a CAS revision.
                $t->string('source_updated_at', 64)->nullable();
                $t->string('mapping_state', 40)->default('unreviewed');
                $t->char('canonical_digest', 64);
                $t->char('digest_key_id', 16);
                $t->timestamp('observed_at', 6);
                $t->string('completeness', 24)->default('staged');
                $t->string('stale_reason', 64)->nullable();
                $t->longText('snapshot');
                $t->longText('mapping')->nullable();
                $t->timestamps(6);
            });
        }

        // CREATE and every ALTER commit independently on MySQL. Repair named
        // constraints after every table exists, preserving all retained evidence.
        foreach ($this->indexes() as $table => $definitions) {
            foreach ($definitions as $name => [$columns, $unique]) {
                $existing = collect(Schema::getIndexes($table))->firstWhere('name', $name);
                if ($existing && ($existing['columns'] !== $columns || $existing['unique'] !== $unique || $existing['primary'])) {
                    throw new RuntimeException('Unexpected Recharge evidence index definition.');
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
                        throw new RuntimeException('Unexpected Recharge evidence foreign key definition.');
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
            'subscription_recharge_connections' => [
                'src_public_uq' => [['public_id'], true],
                'src_scope_uq' => [['id', 'tenant_id'], true],
                'src_store_uq' => [['platform', 'provider_store_id'], true],
                'src_tenant_status_idx' => [['tenant_id', 'status', 'id'], false],
                'src_active_idx' => [['active_credential_revision_id', 'id', 'tenant_id'], false],
                'src_source_idx' => [['source_integration_connection_id'], false],
                'src_shopify_idx' => [['shopify_store_id'], false],
            ],
            'subscription_recharge_credential_revisions' => [
                'srcr_scope_uq' => [['id', 'connection_id', 'tenant_id'], true],
                'srcr_revision_uq' => [['connection_id', 'revision'], true],
                'srcr_connection_idx' => [['connection_id', 'tenant_id'], false],
            ],
            'subscription_recharge_operations' => [
                'sro_scope_uq' => [['id', 'connection_id', 'tenant_id'], true],
                'sro_request_uq' => [['connection_id', 'request_uuid'], true],
                'sro_connection_idx' => [['connection_id', 'tenant_id'], false],
                'sro_credential_idx' => [['credential_revision_id', 'connection_id', 'tenant_id'], false],
                'sro_due_idx' => [['status', 'next_attempt_at', 'id'], false],
                'sro_lease_idx' => [['status', 'lease_expires_at', 'id'], false],
                'sro_tenant_idx' => [['tenant_id', 'connection_id', 'id'], false],
            ],
            'subscription_recharge_resources' => [
                'srr_generation_uq' => [['connection_id', 'resource_type', 'provider_id', 'generation_id'], true],
                'srr_connection_idx' => [['connection_id', 'tenant_id'], false],
                'srr_operation_idx' => [['sync_operation_id', 'connection_id', 'tenant_id'], false],
                'srr_scope_generation_idx' => [['tenant_id', 'connection_id', 'generation_id', 'resource_type', 'id'], false],
                'srr_customer_idx' => [['connection_id', 'generation_id', 'customer_provider_id'], false],
            ],
        ];
    }

    public function foreignKeys(): array
    {
        $scope = fn (string $prefix) => [$prefix.'_connection_fk' => [['connection_id', 'tenant_id'], 'subscription_recharge_connections', ['id', 'tenant_id']]];

        return [
            'subscription_recharge_connections' => [
                'src_tenant_fk' => [['tenant_id'], 'tenants', ['id']],
                // These are provenance only; the read service also validates tenant ownership.
                'src_source_fk' => [['source_integration_connection_id'], 'integration_connections', ['id']],
                'src_shopify_fk' => [['shopify_store_id'], 'shopify_stores', ['id']],
                'src_active_fk' => [['active_credential_revision_id', 'id', 'tenant_id'], 'subscription_recharge_credential_revisions', ['id', 'connection_id', 'tenant_id']],
            ],
            'subscription_recharge_credential_revisions' => $scope('srcr'),
            'subscription_recharge_operations' => $scope('sro') + ['sro_credential_fk' => [['credential_revision_id', 'connection_id', 'tenant_id'], 'subscription_recharge_credential_revisions', ['id', 'connection_id', 'tenant_id']]],
            'subscription_recharge_resources' => $scope('srr') + ['srr_operation_fk' => [['sync_operation_id', 'connection_id', 'tenant_id'], 'subscription_recharge_operations', ['id', 'connection_id', 'tenant_id']]],
        ];
    }

    public function down(): void
    {
        // Verified identity, credentials and accepted history survive code rollback.
    }
};
