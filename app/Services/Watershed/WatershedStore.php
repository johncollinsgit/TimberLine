<?php

namespace App\Services\Watershed;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WatershedStore extends \App\Services\Soccer\SoccerStore
{
    public function query(string $table, int $tenant)
    {
        return DB::table('watershed_'.$table)->where('tenant_id', $tenant);
    }

    public function insert(string $table, int $tenant, array $data): string
    {
        $id = (string) Str::uuid();
        DB::table('watershed_'.$table)->insert(['id' => $id, 'tenant_id' => $tenant, 'created_at' => now(), 'updated_at' => now(), ...$data]);

        return $id;
    }

    public function event(int $tenant, string $action, array $payload, ?int $actor = null, ?string $key = null): void
    {
        $key ??= $action.':'.Str::uuid();
        if (! $this->query('events', $tenant)->where('event_key', $key)->exists()) {
            $this->insert('events', $tenant, ['event_key' => $key, 'action' => $action, 'actor_id' => $actor, 'payload' => $this->encrypt($payload)]);
        }
    }
}
