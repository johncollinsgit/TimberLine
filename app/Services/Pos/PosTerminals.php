<?php

namespace App\Services\Pos;

use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Facades\Cache;

/** One explicitly paired Terminal per register. No browser receives OAuth tokens. */
final class PosTerminals
{
    public const SANDBOX_DEVICE = '9fa747a2-25ff-48ee-b078-04381f7c828f';

    public function __construct(private PosStore $store, private SquareClient $square) {}

    public function current(object $register): ?object
    {
        $id = $this->store->decrypt($register->configuration)['terminal_id'] ?? null;

        return is_string($id) ? $this->store->rows('terminals', (int) $register->tenant_id)->where('register_id', $register->id)->where('id', $id)->first() : null;
    }

    public function pair(array $admin, string $registerId, string $requestId): array
    {
        if (! $admin['admin'] || config('pos_live.terminal_pairing_enabled') !== true) {
            throw new PosException('terminal_owner_pairing_disabled', 403);
        }
        $register = $admin['registers']->firstWhere('id', $registerId);
        if (! $register || ($this->store->decrypt($register->configuration)['payment_mode'] ?? null) !== 'square_terminal') {
            throw new PosException('terminal_register_required', 404);
        }

        return Cache::lock('pos-pair-'.hash('sha256', $this->store->runtime().':'.$admin['tenant_id'].':'.$registerId), 120)->block(5, function () use ($admin, $register, $requestId): array {
            $connection = $this->square->connection($admin['tenant_id'], $register->connection_id);
            $this->square->verify($connection);
            $row = $this->store->db->transaction(function () use ($admin, $register, $connection, $requestId): object {
                $fresh = $this->store->rows('registers', $admin['tenant_id'])->where('id', $register->id)->lockForUpdate()->firstOrFail();
                $old = $this->current($fresh);
                if ($old && ! in_array($old->state, ['EXPIRED', 'retired'], true)) {
                    return $old;
                }
                // Never recreate a previously used request after expiry.
                if ($old = $this->store->rows('terminals', $admin['tenant_id'])->where('request_id', $requestId)->first()) {
                    if ($old->register_id !== $register->id) {
                        throw new PosException('pairing_request_conflict');
                    }

                    return $old;
                }
                $id = bin2hex(random_bytes(16));
                $payload = ['idempotency_key' => $requestId, 'device_code' => ['product_type' => 'TERMINAL_API',
                    'location_id' => $connection->provider_location_id, 'name' => 'Everbranch-'.$id]];
                $this->store->rows('terminals', $admin['tenant_id'])->insert($this->store->values($admin['tenant_id'], [
                    'id' => $id, 'register_id' => $register->id, 'connection_id' => $connection->id,
                    'merchant_id' => $connection->merchant_id, 'location_id' => $connection->provider_location_id, 'environment' => $connection->environment,
                    'request_id' => $requestId, 'request_payload' => $this->store->encrypt($payload), 'request_hash' => Contract::hash($payload),
                    'created_at' => now(), 'actor_id' => $admin['actor_id'],
                ]));
                $settings = $this->store->decrypt($fresh->configuration);
                $settings['terminal_id'] = $id;
                unset($settings['acceptance']);
                $this->store->rows('registers', $admin['tenant_id'])->where('id', $register->id)->update(['configuration' => $this->store->encrypt($settings), 'collection_enabled' => false]);

                return $this->store->rows('terminals', $admin['tenant_id'])->where('id', $id)->firstOrFail();
            }, 3);
            $this->verify($row, $connection);

            return $this->projection($this->store->rows('terminals', $admin['tenant_id'])->where('id', $row->id)->firstOrFail());
        });
    }

    public function verify(object $row, object $connection): void
    {
        if ($row->connection_id !== $connection->id || $row->merchant_id !== $connection->merchant_id
            || $row->location_id !== $connection->provider_location_id || $row->environment !== $connection->environment) {
            throw new PosException('terminal_original_account_mismatch');
        }
        $payload = $this->store->decrypt($row->request_payload);
        if (! hash_equals($row->request_hash, Contract::hash($payload))) {
            throw new PosException('terminal_pairing_integrity_error', 503);
        }
        if ($row->environment === 'sandbox') {
            // Devices API cannot pair physical hardware in Sandbox. Use Square's
            // published success fixture only, under a real Sandbox authorization.
            $this->store->rows('terminals', (int) $row->tenant_id)->where('id', $row->id)->update([
                'device_id' => self::SANDBOX_DEVICE, 'device_claim' => hash('sha256', $row->merchant_id.':'.$row->location_id.':sandbox'),
                'state' => 'PAIRED', 'verified_at' => now(),
            ]);

            return;
        }
        if ($row->code_id) {
            $code = $this->square->get($connection, '/v2/devices/codes/'.rawurlencode($row->code_id))['device_code'] ?? [];
        } else {
            if (strtotime($row->created_at) < time() - 600) {
                throw new PosException('unknown_pairing_requires_provider_review');
            }
            // Online retry of the identical, durably saved provider idempotency
            // key/body. No new code or financial checkout is fabricated.
            $code = $this->square->post($connection, '/v2/devices/codes', $payload)['device_code'] ?? [];
        }
        if (! is_string($code['id'] ?? null) || ($row->code_id && $row->code_id !== $code['id'])
            || ($code['product_type'] ?? null) !== 'TERMINAL_API' || ($code['location_id'] ?? null) !== $row->location_id
            || ($code['name'] ?? null) !== $payload['device_code']['name']
            || ! in_array($code['status'] ?? null, ['UNPAIRED', 'PAIRED', 'EXPIRED'], true)) {
            throw new PosException('terminal_pairing_identity_mismatch');
        }
        $device = $code['device_id'] ?? null;
        if ($code['status'] === 'PAIRED' && (! is_string($device) || ! preg_match('/^[A-Za-z0-9_:-]{1,100}$/D', $device)
            || ($row->device_id && $row->device_id !== $device))) {
            throw new PosException('terminal_device_changed');
        }
        $this->store->rows('terminals', (int) $row->tenant_id)->where('id', $row->id)->update([
            'code_id' => $code['id'], 'pairing' => $this->store->encrypt(collect($code)->only(['code', 'pair_by'])->all()),
            'device_id' => $device, 'device_claim' => $device ? hash('sha256', $device) : null, 'state' => $code['status'], 'verified_at' => now(),
        ]);
    }

    public function overview(array $scope): array
    {
        if (! $scope['admin']) {
            return [];
        }

        return $scope['registers']->filter(fn ($r) => ($this->store->decrypt($r->configuration)['payment_mode'] ?? null) === 'square_terminal')
            ->map(fn ($r) => ['register_id' => $r->id, 'name' => $r->name, 'terminal' => ($row = $this->current($r)) ? $this->projection($row) : null])->all();
    }

    private function projection(object $row): array
    {
        $pairing = $row->pairing ? $this->store->decrypt($row->pairing) : [];

        return ['id' => $row->id, 'state' => $row->state, 'device_id' => $row->device_id, 'environment' => $row->environment,
            'code' => $row->state === 'UNPAIRED' ? ($pairing['code'] ?? null) : null, 'pair_by' => $pairing['pair_by'] ?? null,
            'accepted_build' => $row->acceptance_build];
    }
}
