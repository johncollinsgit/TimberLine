<?php

namespace App\Services\PointOfSale;

use App\Models\PosSimulatorObject;
use Illuminate\Support\Facades\DB;

/** Durable fictional reader state, deliberately containing no network implementation. */
class DatabaseSimulatedReader implements SimulatedReader
{
    public function key(): string
    {
        return 'simulated_reader_v1';
    }

    public function capabilities(): array
    {
        return ['environment' => 'simulation', 'physical_readers' => false, 'offline' => false, 'partial_refunds' => true];
    }

    public function createPayment(array $snapshot, string $key): array
    {
        return $this->create('payment', $snapshot, $key, 'awaiting_reader');
    }

    public function payment(int $tenantId, string $id): array
    {
        return $this->read('payment', $tenantId, $id);
    }

    public function findPayment(int $tenantId, string $key): ?array
    {
        return $this->find('payment', $tenantId, $key);
    }

    public function cancelPayment(int $tenantId, string $id): array
    {
        return $this->simulatePayment($tenantId, $id, 'cancelled');
    }

    public function simulatePayment(int $tenantId, string $id, string $outcome): array
    {
        abort_unless(in_array($outcome, ['approved', 'declined', 'unknown', 'cancelled'], true), 422, 'Choose a supported simulated reader outcome.');

        return $this->transition('payment', $tenantId, $id, match ($outcome) {
            'approved' => 'succeeded', 'declined' => 'failed', 'cancelled' => 'cancelled', 'unknown' => 'processing',
        });
    }

    public function createRefund(array $snapshot, string $key): array
    {
        return $this->create('refund', $snapshot, $key, 'succeeded');
    }

    public function refund(int $tenantId, string $id): array
    {
        return $this->read('refund', $tenantId, $id);
    }

    public function findRefund(int $tenantId, string $key): ?array
    {
        return $this->find('refund', $tenantId, $key);
    }

    public function simulateRefund(int $tenantId, string $id, string $outcome): array
    {
        abort_unless(in_array($outcome, ['approved', 'declined', 'unknown'], true), 422, 'Choose a supported simulated refund outcome.');

        return $this->transition('refund', $tenantId, $id, match ($outcome) {
            'approved' => 'succeeded', 'declined' => 'failed', 'unknown' => 'pending',
        });
    }

    private function assertSimulation(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
    }

    private function create(string $kind, array $snapshot, string $key, string $status): array
    {
        $this->assertSimulation();
        abort_unless(($snapshot['provider_key'] ?? null) === $this->key()
            && ($snapshot['environment'] ?? null) === 'simulation' && (int) ($snapshot['tenant_id'] ?? 0) > 0
            && str_starts_with((string) ($snapshot['merchant_id'] ?? ''), 'sim_'), 422, 'Only fictional local reader requests are supported.');

        return DB::transaction(function () use ($kind, $snapshot, $key, $status): array {
            $objectKey = $kind.':'.$snapshot['tenant_id'].':'.$key;
            $hash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $object = PosSimulatorObject::query()->where('object_key', $objectKey)->lockForUpdate()->first();
            if ($object) {
                abort_unless($object->tenant_id === (int) $snapshot['tenant_id'] && hash_equals($object->request_hash, $hash), 409, 'The simulator key already belongs to different immutable terms.');

                return $object->payload;
            }
            $payload = [...$snapshot, 'id' => ($kind === 'payment' ? 'pi_sim_' : 're_sim_').$snapshot['tenant_id'].'_'.$key, 'status' => $status];
            PosSimulatorObject::query()->create(['tenant_id' => $snapshot['tenant_id'], 'object_key' => $objectKey,
                'kind' => $kind, 'request_hash' => $hash, 'payload' => $payload]);

            return $payload;
        });
    }

    private function find(string $kind, int $tenantId, string $key): ?array
    {
        $this->assertSimulation();

        return PosSimulatorObject::query()->where('tenant_id', $tenantId)->where('kind', $kind)
            ->where('object_key', $kind.':'.$tenantId.':'.$key)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first()?->payload;
    }

    private function object(string $kind, int $tenantId, string $id): PosSimulatorObject
    {
        $this->assertSimulation();
        $prefix = $kind === 'payment' ? 'pi_sim_' : 're_sim_';
        abort_unless(preg_match('/^'.preg_quote($prefix, '/').'([0-9]+)_([a-f0-9-]{36})$/i', $id, $match)
            && (int) $match[1] === $tenantId, 404);
        // Exact server-owned key lookup keeps encrypted evidence out of searchable JSON.
        $object = PosSimulatorObject::query()->where('tenant_id', $tenantId)->where('kind', $kind)
            ->where('object_key', $kind.':'.$tenantId.':'.$match[2])
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->firstOrFail();
        abort_unless(data_get($object->payload, 'id') === $id, 409);

        return $object;
    }

    private function read(string $kind, int $tenantId, string $id): array
    {
        return $this->object($kind, $tenantId, $id)->payload;
    }

    private function transition(string $kind, int $tenantId, string $id, string $status): array
    {
        $object = $this->object($kind, $tenantId, $id);

        return DB::transaction(function () use ($object, $status): array {
            $object = PosSimulatorObject::query()->where('tenant_id', $object->tenant_id)->lockForUpdate()->findOrFail($object->id);
            $payload = $object->payload;
            // Canonical terminal outcomes cannot be overwritten by a later UI command.
            if (! in_array($payload['status'], ['succeeded', 'failed', 'cancelled'], true)) {
                $payload['status'] = $status;
                $object->update(['payload' => $payload]);
            }

            return $payload;
        });
    }
}
