<?php

namespace App\Enterprise\Provisioning;

use App\Http\Middleware\EnsureEnterpriseRuntime;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/** A runtime/DB/files binding, in addition to ordinary tenant and department ACLs. */
final class ClientSilo
{
    public function enabled(): bool
    {
        if ((app()->bound('enterprise.client_root_selected') && app('enterprise.client_root_selected') === true)
            || config('enterprise_client.client_id', '') !== '') {
            return true;
        }

        // A managed database cannot become standalone by accidentally removing
        // its environment selectors. Retail never performs this lookup.
        return config('enterprise.runtime') === true && EnsureEnterpriseRuntime::configured()
            && Schema::hasTable('enterprise_client_silos') && DB::table('enterprise_client_silos')->exists();
    }

    public function binding(): array
    {
        if (! app()->bound('enterprise.client_root_selected') || app('enterprise.client_root_selected') !== true
            || config('enterprise.runtime') !== true || ! EnsureEnterpriseRuntime::configured()) {
            throw new RuntimeException('An isolated client runtime root is required.');
        }
        foreach (['client_id', 'silo_id', 'storage_id'] as $field) {
            if (! Str::isUuid(config('enterprise_client.'.$field))) {
                throw new RuntimeException('The client runtime identity is incomplete.');
            }
        }
        if (! in_array(config('enterprise_client.environment'), ['demo', 'production', 'staging', 'testing'], true)) {
            throw new RuntimeException('The client environment designation is required.');
        }
        $connection = DB::connection('enterprise');
        $database = $connection->getDatabaseName();
        if ($database === '' || config('enterprise_client.database') !== $database
            || ($connection->getDriverName() === 'mysql' && $connection->selectOne('SELECT DATABASE() AS name')->name !== $database)) {
            throw new RuntimeException('The configured client database does not match the active database.');
        }
        $runtime = realpath(app('enterprise.runtime_root'));
        $storage = realpath(storage_path());
        if ($runtime === false || $storage === false || $storage !== realpath($runtime.'/storage')) {
            throw new RuntimeException('The client private storage binding does not match its runtime.');
        }
        foreach (['local' => 'app/private', 'team_private' => 'app/team-private'] as $disk => $relative) {
            $expected = $storage.'/'.$relative;
            if (config('filesystems.disks.'.$disk.'.driver') !== 'local'
                || str_replace('\\', '/', (string) config('filesystems.disks.'.$disk.'.root')) !== str_replace('\\', '/', $expected)
                || config('filesystems.disks.'.$disk.'.serve') !== false || config('filesystems.disks.'.$disk.'.visibility') !== 'private') {
                throw new RuntimeException('The client private disk is not isolated.');
            }
            $cursor = $storage;
            foreach (explode('/', $relative) as $segment) {
                $cursor .= '/'.$segment;
                if (is_link($cursor) || (file_exists($cursor)
                    && str_replace('\\', '/', (string) realpath($cursor)) !== str_replace('\\', '/', $cursor))) {
                    throw new RuntimeException('The client private disk aliases another location.');
                }
            }
        }

        return [
            'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'),
            'environment' => config('enterprise_client.environment'), 'storage_id' => config('enterprise_client.storage_id'),
            'database' => ['driver' => $connection->getDriverName(), 'host' => $connection->getConfig('host'),
                'port' => $connection->getConfig('port'), 'name' => $database, 'username' => $connection->getConfig('username')],
            'runtime' => $runtime, 'storage' => $storage, 'key_fingerprint' => hash('sha256', (string) config('app.key')),
        ];
    }

    public function markerPath(): string
    {
        return app('enterprise.runtime_root').'/client-silo.json';
    }

    public function marker(): ?array
    {
        $path = $this->markerPath();
        if (! file_exists($path)) {
            return null;
        }
        if (! is_file($path) || is_link($path) || filesize($path) > 4096) {
            throw new RuntimeException('The client filesystem marker is invalid.');
        }
        $marker = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($marker)) {
            throw new RuntimeException('The client filesystem marker is invalid.');
        }

        return $marker;
    }

    public function row(): ?object
    {
        if (! Schema::hasTable('enterprise_client_silos')) {
            throw new RuntimeException('The reviewed Enterprise client schema must be installed first.');
        }
        if (DB::table('enterprise_client_silos')->count() > 1) {
            throw new RuntimeException('Unexpected client silo inventory.');
        }

        return DB::table('enterprise_client_silos')->first();
    }

    public function assertInitialSchema(): void
    {
        $migrations = array_map(fn ($path) => pathinfo($path, PATHINFO_FILENAME), glob(database_path('migrations/*.php')));
        $installed = DB::table('migrations')->pluck('migration')->all();
        sort($migrations);
        sort($installed);
        if ($migrations !== $installed) {
            throw new RuntimeException('Client provisioning requires exactly the reviewed Enterprise migration inventory.');
        }
        $expected = ['migrations', 'enterprise_client_silos', 'users', 'tenants', 'tenant_user', 'tenant_access_profiles',
            'tenant_access_addons', 'tenant_module_states', 'tenant_module_entitlements', 'tenant_mail_domains', 'tenant_mailboxes',
            'tenant_mailbox_users', 'team_work_locks', 'team_projects', 'team_tasks', 'team_project_members', 'team_task_projects',
            'team_work_events', 'team_work_receipts', 'team_inbox_items', 'team_work_versions', 'team_file_uploads', 'team_file_chunks',
            'team_areas', 'team_area_members', 'team_provider_connections', 'team_operation_records', 'team_source_snapshots',
            'team_operation_attempts', 'team_operation_revisions'];
        $actual = Schema::getTableListing(schemaQualified: false);
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException('Client provisioning refuses an unexpected database schema.');
        }
        if (Schema::getViews() !== []) {
            throw new RuntimeException('Client provisioning refuses unexpected database views.');
        }
    }

    public function assertEmptyInitialStorage(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(storage_path(), \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink() || ! $item->isDir()) {
                throw new RuntimeException('An unclaimed client runtime must have empty private storage, sessions and caches.');
            }
        }
    }

    public function assertReady(): object
    {
        $bindingHash = hash('sha256', ClientManifest::canonical($this->binding()));
        $row = $this->row();
        $marker = $this->marker();
        if (! $row || (int) $row->id !== 1 || ! $marker || ($marker['binding_hash'] ?? '') !== $bindingHash
            || $row->binding_hash !== $bindingHash || ($marker['plan_hash'] ?? '') !== $row->plan_hash
            || ($marker['manifest_hash'] ?? '') !== $row->manifest_hash || DB::table('tenants')->count() !== 1
            || $row->client_id !== config('enterprise_client.client_id') || $row->silo_id !== config('enterprise_client.silo_id')
            || $row->environment !== config('enterprise_client.environment')
            || ! DB::table('tenants')->where('id', $row->tenant_id)->exists()) {
            throw new RuntimeException('The client database and filesystem markers do not agree.');
        }

        return $row;
    }

    public function configuration(): array
    {
        $row = $this->assertReady();
        $manifest = json_decode(Crypt::decryptString($row->manifest), true, 32, JSON_THROW_ON_ERROR);
        if (! hash_equals($row->manifest_hash, hash('sha256', ClientManifest::canonical($manifest)))) {
            throw new RuntimeException('The installed client configuration digest does not match.');
        }

        return $manifest;
    }

    public function status(): array
    {
        $row = $this->assertReady();

        return ['client_id' => $row->client_id, 'silo_id' => $row->silo_id, 'environment' => $row->environment,
            'tenant_id' => (int) $row->tenant_id, 'manifest_hash' => $row->manifest_hash, 'plan_hash' => $row->plan_hash,
            'state' => 'configuration_installed', 'access_provisioned' => false, 'backup_status' => 'pending_plan_review'];
    }
}
