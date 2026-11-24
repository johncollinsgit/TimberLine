<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class SimpleFinService
{
    private const HOSTS = ['bridge.simplefin.org', 'beta-bridge.simplefin.org'];

    public function ready(): bool
    {
        return (bool) config('trajectory.simplefin.enabled');
    }

    public function linkReady(Space $space): bool
    {
        return $this->ready() && $space->enabled
            && in_array((int) $space->id, config('trajectory.simplefin.link_space_ids', []), true);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['simplefin' => $message]);
    }

    /** Never request a host, port or redirect chosen by an arbitrary token. */
    private function url(string $value, bool $claim = false): array
    {
        $parts = parse_url($value);
        if (! $parts || preg_match('/[\x00-\x20\\\\]/', $value)
            || ($parts['scheme'] ?? '') !== 'https'
            || ! in_array($parts['host'] ?? '', self::HOSTS, true)
            || (isset($parts['port']) && $parts['port'] !== 443)
            || isset($parts['query']) || isset($parts['fragment'])) {
            $this->fail('Use a setup token issued by SimpleFIN Bridge.');
        }
        $path = $parts['path'] ?? '';
        if ($claim ? (! preg_match('~^/simplefin/claim/[A-Za-z0-9_-]+$~D', $path) || isset($parts['user']) || isset($parts['pass']))
            : (! preg_match('~^/simplefin/?$~D', $path) || empty($parts['user']) || empty($parts['pass']))) {
            $this->fail('SimpleFIN returned an unsupported connection. Create a new setup token.');
        }

        return $parts;
    }

    private function http()
    {
        return Http::acceptJson()->connectTimeout(10)->timeout(25)->withoutRedirecting()->withOptions(['verify' => true]);
    }

    public function connect(Space $space, User $owner, string $token, string $requestId, string $sessionId, ?Connection $connection = null): Connection
    {
        app(WorkspaceService::class)->manage($owner, $space);
        abort_unless($this->linkReady($space), 503, 'SimpleFIN connections are not enabled for this space.');
        if ($connection) {
            abort_unless($connection->provider === 'simplefin' && $connection->space_id === $space->id
                && ($connection->coverage['owner_user_id'] ?? $space->owner_user_id) === $owner->id, 403);
        }
        $token = trim($token);
        $decoded = base64_decode($token, true);
        if ($decoded === false || strlen($decoded) > 2048) {
            $this->fail('Paste the complete setup token from SimpleFIN.');
        }
        $this->url($decoded, true);
        $fingerprint = hash('sha256', $token);
        $key = 'trajectory:simplefin:claim:'.$fingerprint;
        $requestKey = 'trajectory:simplefin:request:'.$owner->id.':'.$requestId;
        $binding = [$owner->id, $space->id, hash('sha256', $sessionId), $connection?->id];

        return Cache::lock($requestKey.':lock', 90)->block(5, fn () => Cache::lock($key.':lock', 90)->block(5, function () use ($key, $requestKey, $fingerprint, $binding, $decoded, $connection, $space, $owner): Connection {
            $priorRequest = Cache::get($requestKey);
            abort_if($priorRequest && $priorRequest !== $fingerprint, 409, 'This connection request changed. Start again.');
            Cache::put($requestKey, $fingerprint, now()->addDay());
            $cached = Cache::get($key);
            $attempt = $cached ? decrypt($cached) : null;
            if ($attempt) {
                abort_unless($attempt['binding'] === $binding, 409, 'This setup token was already used. Create a new token.');
                if (isset($attempt['connection_id'])) {
                    $saved = Connection::where('space_id', $space->id)->findOrFail($attempt['connection_id']);
                    abort_if($saved->status === 'disconnected', 410, 'Create a new setup token to reconnect.');

                    return $saved;
                }
                if (! isset($attempt['access_url'])) {
                    $this->fail('The earlier token claim could not be confirmed. Disable that token in SimpleFIN and create a new one.');
                }
            } else {
                // Reserve before HTTP. An uncertain single-use claim is never retried.
                $attempt = ['binding' => $binding];
                Cache::put($key, encrypt($attempt), now()->addDay());
                try {
                    $response = $this->http()->withBody('', 'text/plain')->post($decoded);
                } catch (\Throwable) {
                    $this->fail('SimpleFIN did not confirm the token claim. Disable that token in SimpleFIN and create a new one.');
                }
                if (! $response->successful()) {
                    $this->fail('SimpleFIN could not claim this setup token. It may be expired or already used. Disable it and create a new token.');
                }
                $attempt['access_url'] = trim($response->body());
                $this->url($attempt['access_url']);
                Cache::put($key, encrypt($attempt), now()->addDay());
            }
            $external = 'simplefin:'.hash_hmac('sha256', $attempt['access_url'], (string) config('app.key'));
            $saved = DB::transaction(function () use ($connection, $external, $space, $owner, $attempt): Connection {
                $lockedSpace = Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
                app(WorkspaceService::class)->manage($owner->fresh(), $lockedSpace);
                abort_unless($this->linkReady($lockedSpace), 503);
                $existing = Connection::where('external_id', $external)->lockForUpdate()->first();
                abort_if($existing && ($existing->space_id !== $space->id || ($existing->coverage['owner_user_id'] ?? null) !== $owner->id), 409, 'This connection is already owned elsewhere.');
                abort_if($existing?->status === 'disconnected', 410, 'Create a new setup token to reconnect.');
                abort_if($connection && $existing && $existing->id !== $connection->id, 409, 'This setup token belongs to another saved connection.');
                $saved = $connection ? Connection::whereKey($connection->id)->lockForUpdate()->firstOrFail() : ($existing ?? new Connection);
                if ($saved->exists) {
                    abort_unless($saved->provider === 'simplefin' && $saved->space_id === $space->id
                        && ($saved->coverage['owner_user_id'] ?? null) === $owner->id, 403);
                }
                $saved->fill([
                    'space_id' => $space->id, 'provider' => 'simplefin', 'external_id' => $external,
                    'access_token' => $attempt['access_url'], 'cursor' => null, 'status' => 'mapping_required',
                    'institution_name' => 'SimpleFIN Bridge',
                    'coverage' => [...($saved->coverage ?? []), 'purpose' => 'bank_feed', 'require_account_mapping' => true,
                        'owner_user_id' => $owner->id, 'account_spaces' => $saved->coverage['account_spaces'] ?? [],
                        'account_kinds' => $saved->coverage['account_kinds'] ?? [], 'balance_multipliers' => $saved->coverage['balance_multipliers'] ?? []],
                ])->save();
                Event::create(['space_id' => $space->id, 'actor_id' => $owner->id, 'action' => 'simplefin_connected', 'record_id' => $saved->id]);

                return $saved;
            });
            Cache::put($key, encrypt(['binding' => $binding, 'connection_id' => $saved->id]), now()->addDay());

            return $saved;
        }));
    }

    private function reserveRequest(string $access): void
    {
        $key = 'trajectory:simplefin:requests:'.hash_hmac('sha256', $access, (string) config('app.key'));
        Cache::lock($key.':lock', 10)->block(3, function () use ($key): void {
            $attempts = array_values(array_filter(Cache::get($key, []), fn ($at) => $at > now()->subDay()->timestamp));
            if (count($attempts) >= 20) {
                $this->fail('The daily SimpleFIN refresh budget has been reached. Saved records are available; try again tomorrow.');
            }
            $attempts[] = now()->timestamp;
            Cache::put($key, $attempts, now()->addDay());
        });
    }

    private function fetch(Connection $connection, array $query): array
    {
        abort_unless($this->ready() && $connection->provider === 'simplefin' && $connection->status !== 'disconnected', 410, 'Reconnect SimpleFIN before refreshing.');
        $access = $connection->access_token;
        $parts = $this->url($access);
        $this->reserveRequest($access);
        try {
            $response = $this->http()->withBasicAuth(rawurldecode($parts['user']), rawurldecode($parts['pass']))
                ->get('https://'.$parts['host'].'/simplefin/accounts', ['version' => 2, ...$query]);
        } catch (\Throwable) {
            $this->fail('SimpleFIN is unavailable. Your saved records have been retained. Try again later.');
        }
        if ($response->status() === 402) {
            $this->fail('Your SimpleFIN subscription needs attention. Open SimpleFIN to review it.');
        }
        if ($response->status() === 403) {
            $this->fail('SimpleFIN access has expired or was revoked. Create a new setup token to reconnect.');
        }
        if (! $response->successful()) {
            $this->fail('SimpleFIN could not refresh the bank feed. Your saved records have been retained.');
        }
        $data = $response->json();
        if (! is_array($data) || ! isset($data['accounts']) || ! is_array($data['accounts'])
            || ! is_array($data['errlist'] ?? []) || ! is_array($data['errors'] ?? [])) {
            $this->fail('SimpleFIN returned an incomplete response. Your saved records have been retained.');
        }

        return $data;
    }

    private function remoteId(array $account): string
    {
        $id = $account['id'] ?? null;
        $bank = $account['conn_id'] ?? $account['org']['id'] ?? $account['org']['domain'] ?? null;
        if (! is_string($id) || $id === '' || ! is_string($bank) || $bank === '') {
            $this->fail('SimpleFIN returned an account without a stable bank identity.');
        }

        return hash('sha256', json_encode([$bank, $id], JSON_THROW_ON_ERROR));
    }

    public function accounts(Connection $connection): array
    {
        $data = $this->fetch($connection, ['balances-only' => 1]);
        if (! empty($data['errlist']) || ! empty($data['errors'])) {
            $this->fail('One or more banks need attention in SimpleFIN. Resolve that connection before mapping accounts. '.implode(' ', $this->errors($data, $connection)));
        }
        $rows = [];
        foreach ($data['accounts'] as $account) {
            if (($account['currency'] ?? null) !== 'USD') {
                continue;
            }
            $id = $this->remoteId($account);
            abort_if(isset($rows[$id]), 503, 'SimpleFIN returned duplicate account identities.');
            $rows[$id] = ['id' => $id, 'name' => mb_substr((string) ($account['name'] ?? 'Bank account'), 0, 160),
                'mask' => null, 'currency' => 'USD', 'kind' => $connection->coverage['account_kinds'][$id] ?? null,
                'reported_balance_cents' => $this->amount($account['balance'] ?? null),
                'balance_review_required' => (bool) ($connection->coverage['balance_review_pending'][$id] ?? false),
                'balance_multiplier' => $connection->coverage['balance_multipliers'][$id] ?? null];
        }

        return array_values($rows);
    }

    private function amount(mixed $value): int
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^-?\d{1,10}(?:\.\d{1,2})?$/D', (string) $value)) {
            $this->fail('SimpleFIN returned an unsupported amount. Your saved figures have been retained.');
        }

        return Money::cents($value);
    }

    private function errors(array $data, Connection $connection): array
    {
        $parts = $this->url($connection->access_token);
        $secrets = [$connection->access_token, rawurldecode($parts['user']), rawurldecode($parts['pass'])];
        $messages = [];
        foreach ([...($data['errlist'] ?? []), ...($data['errors'] ?? [])] as $error) {
            $message = is_string($error) ? $error : (is_array($error) ? ($error['msg'] ?? 'A bank connection needs attention in SimpleFIN.') : null);
            if (! is_string($message)) {
                continue;
            }
            $message = strip_tags(html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $message = preg_replace('~https?://\S+~i', '[provider link]', $message);
            $message = preg_replace('/[\x00-\x1F\x7F]/', ' ', $message);
            $messages[] = mb_substr(str_replace($secrets, '[redacted]', $message), 0, 240);
        }

        return array_slice(array_values(array_unique($messages)), 0, 10);
    }

    public function sync(Connection $connection): void
    {
        if (! $this->ready()) {
            return;
        }
        Cache::lock('trajectory:sync:'.$connection->id, 300)->get(function () use ($connection): void {
            $connection->refresh();
            $space = Space::findOrFail($connection->space_id);
            $owner = User::findOrFail($connection->coverage['owner_user_id'] ?? $space->owner_user_id);
            if ($connection->status === 'disconnected' || ! app(FinanceAccess::class)->allows($owner, $space)) {
                return;
            }
            $now = CarbonImmutable::now('UTC');
            $end = $now->addSecond()->timestamp;
            $latestStart = $end - 90 * 86400;
            $cursor = $connection->cursor ? json_decode($connection->cursor, true, 8, JSON_THROW_ON_ERROR) : [];
            $target = $cursor['target'] ?? $now->subDays(730)->timestamp;
            $backfillEnd = $cursor['backfill_end'] ?? $latestStart;
            $windows = [[$latestStart, $end, false]];
            for ($until = $backfillEnd; $until > $target; $until = $start) {
                $start = max($target, $until - 90 * 86400);
                $windows[] = [$start, $until, true];
            }
            foreach ($windows as [$start, $until, $historical]) {
                $connection->refresh();
                if ($connection->status === 'disconnected') {
                    return;
                }
                $data = $this->fetch($connection, ['start-date' => $start, 'end-date' => $until]);
                // A partial response can update valid returned accounts, but cannot
                // advance historical coverage or erase absent accounts/transactions.
                $partial = ! empty($data['errlist']) || ! empty($data['errors']);
                $next = ['target' => $target, 'backfill_end' => $historical && ! $partial ? $start : $backfillEnd];
                $next['complete'] = $next['backfill_end'] <= $target;
                $this->apply($connection, $owner, $data, $next, $partial);
                $backfillEnd = $next['backfill_end'];
                if ($partial || $connection->refresh()->status === 'mapping_required') {
                    break;
                }
            }
        });
    }

    private function apply(Connection $connection, User $owner, array $data, array $cursor, bool $partial): void
    {
        DB::transaction(function () use ($connection, $owner, $data, $cursor, $partial): void {
            $connection = Connection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if ($connection->status === 'disconnected') {
                return;
            }
            $owner = $owner->fresh();
            app(FinanceAccess::class)->authorize($owner, Space::findOrFail($connection->space_id));
            $coverage = $connection->coverage ?? [];
            $unassigned = false;
            $seen = [];
            foreach ($data['accounts'] as $remote) {
                $id = $this->remoteId($remote);
                abort_if(isset($seen[$id]), 503, 'SimpleFIN returned duplicate account identities.');
                $seen[$id] = true;
                $key = app(BankProviderService::class)->sourceKey($connection, $id);
                $account = Account::where('source_key', $key)->lockForUpdate()->first();
                if (($remote['currency'] ?? null) !== 'USD') {
                    abort_if($account, 409, 'This bank account changed currency. Review its mapping before refreshing.');

                    continue;
                }
                $target = $account?->space_id ?? $coverage['account_spaces'][$id] ?? null;
                $kind = $coverage['account_kinds'][$id] ?? null;
                $multiplier = $coverage['balance_multipliers'][$id] ?? null;
                if (! $target || ! in_array($kind, ['cash', 'credit', 'loan'], true) || ! in_array($multiplier, [1, -1], true)) {
                    $unassigned = true;

                    continue;
                }
                $space = Space::whereKey($target)->lockForUpdate()->firstOrFail();
                app(FinanceAccess::class)->authorize($owner, $space);
                abort_if($account && ($account->connection_id !== $connection->id || $account->kind !== $kind || $account->currency !== 'USD'), 409, 'This account mapping changed. Review it before refreshing.');
                $timestamp = $remote['balance-date'] ?? null;
                if (! is_int($timestamp) || $timestamp < 1 || $timestamp > now()->addDay()->timestamp) {
                    $this->fail('SimpleFIN returned an invalid balance date. Saved balances have been retained.');
                }
                $reported = $this->amount($remote['balance'] ?? null);
                $balance = $reported * $multiplier;
                $available = isset($remote['available-balance']) ? $this->amount($remote['available-balance']) : null;
                $account ??= new Account(['space_id' => $space->id, 'connection_id' => $connection->id, 'source_key' => $key,
                    'name' => mb_substr((string) ($remote['name'] ?? 'Bank account'), 0, 160), 'kind' => $kind, 'currency' => 'USD']);
                if (! $account->observed_at || $timestamp >= $account->observed_at->timestamp) {
                    $review = $kind !== 'cash' && $reported !== 0 && ($coverage['zero_balance_review'][$id] ?? false);
                    if ($review) {
                        $coverage['balance_review_pending'][$id] = true;
                        $unassigned = true;
                    }
                    $account->fill(['balance_cents' => $review ? null : $balance, 'observed_at' => CarbonImmutable::createFromTimestampUTC($timestamp)]);
                    $coverage['simplefin_balances'][$id] = ['available_cents' => $available, 'observed_at' => $timestamp];
                }
                $account->save();
                if (! is_array($remote['transactions'] ?? [])) {
                    $this->fail('SimpleFIN returned incomplete transaction history. Saved records have been retained.');
                }
                $rows = [];
                foreach ($remote['transactions'] ?? [] as $tx) {
                    if (! empty($tx['pending'])) {
                        continue;
                    }
                    if (! is_string($tx['id'] ?? null) || $tx['id'] === '' || ! is_int($tx['posted'] ?? null) || $tx['posted'] < 1
                        || $tx['posted'] > now()->addDay()->timestamp || ! is_string($tx['description'] ?? null)) {
                        $this->fail('SimpleFIN returned an incomplete transaction. Saved records have been retained.');
                    }
                    $rows[] = ['id' => hash('sha256', $tx['id']), 'date' => CarbonImmutable::createFromTimestampUTC($tx['posted'])->toDateString(),
                        'merchant' => mb_substr($tx['description'], 0, 160), 'amount_cents' => $this->amount($tx['amount'] ?? null),
                        'source_provider' => 'simplefin', 'pending' => false];
                }
                app(LedgerService::class)->ingest($account, $rows);
                $account->update(['history_start' => Transaction::where('account_id', $account->id)->where('removed', false)->min('posted_on')]);
            }
            $coverage['history_complete'] = $cursor['complete'] && ! $partial && ! $unassigned;
            $coverage['simplefin_attention'] = $partial;
            $coverage['simplefin_errors'] = $this->errors($data, $connection);
            $connection->update(['coverage' => $coverage, 'cursor' => json_encode($cursor, JSON_THROW_ON_ERROR), 'synced_at' => now(),
                'status' => $unassigned ? 'mapping_required' : ($partial ? 'needs_attention' : 'connected')]);
        });
    }
}
