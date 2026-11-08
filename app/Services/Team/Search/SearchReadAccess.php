<?php

namespace App\Services\Team\Search;

use App\Models\TeamArea;
use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Operations\OperationsAccess;

/** One search request only; every cached decision is made by the existing policy. */
class SearchReadAccess extends OperationsAccess
{
    private array $actors = [];

    private array $areas = [];

    private array $connections = [];

    public function actor(Tenant $tenant, User $user): array
    {
        $key = $tenant->id.':'.$user->id;

        return $this->actors[$key] ??= parent::actor($tenant, $user);
    }

    public function area(Tenant $tenant, User $user, string $id, bool $write = false): TeamArea
    {
        $key = $tenant->id.':'.$user->id.':'.$id.':'.(int) $write;
        if (! array_key_exists($key, $this->areas)) {
            try {
                $this->areas[$key] = parent::area($tenant, $user, $id, $write);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
                $this->areas[$key] = $error;
            }
        }
        if ($this->areas[$key] instanceof \Throwable) {
            throw $this->areas[$key];
        }

        return $this->areas[$key];
    }

    public function connection(Tenant $tenant, User $user, string $id, bool $manage = false): TeamProviderConnection
    {
        $key = $tenant->id.':'.$user->id.':'.$id.':'.(int) $manage;
        if (! array_key_exists($key, $this->connections)) {
            try {
                $this->connections[$key] = parent::connection($tenant, $user, $id, $manage);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
                $this->connections[$key] = $error;
            }
        }
        if ($this->connections[$key] instanceof \Throwable) {
            throw $this->connections[$key];
        }

        return $this->connections[$key];
    }
}
