<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PlaidService
{
    public function ready(): bool
    {
        return filled(config('trajectory.plaid.client_id')) && filled(config('trajectory.plaid.secret'));
    }

    public function call(string $path, array $payload): array
    {
        abort_unless($this->ready(), 503, 'Bank connection is not configured.');
        $base = match (config('trajectory.plaid.environment')) {
            'production' => 'https://production.plaid.com', 'sandbox' => 'https://sandbox.plaid.com',
            default => throw ValidationException::withMessages(['provider' => 'Unsupported Plaid environment.']),
        };
        try {
            $response = Http::timeout(25)->post($base.$path, [
                'client_id' => config('trajectory.plaid.client_id'), 'secret' => config('trajectory.plaid.secret'), ...$payload,
            ]);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['provider' => 'Bank provider unavailable. Try again later.']);
        }
        if (! $response->successful()) {
            $code = $response->json('error_code');
            $safe = in_array($code, ['ITEM_LOGIN_REQUIRED', 'PRODUCT_NOT_READY', 'TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION', 'RATE_LIMIT_EXCEEDED'], true) ? $code : 'PROVIDER_ERROR';
            throw ValidationException::withMessages(['provider' => $safe]);
        }

        return $response->json();
    }

    public function link(Space $space, ?Connection $connection = null): array
    {
        $payload = ['client_name' => 'Everbranch Trajectory', 'language' => 'en', 'country_codes' => ['US'], 'user' => ['client_user_id' => 'trajectory:'.$space->id]];
        if ($connection) {
            $payload['access_token'] = $connection->access_token;
        } else {
            // Transactions is required for cash timing. Investments and liabilities are
            // consented during Link but fetched only for compatible selected accounts,
            // preserving bank availability and avoiding product charges for accounts
            // where Trajectory has no applicable evidence to retrieve.
            $payload += [
                'products' => ['transactions'],
                'additional_consented_products' => ['investments', 'liabilities'],
                'transactions' => ['days_requested' => 730],
            ];
        }
        if (config('trajectory.plaid.webhook_url')) {
            $payload['webhook'] = config('trajectory.plaid.webhook_url');
        }
        if (config('trajectory.plaid.redirect_uri')) {
            $payload['redirect_uri'] = config('trajectory.plaid.redirect_uri');
        }
        $result = $this->call('/link/token/create', $payload);

        return ['link_token' => $result['link_token'], 'expiration' => $result['expiration']];
    }

    public function exchange(Space $space, string $publicToken, ?string $institutionName = null): Connection
    {
        $result = $this->call('/item/public_token/exchange', ['public_token' => $publicToken]);
        $existing = Connection::where('external_id', $result['item_id'])->first();
        abort_if($existing && $existing->space_id !== $space->id, 409, 'This bank item is already connected in another space.');

        return Connection::updateOrCreate(['external_id' => $result['item_id']], array_filter(['space_id' => $space->id, 'access_token' => $result['access_token'], 'status' => 'connected', 'institution_name' => $institutionName], fn ($value) => $value !== null));
    }

    public function sync(Connection $connection): void
    {
        Cache::lock('trajectory:sync:'.$connection->id, 300)->get(function () use ($connection): void {
            $connection->refresh();
            $space = Space::findOrFail($connection->space_id);
            if (! config('trajectory.enabled') || ! $space->enabled || ! app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($space->tenant_id, 'trajectory') || $connection->status === 'disconnected') {
                return;
            }
            $cursor = $connection->cursor;
            $changes = [];
            $accounts = [];
            // Do not commit pages until a complete consistent pagination pass succeeds.
            for ($page = 0; $page < 200; $page++) {
                $response = $this->call('/transactions/sync', array_filter(['access_token' => $connection->access_token, 'cursor' => $cursor, 'count' => 500], fn ($v) => $v !== null));
                $changes[] = $response;
                foreach ($response['accounts'] ?? [] as $account) {
                    $accounts[$account['account_id']] = $account;
                }
                $cursor = $response['next_cursor'];
                if (! $response['has_more']) {
                    break;
                }
            }
            abort_if($response['has_more'], 503, 'Bank history exceeds this sync batch.');
            DB::transaction(function () use ($connection, $changes, $accounts, $cursor): void {
                $connection = Connection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
                if ($connection->status === 'disconnected') {
                    return;
                }
                foreach ($accounts as $remoteId => $data) {
                    if (($data['balances']['iso_currency_code'] ?? null) !== 'USD') {
                        continue;
                    }
                    $account = Account::updateOrCreate(['source_key' => 'plaid:'.$connection->id.':'.$remoteId], [
                        'space_id' => $connection->space_id, 'connection_id' => $connection->id, 'name' => $data['name'],
                        'kind' => in_array($data['type'], ['credit', 'loan'], true) ? $data['type'] : 'cash',
                        'balance_cents' => isset($data['balances']['current']) ? Money::cents($data['balances']['current']) : null,
                        'observed_at' => now(),
                    ]);
                    foreach ($changes as $page) {
                        $rows = [];
                        foreach ([...$page['added'], ...$page['modified']] as $tx) {
                            if ($tx['account_id'] !== $remoteId || ($tx['iso_currency_code'] ?? null) !== 'USD') {
                                continue;
                            }
                            $providerCategory = strtolower($tx['personal_finance_category']['primary'] ?? '');
                            $category = match ($providerCategory) {
                                'income' => 'income', 'rent_and_utilities' => 'utilities', 'transportation', 'travel' => 'transport', 'medical' => 'health', 'entertainment' => 'entertainment', 'bank_fees' => 'fees', default => null
                            };
                            $rows[] = ['id' => $tx['transaction_id'], 'pending_id' => $tx['pending_transaction_id'] ?? null, 'date' => $tx['date'], 'merchant' => $tx['merchant_name'] ?? $tx['name'], 'amount_cents' => -Money::cents($tx['amount']), 'pending' => $tx['pending'], 'category' => $category];
                        }
                        app(LedgerService::class)->ingest($account, $rows);
                        foreach ($page['removed'] as $removed) {
                            Transaction::where('account_id', $account->id)->where('source_key', $account->source_key.':'.$removed['transaction_id'])->update(['removed' => true]);
                        }
                    }
                    $account->update(['history_start' => Transaction::where('account_id', $account->id)->where('removed', false)->min('posted_on')]);
                }
                $connection->update(['cursor' => $cursor, 'synced_at' => now(), 'status' => 'connected']);
            });
            $this->liabilities($connection);
            $this->investments($connection);
        });
    }

    public function liabilities(Connection $connection): void
    {
        try {
            $data = $this->call('/liabilities/get', ['access_token' => $connection->access_token]);
            // Provider evidence is a setup suggestion, never an overwrite of reviewed debt terms.
            $connection->update(['coverage' => ['liabilities' => $data['liabilities'] ?? [], 'observed_at' => now()->toIso8601String()]]);
        } catch (\Throwable) {
            // Transactions can be complete while debt metadata is unsupported.
        }
    }

    public function investments(Connection $connection): void
    {
        try {
            $data = $this->call('/investments/holdings/get', ['access_token' => $connection->access_token]);
            DB::transaction(function () use ($connection, $data): void {
                $connection = Connection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
                if ($connection->status === 'disconnected') {
                    return;
                }
                $supported = 0;
                foreach ($data['accounts'] ?? [] as $account) {
                    if (($account['balances']['iso_currency_code'] ?? null) !== 'USD' || empty($account['account_id'])) {
                        continue;
                    }
                    Account::updateOrCreate(['source_key' => 'plaid:'.$connection->id.':'.$account['account_id']], [
                        'space_id' => $connection->space_id,
                        'connection_id' => $connection->id,
                        'name' => $account['name'] ?? 'Investment account',
                        'kind' => 'investment',
                        'balance_cents' => isset($account['balances']['current']) ? Money::cents($account['balances']['current']) : null,
                        'observed_at' => now(),
                    ]);
                    $supported++;
                }
                $coverage = $connection->coverage ?? [];
                $coverage['investments'] = ['account_count' => $supported, 'observed_at' => now()->toIso8601String()];
                $connection->update(['coverage' => $coverage]);
            });
        } catch (\Throwable) {
            // Transactions can be complete while investment data is unavailable.
        }
    }

    public function debtSuggestions(Space $space): array
    {
        $suggestions = [];
        foreach (Connection::where('space_id', $space->id)->where('status', '!=', 'disconnected')->get() as $connection) {
            foreach (($connection->coverage['liabilities'] ?? []) as $kind => $rows) {
                foreach ($rows ?? [] as $row) {
                    $account = Account::where('space_id', $space->id)->where('source_key', 'plaid:'.$connection->id.':'.($row['account_id'] ?? ''))->first();
                    if (! $account) {
                        continue;
                    }
                    $rate = $kind === 'credit' ? collect($row['aprs'] ?? [])->firstWhere('apr_type', 'purchase_apr')['apr_percentage'] ?? null : ($row['interest_rate']['percentage'] ?? $row['interest_rate_percentage'] ?? null);
                    $payment = $kind === 'mortgage' ? null : ($row['minimum_payment_amount'] ?? null);
                    $suggestions[] = ['account_id' => $account->id, 'name' => $account->name, 'kind' => $kind === 'mortgage' ? 'mortgage' : ($kind === 'credit' ? 'credit' : 'loan'), 'balance_cents' => $account->balance_cents, 'apr_bps' => $rate === null ? null : Money::cents($rate), 'payment_cents' => $payment === null ? null : Money::cents($payment), 'next_due_on' => $row['next_payment_due_date'] ?? null, 'observed_on' => substr($connection->coverage['observed_at'] ?? now()->toDateString(), 0, 10), 'confirmed' => false, 'source' => 'Plaid', 'note' => $kind === 'mortgage' ? 'Review principal and interest separately from escrow and fees. Provider rate is the current interest rate, not origination APR.' : 'Review the payment and all rate tiers. A single reviewed rate is used for the estimate.'];
                }
            }
        }

        return $suggestions;
    }

    public function verifyWebhook(string $jwt, string $body): bool
    {
        try {
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) {
                return false;
            }
            $decode = fn ($value) => base64_decode(strtr($value, '-_', '+/'), true);
            $header = json_decode($decode($parts[0]), true, 8, JSON_THROW_ON_ERROR);
            $claims = json_decode($decode($parts[1]), true, 8, JSON_THROW_ON_ERROR);
            if (($header['alg'] ?? '') !== 'ES256' || ! is_string($header['kid'] ?? null)) {
                return false;
            }
            if (! is_int($claims['iat'] ?? null) || abs(time() - $claims['iat']) > 300 || ! hash_equals(hash('sha256', $body), $claims['request_body_sha256'] ?? '')) {
                return false;
            }
            $key = $this->call('/webhook_verification_key/get', ['key_id' => $header['kid']])['key'];
            if (($key['alg'] ?? '') !== 'ES256' || ($key['crv'] ?? '') !== 'P-256' || ! empty($key['expired_at'])) {
                return false;
            }
            $x = $decode($key['x']);
            $y = $decode($key['y']);
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                return false;
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004').$x.$y;
            $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
            $signature = $decode($parts[2]);
            if (strlen($signature) !== 64) {
                return false;
            }
            $integer = function ($bytes): string {
                $bytes = ltrim($bytes, "\x00");
                if ($bytes === '') {
                    $bytes = "\x00";
                } if (ord($bytes[0]) > 127) {
                    $bytes = "\x00".$bytes;
                }

                return "\x02".chr(strlen($bytes)).$bytes;
            };
            $rs = $integer(substr($signature, 0, 32)).$integer(substr($signature, 32));

            return openssl_verify($parts[0].'.'.$parts[1], "\x30".chr(strlen($rs)).$rs, $pem, OPENSSL_ALGO_SHA256) === 1;
        } catch (\Throwable) {
            return false;
        }
    }
}
