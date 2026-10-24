<?php

declare(strict_types=1);

namespace App\Services\Pos;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/** All POS tables are runtime/tenant scoped, including jobs and historical reads. */
final class PosStore
{
    public function __construct(public readonly Connection $db, private Encrypter $crypt) {}

    public function runtime(): string
    {
        $id = config('pos_live.runtime_id');
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $id)) {
            throw new PosException('runtime_unconfigured', 503);
        }

        return $id;
    }

    public function rows(string $table, int $tenant): Builder
    {
        if (! in_array($table, ['merchants', 'registers', 'assignments', 'connections', 'items', 'sales', 'holds', 'outbox', 'events', 'handoffs'], true) || $tenant < 1) {
            throw new PosException('scope_invalid', 403);
        }

        return $this->db->table('pos_live_'.$table)->where('runtime_id', $this->runtime())->where('tenant_id', $tenant);
    }

    public function values(int $tenant, array $values): array
    {
        return [...$values, 'runtime_id' => $this->runtime(), 'tenant_id' => $tenant];
    }

    public function encrypt(array $value): string
    {
        return $this->crypt->encrypt(json_encode($value, JSON_THROW_ON_ERROR), false);
    }

    public function decrypt(string $value): array
    {
        return json_decode($this->crypt->decrypt($value, false), true, 512, JSON_THROW_ON_ERROR);
    }

    public function event(int $tenant, string $sale, string $code, array $details = []): void
    {
        $this->rows('events', $tenant)->insert($this->values($tenant, ['sale_id' => $sale, 'code' => $code,
            'details' => $this->encrypt($details), 'created_at' => now()]));
    }

    public function outsideTransaction(): void
    {
        if ($this->db->transactionLevel() !== 0 || $this->db->getPdo()->inTransaction()) {
            throw new PosException('provider_transaction_boundary', 503);
        }
    }
}
