<?php

namespace App\Services\Soccer;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SoccerStore
{
    public function query(string $table, int $tenant)
    {
        return DB::table('soccer_'.$table)->where('tenant_id', $tenant);
    }

    public function row(string $table, int $tenant, string $id)
    {
        return $this->query($table, $tenant)->where('id', $id)->firstOrFail();
    }

    public function insert(string $table, int $tenant, array $data): string
    {
        $id = (string) Str::uuid();
        DB::table('soccer_'.$table)->insert(['id' => $id, 'tenant_id' => $tenant, 'created_at' => now(), 'updated_at' => now(), ...$data]);

        return $id;
    }

    public function encrypt(array $data): string
    {
        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function decrypt(?string $data): array
    {
        return $data ? json_decode(Crypt::decryptString($data), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function settings(int $tenant): array
    {
        return $this->decrypt(DB::table('soccer_settings')->where('tenant_id', $tenant)->value('data'));
    }

    public function saveSettings(int $tenant, array $data): void
    {
        DB::table('soccer_settings')->updateOrInsert(['tenant_id' => $tenant], ['data' => $this->encrypt($data), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function lockSeason(int $tenant, string $id)
    {
        return $this->query('seasons', $tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
    }
}
