<?php

namespace App\Services\Team\Studio;

use App\Services\Marketing\CustomerProfileAccess;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Marketing\SquareCustomerIdentityService;
use App\Services\Marketing\SquareSourceRefresh;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Owns local reporting reads; never grants access or changes a writer's transaction. */
final class EventCustomerReadView
{
    private ?array $view = null;

    public function run(int $tenant, callable $work): mixed
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'mysql') {
            return $db->transaction($work);
        }
        abort_if($tenant < 1 || $this->view !== null || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction(), 409, 'Customer dependency reads need a fresh short transaction.');
        $receipt = null;
        $result = $this->read($db, $tenant, function () use ($work, &$receipt) {
            $result = $work();
            $receipt = $this->view;
            $receipt['authority'] = $this->authority($receipt);

            return $result;
        });
        if ($receipt['bindings'] || $receipt['policies'] || $receipt['actors'] || $receipt['profiles']) {
            $this->read($db, $tenant, function () use ($receipt, $tenant): void {
                abort_unless(hash_equals($receipt['authority'], $this->authority($receipt)), 409, 'Original Square reporting authority changed during its read.');
                foreach ($receipt['bindings'] as $entry) {
                    if ($entry['admitted']) {
                        app(SquareConnectionContext::class)->assertCurrent($tenant, $entry['binding']);
                    }
                }
                foreach ($receipt['policies'] as $version => $admitted) {
                    if ($admitted) {
                        app(SquareSourceRefresh::class)->assertPolicyCurrent($tenant, $version);
                    }
                }
                foreach ($receipt['actors'] as $actor => $admitted) {
                    if (! $admitted) {
                        continue;
                    }
                    $user = \App\Models\User::find($actor);
                    abort_unless($user, 409, 'The Square reporting reviewer changed.');
                    app(SquareCustomerIdentityService::class)->authorize($tenant, $user);
                }
                foreach ($receipt['profiles'] as $actor => $admitted) {
                    if ($admitted) {
                        abort_unless(app(CustomerProfileAccess::class)->allows(\App\Models\User::find($actor), $tenant), 409, 'Customer reporting access changed.');
                    }
                }
            });
        }

        return $result;
    }

    /** A transaction level alone must never select nonlocking writer behavior. */
    public function owns(int $tenant, ?Connection $db = null): bool
    {
        $db ??= DB::connection();

        return $this->view !== null && $this->view['tenant'] === $tenant && $this->view['db'] === $db
            && $db->transactionLevel() === 1 && $db->getPdo() === $this->view['pdo'] && $db->getPdo()->inTransaction();
    }

    public function binding(int $tenant, array $binding, bool $admitted = false): void
    {
        if ($this->owns($tenant)) {
            $key = hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR));
            $this->view['bindings'][$key] = ['binding' => $binding, 'admitted' => $admitted || ($this->view['bindings'][$key]['admitted'] ?? false)];
        }
    }

    public function policy(int $tenant, string $version, int $actor, bool $admitted = false): void
    {
        if ($this->owns($tenant)) {
            $this->view['policies'][$version] = $admitted || ($this->view['policies'][$version] ?? false);
            $this->view['policy_actors'][$actor] = $actor;
        }
    }

    public function actor(int $tenant, int $actor, bool $admitted = false): void
    {
        if ($this->owns($tenant)) {
            $this->view['actors'][$actor] = $admitted || ($this->view['actors'][$actor] ?? false);
        }
    }

    public function profile(int $tenant, int $actor, bool $admitted): void
    {
        if ($this->owns($tenant)) {
            $this->view['profiles'][$actor] = $admitted || ($this->view['profiles'][$actor] ?? false);
        }
    }

    /** Resolver instance caches belong to this one physical read view only. */
    public function modules(int $tenant): ?TenantModuleAccessResolver
    {
        if (! $this->owns($tenant)) {
            return null;
        }

        return $this->view['modules'] ??= app()->build(TenantModuleAccessResolver::class);
    }

    private function read(Connection $db, int $tenant, callable $work): mixed
    {
        abort_if($this->view !== null || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction(), 409, 'Customer reporting needs its own read view.');
        $pdo = $db->getPdo();
        // Next transaction only: neither the caller nor the session is made read-only.
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

        return $db->transaction(function () use ($db, $pdo, $tenant, $work) {
            abort_unless($db->transactionLevel() === 1 && $db->getPdo() === $pdo && $pdo->inTransaction(), 409, 'The customer reporting connection changed.');
            $marker = 'event_customer_read_'.bin2hex(random_bytes(12));
            $db->statement('SAVEPOINT '.$marker);
            $this->view = ['tenant' => $tenant, 'db' => $db, 'pdo' => $pdo, 'bindings' => [], 'policies' => [], 'policy_actors' => [], 'actors' => [], 'profiles' => [], 'modules' => null];
            try {
                $result = $work();
                abort_unless($this->owns($tenant, $db), 409, 'The customer reporting transaction changed.');
                try {
                    // Commit/restart on the same PDO loses this transaction's marker.
                    $db->statement('RELEASE SAVEPOINT '.$marker);
                } catch (\Illuminate\Database\QueryException $error) {
                    abort(409, 'The customer reporting read transaction was replaced.');
                }

                return $result;
            } finally {
                $this->view = null;
            }
        });
    }

    /** Full private input witnesses, including absent rows and new matching rows. */
    private function authority(array $receipt): string
    {
        $tenant = $receipt['tenant'];
        $parts = [];
        if ($receipt['bindings'] || $receipt['policies']) {
            $keys = ['square_config', ...($receipt['policies'] ? [SquareSourceRefresh::KEY] : [])];
            $parts['settings'] = $this->rows('tenant_marketing_settings', fn ($q) => $q->where('tenant_id', $tenant)->whereIn('key', $keys));
            $parts['connections'] = $this->rows('pos_live_connections', fn ($q) => $q->where('tenant_id', $tenant)->where('runtime_id', config('pos_live.runtime_id')));
            $parts['runtime'] = [config('pos_live.runtime_id'), config('marketing.square.environment'), hash('sha256', (string) config('marketing.square.access_token'))];
        }
        $actors = array_values(array_unique([...array_keys($receipt['actors']), ...array_keys($receipt['profiles']), ...array_values($receipt['policy_actors'])]));
        if ($actors) {
            $parts['tenant'] = $this->rows('tenants', fn ($q) => $q->where('id', $tenant));
            $parts['actors'] = $this->rows('users', fn ($q) => $q->whereIn('id', $actors));
            $parts['memberships'] = $this->rows('tenant_user', fn ($q) => $q->where('tenant_id', $tenant)->whereIn('user_id', $actors));
            foreach (['tenant_access_profiles', 'tenant_access_addons', 'tenant_module_states', 'tenant_module_entitlements'] as $table) {
                $parts[$table] = $this->rows($table, fn ($q) => $q->where('tenant_id', $tenant));
            }
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function rows(string $table, callable $scope): ?array
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        $rows = $scope(DB::table($table))->get()->map(fn ($row) => (array) $row)->all();
        // Stable multiplicity without assuming every authority table has an id.
        usort($rows, fn ($a, $b) => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));

        return $rows;
    }
}
