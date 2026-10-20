<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlaidService
{
    public function ready(): bool
    {
        return filled(config('trajectory.plaid.client_id')) && filled(config('trajectory.plaid.secret'));
    }

    public function linkReady(): bool
    {
        return $this->ready() && $this->redirectUri() !== null;
    }

    private function redirectUri(): ?string
    {
        $uri = config('trajectory.plaid.redirect_uri');
        if (! is_string($uri) || ! filter_var($uri, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($uri);
        $origin = parse_url((string) config('app.url'));
        if (($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== strtolower($origin['host'] ?? '')
            || ($parts['port'] ?? null) !== ($origin['port'] ?? null) || ($parts['path'] ?? '') !== '/trajectory'
            || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $uri;
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

    public function link(Space $space, ?Connection $connection = null, ?int $actorId = null): array
    {
        abort_unless($this->linkReady(), 503, 'Bank sign-in is being prepared for mobile. Existing feeds and statement imports remain available.');
        abort_if($connection && ($connection->space_id !== $space->id || $connection->status === 'disconnected'), 410, 'Reconnect this bank with a new sign-in.');
        $payload = ['client_name' => 'Everbranch Trajectory', 'language' => 'en', 'country_codes' => ['US'], 'user' => ['client_user_id' => 'trajectory:user:'.($actorId ?? $space->owner_user_id).':space:'.$space->id]];
        if ($connection) {
            $payload['access_token'] = $connection->access_token;
        } else {
            $payload += [
                'products' => ['transactions'],
                'transactions' => ['days_requested' => 730],
            ];
            if ($space->kind === 'household') {
                $payload['additional_consented_products'] = ['investments', 'liabilities'];
            }
        }
        if (config('trajectory.plaid.webhook_url')) {
            $payload['webhook'] = config('trajectory.plaid.webhook_url');
        }
        $payload['redirect_uri'] = $this->redirectUri();
        $result = $this->call('/link/token/create', $payload);

        return ['link_token' => $result['link_token'], 'expiration' => $result['expiration']];
    }

    public function issueLinkSession(Space $space, ?Connection $connection, int $actorId, string $browserSession): array
    {
        $link = $this->link($space, $connection, $actorId);
        $expiresAt = CarbonImmutable::parse($link['expiration'])->min(now()->addHours(4));
        abort_unless($expiresAt->isFuture(), 503, 'Bank sign-in is temporarily unavailable. Please try again.');
        $session = (string) Str::uuid();
        $state = [
            'actor_id' => $actorId, 'space_id' => $space->id, 'connection_id' => $connection?->id,
            'browser_session' => hash('sha256', $browserSession), 'link_token' => $link['link_token'],
            'expires_at' => $expiresAt->toIso8601String(), 'status' => 'issued',
        ];
        $this->storeLinkSession($session, $state);

        return [...$link, 'expiration' => $state['expires_at'], 'link_session' => $session, 'connection_id' => $connection?->id];
    }

    public function completeLinkSession(Space $space, int $actorId, string $browserSession, string $session, ?int $connectionId, ?string $publicToken, callable $complete): array
    {
        return Cache::lock($this->linkSessionKey($session).':lock', 60)->block(5, function () use ($space, $actorId, $browserSession, $session, $connectionId, $publicToken, $complete): array {
            try {
                $encrypted = Cache::get($this->linkSessionKey($session));
                $state = is_string($encrypted) ? json_decode(Crypt::decryptString($encrypted), true, 16, JSON_THROW_ON_ERROR) : null;
            } catch (\Throwable) {
                $state = null;
            }
            abort_unless(is_array($state) && CarbonImmutable::parse($state['expires_at'])->isFuture(), 410, 'Your bank sign-in expired. Choose Connect a bank to start again.');
            abort_unless($state['actor_id'] === $actorId && $state['space_id'] === $space->id
                && $state['connection_id'] === $connectionId && hash_equals($state['browser_session'], hash('sha256', $browserSession)), 403, 'This bank sign-in belongs to another session or workspace.');
            $tokenHash = $publicToken === null ? null : hash('sha256', $publicToken);
            if ($state['status'] === 'complete') {
                abort_unless(($state['public_token_hash'] ?? null) === $tokenHash, 409, 'This bank sign-in has already finished.');

                return $state['result'];
            }
            abort_unless($state['status'] === 'issued', 409, 'This bank sign-in could not be completed. Choose Connect a bank to start again.');
            $state['status'] = 'processing';
            $state['public_token_hash'] = $tokenHash;
            $this->storeLinkSession($session, $state);
            try {
                $state['result'] = $complete();
                $state['status'] = 'complete';
            } catch (\Throwable $error) {
                // An exchanged public token is single-use. An uncertain provider
                // result must never be silently replayed or assigned elsewhere.
                $state['status'] = 'failed';
                $this->storeLinkSession($session, $state);
                throw $error;
            }
            $this->storeLinkSession($session, $state);

            return $state['result'];
        });
    }

    private function linkSessionKey(string $session): string
    {
        return 'trajectory:plaid:link:'.hash('sha256', $session);
    }

    private function storeLinkSession(string $session, array $state): void
    {
        Cache::put($this->linkSessionKey($session), Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)), CarbonImmutable::parse($state['expires_at']));
    }

    public function exchange(Space $space, string $publicToken, ?string $institutionName = null, ?int $ownerId = null): Connection
    {
        $result = $this->call('/item/public_token/exchange', ['public_token' => $publicToken]);
        $existing = Connection::where('external_id', $result['item_id'])->first();
        abort_if($existing && $existing->space_id !== $space->id, 409, 'This bank item is already connected in another space.');
        abort_if($existing && $ownerId && ($existing->coverage['owner_user_id'] ?? $space->owner_user_id) !== $ownerId, 403, 'Only the bank connection owner can reconnect this login.');

        $connection = Connection::updateOrCreate(['external_id' => $result['item_id']], array_filter(['space_id' => $space->id, 'access_token' => $result['access_token'], 'status' => 'connected', 'institution_name' => $institutionName,
            'coverage' => [...($existing?->coverage ?? []), 'purpose' => 'bank_feed']], fn ($value) => $value !== null));
        if (! $existing && $ownerId) {
            $connection->update(['status' => 'mapping_required', 'coverage' => ['purpose' => 'bank_feed', 'require_account_mapping' => true, 'owner_user_id' => $ownerId, 'account_spaces' => []]]);
        }

        return $connection;
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
                $unassigned = false;
                foreach ($accounts as $remoteId => $data) {
                    if (($data['balances']['iso_currency_code'] ?? null) !== 'USD') {
                        continue;
                    }
                    $account = Account::firstOrNew(['source_key' => 'plaid:'.$connection->id.':'.$remoteId]);
                    $targetId = $account->exists ? $account->space_id : ($connection->coverage['account_spaces'][$remoteId] ?? null);
                    if (! $targetId && ($connection->coverage['require_account_mapping'] ?? false)) {
                        $unassigned = true;

                        continue;
                    }
                    $account->fill([
                        'space_id' => $targetId ?? $connection->space_id, 'connection_id' => $connection->id, 'name' => $data['name'].(empty($data['mask']) ? '' : ' (…'.$data['mask'].')'),
                        'kind' => in_array($data['type'], ['credit', 'loan'], true) ? $data['type'] : 'cash',
                        'balance_cents' => isset($data['balances']['current']) ? Money::cents($data['balances']['current']) : null,
                        'observed_at' => now(),
                    ])->save();
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
                $connection->update(['cursor' => $cursor, 'synced_at' => now(), 'status' => $unassigned ? 'mapping_required' : 'connected']);
            });
            if ($space->kind === 'household') {
                $this->liabilities($connection);
                $this->investments($connection);
            }
        });
    }

    public function liabilities(Connection $connection): void
    {
        try {
            $data = $this->call('/liabilities/get', ['access_token' => $connection->access_token]);
            // Provider evidence is a setup suggestion, never an overwrite of reviewed debt terms.
            $connection->update(['coverage' => [...($connection->coverage ?? []), 'liabilities' => $data['liabilities'] ?? [], 'observed_at' => now()->toIso8601String()]]);
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
                    $local = Account::firstOrNew(['source_key' => 'plaid:'.$connection->id.':'.$account['account_id']]);
                    $targetId = $local->exists ? $local->space_id : ($connection->coverage['account_spaces'][$account['account_id']] ?? null);
                    if (! $targetId && ($connection->coverage['require_account_mapping'] ?? false)) {
                        $connection->update(['status' => 'mapping_required']);

                        continue;
                    }
                    $local->fill([
                        'space_id' => $targetId ?? $connection->space_id,
                        'connection_id' => $connection->id,
                        'name' => $account['name'] ?? 'Investment account',
                        'kind' => 'investment',
                        'balance_cents' => isset($account['balances']['current']) ? Money::cents($account['balances']['current']) : null,
                        'observed_at' => now(),
                    ])->save();
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
        foreach (Connection::whereIn('id', Account::where('space_id', $space->id)->whereNotNull('connection_id')->pluck('connection_id'))->where('status', '!=', 'disconnected')->get() as $connection) {
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
