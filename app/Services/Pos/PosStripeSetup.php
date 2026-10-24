<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\TenantPaymentAccount;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Facades\Cache;

final class PosStripeSetup
{
    public function __construct(private PosStore $store, private StripeTerminalClient $stripe) {}

    public function bind(array $scope, string $registerId, string $location, string $readerId): void
    {
        if (! $scope['admin'] || config('pos_live.stripe_setup_enabled') !== true || config('enterprise.runtime') === true) {
            throw new PosException('stripe_owner_setup_unavailable', 403);
        }
        $account = TenantPaymentAccount::forTenantId($scope['tenant_id'])->where('provider', 'stripe_connect')->first();
        if (! $account || ! $account->isReady() || $account->routingMode() !== 'direct'
            || ! in_array(data_get($account->metadata, 'ownership_verified_via'), ['oauth', 'created_account'], true)) {
            throw new PosException('connect_existing_standard_account_first');
        }
        Cache::lock('pos-stripe-setup-'.hash('sha256', $this->store->runtime().':'.$scope['tenant_id'].':'.$registerId), 180)->block(5, function () use ($scope, $registerId, $location, $readerId, $account): void {
            $connectionId = substr(Contract::hash([$this->store->runtime(), $scope['tenant_id'], $account->provider_account_id, $location, config('pos_live.stripe_environment')]), 0, 32);
            $connection = (object) $this->store->values($scope['tenant_id'], ['id' => $connectionId, 'provider' => 'stripe_terminal',
                'merchant_id' => $account->provider_account_id, 'provider_location_id' => $location,
                'environment' => config('pos_live.stripe_environment'), 'credentials' => $this->store->encrypt(['platform_account' => config('pos_live.stripe_platform_account')])]);
            $this->stripe->verify($connection, false);
            $reader = $this->stripe->reader($connection, $readerId, true);
            $this->store->db->transaction(function () use ($scope, $registerId, $location, $readerId, $connection, $reader): void {
                $register = $this->store->rows('registers', $scope['tenant_id'])->where('id', $registerId)->lockForUpdate()->firstOrFail();
                $oldReader = $this->store->rows('readers', $scope['tenant_id'])->where('register_id', $registerId)->first();
                if ($oldReader && $oldReader->connection_id === $connection->id && $oldReader->reader_id === $readerId) {
                    return;
                }
                if ($this->store->rows('sales', $scope['tenant_id'])->where('register_id', $registerId)->exists()) {
                    throw new PosException('use_new_register_to_change_payment_binding');
                }
                $this->store->rows('connections', $scope['tenant_id'])->updateOrInsert(['id' => $connection->id], $this->store->values($scope['tenant_id'], [
                    'provider' => 'stripe_terminal', 'merchant_id' => $connection->merchant_id, 'provider_location_id' => $location,
                    'environment' => $connection->environment, 'credentials' => $connection->credentials, 'active' => true, 'verified_at' => now(),
                ]));
                $this->store->rows('readers', $scope['tenant_id'])->updateOrInsert(['register_id' => $registerId], $this->store->values($scope['tenant_id'], [
                    'id' => $oldReader?->id ?? bin2hex(random_bytes(16)), 'connection_id' => $connection->id, 'reader_id' => $readerId,
                    'location_id' => $location, 'identity_hash' => Contract::hash([$connection->merchant_id, $connection->environment, $readerId]),
                    'state' => $reader['status'], 'verified_at' => now(), 'acceptance_at' => null, 'acceptance_build' => null,
                ]));
                $settings = $this->store->decrypt($register->configuration);
                unset($settings['acceptance']);
                $this->store->rows('registers', $scope['tenant_id'])->where('id', $registerId)->update([
                    'provider' => 'stripe_terminal', 'connection_id' => $connection->id, 'collection_enabled' => false, 'configuration' => $this->store->encrypt($settings)]);
                $this->store->event($scope['tenant_id'], $registerId, 'stripe_reader_bound', ['actor_id' => $scope['actor_id'], 'reader_id' => $readerId, 'collection_enabled' => false]);
            });
        });
    }
}
