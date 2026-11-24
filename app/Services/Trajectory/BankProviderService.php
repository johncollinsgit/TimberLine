<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Connection;

/** One credential owner and ledger, with provider-specific transport. */
class BankProviderService
{
    public function accounts(Connection $connection): array
    {
        if ($connection->provider === 'simplefin') {
            return app(SimpleFinService::class)->accounts($connection);
        }
        abort_unless($connection->provider === 'plaid', 422, 'This bank provider is unavailable.');
        $data = app(PlaidService::class)->call('/accounts/get', ['access_token' => $connection->access_token]);

        return collect($data['accounts'] ?? [])->filter(fn ($a) => ($a['balances']['iso_currency_code'] ?? null) === 'USD')->map(fn ($a) => [
            'id' => $a['account_id'], 'name' => $a['name'], 'mask' => $a['mask'] ?? null, 'currency' => 'USD',
            'kind' => match ($a['type'] ?? null) {
                'depository' => 'cash', 'credit' => 'credit', 'loan' => 'loan', 'investment' => 'investment', default => null,
            },
        ])->all();
    }

    public function sourceKey(Connection $connection, string $remoteId): string
    {
        abort_unless(in_array($connection->provider, ['plaid', 'simplefin'], true), 422);

        return $connection->provider.':'.$connection->id.':'.$remoteId;
    }

    public function sync(Connection $connection): void
    {
        match ($connection->provider) {
            'plaid' => app(PlaidService::class)->sync($connection),
            'simplefin' => app(SimpleFinService::class)->sync($connection),
            default => abort(422, 'This bank provider is unavailable.'),
        };
    }

    public function disconnect(Connection $connection): void
    {
        if ($connection->provider === 'plaid') {
            app(PlaidService::class)->call('/item/remove', ['access_token' => $connection->access_token]);
        } else {
            abort_unless($connection->provider === 'simplefin', 422);
        }
        // SimpleFIN has no remote revoke endpoint. The owner can also revoke
        // the app in SimpleFIN; erasing our credential stops this app's access.
        $connection->update(['status' => 'disconnected', 'access_token' => '']);
    }
}
