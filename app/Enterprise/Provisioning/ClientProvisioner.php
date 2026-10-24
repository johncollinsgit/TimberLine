<?php

namespace App\Enterprise\Provisioning;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Initializes client-owned structure only. Employee access is a separate approval. */
final class ClientProvisioner
{
    public function __construct(private ClientSilo $silo) {}

    public function plan(array $input): array
    {
        $manifest = ClientManifest::validate($input);
        $binding = $this->silo->binding();
        foreach (['client_id', 'silo_id', 'environment', 'storage_id'] as $field) {
            if ($manifest[$field] !== $binding[$field]) {
                throw new RuntimeException('Client manifest does not match the configured '.$field.'.');
            }
        }
        $manifestHash = hash('sha256', ClientManifest::canonical($manifest));
        $bindingHash = hash('sha256', ClientManifest::canonical($binding));
        $row = $this->silo->row();
        if ($row) {
            $this->silo->assertReady();
            if (! hash_equals($row->manifest_hash, $manifestHash)) {
                throw new RuntimeException('An installed client configuration cannot be replaced by initial provisioning.');
            }

            return ['plan_hash' => $row->plan_hash, 'manifest_hash' => $manifestHash, 'binding_hash' => $bindingHash,
                'state' => 'already_applied', 'changes' => [], 'backup_status' => 'pending_plan_review'];
        }
        // A new claim is allowed only for a genuinely empty, reviewed schema.
        $this->silo->assertInitialSchema();
        $this->silo->assertEmptyInitialStorage();
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (! in_array($table, ['migrations', 'enterprise_client_silos'], true) && DB::table($table)->exists()) {
                throw new RuntimeException('Refusing to claim a database containing existing records.');
            }
        }
        $sources = [];
        foreach ([...glob(__DIR__.'/*.php'), ...glob(database_path('migrations/*.php')), base_path('apps/enterprise/database/shared-schema-v1.php'), resource_path('data/enterprise/lees-mcrae-locations.json')] as $path) {
            $sources[str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)] = hash('sha256', str_replace("\r\n", "\n", file_get_contents($path)));
        }
        $plan = ['manifest_hash' => $manifestHash, 'binding_hash' => $bindingHash,
            'source_digest' => hash('sha256', ClientManifest::canonical($sources)),
            'changes' => ['tenants' => 1, 'departments' => count($manifest['departments']), 'locations' => count($manifest['locations']),
                'users' => 0, 'memberships' => 0, 'entitlements' => 0, 'workflow_runs' => 0, 'provider_actions' => 0],
            'backup_status' => 'pending_plan_review'];
        $plan['plan_hash'] = hash('sha256', ClientManifest::canonical($plan));
        $marker = $this->silo->marker();
        if ($marker && (($marker['binding_hash'] ?? '') !== $bindingHash || ($marker['plan_hash'] ?? '') !== $plan['plan_hash'])) {
            throw new RuntimeException('A different or interrupted client claim requires operator review.');
        }

        return $plan + ['state' => $marker ? 'prepared' : 'ready_to_apply'];
    }

    public function apply(array $input, string $expectedHash): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $expectedHash)) {
            throw new RuntimeException('The exact reviewed plan hash is required.');
        }
        $this->silo->binding();
        $connection = DB::connection('enterprise');
        $lock = 'enterprise-client:'.hash('sha256', $connection->getDatabaseName());
        $lock = substr($lock, 0, 64);
        $mysql = $connection->getDriverName() === 'mysql';
        if ($mysql && (int) $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lock])->acquired !== 1) {
            throw new RuntimeException('The client database provisioning lock is busy.');
        }
        try {
            $plan = $this->plan($input);
            if (! hash_equals($plan['plan_hash'], $expectedHash)) {
                throw new RuntimeException('The reviewed plan is stale or targets another runtime.');
            }
            if ($plan['state'] === 'already_applied') {
                return $this->silo->status() + ['replayed' => true];
            }
            $manifest = ClientManifest::validate($input);
            $this->prepareMarker($plan);

            return $connection->transaction(function () use ($manifest, $plan): array {
                $tenant = Tenant::query()->create($manifest['tenant']);
                $areas = [];
                foreach ($manifest['departments'] as $department) {
                    $areas[$department['key']] = $department['id'];
                    TeamArea::query()->create(['id' => $department['id'], 'tenant_id' => $tenant->id,
                        'slug' => $department['key'], 'name' => $department['name'],
                        'profile' => $department['visibility'] === 'shared' ? 'enterprise_shared' : 'enterprise_department',
                        'version' => 1, 'settings' => ['client_department_key' => $department['key']]]);
                }
                foreach ($manifest['locations'] as $location) {
                    TeamOperationRecord::query()->create(['id' => $location['id'], 'tenant_id' => $tenant->id,
                        'area_id' => $areas[$location['department_key']], 'kind' => 'ent_resource_location',
                        'title' => $location['name'], 'status' => 'active', 'version' => 1, 'visibility' => 'area',
                        'source_key' => hash('sha256', 'client-location:'.$manifest['silo_id'].':'.$location['key']),
                        'data' => [...($location['source_metadata'] ?? []), 'level' => 'facility', 'parent_id' => null, 'catalog_key' => $location['key'],
                            'source_url' => $location['source_metadata']['source_url'] ?? $location['source_url'], 'source_label' => $location['source_label'],
                            'hierarchy_verified' => false, 'latitude' => null, 'longitude' => null,
                            'ownership' => null, 'campus_area_verified' => false]]);
                }
                DB::table('enterprise_client_silos')->insert(['id' => 1, 'client_id' => $manifest['client_id'],
                    'silo_id' => $manifest['silo_id'], 'environment' => $manifest['environment'], 'tenant_id' => $tenant->id,
                    'manifest_hash' => $plan['manifest_hash'], 'plan_hash' => $plan['plan_hash'], 'binding_hash' => $plan['binding_hash'],
                    'manifest' => Crypt::encryptString(ClientManifest::canonical($manifest)), 'applied_at' => now()]);

                return $this->silo->status() + ['replayed' => false];
            });
        } finally {
            if ($mysql) {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            }
        }
    }

    private function prepareMarker(array $plan): void
    {
        if ($this->silo->marker() !== null) {
            return;
        }
        $handle = @fopen($this->silo->markerPath(), 'x');
        if ($handle === false) {
            throw new RuntimeException('The client filesystem marker could not be claimed exclusively.');
        }
        try {
            $json = ClientManifest::canonical(array_intersect_key($plan, array_flip(['manifest_hash', 'plan_hash', 'binding_hash'])));
            if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('The client marker write is incomplete; operator review is required.');
            }
        } finally {
            fclose($handle);
        }
    }
}
